import { test, expect, chromium, firefox, webkit } from '@playwright/test'
import type { Browser, BrowserType, Page, TestInfo } from '@playwright/test'
import { createServer } from 'node:http'
import type { IncomingMessage, ServerResponse } from 'node:http'
import { readFileSync } from 'node:fs'
import type { AddressInfo } from 'node:net'

// How a native <audio> element reports a stream that the server cuts midway,
// and whether it can resume at a saved position after the server comes back.
// The browsers reach `audio.baander.app` only through a loopback HTTP proxy
// that this test serves itself; nothing is forwarded.

const HOST = 'audio.baander.app'
const ORIGIN = `http://${HOST}`
// The fixture is 4.05 s of 192 kbit/s CBR MP3 without Xing or ID3 headers, so
// copies of it join into one longer stream, as a progressive encode writes it.
const FIXTURE = readFileSync(new URL('./fixtures/tone-4s.mp3', import.meta.url))
const FIXTURE_SECONDS = 4.049
const TRACK = Buffer.concat([FIXTURE, FIXTURE, FIXTURE])
const TRACK_SECONDS = 3 * FIXTURE_SECONDS
// WebKit starts a response with a Content-Length only after more than about
// four seconds of it have arrived; Chromium and Firefox wait for 32 KiB.
// Playback stays at normal speed: a raised rate makes WebKit end an MP3
// without a Xing header at once.
const CUT_SECONDS = 6.5
const CUT_BYTES = Math.floor(TRACK.length * CUT_SECONDS / TRACK_SECONDS)
// The cut arrives while the element plays, with buffered audio ahead of it.
const PLAYING_BEFORE_CUT = 1

type Behaviour =
  | 'chunked-held'
  | 'length-held'
  | 'chunked-complete'
  | 'ranged-complete'
  | 'bad-gateway'
  | 'unreachable'

type Cut = 'reset' | 'clean'

interface RequestRecord {
  id: string
  behaviour: Behaviour
  range: string | null
}

interface MediaEventRecord {
  type: string
  at: number
  time: number
  readyState: number
  networkState: number
  error: number | null
}

interface MediaState {
  events: MediaEventRecord[]
  now: number
  openedAt: number
  lastAdvanceAt: number
  time: number
  maxTime: number
  duration: number
  paused: boolean
  ended: boolean
  readyState: number
  networkState: number
  error: number | null
  errorMessage: string
  buffered: number[][]
  seekable: number[][]
  playRejection: string | null
}

interface MediaProbe {
  open(url: string): void
  seek(time: number): void
  play(): void
  pause(): void
  state(): MediaState
}

declare global {
  interface Window {
    mediaProbe: MediaProbe
  }
}

class InterruptionServer {
  readonly requests: RequestRecord[] = []
  private readonly scripts = new Map<string, Behaviour[]>()
  private readonly held = new Map<string, ServerResponse>()
  private readonly server = createServer((request, response) => this.handle(request, response))
  private port = 0

  get proxy(): string {
    return `http://127.0.0.1:${this.port}`
  }

  // Each request for `id` takes the next behaviour; the last one repeats.
  script(id: string, behaviours: Behaviour[]): string {
    this.scripts.set(id, [...behaviours])

    return `${ORIGIN}/media/${id}.mp3`
  }

  requestsFor(id: string): RequestRecord[] {
    return this.requests.filter(request => request.id === id)
  }

  // Ends a held response: a TCP reset without the terminating chunk, or a
  // clean end that writes it, which is what the server does today.
  cut(id: string, how: Cut): void {
    const response = this.held.get(id)
    if (!response) {
      throw new Error(`No held response for ${id}`)
    }
    this.held.delete(id)
    if (how === 'reset') {
      response.socket?.resetAndDestroy()
    } else {
      response.end()
    }
  }

  async start(): Promise<void> {
    await this.listen(0)
    this.port = (this.server.address() as AddressInfo).port
  }

  // Held responses are reset and new connections are refused until `recover`.
  async goDown(): Promise<void> {
    for (const id of [...this.held.keys()]) {
      this.cut(id, 'reset')
    }
    const closed = new Promise<void>(resolve => this.server.close(() => resolve()))
    this.server.closeAllConnections()
    await closed
  }

  async recover(): Promise<void> {
    await this.listen(this.port)
  }

  async stop(): Promise<void> {
    if (this.server.listening) {
      await this.goDown()
    }
  }

  private listen(port: number): Promise<void> {
    return new Promise((resolve, reject) => {
      this.server.once('error', reject)
      this.server.listen(port, '127.0.0.1', () => {
        this.server.off('error', reject)
        resolve()
      })
    })
  }

  private handle(request: IncomingMessage, response: ServerResponse): void {
    const url = new URL(request.url ?? '/', `http://${request.headers.host ?? 'invalid'}`)
    if (url.hostname !== HOST) {
      response.writeHead(403)
      response.end()

      return
    }
    if (url.pathname === '/') {
      response.setHeader('Content-Type', 'text/html')
      response.end('<!doctype html><title>Media interruption</title>')

      return
    }
    const match = url.pathname.match(/^\/media\/([a-z0-9-]+)\.mp3$/)
    const script = match ? this.scripts.get(match[1]) : undefined
    if (!match || !script) {
      response.writeHead(404)
      response.end()

      return
    }
    const id = match[1]
    const behaviour = script.length > 1 ? script.shift()! : script[0]
    this.requests.push({ id, behaviour, range: request.headers.range ?? null })
    this.respond(id, behaviour, request, response)
  }

  private respond(id: string, behaviour: Behaviour, request: IncomingMessage, response: ServerResponse): void {
    response.setHeader('Content-Type', 'audio/mpeg')
    response.setHeader('Cache-Control', 'no-store')

    switch (behaviour) {
      case 'bad-gateway':
        // What nginx answers while the web container restarts.
        response.writeHead(502)
        response.end()

        return
      case 'unreachable':
        // No proxy in front: the request is reset before any response.
        response.socket?.resetAndDestroy()

        return
      case 'chunked-held':
      case 'length-held':
        // A progressive rendition (chunked, no ranges) or an original file
        // (Content-Length, ranges) whose body stops at the cut and waits.
        if (behaviour === 'length-held') {
          response.setHeader('Accept-Ranges', 'bytes')
          response.setHeader('Content-Length', TRACK.length)
        } else {
          response.setHeader('Accept-Ranges', 'none')
        }
        this.held.set(id, response)
        response.write(TRACK.subarray(0, CUT_BYTES))

        return
      case 'chunked-complete':
        response.setHeader('Accept-Ranges', 'none')
        response.write(TRACK.subarray(0, CUT_BYTES))
        setTimeout(() => response.end(TRACK.subarray(CUT_BYTES)), 50)

        return
      case 'ranged-complete': {
        response.setHeader('Accept-Ranges', 'bytes')
        const range = request.headers.range?.match(/^bytes=(\d+)-(\d*)$/)
        if (!range) {
          response.setHeader('Content-Length', TRACK.length)
          response.end(TRACK)

          return
        }
        const start = Number(range[1])
        const end = range[2] ? Math.min(Number(range[2]), TRACK.length - 1) : TRACK.length - 1
        response.writeHead(206, {
          'Content-Range': `bytes ${start}-${end}/${TRACK.length}`,
          'Content-Length': end - start + 1,
        })
        response.end(TRACK.subarray(start, end + 1))
      }
    }
  }
}

// Runs in the page: one muted media element and a log of its events.
function installMediaProbe(): void {
  const audio = new Audio()
  audio.muted = true
  audio.preload = 'auto'
  const events: MediaEventRecord[] = []
  let openedAt = performance.now()
  let lastAdvanceAt = openedAt
  let maxTime = 0
  let playRejection: string | null = null
  const ranges = (list: TimeRanges) => Array.from({ length: list.length }, (_, index) => [list.start(index), list.end(index)])
  const log = (type: string) => events.push({
    type,
    at: Math.round(performance.now() - openedAt),
    time: audio.currentTime,
    readyState: audio.readyState,
    networkState: audio.networkState,
    error: audio.error?.code ?? null,
  })
  const types = [
    'loadstart', 'loadedmetadata', 'loadeddata', 'canplay', 'play', 'playing',
    'waiting', 'stalled', 'abort', 'emptied', 'error', 'ended', 'pause', 'seeking', 'seeked',
  ]
  for (const type of types) {
    audio.addEventListener(type, () => log(type))
  }
  audio.addEventListener('timeupdate', () => {
    if (audio.currentTime > maxTime) {
      maxTime = audio.currentTime
      lastAdvanceAt = performance.now()
    }
  })
  const play = () => {
    audio.play().catch((error: unknown) => {
      playRejection = error instanceof Error ? `${error.name}: ${error.message}` : String(error)
    })
  }

  window.mediaProbe = {
    open(url) {
      openedAt = performance.now()
      lastAdvanceAt = openedAt
      maxTime = 0
      playRejection = null
      log('probe:open')
      audio.src = url
      audio.load()
      play()
    },
    seek(time) {
      log('probe:seek')
      audio.currentTime = time
    },
    play() {
      log('probe:play')
      play()
    },
    pause() {
      log('probe:pause')
      audio.pause()
    },
    state() {
      return {
        events: [...events],
        now: performance.now(),
        openedAt,
        lastAdvanceAt,
        time: audio.currentTime,
        maxTime,
        duration: audio.duration,
        paused: audio.paused,
        ended: audio.ended,
        readyState: audio.readyState,
        networkState: audio.networkState,
        error: audio.error?.code ?? null,
        errorMessage: audio.error?.message ?? '',
        buffered: ranges(audio.buffered),
        seekable: ranges(audio.seekable),
        playRejection,
      }
    },
  }
}

const state = (page: Page) => page.evaluate(() => window.mediaProbe.state())
const open = (page: Page, url: string) => page.evaluate(source => window.mediaProbe.open(source), url)
const round = (value: number) => Number(value.toFixed(3))
const covers = (ranges: number[][], time: number) => ranges.some(([start, end]) => start <= time && time < end)

async function openProbe(browser: Browser): Promise<Page> {
  const page = await browser.newPage()
  await page.goto(`${ORIGIN}/`)
  await page.evaluate(installMediaProbe)

  return page
}

async function waitFor(page: Page, condition: (current: MediaState) => boolean, timeoutMs: number): Promise<MediaState> {
  const deadline = Date.now() + timeoutMs
  for (;;) {
    const current = await state(page)
    if (condition(current) || Date.now() > deadline) {
      return current
    }
    await page.waitForTimeout(50)
  }
}

// Waits until the element fires `ended`, or its playhead has not moved for `quietMs`.
function settle(page: Page, quietMs: number, timeoutMs: number): Promise<MediaState> {
  return waitFor(page, current => current.events.some(event => event.type === 'ended')
    || current.now - current.lastAdvanceAt >= quietMs, timeoutMs)
}

function summary(current: MediaState) {
  const count = (type: string) => current.events.filter(event => event.type === type).length

  return {
    endedEvents: count('ended'),
    ended: current.ended,
    errorCode: current.error,
    errorMessage: current.errorMessage,
    stalledEvents: count('stalled'),
    waitingEvents: count('waiting'),
    maxTime: round(current.maxTime),
    time: round(current.time),
    duration: current.duration,
    paused: current.paused,
    readyState: current.readyState,
    networkState: current.networkState,
    buffered: current.buffered,
    seekable: current.seekable,
    playRejection: current.playRejection,
  }
}

// Prints the outcome without its event log and attaches all of it.
async function record(testInfo: TestInfo, name: string, outcome: { events: MediaEventRecord[], [key: string]: unknown }): Promise<void> {
  const { events, ...rest } = outcome
  console.log(`[${testInfo.title}] ${name}: ${JSON.stringify(rest)}`)
  await testInfo.attach(name, { body: JSON.stringify({ ...rest, events }, null, 2), contentType: 'application/json' })
}

async function launch(engine: BrowserType, server: InterruptionServer): Promise<Browser> {
  return engine.launch({
    proxy: { server: server.proxy },
    args: engine === chromium ? ['--autoplay-policy=no-user-gesture-required'] : undefined,
    firefoxUserPrefs: engine === firefox
      ? { 'media.autoplay.default': 0, 'network.dns.disablePrefetch': true, 'network.dns.localDomains': HOST }
      : undefined,
  })
}

for (const engine of [chromium, firefox, webkit]) {
  test.describe(`${engine.name()} media interruption`, () => {
    let server: InterruptionServer
    let browser: Browser | undefined

    test.beforeEach(async () => {
      server = new InterruptionServer()
      await server.start()
      browser = await launch(engine, server)
    })

    test.afterEach(async () => {
      await browser?.close()
      await server.stop()
    })

    test(`${engine.name()} surfaces a reset stream as an error or a stall before any ended`, async () => {
      const testInfo = test.info()
      // Requests after the cut get a 502 (nginx in front) or are reset (none).
      const cases: { id: string, cut: Cut, then: Behaviour }[] = [
        { id: 'chunked-reset-502', cut: 'reset', then: 'bad-gateway' },
        { id: 'chunked-reset-unreachable', cut: 'reset', then: 'unreachable' },
        { id: 'length-reset-502', cut: 'reset', then: 'bad-gateway' },
        { id: 'length-reset-unreachable', cut: 'reset', then: 'unreachable' },
        { id: 'chunked-clean-end', cut: 'clean', then: 'bad-gateway' },
      ]
      const urls = cases.map(({ id, then }) => server.script(id, [id.startsWith('length') ? 'length-held' : 'chunked-held', then]))
      const pages = await Promise.all(cases.map(() => openProbe(browser!)))
      const outcomes = await Promise.all(pages.map(async (page, index) => {
        await open(page, urls[index])
        const beforeCut = await waitFor(page, current => current.time >= PLAYING_BEFORE_CUT, 8000)
        server.cut(cases[index].id, cases[index].cut)

        return { beforeCut, settled: await settle(page, 1500, 10_000) }
      }))

      for (const [index, testCase] of cases.entries()) {
        const { beforeCut, settled } = outcomes[index]
        await record(testInfo, testCase.id, {
          cutAt: { time: round(beforeCut.time), buffered: beforeCut.buffered },
          ...summary(settled),
          requests: server.requestsFor(testCase.id),
          events: settled.events,
        })
      }

      for (const [index, testCase] of cases.entries()) {
        const { beforeCut, settled } = outcomes[index]
        expect(beforeCut.time, `${testCase.id} was playing when it was cut`).toBeGreaterThanOrEqual(PLAYING_BEFORE_CUT)
        if (testCase.cut === 'clean') {
          continue
        }
        const afterCut = settled.events.filter(event => event.at > beforeCut.now - beforeCut.openedAt)
        expect.soft(
          afterCut.some(event => event.type === 'error' || event.type === 'waiting' || event.type === 'stalled'),
          `${testCase.id} reported an error or a stall`,
        ).toBe(true)
        // Firefox reports the error, keeps playing what it buffered and then fires `ended`. The
        // player lets the error win over a later `ended` of the same load, so the error must come
        // first; Chromium and WebKit never end a cut stream at all.
        const firstError = afterCut.findIndex(event => event.type === 'error')
        const firstEnded = afterCut.findIndex(event => event.type === 'ended')
        if (firstEnded !== -1) {
          expect.soft(firstError, `${testCase.id} reported an error before ended`).toBeGreaterThanOrEqual(0)
          expect.soft(firstError, `${testCase.id} reported an error before ended`).toBeLessThan(firstEnded)
        }
      }
    })

    test(`${engine.name()} reloads the same source after an outage and seeks to the saved position`, async () => {
      const testInfo = test.info()
      test.setTimeout(40_000)
      // The ranged flow is an original file; the chunked flows are a progressive
      // rendition that arrives complete after the outage. They seek as soon as
      // the saved position is seekable, or once the whole body has arrived.
      const flows: { id: string, seek: 'when-seekable' | 'when-complete' }[] = [
        { id: 'resume-ranged', seek: 'when-seekable' },
        { id: 'resume-chunked-when-seekable', seek: 'when-seekable' },
        { id: 'resume-chunked-when-complete', seek: 'when-complete' },
      ]
      const urls = flows.map(({ id }) => id === 'resume-ranged'
        ? server.script(id, ['length-held', 'ranged-complete'])
        : server.script(id, ['chunked-held', 'chunked-complete']))
      const pages = await Promise.all(flows.map(() => openProbe(browser!)))
      const playing = await Promise.all(pages.map(async (page, index) => {
        await open(page, urls[index])

        return waitFor(page, current => current.time >= PLAYING_BEFORE_CUT, 8000)
      }))

      // The player keeps the last position the element reported, because
      // WebKit resets currentTime to 0 on a network error, then pauses.
      await server.goDown()
      const interrupted = await Promise.all(pages.map(async (page, index) => {
        const since = playing[index].now - playing[index].openedAt
        const isSignal = (event: MediaEventRecord) => event.at > since
          && ['error', 'waiting', 'stalled', 'ended'].includes(event.type)
        const signalled = await waitFor(page, current => current.events.some(isSignal), 10_000)
        await page.evaluate(() => window.mediaProbe.pause())

        return {
          signal: signalled.events.find(isSignal)?.type ?? 'none',
          error: signalled.error,
          elementTime: round(signalled.time),
          saved: signalled.maxTime,
        }
      }))

      // A reload while the server still refuses connections.
      const refused = await Promise.all(pages.map(async (page, index) => {
        await open(page, urls[index])

        return waitFor(page, current => current.error !== null || current.time > PLAYING_BEFORE_CUT, 3000)
      }))

      await server.recover()
      const results = await Promise.all(pages.map(async (page, index) => {
        const saved = interrupted[index].saved
        await open(page, urls[index])
        let complete: boolean | null = null
        if (flows[index].seek === 'when-complete') {
          // Playing reads the whole body; a paused Chromium stops reading early.
          const loaded = await waitFor(page, current => current.networkState === 1 && Number.isFinite(current.duration), 5000)
          complete = loaded.networkState === 1 && Number.isFinite(loaded.duration)
        }
        await page.evaluate(() => window.mediaProbe.pause())
        const ready = await waitFor(page, current => covers(current.seekable, saved), 3000)
        const seekableWhilePaused = covers(ready.seekable, saved)
        let seekableWhilePlaying: boolean | null = null
        if (!seekableWhilePaused) {
          // Some engines only expose a seekable range once playback starts.
          // A range that merely follows the playhead up to the position does
          // not count as seekable.
          await page.evaluate(() => window.mediaProbe.play())
          const started = await waitFor(page, current => covers(current.seekable, saved) || current.time >= saved - 0.3, 3000)
          seekableWhilePlaying = covers(started.seekable, saved) && started.time < saved - 0.3
        }
        const atSeek = await state(page)
        const seeksBefore = atSeek.events.filter(event => event.type === 'seeked').length
        await page.evaluate(time => window.mediaProbe.seek(time), saved)
        const sought = await waitFor(page, current => current.events.filter(event => event.type === 'seeked').length > seeksBefore, 2000)
        await page.evaluate(() => window.mediaProbe.play())
        await page.waitForTimeout(300)
        const resumed = await state(page)

        return {
          flow: flows[index].id,
          interrupted: { ...interrupted[index], saved: round(saved) },
          reloadDuringOutage: summary(refused[index]),
          readyForSeek: {
            readyState: ready.readyState,
            networkState: ready.networkState,
            duration: ready.duration,
            buffered: ready.buffered,
            seekable: ready.seekable,
          },
          complete,
          seekableWhilePaused,
          seekableWhilePlaying,
          timeBeforeSeek: round(atSeek.time),
          afterSeek: { time: round(sought.time), seekable: sought.seekable },
          afterPlay: { time: round(resumed.time), paused: resumed.paused, error: resumed.error },
          resumedAtSaved: atSeek.time < saved - 0.25 && Math.abs(sought.time - saved) < 0.25 && resumed.time > saved,
          requests: server.requestsFor(flows[index].id),
          events: resumed.events,
        }
      }))

      for (const result of results) {
        await record(testInfo, result.flow, result)
      }
    })
  })
}
