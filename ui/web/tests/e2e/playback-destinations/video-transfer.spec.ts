import { test, expect, type Browser, type Page } from '@playwright/test'
import { VideoDiagnostics } from './video-diagnostics'
import { loadedRuntimeModule } from './loaded-runtime-module'
import type { DeliveryResource, PlaybackSnapshotResource } from '../../../src/shared/api-client/gen/endpoints'
import { authenticatedGet, FixtureClient, login, type FixtureManifest, type FixtureUser } from '../live-updates/capacity-fixtures'

interface VideoManifest extends FixtureManifest {
  movie: { id: string; publicId: string }
  video: { id: string; publicId: string; path: string }
}

async function openBrowser(browser: Browser, user: FixtureUser, baseURL: string, name: string) {
  const context = await browser.newContext({ baseURL, ignoreHTTPSErrors: true })
  await context.addInitScript(deviceName => localStorage.setItem('baander-device-name', deviceName), name)
  const page = await context.newPage()
  await login(page, user)
  return { context, page }
}

function observeVideoDelivery(page: Page, videoId: string) {
  const observation: { startTime: number | null; initialKind: DeliveryResource['kind'] | null } = {
    startTime: null, initialKind: null,
  }
  page.on('response', async response => {
    const path = new URL(response.url()).pathname
    if (!response.ok() || (path !== '/api/stream/delivery' && path !== '/api/stream/sign')) return
    try {
      const request = response.request().postDataJSON() as { id?: string; path?: string }
      if (path === '/api/stream/delivery') {
        if (request.id !== videoId) return
        const delivery = await response.json() as DeliveryResource
        observation.initialKind ??= delivery.kind
        observation.startTime = (delivery.kind === 'original' || delivery.kind === 'cached')
          && typeof delivery.url === 'string' && delivery.url.length > 0 ? 0 : null
      } else {
        if (request.path !== `/api/transcode/${videoId}/master.m3u8`) return
        const body = await response.json() as { startTime?: number; data?: { startTime?: number } }
        const start = body.data?.startTime ?? body.startTime
        if (typeof start === 'number') observation.startTime = start
      }
    } catch { /* Reloads and closed contexts can interrupt an in-flight observation. */ }
  })
  return observation
}

async function snapshot(page: Page) {
  return (await authenticatedGet<{ data: PlaybackSnapshotResource }>(page, '/api/session/snapshot')).data
}

async function seek(page: Page, position: number) {
  const modulePath = await loadedRuntimeModule(page, '/src/features/session/services/playback-execution.ts')
  if (!modulePath) throw new Error('The application playback runtime has not loaded.')
  await page.evaluate(async ({ value, modulePath }) => {
    const { requestPlayback } = await import(/* @vite-ignore */ modulePath) as {
      requestPlayback: (intent: { action: 'seek'; position: number }) => Promise<void>
    }
    await requestPlayback({ action: 'seek', position: value })
  }, { value: position, modulePath })
}

const movieControls = (page: Page) => page.getByRole('slider', { name: 'Seek movie' }).locator('xpath=ancestor::div[1]')

async function waitForDestination(page: Page, name: string, position: number) {
  await expect.poll(async () => {
    const state = await snapshot(page)
    const destination = state.destinations.find(item => item.destinationId === state.activeDestinationId)
    return {
      name: destination?.name, transfer: state.transfer, state: state.observedState,
      cursor: Math.round(state.position * 10) / 10,
    }
  }, { timeout: 45_000 }).toEqual({ name, transfer: null, state: 'paused', cursor: position })
}

test('paused video transfers, reopens remotely, returns locally, and recovers after reload', async ({ browser, baseURL }, testInfo) => {
  test.setTimeout(360_000)
  if (!baseURL) throw new Error('An explicit isolated preview URL is required.')
  if (process.env.BAANDER_LIVE_CAPACITY_MANIFEST) {
    throw new Error('The video fixture requires the preview managed executor; the capacity CI manifest cannot launch this producer.')
  }
  const fixture = FixtureClient.start('tests/Fixtures/Playback/video-fixture.php')
  const diagnostics = new VideoDiagnostics()
  const contexts: Awaited<ReturnType<typeof openBrowser>>[] = []
  const run = `video-${Date.now()}-${testInfo.retry}`
  let playbackFailed = false
  let playbackError: unknown
  const cleanupErrors: unknown[] = []
  const transcodeSamples: unknown[] = []
  let startupInspection: Promise<void> | null = null
  try {
    const manifest = (await fixture.request<VideoManifest>({ op: 'create', run, delivery: 'encoded' })).receipt
    const source = await openBrowser(browser, manifest.users[0]!, baseURL, 'Video source')
    contexts.push(source)
    diagnostics.observe(source.page, 'source')
    const receiver = await openBrowser(browser, manifest.users[0]!, baseURL, 'Video receiver')
    contexts.push(receiver)
    diagnostics.observe(receiver.page, 'receiver')
    await diagnostics.instrument(receiver.page)
    await expect.poll(async () => (await snapshot(source.page)).destinations.filter(item => item.available).length, { timeout: 30_000 }).toBe(2)

    const sourceDelivery = observeVideoDelivery(source.page, manifest.video.publicId)
    const receiverDelivery = observeVideoDelivery(receiver.page, manifest.video.publicId)
    const sourcePosition = async () => {
      const nativeTime = await source.page.locator('video').evaluateAll(videos => {
        const video = videos[0]
        return video instanceof HTMLVideoElement ? video.currentTime : Number.NaN
      })
      return sourceDelivery.startTime === null ? Number.NaN : sourceDelivery.startTime + nativeTime
    }
    const controls: string[] = []
    source.page.on('request', request => {
      if (request.method() === 'POST' && new URL(request.url()).pathname === '/api/session/control') {
        const body = request.postDataJSON() as { action?: string }
        if (body.action) controls.push(body.action)
      }
    })
    await source.page.goto(`/movies/${manifest.movie.publicId}`)
    await diagnostics.instrument(source.page)
    await source.page.getByRole('heading', { name: `Owned playback movie ${run}`, exact: true }).locator('..').getByRole('button', { name: 'Play', exact: true }).click()
    await expect.poll(() => sourceDelivery.initialKind, { timeout: 30_000 }).toBe('encoded')
    startupInspection = new Promise<void>(resolve => { setTimeout(resolve, 5000) })
      .then(async () => { transcodeSamples.push((await fixture.request({ op: 'inspect', run })).receipt) })
      .catch(error => { transcodeSamples.push({ inspectionError: error instanceof Error ? error.message : String(error) }) })
    await expect.poll(() => source.page.locator('video').evaluateAll(videos => videos.some(video => video instanceof HTMLVideoElement && !video.paused && video.readyState >= 2)), { timeout: 60_000 }).toBe(true)
    const routingGuards = await source.page.locator('video').evaluate(async element => {
      if (!(element instanceof HTMLVideoElement)) throw new Error('The source has no native video.')
      const state = {
        disableRemotePlayback: element.disableRemotePlayback,
        disablePictureInPicture: element.disablePictureInPicture,
        airplay: element.getAttribute('x-webkit-airplay'),
        nativeControls: element.controls,
        remoteState: element.remote?.state,
        prompt: 'not-attempted',
      }
      if (state.disableRemotePlayback && element.remote) {
        try { await element.remote.prompt(); state.prompt = 'unexpectedly-allowed' }
        catch (error) { state.prompt = error instanceof Error ? error.name : 'rejected' }
      }
      return state
    })
    await testInfo.attach('video-native-routing-guards', {
      body: JSON.stringify({ browser: browser.version(), guards: routingGuards }), contentType: 'application/json',
    })
    expect(routingGuards).toEqual({
      disableRemotePlayback: true, disablePictureInPicture: true, airplay: 'deny', nativeControls: false,
      remoteState: 'disconnected', prompt: 'InvalidStateError',
    })
    await movieControls(source.page).getByRole('button', { name: 'Pause', exact: true }).click()
    await seek(source.page, 12)
    await waitForDestination(source.page, 'Video source', 12)
    await expect(source.page.getByRole('slider', { name: 'Seek movie' })).toHaveAttribute('aria-valuenow', '12')

    await movieControls(source.page).getByRole('button', { name: 'Playback destination', exact: true }).click()
    await source.page.getByRole('menuitem', { name: 'Video receiver', exact: true }).click()
    await waitForDestination(source.page, 'Video receiver', 12)
    await expect.poll(() => receiver.page.locator('video').evaluateAll(videos => videos.length === 1 && videos[0] instanceof HTMLVideoElement && videos[0].paused && videos[0].readyState >= 2), { timeout: 45_000 }).toBe(true)
    const receiverVideo = await receiver.page.locator('video').elementHandle()
    if (!receiverVideo) throw new Error('The receiver did not retain a native video element.')
    await expect.poll(async () => receiverDelivery.startTime === null ? null
      : Math.round((receiverDelivery.startTime + await receiverVideo.evaluate(video => video instanceof HTMLVideoElement ? video.currentTime : Number.NaN)) * 10) / 10,
    { timeout: 30_000 }).toBe(12)
    const loads = controls.filter(action => action === 'load').length
    expect(loads).toBe(1)

    await source.page.getByRole('button', { name: 'Back', exact: true }).click()
    await expect(source.page.getByRole('slider', { name: 'Seek movie' })).toBeHidden()
    await source.page.getByRole('heading', { name: `Owned playback movie ${run}`, exact: true }).locator('..').getByRole('button', { name: 'Play', exact: true }).click()
    await expect(source.page.getByRole('slider', { name: 'Seek movie' })).toHaveAttribute('aria-valuenow', '12')
    expect(controls.filter(action => action === 'load')).toHaveLength(loads)
    expect(await receiver.page.evaluate(element => element === document.querySelector('video'), receiverVideo)).toBe(true)
    expect(await receiverVideo.evaluate(video => video instanceof HTMLVideoElement && video.paused)).toBe(true)
    await expect.poll(() => receiverVideo.evaluate(video => video instanceof HTMLVideoElement ? video.readyState : 0), { timeout: 30_000 }).toBeGreaterThanOrEqual(2)
    expect(await receiver.page.evaluate(element => element === document.querySelector('video'), receiverVideo)).toBe(true)
    expect(await receiverVideo.evaluate(video => video instanceof HTMLVideoElement && video.paused)).toBe(true)
    await waitForDestination(source.page, 'Video receiver', 12)
    expect(await source.page.locator('video').evaluateAll(videos => videos.every(video => video instanceof HTMLVideoElement && video.paused))).toBe(true)

    await movieControls(source.page).getByRole('button', { name: 'Playback destination', exact: true }).click()
    await source.page.getByRole('menuitem', { name: 'Return playback to this browser', exact: true }).click()
    await waitForDestination(source.page, 'Video source', 12)
    await expect(source.page.getByRole('slider', { name: 'Seek movie' })).toHaveAttribute('aria-valuenow', '12')
    expect(await receiverVideo.evaluate(video => video instanceof HTMLVideoElement && video.paused)).toBe(true)
    await movieControls(source.page).getByRole('button', { name: 'Play', exact: true }).click()
    await expect.poll(() => source.page.locator('video').evaluateAll(videos => videos.some(video => video instanceof HTMLVideoElement && !video.paused)), { timeout: 30_000 }).toBe(true)
    expect(await receiver.page.locator('video').evaluateAll(videos => videos.every(video => video instanceof HTMLVideoElement && video.paused))).toBe(true)
    await expect.poll(sourcePosition).toBeGreaterThan(13)
    const beforeReload = await snapshot(source.page)
    const nativePositionBeforeReload = await sourcePosition()
    expect(beforeReload.activeIncarnationId).toBeTruthy()
    const recoveredResponse = source.page.waitForResponse(response => {
      if (new URL(response.url()).pathname !== '/api/session/recover-local') return false
      const request = response.request().postDataJSON() as { incarnationId?: string } | null
      return request?.incarnationId === beforeReload.activeIncarnationId
    })
    await source.page.reload()
    const recoveryResponse = await recoveredResponse
    expect(recoveryResponse.status()).toBe(200)
    const recoveryRequest = recoveryResponse.request().postDataJSON() as { checkpoint: { position: number } }
    const recoveryBody = await recoveryResponse.json() as { data: PlaybackSnapshotResource }
    expect(recoveryBody.data.recoveryResult?.outcome).toBe('recovered')
    await expect.poll(async () => (await snapshot(source.page)).activeIncarnationId).toBeNull()
    const recovered = await snapshot(source.page)
    expect(recovered.media).toEqual(beforeReload.media)
    expect(recovered.desiredState).toBe('paused')
    expect(recovered.position).toBe(recoveryRequest.checkpoint.position)
    expect(recovered.position).toBeGreaterThanOrEqual(12)
    expect(recovered.position).toBeLessThanOrEqual(nativePositionBeforeReload + 0.5)
    expect(await source.page.locator('video').evaluateAll(videos => videos.every(video => video instanceof HTMLVideoElement && video.paused))).toBe(true)
    await diagnostics.instrument(source.page)
    await source.page.getByRole('heading', { name: `Owned playback movie ${run}`, exact: true }).locator('..').getByRole('button', { name: 'Play', exact: true }).click()
    await expect.poll(async () => Number(await source.page.getByRole('slider', { name: 'Seek movie' }).getAttribute('aria-valuenow')))
      .toBeCloseTo(recovered.position, 1)
    await movieControls(source.page).getByRole('button', { name: 'Play', exact: true }).click()
    await expect.poll(() => source.page.locator('video').evaluateAll(videos => videos.some(video => video instanceof HTMLVideoElement && !video.paused && video.readyState >= 2)), { timeout: 30_000 }).toBe(true)
    await expect.poll(async () => {
      const state = await snapshot(source.page)
      return { active: state.activeIncarnationId !== null, transfer: state.transfer }
    }).toEqual({ active: true, transfer: null })
    const resumed = await snapshot(source.page)
    expect(resumed.activeIncarnationId).toBeTruthy()
    expect(resumed.activeIncarnationId).not.toBe(beforeReload.activeIncarnationId)
    expect(Math.abs(await sourcePosition() - recovered.position)).toBeLessThan(2)
    await expect.poll(sourcePosition).toBeGreaterThan(recovered.position + 0.2)
    expect(await receiver.page.locator('video').evaluateAll(videos => videos.every(video => video instanceof HTMLVideoElement && video.paused))).toBe(true)
    await testInfo.attach('video-reload-recovery', {
      body: JSON.stringify({ beforeReload: { incarnationId: beforeReload.activeIncarnationId, nativePosition: nativePositionBeforeReload },
        recovered: { position: recovered.position, media: recovered.media, desiredState: recovered.desiredState, activeIncarnationId: recovered.activeIncarnationId },
        resumed: { incarnationId: resumed.activeIncarnationId, nativePosition: await sourcePosition() } }), contentType: 'application/json',
    })
    await movieControls(source.page).getByRole('button', { name: 'Stop', exact: true }).click()
    await expect.poll(async () => (await snapshot(source.page)).observedState).toBe('stopped')
    await testInfo.attach('video-transfer-state', {
      body: JSON.stringify({ run, snapshot: await snapshot(source.page), controlActions: controls }), contentType: 'application/json',
    })
  } catch (error) {
    playbackFailed = true
    playbackError = error
  } finally {
    await startupInspection
    try { transcodeSamples.push((await fixture.request({ op: 'inspect', run })).receipt) }
    catch (error) { transcodeSamples.push({ inspectionError: error instanceof Error ? error.message : String(error) }) }
    await testInfo.attach('video-transcode-inspection', {
      body: JSON.stringify({ run, samples: transcodeSamples }), contentType: 'application/json',
    }).catch(error => { cleanupErrors.push(error) })
    await diagnostics.attach(testInfo, run).catch(error => { cleanupErrors.push(error) })
    const closed = await Promise.allSettled(contexts.map(context => context.context.close()))
    for (const result of closed) {
      if (result.status === 'rejected') cleanupErrors.push(result.reason)
    }
    try {
      const receipt = (await fixture.request({ op: 'cleanup', run })).receipt
      await testInfo.attach('video-fixture-cleanup', { body: JSON.stringify(receipt), contentType: 'application/json' })
      expect(receipt.complete, `Owned video fixture ${run} must be cleaned; recover it through its saved manifest if this fails.`).toBe(true)
    } catch (error) {
      cleanupErrors.push(error)
    }
    try {
      await fixture.close()
    } catch (error) {
      cleanupErrors.push(error)
    }
    if (cleanupErrors.length > 0) {
      await testInfo.attach('video-fixture-cleanup-errors', {
        body: JSON.stringify({
          run,
          manifest: `var/playback-video/${run}/manifest.json`,
          errors: cleanupErrors.map(error => error instanceof Error ? error.stack ?? error.message : String(error)),
        }),
        contentType: 'application/json',
      }).catch(error => { cleanupErrors.push(error) })
    }
  }
  if (playbackFailed) throw playbackError
  if (cleanupErrors.length > 0) throw new AggregateError(cleanupErrors, `Video fixture ${run} cleanup failed.`)
})
