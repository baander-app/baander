import { expect, test, type Page } from '@playwright/test'
import type { PlaybackExecutionAdapter } from '../../../src/features/session/services/playback-execution'
import { FixtureClient, login, type FixtureManifest } from '../live-updates/capacity-fixtures'
import { loadedRuntimeModule } from './loaded-runtime-module'

interface TimingEvent {
  at: number
  sample: string
  kind: string
  name: string
  [key: string]: unknown
}

async function instrument(page: Page) {
  const store = await loadedRuntimeModule(page, '/src/features/player/stores/player-store.ts')
  const execution = await loadedRuntimeModule(page, '/src/features/session/services/playback-execution.ts')
  const clock = await loadedRuntimeModule(page, '/src/features/player/stores/player-time-tracker.ts')
  if (!store || !execution || !clock) throw new Error('The application music runtime has not loaded.')
  await page.evaluate(async ({ store, execution, clock }) => {
    const { usePlayerStore } = await import(/* @vite-ignore */ store)
    const { getCurrentTime } = await import(/* @vite-ignore */ clock)
    const { getPlaybackAdapter } = await import(/* @vite-ignore */ execution) as {
      getPlaybackAdapter(kind: 'song'): PlaybackExecutionAdapter | undefined
    }
    const adapter = getPlaybackAdapter('song')
    if (!adapter) throw new Error('The application music adapter has not registered.')
    const events: TimingEvent[] = []
    const ids = new WeakMap<HTMLMediaElement, number>()
    let sequence = 0
    const measured = {
      sample: 'warmup', events,
      state: () => {
        const state = usePlayerStore.getState()
        const row = document.querySelector('[aria-label="Seek"]')?.closest('[data-slot="slider"]')?.parentElement
        return { title: state.currentTrack?.title, publicId: state.currentTrack?.publicId,
          queueLength: state.queue.length, paused: state.audioElement?.paused ?? true,
          position: state.audioElement?.currentTime ?? 0,
          nativeDuration: Number.isFinite(state.audioElement?.duration) ? state.audioElement.duration : null,
          catalogueDuration: state.currentTrack?.duration, storeDuration: state.duration, clockPosition: getCurrentTime(),
          elapsedLabel: row?.firstElementChild?.textContent, durationLabel: row?.lastElementChild?.textContent,
          crossfadeEnabled: state.crossfadeEnabled, crossfadeDuration: state.crossfadeDuration }
      },
    }
    ;(window as typeof window & { __songTiming?: typeof measured }).__songTiming = measured
    const record = (kind: string, name: string, detail: object = {}) => {
      events.push({ at: performance.timeOrigin + performance.now(), sample: measured.sample, kind, name, ...detail })
    }
    const attach = (audio: HTMLMediaElement) => {
      if (!(audio instanceof HTMLAudioElement) || ids.has(audio)) return
      ids.set(audio, ++sequence)
      for (const name of ['loadstart', 'loadedmetadata', 'loadeddata', 'canplay', 'playing', 'pause', 'waiting', 'ended', 'error']) {
        audio.addEventListener(name, () => record('native', name, { element: ids.get(audio), paused: audio.paused,
          readyState: audio.readyState, position: audio.currentTime }))
      }
    }
    for (const method of ['play', 'pause', 'load'] as const) {
      const original = HTMLMediaElement.prototype[method]
      Object.defineProperty(HTMLMediaElement.prototype, method, { configurable: true, value: function (this: HTMLMediaElement) {
        attach(this)
        if (this instanceof HTMLAudioElement) record('native-call', method, { element: ids.get(this), paused: this.paused })
        return original.call(this)
      } })
    }
    const current = usePlayerStore.getState().audioElement
    if (current) attach(current)
    document.addEventListener('dblclick', () => record('input', 'dblclick'), true)
    document.addEventListener('click', event => {
      if ((event.target as Element).closest('button')?.getAttribute('aria-label') === 'Next') record('input', 'next')
    }, true)
    const prepare = adapter.prepare
    adapter.prepare = async function (payload, signal) {
      record('adapter', 'prepare-start', { queueLength: payload.queue.length })
      try { return await prepare.call(this, payload, signal) }
      finally { record('adapter', 'prepare-end') }
    }
    const execute = adapter.execute
    adapter.execute = async function (command, signal) {
      record('adapter', 'execute-start', { command: command.id, type: command.type, revision: command.revision, queueLength: command.payload.queue.length })
      try { return await execute.call(this, command, signal) }
      finally { record('adapter', 'execute-end', { command: command.id, type: command.type }) }
    }
  }, { store, execution, clock })
}

test('measure changing songs through selection and Next while music is playing', async ({ browser, baseURL }, testInfo) => {
  test.setTimeout(240_000)
  const fixture = FixtureClient.start()
  const context = await browser.newContext({ baseURL, ignoreHTTPSErrors: true })
  const events: TimingEvent[] = []
  const samples: object[] = []
  const footerSamples: object[] = []
  let sample = 'warmup'
  let measuredPage: Page | undefined
  try {
    const manifest = (await fixture.request<FixtureManifest>({ op: 'create' })).receipt
    await fixture.request({ op: 'catalog', albums: 1, songs: 77, durations: [60, 45, 90] })
    const page = await context.newPage()
    measuredPage = page
    const relevant = (path: string) => path.startsWith('/api/session/') || path.includes('/songs') || path.startsWith('/api/stream')
    page.on('request', request => {
      const path = new URL(request.url()).pathname
      if (!relevant(path)) return
      const body = request.method() === 'POST' ? request.postDataJSON() as { action?: string; expectedRevision?: number; queue?: string[] } : null
      events.push({ at: Date.now(), sample, kind: 'http-start', name: path, action: body?.action,
        revision: body?.expectedRevision, queueLength: body?.queue?.length })
    })
    page.on('response', async response => {
      const path = new URL(response.url()).pathname
      if (!relevant(path)) return
      const capturedSample = sample
      const at = Date.now()
      const timing = response.request().timing()
      let outcome: string | null = null
      if (path === '/api/session/control') {
        try {
          const state = (await response.json()).data
          outcome = state?.requestResult?.outcome ?? null
          for (const command of state?.commands ?? []) events.push({ at, sample: capturedSample,
            kind: 'http-command', name: command.type, command: command.id, revision: command.revision,
            queueLength: command.payload?.queue?.length })
        } catch { /* Teardown can close a response. */ }
      }
      events.push({ at, sample: capturedSample, kind: 'http-response', name: path, status: response.status(), outcome,
        requestStart: timing.requestStart, responseStart: timing.responseStart, responseEnd: timing.responseEnd })
    })
    page.on('websocket', socket => socket.on('framereceived', frame => {
      if (typeof frame.payload !== 'string') return
      try {
        const message = JSON.parse(frame.payload)
        if (message.type !== 'session.snapshot') return
        for (const command of message.data?.commands ?? []) events.push({ at: Date.now(), sample, kind: 'ws-command', name: command.type,
          command: command.id, revision: command.revision, queueLength: command.payload?.queue?.length })
      } catch { /* Non-JSON frames are not playback observations. */ }
    }))
    await login(page, manifest.users[0])
    await page.goto('/music/songs')
    await instrument(page)
    const state = () => page.evaluate(() => (window as typeof window & {
      __songTiming: { state(): { title: string; publicId: string; queueLength: number; paused: boolean; position: number;
        nativeDuration: number | null; catalogueDuration: number; storeDuration: number; clockPosition: number;
        elapsedLabel: string; durationLabel: string; crossfadeEnabled: boolean; crossfadeDuration: number } }
    }).__songTiming.state())
    const assertFooterTime = async (stage: string) => {
      const current = await state()
      footerSamples.push({ stage, ...current })
      console.log(`SONG_FOOTER_TIME ${JSON.stringify({ stage, ...current })}`)
      expect(current.nativeDuration).not.toBeNull()
      expect(current.nativeDuration!).toBeGreaterThan(0)
      expect(current.catalogueDuration).toBeCloseTo(current.nativeDuration!, 0)
      expect(current.storeDuration).toBeCloseTo(current.nativeDuration!, 1)
      const minutes = Math.floor(current.nativeDuration! / 60)
      const seconds = Math.floor(current.nativeDuration! % 60).toString().padStart(2, '0')
      expect(current.durationLabel).toBe(`${minutes}:${seconds}`)
      expect(Math.abs(current.clockPosition - current.position)).toBeLessThan(1)
      const elapsed = current.elapsedLabel.split(':').map(Number)
      expect(elapsed[0] * 60 + elapsed[1]).toBe(Math.floor(current.clockPosition))
    }
    await page.getByText('Live song 00000', { exact: true }).dblclick()
    await expect.poll(async () => (await state()).position, { timeout: 30_000 }).toBeGreaterThan(0.2)
    await assertFooterTime('initial')
    for (const mode of ['dblclick', 'next'] as const) {
      for (let index = 0; index < 3; index++) {
        sample = `${mode}-${index + 1}`
        const before = await state()
        await page.evaluate(name => { (window as typeof window & { __songTiming: { sample: string } }).__songTiming.sample = name }, sample)
        if (mode === 'dblclick') await page.getByText(`Live song ${String(index + 1).padStart(5, '0')}`, { exact: true }).dblclick()
        else await page.getByRole('region', { name: 'Playback', exact: true }).getByRole('button', { name: 'Next', exact: true }).click()
        await expect.poll(async () => {
          const current = await state()
          return current.publicId !== before.publicId && !current.paused && current.position > 0.1
        }, { timeout: 30_000 }).toBe(true)
        await expect.poll(() => events.some(event => event.sample === sample && event.kind === 'http-response'
          && event.name === '/api/session/control' && ['applied', 'playing'].includes(String(event.outcome))), { timeout: 15_000 }).toBe(true)
        await assertFooterTime(sample)
        const native = await page.evaluate(() => (window as typeof window & { __songTiming: { events: TimingEvent[] } }).__songTiming.events)
        const measured = [...events, ...native].filter(event => event.sample === sample).sort((a, b) => a.at - b.at)
        const input = measured.find(event => event.kind === 'input')?.at ?? 0
        const offset = (kind: string, name?: string) => {
          const at = measured.find(event => event.kind === kind && (!name || event.name === name))?.at
          return at === undefined ? null : Math.round(at - input)
        }
        const playing = measured.find(event => event.kind === 'native' && event.name === 'playing')
        const pause = measured.find(event => event.kind === 'native' && event.name === 'pause' && event.element !== playing?.element)
        const delivered = measured.find(event => ['http-command', 'ws-command'].includes(event.kind) && event.name === 'load')
        const summary = { sample, queueLength: before.queueLength, crossfadeEnabled: before.crossfadeEnabled,
          crossfadeDuration: before.crossfadeDuration, controlRequestMs: offset('http-start', '/api/session/control'),
          controlResponseMs: offset('http-response', '/api/session/control'),
          commandMs: delivered ? Math.round(delivered.at - input) : null, commandDelivery: delivered?.kind ?? null,
          httpCommandMs: offset('http-command', 'load'), wsCommandMs: offset('ws-command', 'load'),
          executeMs: offset('adapter', 'execute-start'), loadstartMs: offset('native', 'loadstart'),
          canplayMs: offset('native', 'canplay'), playingMs: offset('native', 'playing'),
          silenceMs: pause && playing ? Math.round(playing.at - pause.at) : null }
        console.log(`SONG_SWITCH_TIMING ${JSON.stringify(summary)}`)
        samples.push({ summary, before, after: await state() })
      }
    }
    const native = await page.evaluate(() => (window as typeof window & { __songTiming: { events: TimingEvent[] } }).__songTiming.events)
    await testInfo.attach('song-switch-timings', { body: JSON.stringify({ samples, events: [...events, ...native].sort((a, b) => a.at - b.at) }, null, 2), contentType: 'application/json' })
  } finally {
    await testInfo.attach('song-footer-time-observations', { body: JSON.stringify(footerSamples, null, 2), contentType: 'application/json' })
    const native = await measuredPage?.evaluate(() => (window as typeof window & { __songTiming?: { events: TimingEvent[] } }).__songTiming?.events ?? []).catch(() => []) ?? []
    await testInfo.attach('song-switch-partial-timings', { body: JSON.stringify({ samples, events: [...events, ...native].sort((a, b) => a.at - b.at) }, null, 2), contentType: 'application/json' })
    await context.close()
    try {
      if (fixture.run) expect((await fixture.request({ op: 'cleanup' })).receipt.complete).toBe(true)
    } finally { await fixture.close() }
  }
})
