import { expect, test, type Page } from '@playwright/test'
import type { PlaybackSnapshotResource } from '@/shared/api-client/gen/endpoints'
import { authenticatedGet, FixtureClient, login, type FixtureManifest } from '../live-updates/capacity-fixtures'

async function snapshot(page: Page) {
  return (await authenticatedGet<{ data: PlaybackSnapshotResource }>(page, '/api/session/snapshot')).data
}

async function nativeAudio(page: Page) {
  return page.evaluate(async () => {
    const path = '/src/features/player/stores/player-store.ts'
    const { usePlayerStore } = await import(/* @vite-ignore */ path)
    const state = usePlayerStore.getState()
    const audio = state.audioElement as HTMLAudioElement | null
    return {
      paused: audio?.paused ?? true,
      time: audio?.currentTime ?? 0,
      readyState: audio?.readyState ?? 0,
      src: audio?.currentSrc ?? '',
      queue: state.queue.map((track: { publicId: string }) => track.publicId),
    }
  })
}

test('music transfers between real browsers and remains remotely controllable', async ({ browser, baseURL }, testInfo) => {
  test.setTimeout(240_000)
  const fixture = FixtureClient.start()
  const contexts = []
  const exchanges: object[] = []
  try {
    const manifest = (await fixture.request<FixtureManifest>({ op: 'create' })).receipt
    const pages: Page[] = []
    for (const name of ['Music source', 'Music target']) {
      const context = await browser.newContext({ baseURL, ignoreHTTPSErrors: true, viewport: { width: 1280, height: 800 } })
      contexts.push(context)
      await context.addInitScript((deviceName: string) => {
        localStorage.setItem('baander-device-name', deviceName)
      }, name)
      const page = await context.newPage()
      page.on('request', request => {
        if (request.method() !== 'POST' || !new URL(request.url()).pathname.startsWith('/api/session/')) return
        const body = request.postDataJSON()
        exchanges.push({ browser: name, at: Date.now(), path: new URL(request.url()).pathname,
          action: body.action, revision: body.expectedRevision, outcome: body.outcome, position: body.position })
      })
      page.on('response', async response => {
        if (!new URL(response.url()).pathname.startsWith('/api/session/')) return
        try {
          const body = await response.json()
          exchanges.push({ browser: name, at: Date.now(), status: response.status(), path: new URL(response.url()).pathname,
            revision: body.data?.revision, observed: body.data?.observedState, position: body.data?.position,
            transfer: body.data?.transfer?.state, result: body.data?.requestResult?.outcome,
            error: body.message ?? body.detail ?? body.error })
        } catch { /* Context teardown can interrupt a response body. */ }
      })
      await login(page, manifest.users[0])
      pages.push(page)
    }
    const [source, target] = pages
    const sourceFooter = source.getByRole('region', { name: 'Playback', exact: true })
    await expect.poll(async () => (await snapshot(source)).destinations.filter(destination => destination.available).length).toBe(2)
    await source.goto('/music/songs')
    await source.getByText('Live song 00000', { exact: true }).dblclick()
    await expect.poll(async () => (await nativeAudio(source)).paused, { timeout: 25_000 }).toBe(false)
    await expect.poll(async () => (await nativeAudio(source)).time).toBeGreaterThan(1)
    expect((await nativeAudio(target)).paused).toBe(true)
    const sourceId = (await snapshot(source)).activeIncarnationId

    await sourceFooter.getByRole('button', { name: 'Pause', exact: true }).click()
    await expect.poll(async () => (await nativeAudio(source)).paused).toBe(true)
    const seek = source.locator('[data-slot="slider"]').filter({ has: source.getByRole('slider', { name: 'Seek', exact: true }) })
    const bounds = await seek.boundingBox()
    if (!bounds) throw new Error('The music seek control is not visible.')
    await seek.click({ position: { x: bounds.width / 3, y: bounds.height / 2 } })
    await expect.poll(async () => (await nativeAudio(source)).time).toBeGreaterThan(18)
    await sourceFooter.getByRole('button', { name: 'Play', exact: true }).click()
    await expect.poll(async () => (await nativeAudio(source)).paused).toBe(false)

    // Native events across contexts share epoch timestamps; no playback methods are mocked.
    await source.evaluate(async () => {
      const path = '/src/features/player/stores/player-store.ts'
      const { usePlayerStore } = await import(/* @vite-ignore */ path)
      usePlayerStore.getState().audioElement.addEventListener('pause', () => {
        document.documentElement.dataset.sourcePausedAt = String(Date.now())
      }, { once: true })
    })
    await target.evaluate(async () => {
      const path = '/src/features/player/stores/player-store.ts'
      const { usePlayerStore } = await import(/* @vite-ignore */ path)
      const watched = new WeakSet<HTMLAudioElement>()
      const watch = ({ audioElement }: { audioElement: HTMLAudioElement | null }) => {
        if (!audioElement || watched.has(audioElement)) return
        watched.add(audioElement)
        audioElement.addEventListener('play', () => {
          document.documentElement.dataset.targetPlayedAt = String(Date.now())
        }, { once: true })
      }
      watch(usePlayerStore.getState())
      usePlayerStore.subscribe(watch)
    })
    const before = (await nativeAudio(source)).time
    await source.getByRole('button', { name: 'Playback destination', exact: true }).click()
    await source.getByRole('menuitem', { name: 'Music target', exact: true }).click()
    await expect.poll(async () => (await nativeAudio(target)).paused, { timeout: 25_000 }).toBe(false)
    await expect.poll(async () => (await snapshot(source)).transfer).toBeNull()
    const after = await nativeAudio(target)
    expect(after.time).toBeGreaterThanOrEqual(before - 0.25)
    expect(after.time).toBeLessThan(before + 12)
    expect(after.queue).toEqual([manifest.song.publicId])
    expect((await nativeAudio(source)).paused).toBe(true)
    expect((await snapshot(source)).activeIncarnationId).not.toBe(sourceId)
    const pausedAt = await source.evaluate(() => Number(document.documentElement.dataset.sourcePausedAt))
    const playedAt = await target.evaluate(() => Number(document.documentElement.dataset.targetPlayedAt))
    expect(pausedAt).toBeGreaterThan(0)
    expect(playedAt).toBeGreaterThanOrEqual(pausedAt)

    await sourceFooter.getByRole('button', { name: 'Pause', exact: true }).click()
    await expect.poll(async () => (await nativeAudio(target)).paused).toBe(true)
    await source.getByRole('button', { name: 'Playback destination', exact: true }).click()
    await source.getByRole('menuitem', { name: 'Return playback to this browser', exact: true }).click()
    await expect.poll(async () => (await snapshot(source)).activeIncarnationId).toBe(sourceId)
    expect((await nativeAudio(source)).paused).toBe(true)
    expect((await nativeAudio(target)).paused).toBe(true)
    await expect(source.getByRole('main').getByText('Live song 00000', { exact: true })).toBeVisible()
    await source.screenshot({ path: testInfo.outputPath('music-returned-paused.png'), animations: 'disabled' })
    await source.setViewportSize({ width: 390, height: 844 })
    await sourceFooter.getByRole('button', { name: 'Playback destination', exact: true }).click()
    const menu = source.getByRole('menu')
    await expect(menu).toBeVisible()
    const menuBounds = await menu.boundingBox()
    if (!menuBounds) throw new Error('The narrow playback destination menu is not visible.')
    expect(menuBounds.x).toBeGreaterThanOrEqual(0)
    expect(menuBounds.x + menuBounds.width).toBeLessThanOrEqual(390)
    expect(await source.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390)
    await source.screenshot({ path: testInfo.outputPath('music-destination-narrow.png'), animations: 'disabled' })
  } finally {
    await testInfo.attach('playback-exchanges', { body: JSON.stringify(exchanges, null, 2), contentType: 'application/json' })
    await Promise.all(contexts.map(context => context.close()))
    try {
      if (fixture.run) expect((await fixture.request({ op: 'cleanup' })).receipt.complete).toBe(true)
    } finally {
      await fixture.close()
    }
  }
})
