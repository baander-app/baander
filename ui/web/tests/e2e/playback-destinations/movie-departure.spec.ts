import { expect, test, type Locator, type Page } from '@playwright/test'
import {
  FixtureClient,
  login,
  redact,
  type FixtureManifest,
} from '../live-updates/capacity-fixtures'
import { loadedRuntimeModule } from './loaded-runtime-module'
import { VideoDiagnostics } from './video-diagnostics'

interface MovieSource {
  run: string
  movie: { id: string; publicId: string }
  video: { id: string; publicId: string; path: string }
}

interface DepartureManifest extends FixtureManifest, MovieSource {
  successor: MovieSource
  successorRun: string
}

interface JobInspection {
  sampledAt: string
  jobs: {
    id: string
    status: string
    outcome: string | null
    desired_status: string | null
    desired_position: string | null
    owner_live: boolean
    session_public_id: string | null
  }[]
}

async function decodedFrames(player: Locator) {
  return player.locator('video').evaluateAll(elements => {
    const video = elements[0]
    if (!(video instanceof HTMLVideoElement) || video.paused || video.readyState < 2) return 0
    return video.getVideoPlaybackQuality().totalVideoFrames
  })
}

async function expectAdvancingFrames(player: Locator) {
  await expect.poll(() => decodedFrames(player), { timeout: 60_000 }).toBeGreaterThan(0)
  const initial = await decodedFrames(player)
  await expect.poll(() => decodedFrames(player), { timeout: 15_000 }).toBeGreaterThan(initial + 12)
}

async function openMovie(page: Page, manifest: MovieSource, waitForPlayer = true) {
  const title = `Owned playback movie ${manifest.run}`
  await page.getByRole('button', { name: title, exact: true }).click()
  await expect(page).toHaveURL(new RegExp(`/movies/${manifest.movie.publicId}$`))
  await launchButton(page, title).click()
  const player = page.getByRole('dialog', { name: title, exact: true })
  if (waitForPlayer) await expect(player).toBeVisible({ timeout: 30_000 })
  return player
}

function launchButton(page: Page, title: string) {
  return page.getByRole('heading', { name: title, exact: true })
    .locator('..').getByRole('button', { name: 'Play', exact: true })
}

function safeBrowserText(message: string, length: number) {
  return message.replace(/https?:\/\/\S+/g, '[url]').slice(0, length)
}

async function playbackState(page: Page) {
  const modulePath = await loadedRuntimeModule(page, '/src/features/catalog/stores/video-presentation-store.ts')
  if (!modulePath) throw new Error('The playback presentation module has not loaded.')
  return page.evaluate(async path => {
    const { useVideoPresentation } = await import(/* @vite-ignore */ path) as {
      useVideoPresentation: { getState(): { videoId: string; state: string; error: string | null } }
    }
    const presentation = useVideoPresentation.getState()
    return {
      videoId: presentation.videoId,
      state: presentation.state,
      error: presentation.error,
      mounted: document.querySelector('[role="dialog"]') !== null,
      retryButtons: Array.from(document.querySelectorAll('button'))
        .map(button => button.textContent?.trim()).filter(label => /retry/i.test(label ?? '')),
      native: Array.from(document.querySelectorAll('video')).map(video => ({
        currentTime: video.currentTime,
        paused: video.paused,
        readyState: video.readyState,
        frames: video.getVideoPlaybackQuality().totalVideoFrames,
        errorCode: video.error?.code ?? null,
      })),
    }
  }, modulePath)
}

test('Back releases the encoded movie before the next movie starts', async ({ browser, baseURL }, testInfo) => {
  test.setTimeout(300_000)
  if (!baseURL) throw new Error('An explicit isolated preview URL is required.')
  const baseline = process.env.BAANDER_MOVIE_DEPARTURE_BASELINE === '1'
  const fixture = FixtureClient.start('tests/Fixtures/Playback/video-fixture.php')
  const run = `departure-${Date.now()}`
  const context = await browser.newContext({ baseURL, ignoreHTTPSErrors: true })
  await context.addInitScript(() => performance.setResourceTimingBufferSize(4096))
  const page = await context.newPage()
  const diagnostics = new VideoDiagnostics()
  diagnostics.observe(page, 'departure')
  const evidence: unknown[] = []
  const failures: unknown[] = []
  const cancellations: { sessionId: string; status: number }[] = []
  const browserEvents: unknown[] = []
  const recordBrowserEvent = (event: object) => {
    browserEvents.push({ at: Date.now(), ...event })
    if (browserEvents.length > 100) browserEvents.shift()
  }
  page.on('pageerror', error => recordBrowserEvent({
    kind: 'pageerror',
    name: error.name,
    message: safeBrowserText(error.message, 300),
    stack: safeBrowserText(error.stack ?? '', 1200),
  }))
  page.on('console', message => {
    if (message.type() === 'error') {
      recordBrowserEvent({ kind: 'console-error', text: safeBrowserText(message.text(), 300) })
    }
  })
  page.on('crash', () => recordBrowserEvent({ kind: 'crash' }))
  page.on('close', () => recordBrowserEvent({ kind: 'close' }))
  page.on('response', response => {
    const path = new URL(response.url()).pathname
    if (response.request().method() === 'DELETE' && path.startsWith('/api/transcode/sessions/')) {
      cancellations.push({ sessionId: path.split('/').at(-1)!, status: response.status() })
    }
  })
  const inspect = async (movieRun: string, phase: string) => {
    const state = (await fixture.request<JobInspection>({ op: 'inspect', run: movieRun })).receipt
    evidence.push({ phase, movieRun, ...state })
    return state
  }
  try {
    const manifest = (await fixture.request<DepartureManifest>({ op: 'create', run, departure: true })).receipt
    await login(page, manifest.users[0]!)
    await page.goto('/movies/browse')
    const first = await openMovie(page, manifest)
    await expectAdvancingFrames(first)
    await expect.poll(async () => (await inspect(run, 'first-playing')).jobs.some(job => job.owner_live), {
      timeout: 15_000,
    }).toBe(true)
    const firstJobs = await inspect(run, 'before-back')
    const sessionIds = [...new Set(firstJobs.jobs.flatMap(job => job.session_public_id ? [job.session_public_id] : []))]
    expect(sessionIds.length).toBeGreaterThan(0)
    await first.getByRole('button', { name: 'Back', exact: true }).click()
    await expect(first).toBeHidden()
    evidence.push({ phase: 'after-back', presentation: await playbackState(page) })

    if (!baseline) {
      await expect.poll(() => sessionIds.some(id => cancellations.some(cancel =>
        cancel.sessionId === id && cancel.status >= 200 && cancel.status < 300,
      )), { timeout: 10_000 }).toBe(true)
    }
    await page.goBack()
    const second = await openMovie(page, manifest.successor, !baseline)
    const launchSecond = launchButton(page, `Owned playback movie ${manifest.successor.run}`)
    if (baseline) {
      await expect.poll(async () => (await playbackState(page)).error, { timeout: 30_000 }).toBeTruthy()
      const startup = await playbackState(page)
      evidence.push({ phase: 'second-startup-failed', presentation: startup })
      expect((await inspect(run, 'first-retained-after-second-failure')).jobs.some(job => job.owner_live)).toBe(true)
      await inspect(manifest.successorRun, 'second-startup-failed')
      const clientModule = await loadedRuntimeModule(page, '/src/shared/api-client/axios-instance.ts')
      if (!clientModule) throw new Error('The authenticated application client has not loaded.')
      await page.evaluate(async ({ path, ids }) => {
        const { AXIOS_INSTANCE } = await import(/* @vite-ignore */ path) as {
          AXIOS_INSTANCE: { delete(url: string): Promise<unknown> }
        }
        for (const id of ids) await AXIOS_INSTANCE.delete(`/api/transcode/sessions/${encodeURIComponent(id)}`)
      }, { path: clientModule, ids: sessionIds })
      await expect.poll(async () => (await inspect(run, 'authenticated-recovery-release')).jobs.every(job => !job.owner_live), {
        timeout: 20_000,
      }).toBe(true)
      if (!await second.isVisible()) {
        await launchSecond.click()
        await expect(second).toBeVisible()
      }
      evidence.push({ phase: 'recovery-reopened', presentation: await playbackState(page) })
      await second.getByRole('button', { name: 'Back', exact: true }).click()
      await expect(second).toBeHidden()
      await launchSecond.click()
      await expect(second).toBeVisible()
      await second.getByRole('button', { name: 'Play', exact: true }).click()
    }
    await expectAdvancingFrames(second)
    await expect.poll(async () => (await inspect(run, 'after-next-movie-release')).jobs.every(job => !job.owner_live), {
      timeout: 20_000,
    }).toBe(true)
    evidence.push({ phase: 'second-playing', presentation: await playbackState(page) })
    expect((await inspect(manifest.successorRun, 'second-playing')).jobs.length).toBeGreaterThan(0)
    if (!baseline) {
      await second.getByRole('button', { name: 'Back', exact: true }).click()
      await expect(second).toBeHidden()
    }
  } catch (error) {
    failures.push(error)
    if (!page.isClosed()) {
      evidence.push({ phase: 'failure', presentation: await playbackState(page).catch(() => null) })
      await inspect(run, 'failure').catch(error => failures.push(error))
      await inspect(`${run}-next`, 'failure').catch(error => failures.push(error))
    }
  } finally {
    await diagnostics.attach(testInfo, run).catch(error => failures.push(error))
    await context.close().catch(error => failures.push(error))
    try {
      const cleanup = (await fixture.request({ op: 'cleanup', run })).receipt
      evidence.push({ phase: 'cleanup', ...cleanup })
      expect(cleanup.complete).toBe(true)
    } catch (error) {
      failures.push(error)
    }
    await fixture.close().catch(error => failures.push(error))
    await testInfo.attach('movie-departure-evidence', {
      body: JSON.stringify(redact({ run, baseline, evidence, cancellations, browserEvents, fixtures: fixture.receipts }), null, 2),
      contentType: 'application/json',
    })
  }
  if (failures.length > 0) throw new AggregateError(failures, 'Movie departure or fixture cleanup failed.')
})
