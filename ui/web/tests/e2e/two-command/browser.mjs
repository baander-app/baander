import assert from 'node:assert/strict'
import { createHash } from 'node:crypto'
import { createServer } from 'node:http'
import { mkdir, writeFile } from 'node:fs/promises'
import { chromium } from 'playwright-core'

const evidence = '/work/evidence'
await mkdir(evidence, { recursive: true })
const browser = await chromium.launch({ channel: 'chromium', headless: true })
const browserInfo = { engine: 'chromium', version: browser.version() }
const context = await browser.newContext({ viewport: { width: 1280, height: 800 } })
await context.addInitScript(() => {
  const ranges = value => Array.from({ length: value.length }, (_, index) => [value.start(index), value.end(index)])
  window.__twoCommandMediaState = element => ({
    at: Date.now(), probe: element.dataset.twoCommandProbe === 'true',
    time: element.currentTime, paused: element.paused, ready: element.readyState,
    seeking: element.seeking, network: element.networkState,
    duration: Number.isFinite(element.duration) ? element.duration : null,
    buffered: ranges(element.buffered), seekable: ranges(element.seekable),
    decodedFrames: element.getVideoPlaybackQuality?.().totalVideoFrames ?? element.webkitDecodedFrameCount ?? null,
    errorCode: element.error?.code ?? null,
    source: element.currentSrc ? new URL(element.currentSrc).pathname : null,
  })
  window.__twoCommandMediaEvents = []
  for (const name of ['loadstart', 'loadedmetadata', 'loadeddata', 'canplay', 'playing', 'pause', 'seeking', 'seeked', 'waiting', 'stalled', 'timeupdate', 'error', 'emptied']) {
    document.addEventListener(name, event => {
      if (!(event.target instanceof HTMLVideoElement)) return
      const events = window.__twoCommandMediaEvents
      events.push({ event: name, ...window.__twoCommandMediaState(event.target) })
      if (events.length > 200) events.shift()
    }, true)
  }
})
const page = await context.newPage()
let video
let snapshot
let initialGeneration
let soughtGeneration
const phases = []
const mediaResponses = []
const apiResponses = []
const playbackControls = []
const playbackSnapshots = []
const clientControls = []
const previousMediaEvents = []
const networkInterruptions = []
let savingTimeline = false
const nativeResponseEvidence = []
const nativeResponseKeys = new Set()
const nativeBodyLimit = 32 * 1024 * 1024
let nativeBodyReads = 0
async function captureNativeResponse(response, url) {
  const match = /^\/api\/transcode\/([^/]+)\/(init|segment)$/.exec(url.pathname)
  const generation = url.searchParams.get('generation')
  const index = url.searchParams.get('index')
  if (!match || !snapshot?.ready || match[1] !== snapshot.jobId || generation !== String(snapshot.generation)
    || response.status() !== 200 || (match[2] === 'segment' && !['0', '1', '2'].includes(index))) return
  const key = `${match[1]}:${generation}:${match[2]}:${index ?? ''}`
  if (nativeResponseKeys.has(key) || nativeResponseEvidence.length >= 16) return
  nativeResponseKeys.add(key)
  const item = { at: Date.now(), jobId: match[1], generation, resource: match[2], index, status: 200, state: 'pending' }
  nativeResponseEvidence.push(item)
  let stage = 'headers'
  let timer
  try {
    await Promise.race([
      (async () => {
        const headers = await response.allHeaders()
        const requestHeaders = await response.request().allHeaders()
        if (item.state !== 'pending') return
        item.headers = Object.fromEntries(['content-type', 'content-encoding', 'content-length', 'transfer-encoding', 'content-range', 'accept-ranges', 'vary']
          .filter(name => headers[name] !== undefined).map(name => [name, headers[name]]))
        item.requestHeaders = Object.fromEntries(['accept-encoding', 'range']
          .filter(name => requestHeaders[name] !== undefined).map(name => [name, requestHeaders[name]]))
        // Four bounded fixture assets (init plus the first three fragments), at most 32 MiB hashed.
        if (nativeBodyReads >= 4 || Number(headers['content-length'] ?? 0) > nativeBodyLimit / 4) {
          item.state = 'body-budget-unavailable'
          return
        }
        nativeBodyReads++
        stage = 'transfer'
        if (await response.finished()) {
          item.state = 'transfer-failed'
          return
        }
        if (item.state !== 'pending') return
        stage = 'body'
        const body = await response.body()
        if (item.state !== 'pending') return
        if (body.length > nativeBodyLimit / 4) {
          item.state = 'body-limit-unavailable'
          return
        }
        item.bytes = body.length
        item.sha256 = createHash('sha256').update(body).digest('hex')
        item.state = 'hashed'
      })(),
      new Promise((_, reject) => { timer = setTimeout(() => reject(new Error('native-evidence-timeout')), 5000) }),
    ])
  } catch {
    item.state = `${stage}-unavailable`
  } finally {
    clearTimeout(timer)
  }
}
function appendBounded(list, value, limit = 300) {
  list.push(value)
  if (list.length > limit) list.shift()
}
function safePlayback(value) {
  return Object.fromEntries(['jobId', 'sessionId', 'ready', 'generation', 'revision', 'status', 'position', 'startTime', 'duration', 'renditionSet'].map(key => [key, value[key]]))
}
async function savePlaybackTimeline() {
  if (savingTimeline) return
  savingTimeline = true
  try {
    const mediaEvents = await page.evaluate(() => window.__twoCommandMediaEvents ?? [])
    await writeFile(`${evidence}/playback-timeline.json`, JSON.stringify({
      playbackSnapshots, playbackControls, clientControls, networkInterruptions, mediaEvents: [...previousMediaEvents, ...mediaEvents],
    }, null, 2))
  } finally {
    savingTimeline = false
  }
}
page.on('requestfailed', request => {
  const url = new URL(request.url())
  if (url.pathname === '/api/stream/sign') {
    appendBounded(networkInterruptions, { at: Date.now(), path: url.pathname })
  }
})
page.on('request', request => {
  const url = new URL(request.url())
  if (request.method() !== 'POST' || !/^\/api\/transcode\/sessions\/[^/]+\/position$/.test(url.pathname)) return
  try {
    const value = request.postDataJSON()
    appendBounded(playbackControls, { at: Date.now(), ...Object.fromEntries(
      ['action', 'position', 'generation', 'revision'].map(key => [key, value[key]]),
    ) }, 100)
  } catch { /* An interrupted request may have no readable JSON body. */ }
})
page.on('response', async response => {
  const url = new URL(response.url())
  if (url.pathname.startsWith('/api/')) {
    appendBounded(apiResponses, { at: Date.now(), path: url.pathname, status: response.status() })
  }
  if (url.pathname === '/api/stream/sign' && response.ok()) {
    try {
      const next = await response.json()
      const accepted = !snapshot || next.jobId !== snapshot.jobId
        || (next.generation >= snapshot.generation && next.revision >= snapshot.revision)
      appendBounded(playbackSnapshots, { at: Date.now(), accepted, ...safePlayback(next) }, 100)
      if (accepted) {
        snapshot = next
      }
      await savePlaybackTimeline()
    } catch { /* A web restart may abort an already received response. */ }
  }
  if (url.pathname.startsWith('/api/transcode/') && /(?:\.m3u8|\/init|\/segment)$/.test(url.pathname)) {
    appendBounded(mediaResponses, { at: Date.now(), path: url.pathname, generation: url.searchParams.get('generation'), index: url.searchParams.get('index'), status: response.status() })
    await captureNativeResponse(response, url)
  }
})

async function until(check, description, milliseconds = 45000) {
  const deadline = Date.now() + milliseconds
  while (Date.now() < deadline) {
    if (await check()) return
    await new Promise(resolve => setTimeout(resolve, 100))
  }
  throw new Error(`Timed out waiting for ${description}`)
}

async function mediaState() {
  assert(video, 'An attached video must exist')
  return video.evaluate(element => ({
    ...window.__twoCommandMediaState(element),
    connected: element.isConnected, time: element.currentTime, paused: element.paused,
    ready: element.readyState, error: element.error?.code ?? null, source: element.currentSrc,
    nativeHls: element.canPlayType('application/vnd.apple.mpegurl'),
    h264: element.canPlayType('video/mp4; codecs="avc1.42E01E"'),
  }))
}

async function pausedAt(position, minimumGeneration) {
  await until(async () => {
    const state = await mediaState()
    return state.connected && state.paused && state.ready >= 2
      && snapshot?.ready && snapshot.status === 'paused' && snapshot.generation >= minimumGeneration
      && Number.isFinite(snapshot.startTime)
      && Math.abs(snapshot.startTime + state.time - position) < 2
      && new URL(state.source).searchParams.get('set') === snapshot.renditionSet
  }, 'the attached player to restore its paused global position')
}

function ownedPlayback() {
  assert(snapshot?.ready && snapshot.sessionId, 'Playback must have a ready owned session')
  return { jobId: snapshot.jobId, sessionId: snapshot.sessionId, generation: snapshot.generation }
}

function samePlayback(owner, generation = owner.generation) {
  assert.equal(snapshot.jobId, owner.jobId, 'Client controls must retain the owned job')
  assert.equal(snapshot.sessionId, owner.sessionId, 'Client controls must retain the canonical session')
  assert.equal(snapshot.generation, generation, 'Only an intentional seek may advance generation')
}

async function recordClientControl(action, started) {
  const state = await mediaState()
  const { source, ...safeState } = state
  clientControls.push({ action, at: Date.now(), elapsedMs: Date.now() - started,
    state: { ...safeState, source: source ? new URL(source).pathname : null },
    playback: safePlayback(snapshot) })
  await savePlaybackTimeline()
}

async function resumeAndDecode(owner, action) {
  const started = Date.now()
  const before = await mediaState()
  await video.evaluate(element => element.play())
  await until(async () => {
    const state = await mediaState()
    return state.connected && !state.paused && state.ready >= 2 && state.error === null
      && state.time > before.time + 1 && state.decodedFrames > before.decodedFrames
      && snapshot?.ready && snapshot.status === 'running'
      && new URL(state.source).searchParams.get('set') === snapshot.renditionSet
  }, `${action} to decode current signed HLS and advance`)
  samePlayback(owner)
  await recordClientControl(action, started)
}

async function pausePlayback(owner, action) {
  const started = Date.now()
  await video.evaluate(element => element.pause())
  await until(async () => (await mediaState()).paused && snapshot?.status === 'paused', 'native pause to commit')
  samePlayback(owner)
  await recordClientControl(action, started)
}

async function checkpoint({ phase, baseUrl, credentials }) {
  if (phase === 'authenticated') {
    assert(await page.evaluate(() => document.createElement('video').canPlayType('application/vnd.apple.mpegurl')),
      'The browser must provide native HLS decoding')
    await page.goto(`${baseUrl}/login`)
    await page.locator('#email').fill(credentials.email)
    await page.locator('#password').fill(credentials.password)
    await page.locator('button[type=submit]').click()
    await page.waitForURL(url => url.pathname !== '/login')
  } else if (phase === 'attached') {
    await page.goto(`${baseUrl}/movies/${credentials.moviePublicId}`)
    await page.getByRole('button', { name: 'Play', exact: true }).click()
    video = await page.locator('video').elementHandle()
    assert(video, 'Movie playback must create a video element')
    await until(async () => {
      const state = await mediaState()
      return state.connected && !state.paused && state.ready >= 2 && state.time > 1 && snapshot?.ready
    }, 'native HLS to decode and advance')
    initialGeneration = snapshot.generation
    await page.screenshot({ path: `${evidence}/desktop.png` })
    await page.setViewportSize({ width: 390, height: 844 })
    await page.screenshot({ path: `${evidence}/mobile.png` })
    await page.setViewportSize({ width: 1280, height: 800 })
  } else if (phase === 'client-controls') {
    const owner = ownedPlayback()
    await pausePlayback(owner, 'pause')
    const started = Date.now()
    // Exercise the browser-native seek event path, not a test-only REST control.
    const localTarget = credentials.expectedSeekPosition - snapshot.startTime
    assert(localTarget >= 0, 'The requested seek must be inside the current native timeline')
    await video.evaluate((element, position) => { element.currentTime = position }, localTarget)
    await pausedAt(credentials.expectedSeekPosition, owner.generation + 1)
    samePlayback(owner, owner.generation + 1)
    await recordClientControl('seek-paused', started)
    owner.generation = snapshot.generation
    await resumeAndDecode(owner, 'resume-after-seek')
    await pausePlayback(owner, 'pause-before-network-interruption')

    const networkStarted = Date.now()
    const failureCount = networkInterruptions.length
    try {
      await context.setOffline(true)
      await until(() => networkInterruptions.length > failureCount, 'a real stream refresh failure while offline', 10000)
    } finally {
      await context.setOffline(false)
    }
    const reconnected = Date.now()
    await until(() => playbackSnapshots.some(item => item.accepted && item.at >= reconnected
      && item.jobId === owner.jobId && item.sessionId === owner.sessionId && item.status === 'paused'),
    'a fresh paused signed snapshot after network reconnection')
    samePlayback(owner)
    await recordClientControl('network-reconnected-paused', networkStarted)
    await resumeAndDecode(owner, 'resume-after-network-reconnection')
    await pausePlayback(owner, 'pause-before-remount')
    const pausedPosition = snapshot.startTime + (await mediaState()).time

    const remountStarted = Date.now()
    previousMediaEvents.push(...await page.evaluate(() => window.__twoCommandMediaEvents ?? []))
    const previousVideo = video
    snapshot = undefined
    await page.reload()
    await page.getByRole('button', { name: 'Play', exact: true }).click()
    video = await page.locator('video').elementHandle()
    assert(video, 'Reload must mount a new video element')
    await previousVideo.dispose()
    await pausedAt(pausedPosition, owner.generation)
    samePlayback(owner)
    await recordClientControl('remounted-paused', remountStarted)
    await resumeAndDecode(owner, 'resume-after-remount')
    await pausePlayback(owner, 'final-pause')
    // Leave a different paused cursor so the following operator seek changes position.
    const finalSeekStarted = Date.now()
    const finalPosition = credentials.expectedSeekPosition + 5
    const finalLocalPosition = finalPosition - snapshot.startTime
    assert(finalLocalPosition >= 0 && finalPosition < snapshot.duration, 'The final seek must fit the current native timeline')
    await video.evaluate((element, position) => { element.currentTime = position }, finalLocalPosition)
    await pausedAt(finalPosition, owner.generation + 1)
    samePlayback(owner, owner.generation + 1)
    await recordClientControl('final-seek-paused', finalSeekStarted)
  } else if (phase === 'sought-paused') {
    await pausedAt(credentials.expectedSeekPosition, initialGeneration + 1)
    soughtGeneration = snapshot.generation
  } else if (phase === 'web-restarted') {
    await pausedAt(credentials.expectedSeekPosition, soughtGeneration)
  } else if (phase === 'worker-contained') {
    assert((await mediaState()).connected, 'Worker containment must retain the attached player')
  } else if (phase === 'worker-recovered') {
    await pausedAt(credentials.expectedSeekPosition, soughtGeneration + 1)
    const before = await mediaState()
    await video.evaluate(element => element.play())
    await until(async () => {
      const state = await mediaState()
      return state.connected && !state.paused && state.time > before.time + 1
        && snapshot?.status === 'running'
    }, 'the same player to resume after worker recovery')
    assert(mediaResponses.some(item => {
      const job = /^\/api\/transcode\/([^/]+)\/segment$/.exec(item.path)?.[1]
      return item.status === 200 && job && snapshot.renditionSet.split(',').includes(`${job}.${item.generation}`)
    }), 'Recovered playback must receive media from the current signed rendition set')
  } else if (phase === 'complete') {
    assert.deepEqual(phases, ['authenticated', 'attached', ...(phases.includes('client-controls') ? ['client-controls'] : []), 'sought-paused', 'web-restarted', 'worker-contained', 'worker-recovered'])
  } else {
    throw new Error(`Unexpected browser checkpoint: ${phase}`)
  }
  phases.push(phase)
  // No credentials or signed URLs are written to the evidence artifact.
  await writeFile(`${evidence}/result.json`, JSON.stringify({ browser: browserInfo, phases, generation: snapshot?.generation, mediaResponses, nativeResponseEvidence, clientControls, networkInterruptions }, null, 2))
  return { phase, passed: true }
}

const server = createServer(async (request, response) => {
  try {
    let body = ''
    for await (const chunk of request) body += chunk
    const result = await checkpoint(JSON.parse(body))
    response.writeHead(200, { 'Content-Type': 'application/json' })
    response.end(JSON.stringify(result))
  } catch (error) {
    const state = video ? await mediaState().catch(() => null) : null
    if (state?.source) state.source = new URL(state.source).pathname
    const playback = snapshot ? safePlayback(snapshot) : null
    const mediaEvents = await page.evaluate(() => window.__twoCommandMediaEvents ?? []).catch(() => [])
    await writeFile(`${evidence}/failure.json`, JSON.stringify({
      browser: browserInfo, error: error.message, state, playback, playbackSnapshots, clientControls, networkInterruptions, mediaEvents: [...previousMediaEvents, ...mediaEvents], mediaResponses, apiResponses, nativeResponseEvidence,
    }, null, 2))
    await page.screenshot({ path: `${evidence}/failure.png` }).catch(() => {})
    response.writeHead(500, { 'Content-Type': 'application/json' })
    response.end(JSON.stringify({ error: error.message }))
  }
})
server.listen(0, '127.0.0.1', async () => {
  await writeFile('/work/browser-port', String(server.address().port))
})
process.on('SIGTERM', () => {
  browser.close().then(() => server.close()).catch(() => process.exitCode = 1)
})
