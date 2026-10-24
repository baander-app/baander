import assert from 'node:assert/strict'
import { createHash } from 'node:crypto'
import { createServer } from 'node:http'
import { mkdir, writeFile } from 'node:fs/promises'
import { chromium, firefox } from 'playwright-core'

const evidence = '/work/evidence'
const requestedEngine = process.env.BAANDER_TEST_BROWSER_ENGINE ?? 'chromium'
const browserInfo = { engine: requestedEngine, version: null }
let browser
let context
try {
  assert(['chromium', 'firefox'].includes(requestedEngine), `Unsupported browser engine: ${requestedEngine}`)
  await mkdir(evidence, { recursive: true })
  const engine = requestedEngine === 'firefox' ? firefox : chromium
  browser = await engine.launch(requestedEngine === 'chromium'
    ? { channel: 'chromium', headless: true, args: ['--mute-audio'] } : { headless: true })
  browserInfo.engine = engine.name()
  browserInfo.version = browser.version()
  context = await browser.newContext({ viewport: { width: 1280, height: 800 } })
} catch (error) {
  await mkdir(evidence, { recursive: true })
  await writeFile(`${evidence}/failure.json`, JSON.stringify({ requestedEngine, browser: browserInfo, error: error.message }, null, 2))
  if (browser) await browser.close().catch(() => {})
  throw error
}
await context.addInitScript(() => {
  const ranges = value => Array.from({ length: value.length }, (_, index) => [value.start(index), value.end(index)])
  window.__twoCommandMediaState = element => ({
    at: Date.now(), probe: element.dataset.twoCommandProbe === 'true',
    time: element.currentTime, paused: element.paused, ended: element.ended, playbackRate: element.playbackRate, ready: element.readyState,
    seeking: element.seeking, network: element.networkState,
    duration: Number.isFinite(element.duration) ? element.duration : null,
    buffered: ranges(element.buffered), seekable: ranges(element.seekable),
    decodedFrames: element.getVideoPlaybackQuality?.().totalVideoFrames ?? element.webkitDecodedFrameCount ?? null,
    errorCode: element.error?.code ?? null,
    source: element.currentSrc ? new URL(element.currentSrc).pathname : null,
  })
  window.__twoCommandMediaEvents = []
  window.__twoCommandSigningReceipts = []
  window.__twoCommandObserveSigningReceipts = false
  window.__twoCommandSeekReceipts = []
  window.__twoCommandSeekRequestOrders = []
  window.__twoCommandObserveSeekReceipts = false
  window.__twoCommandObservationOrder = 0
  const open = XMLHttpRequest.prototype.open
  XMLHttpRequest.prototype.open = function (method, url) {
    const result = open.apply(this, arguments)
    const path = new URL(url, window.location.href).pathname
    const signing = path === '/api/stream/sign'
    const position = /^\/api\/transcode\/sessions\/[^/]+\/position$/.test(path)
    if (String(method).toUpperCase() === 'POST' && (signing || position)) {
      const requestedAt = Date.now()
      const requestedOrder = ++window.__twoCommandObservationOrder
      if (position && window.__twoCommandObserveSeekReceipts) {
        const requests = window.__twoCommandSeekRequestOrders
        requests.push({ requestedAt, requestedOrder })
        if (requests.length > 20) requests.shift()
      }
      const observe = () => {
        if (this.readyState !== XMLHttpRequest.DONE) return
        this.removeEventListener('readystatechange', observe)
        if (this.status < 200 || this.status >= 300 || !(signing
          ? window.__twoCommandObserveSigningReceipts : window.__twoCommandObserveSeekReceipts)) return
        try {
          const value = JSON.parse(this.responseText)
          if (position) {
            if (typeof value.data?.requestId !== 'string') return
            const receipts = window.__twoCommandSeekReceipts
            receipts.push({ requestId: value.data.requestId, receivedAt: Date.now(),
              receivedOrder: ++window.__twoCommandObservationOrder,
              ...(Number.isSafeInteger(value.data.acceptedGeneration) && value.data.acceptedGeneration > 0
                ? { acceptedGeneration: value.data.acceptedGeneration } : {}) })
            if (receipts.length > 20) receipts.shift()
            return
          }
          const receipts = window.__twoCommandSigningReceipts
          const previous = receipts.findLast(item => item.jobId === value.jobId)
          if (typeof value.jobId !== 'string' || typeof value.sessionId !== 'string'
            || !Number.isSafeInteger(value.generation) || !Number.isSafeInteger(value.revision)
            || typeof value.renditionSet !== 'string' || typeof value.ready !== 'boolean'
            || typeof value.status !== 'string' || (previous
              && (value.generation < previous.generation || value.revision < previous.revision))) return
          receipts.push({ requestedAt, requestedOrder, receivedAt: Date.now(),
            receivedOrder: ++window.__twoCommandObservationOrder, jobId: value.jobId, sessionId: value.sessionId,
            generation: value.generation, revision: value.revision, renditionSet: value.renditionSet,
            ready: value.ready, status: value.status })
          if (receipts.length > 100) receipts.shift()
        } catch { /* Malformed responses cannot authorize media replacement or seek continuation. */ }
      }
      // Axios installs its loadend handler after open returns. Observe the real
      // response at DONE before Axios can replace the current attachment.
      this.addEventListener('readystatechange', observe)
    }
    return result
  }
  const removeAttribute = Element.prototype.removeAttribute
  Element.prototype.removeAttribute = function (name) {
    if (this instanceof HTMLVideoElement && name.toLowerCase() === 'src' && this.hasAttribute('src')) {
      const events = window.__twoCommandMediaEvents
      events.push({ event: 'source-detached', ...window.__twoCommandMediaState(this) })
      if (events.length > 200) events.shift()
    }
    return removeAttribute.call(this, name)
  }
  for (const name of ['loadstart', 'loadedmetadata', 'loadeddata', 'canplay', 'playing', 'pause', 'seeking', 'seeked', 'waiting', 'stalled', 'timeupdate', 'error', 'emptied', 'ended']) {
    document.addEventListener(name, event => {
      if (!(event.target instanceof HTMLVideoElement)) return
      const events = window.__twoCommandMediaEvents
      events.push({ event: name, ...window.__twoCommandMediaState(event.target) })
      if (events.length > 200) events.shift()
    }, true)
  }
})
const page = await context.newPage()
let shortSigningLifetime = true
await page.route('**/api/stream/sign', async route => {
  if (!shortSigningLifetime || route.request().method() !== 'POST') {
    await route.continue()
    return
  }
  const response = await route.fetch({
    postData: { ...route.request().postDataJSON(), expiresInSeconds: 12 },
    timeout: 4500,
  })
  await route.fulfill({ response })
})
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
const subtitleObservations = []
const previousMediaEvents = []
const networkInterruptions = []
const manifestEvidence = []
const mediaRequestOrder = []
const capabilityRenewals = []
const progressOutages = []
const statusOutages = []
const seekOutages = []
const movieCompletions = []
const signingRequests = new WeakMap()
const attachedCapabilities = new Map()
function capabilityEvidence(url) {
  const expires = Number(url.searchParams.get('exp'))
  return {
    expires: Number.isSafeInteger(expires) && expires > 0 ? expires : null,
    capability: createHash('sha256').update(url.href).digest('hex'),
  }
}
const requestAttachments = new WeakMap()
let attachmentNumber = 0
let attachedRenditionSet = null
function currentSource(state) {
  return state.source?.startsWith('blob:')
    ? attachedRenditionSet === snapshot.renditionSet
    : new URL(state.source).searchParams.get('set') === snapshot.renditionSet
}
function sanitizedManifest(body, base) {
  const clean = value => {
    const parsed = new URL(value, base)
    const query = new URLSearchParams()
    for (const key of ['generation', 'index', 'tier']) {
      if (parsed.searchParams.has(key)) query.set(key, parsed.searchParams.get(key))
    }
    return parsed.pathname + (query.size ? `?${query}` : '')
  }
  return body.split('\n').map(line => line.startsWith('#')
    ? line.replace(/URI="([^"]+)"/g, (_match, value) => `URI="${clean(value)}"`)
    : line.trim() ? clean(line.trim()) : line).join('\n')
}
let savingTimeline = false
const nativeResponseEvidence = []
const nativeResponseKeys = new Set()
const nativeBodyLimit = 32 * 1024 * 1024
let nativeBodyReads = 0
async function captureNativeResponse(response, url) {
  const match = /^\/api\/transcode\/([^/]+)\/tracks\/(video|audio)\/(init\.mp4|segment\.m4s)$/.exec(url.pathname)
  const generation = url.searchParams.get('generation')
  const index = url.searchParams.get('index')
  if (!match || !snapshot?.ready || !snapshot.renditionSet.split(',').includes(`${match[1]}.${generation}`)
    || response.status() !== 200 || (match[3] === 'segment.m4s' && !['0', '1', '2'].includes(index))) return
  const resource = match[3] === 'init.mp4' ? 'init' : 'segment'
  const key = `${match[1]}:${generation}:${match[2]}:${resource}:${index ?? ''}`
  if (nativeResponseKeys.has(key) || nativeResponseEvidence.length >= 32) return
  nativeResponseKeys.add(key)
  const item = { at: Date.now(), jobId: match[1], generation, track: match[2], resource, index, status: 200, state: 'pending' }
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
        // Each track's init plus its first three fragments, at most 32 MiB hashed.
        if (nativeBodyReads >= 8 || Number(headers['content-length'] ?? 0) > nativeBodyLimit / 8) {
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
        if (body.length > nativeBodyLimit / 8) {
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
      playbackSnapshots, playbackControls, clientControls, subtitleObservations, networkInterruptions, manifestEvidence, mediaRequestOrder, capabilityRenewals, progressOutages, statusOutages, seekOutages, movieCompletions, mediaEvents: [...previousMediaEvents, ...mediaEvents],
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
  if (url.pathname === '/api/stream/sign') signingRequests.set(request, Date.now())
  if (url.pathname.startsWith('/api/transcode/') && /(?:\.m3u8|\/init\.mp4|\/segment\.m4s|\.vtt)$/.test(url.pathname)) {
    if (url.pathname.endsWith('/master.m3u8')) {
      attachmentNumber++
      attachedRenditionSet = url.searchParams.get('set')
      attachedCapabilities.set(attachmentNumber, { url: url.href, at: Date.now(), attachment: attachmentNumber, ...capabilityEvidence(url) })
    }
    requestAttachments.set(request, attachmentNumber)
    mediaRequestOrder.push({ at: Date.now(), attachment: attachmentNumber, renditionSet: attachedRenditionSet, path: url.pathname,
      track: /\/tracks\/(video|audio)\//.exec(url.pathname)?.[1] ?? null,
      generation: url.searchParams.get('generation'), index: url.searchParams.get('index'), ...capabilityEvidence(url) })
  }
  if (request.method() !== 'POST' || !/^\/api\/transcode\/sessions\/[^/]+\/position$/.test(url.pathname)) return
  try {
    const value = request.postDataJSON()
    appendBounded(playbackControls, { at: Date.now(), ...Object.fromEntries(
      ['action', 'position', 'generation', 'revision', 'requestId'].map(key => [key, value[key]]),
    ) }, 100)
  } catch { /* An interrupted request may have no readable JSON body. */ }
})
page.on('response', async response => {
  const url = new URL(response.url())
  if (url.pathname.startsWith('/api/')) {
    appendBounded(apiResponses, { at: Date.now(), path: url.pathname, status: response.status() })
  }
  if (url.pathname === '/api/stream/sign' && response.ok()) {
    const receivedAt = Date.now()
    try {
      const next = await response.json()
      const accepted = !snapshot || next.jobId !== snapshot.jobId
        || (next.generation >= snapshot.generation && next.revision >= snapshot.revision)
      appendBounded(playbackSnapshots, { at: Date.now(), receivedAt, requestedAt: signingRequests.get(response.request()), accepted, ...safePlayback(next),
        ...(typeof next.url === 'string' ? capabilityEvidence(new URL(next.url, response.url())) : {}) }, 100)
      if (accepted) {
        snapshot = next
      }
      await savePlaybackTimeline()
    } catch { /* A web restart may abort an already received response. */ }
  }
  if (url.pathname.startsWith('/api/transcode/') && url.pathname.endsWith('.m3u8') && response.ok()) {
    const attachment = requestAttachments.get(response.request()) ?? null
    try {
      manifestEvidence.push({ at: Date.now(), attachment, path: url.pathname,
        generation: url.searchParams.get('generation'), body: sanitizedManifest(await response.text(), response.url()) })
    } catch { /* An execution replacement can cancel a manifest response body. */ }
  }
  if (url.pathname.startsWith('/api/transcode/') && /(?:\.m3u8|\/init\.mp4|\/segment\.m4s)$/.test(url.pathname)) {
    const track = /\/tracks\/(video|audio)\//.exec(url.pathname)?.[1] ?? null
    appendBounded(mediaResponses, { at: Date.now(), attachment: requestAttachments.get(response.request()) ?? null, path: url.pathname, track, generation: url.searchParams.get('generation'), index: url.searchParams.get('index'), status: response.status(), ...capabilityEvidence(url) })
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
  return video.evaluate(element => {
    const state = { ...window.__twoCommandMediaState(element),
      connected: element.isConnected, time: element.currentTime, paused: element.paused,
      ready: element.readyState, error: element.error?.code ?? null, source: element.currentSrc,
      nativeHls: element.canPlayType('application/vnd.apple.mpegurl'),
      h264: element.canPlayType('video/mp4; codecs="avc1.42E01E"') }
    // Firefox can expose a decoded paused frame while its presentation counter is zero.
    if (state.paused && state.ready >= 2 && !state.seeking && state.error === null
      && element.videoWidth > 0 && element.videoHeight > 0) {
      try {
        const canvas = document.createElement('canvas')
        canvas.width = 16
        canvas.height = 9
        const context = canvas.getContext('2d', { willReadFrequently: true })
        context.drawImage(element, 0, 0, canvas.width, canvas.height)
        const pixels = context.getImageData(0, 0, canvas.width, canvas.height).data
        let minimum = 255, maximum = 0, chromaticPixels = 0, opaquePixels = 0
        for (let index = 0; index < pixels.length; index += 4) {
          const rgb = [pixels[index], pixels[index + 1], pixels[index + 2]]
          const luminance = (rgb[0] + rgb[1] + rgb[2]) / 3
          minimum = Math.min(minimum, luminance)
          maximum = Math.max(maximum, luminance)
          if (Math.max(...rgb) - Math.min(...rgb) > 32) chromaticPixels++
          if (pixels[index + 3] === 255) opaquePixels++
        }
        state.pausedFrame = { at: Date.now(), source: new URL(state.source).pathname, time: state.time,
          width: element.videoWidth, height: element.videoHeight, luminanceRange: maximum - minimum,
          chromaticPixels, opaquePixels,
          stable: element.currentSrc === state.source && element.src === state.source && element.currentTime === state.time
            && element.paused && !element.seeking && element.error === null }
      } catch (error) { state.pausedFrame = { error: error.name } }
    }
    return state
  })
}

function hasDecodedFrame(state) {
  if (state.decodedFrames > 0) return true
  const frame = state.pausedFrame
  return state.paused && state.ready >= 2 && !state.seeking && state.error === null && frame?.stable
    && frame.source === new URL(state.source).pathname && frame.time === state.time
    && frame.width > 0 && frame.height > 0 && frame.luminanceRange >= 32
    && frame.chromaticPixels >= 8 && frame.opaquePixels === 144
}

async function subtitleState(selectDialogue = false) {
  return video.evaluate((element, select) => {
    const tracks = Array.from(element.textTracks)
    const dialogue = tracks.find(track => track.label.includes('Dialogue'))
    if (select && dialogue) {
      for (const track of tracks) track.mode = track === dialogue ? 'showing' : 'disabled'
    }
    const cues = values => Array.from(values ?? [], cue => ({
      start: cue.startTime, end: cue.endTime, text: 'text' in cue ? cue.text : null,
    }))
    return {
      time: element.currentTime,
      tracks: tracks.map(track => ({
        label: track.label, language: track.language, kind: track.kind, mode: track.mode,
        cueCount: track.cues?.length ?? null, activeCues: cues(track.activeCues),
      })),
    }
  }, selectDialogue)
}

async function observeSubtitles(phase, expectedText) {
  // Native HLS capability does not establish native subtitle support. Retain
  // that distinction explicitly instead of treating an empty track list as a pass.
  let state = await subtitleState(true)
  const engine = (await mediaState()).source.startsWith('blob:') ? 'mse-hls' : 'native-hls'
  const deadline = Date.now() + 5000
  while (state.tracks.length < 3 && Date.now() < deadline) {
    await new Promise(resolve => setTimeout(resolve, 100))
    state = await subtitleState(true)
  }
  if (state.tracks.length === 0 && engine === 'native-hls') {
    if (subtitleObservations.some(observation => observation.status === 'active-cue')) {
      subtitleObservations.push({ phase, engine, status: 'failed', expectedText, ...state })
      await savePlaybackTimeline()
      assert.fail('Previously available native subtitle tracks disappeared after the generation changed')
    }
    const limitation = 'The native HLS engine exposed no TextTracks; subtitle cue activation and visible rendering remain unverified.'
    subtitleObservations.push({ phase, engine, status: 'native-tracks-unavailable', limitation, ...state })
    console.warn(`${phase}: ${limitation}`)
    await savePlaybackTimeline()
    return
  }
  try {
    assert.equal(state.tracks.length, 3, 'HLS playback must retain all three independently identified subtitle tracks')
    assert(state.tracks.some(track => track.label.includes('Forced')), 'The duplicate-language forced track must remain selectable')
    assert(state.tracks.some(track => track.label.includes('Unlabelled')), 'The track without language metadata must remain selectable')
    await until(async () => {
      state = await subtitleState(true)
      return state.tracks.some(track => track.label.includes('Dialogue') && track.mode === 'showing'
        && track.activeCues.some(cue => cue.text?.includes(expectedText)))
    }, `the Dialogue track to activate "${expectedText}"`, 10000)
    subtitleObservations.push({ phase, engine, status: 'active-cue', expectedText, ...state })
    // The cue assertion establishes TextTrack activation; this screenshot supports
    // inspection of painted captions without claiming a pixel-level assertion.
    await page.screenshot({ path: `${evidence}/subtitles-${phase}.png` })
  } catch (error) {
    subtitleObservations.push({ phase, engine, status: 'failed', expectedText, ...state })
    throw error
  } finally {
    await savePlaybackTimeline()
  }
}

async function pausedAt(position, minimumGeneration) {
  await until(async () => {
    const state = await mediaState()
    return state.connected && state.paused && state.ready >= 2 && hasDecodedFrame(state) && !state.seeking
      && snapshot?.ready && snapshot.status === 'paused' && snapshot.generation >= minimumGeneration
      && Number.isFinite(snapshot.startTime)
      && state.error === null && state.seekable.some(([start, end]) => state.time >= start && state.time <= end)
      && Math.abs(snapshot.startTime + state.time - position) < 2
      && currentSource(state)
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

async function recordClientControl(action, started, details = {}) {
  const state = await mediaState()
  const { source, ...safeState } = state
  clientControls.push({ action, at: Date.now(), elapsedMs: Date.now() - started, ...details,
    state: { ...safeState, source: source ? new URL(source).pathname : null },
    playback: safePlayback(snapshot) })
  await savePlaybackTimeline()
}

async function resumeAndDecode(owner, action, milliseconds = 45000) {
  const started = Date.now()
  const before = await mediaState()
  await video.evaluate(element => element.play())
  await until(async () => {
    const state = await mediaState()
    return state.connected && !state.paused && state.ready >= 2 && state.error === null
      && state.time > before.time + 1 && state.decodedFrames > before.decodedFrames
      && snapshot?.ready && snapshot.status === 'running'
      && currentSource(state)
  }, `${action} to decode current signed HLS and advance`, milliseconds)
  samePlayback(owner)
  await recordClientControl(action, started)
}

async function pausePlayback(owner, action, milliseconds = 45000) {
  const started = Date.now()
  await video.evaluate(element => element.pause())
  await until(async () => (await mediaState()).paused && snapshot?.status === 'paused', 'native pause to commit', milliseconds)
  samePlayback(owner)
  await recordClientControl(action, started)
}

async function renewPlaybackCapabilities(owner) {
  const deadline = Date.now() + 60000
  const waitFor = (check, description, milliseconds) => {
    assert(Date.now() < deadline, 'Capability renewal proof exceeded its total time budget')
    return until(check, description, Math.min(milliseconds, deadline - Date.now()))
  }
  const seekCount = () => playbackControls.filter(control => control.action === 'seek').length
  const seeksBefore = seekCount()
  const unchangedExecution = () => {
    samePlayback(owner)
    assert.equal(seekCount(), seeksBefore, 'Capability renewal must not submit source seeks')
  }
  const capabilitySet = capability => new URL(capability.url).searchParams.get('set')
  const hasDelivery = capability => mediaResponses.some(item => item.attachment === capability.attachment
    && item.status === 200 && item.path.endsWith('/master.m3u8'))
    && ['video', 'audio'].every(track => mediaResponses.some(item => item.attachment === capability.attachment
      && item.status === 200 && item.track === track && item.path.endsWith('/segment.m4s')
      && item.expires === capability.expires))
  const readyCapability = async () => {
    let selected
    await waitFor(async () => {
      unchangedExecution()
      const candidate = attachedCapabilities.get(attachmentNumber)
      const state = await mediaState()
      if (candidate?.expires && (candidate.expires + 1) * 1000 > Date.now() + 1000
        && capabilitySet(candidate) === snapshot.renditionSet && hasDelivery(candidate)
        && state.ready >= 2 && hasDecodedFrame(state) && !state.seeking
        && state.error === null && currentSource(state)) selected = candidate
      return Boolean(selected)
    }, 'a decoded attachment with genuine short-lived signed delivery', 15000)
    return selected
  }
  let pausedCursor
  const crossDeadline = async mode => {
    for (let attempt = 1; attempt <= 3; attempt++) {
      const original = await readyCapability()
      const renditionSet = capabilitySet(original)
      const before = await mediaState()
      assert.equal(before.paused, mode === 'paused')
      if (mode === 'paused') {
        pausedCursor ??= snapshot.startTime + before.time
        assert(Math.abs(snapshot.startTime + before.time - pausedCursor) < 0.1,
          'Publication changes must preserve the original paused cursor')
      }
      assert(Date.now() < (original.expires + 1) * 1000 && original.expires * 1000 - Date.now() <= 12000,
        'Renewal must begin before the actually attached short-lived capability expires')
      const proof = { mode, attempt, status: 'observing', startedAt: Date.now(), renditionSet, original: {
        attachment: original.attachment, expires: original.expires, capability: original.capability,
      }, before: { time: before.time, decodedFrames: before.decodedFrames, paused: before.paused } }
      capabilityRenewals.push(proof)
      const publicationChanged = () => {
        unchangedExecution()
        if (snapshot.renditionSet === renditionSet) return false
        proof.status = 'superseded-by-publication'
        proof.publicationChange = { at: Date.now(), renditionSet: snapshot.renditionSet }
        return true
      }
      await waitFor(() => publicationChanged() || Date.now() >= (original.expires + 1) * 1000,
        `the ${mode} attachment deadline to pass its final valid integer second`, 14000)
      if (publicationChanged()) continue
      const refusal = await context.request.get(original.url, { timeout: Math.min(5000, Math.max(1, deadline - Date.now())) })
      proof.refusal = { at: Date.now(), status: refusal.status() }
      await refusal.dispose()
      assert.equal(proof.refusal.status, 403, 'The original attached capability must be refused after expiry')
      if (publicationChanged()) continue
      let renewed
      let runningBaseline
      await waitFor(async () => {
        if (publicationChanged()) return true
        const candidate = attachedCapabilities.get(attachmentNumber)
        const state = await mediaState()
        if (mode === 'running' && candidate?.expires > original.expires && hasDelivery(candidate)
          && capabilitySet(candidate) === renditionSet && state.ready >= 2 && !state.seeking && state.error === null
          && runningBaseline?.attachment !== candidate.attachment) {
          runningBaseline = { attachment: candidate.attachment, at: Date.now(),
            time: state.time, decodedFrames: state.decodedFrames }
        }
        const valid = candidate?.expires > original.expires && candidate.expires * 1000 > Date.now()
          && capabilitySet(candidate) === renditionSet && hasDelivery(candidate)
          && state.connected && state.ready >= 2 && state.error === null && !state.seeking && hasDecodedFrame(state)
          && currentSource(state) && state.paused === before.paused
          && (mode === 'paused' ? Math.abs(snapshot.startTime + state.time - pausedCursor) < 0.1
            : runningBaseline && state.time > before.time + 1 && state.time > runningBaseline.time + 1
              && state.decodedFrames > runningBaseline.decodedFrames)
        if (valid) renewed = { candidate, state }
        return valid
      }, `${mode} playback to use renewed signed media across the old deadline`, 15000)
      if (publicationChanged()) continue
      assert(renewed, 'A successful renewal interval must retain its original rendition set')
      proof.status = 'passed'
      if (runningBaseline) proof.runningBaseline = runningBaseline
      proof.renewed = { attachment: renewed.candidate.attachment, expires: renewed.candidate.expires,
        capability: renewed.candidate.capability, requestedAt: renewed.candidate.at }
      proof.after = { at: Date.now(), time: renewed.state.time,
        decodedFrames: renewed.state.decodedFrames, paused: renewed.state.paused, pausedFrame: renewed.state.pausedFrame }
      await recordClientControl(`capability-renewal-${mode}`, proof.startedAt)
      return
    }
    assert.fail(`Publication did not leave a stable ${mode} renewal interval within three attempts`)
  }
  await pausePlayback(owner, 'capability-renewal-pause', Math.max(1, Math.min(15000, deadline - Date.now())))
  await crossDeadline('paused')
  await resumeAndDecode(owner, 'capability-renewal-resume', Math.max(1, Math.min(15000, deadline - Date.now())))
  await crossDeadline('running')
  shortSigningLifetime = false
  let normalBaseline
  await waitFor(async () => {
    unchangedExecution()
    const capability = attachedCapabilities.get(attachmentNumber)
    const state = await mediaState()
    if (!(capability?.expires * 1000 > Date.now() + 60000 && hasDelivery(capability))
      || capabilitySet(capability) !== snapshot.renditionSet
      || !state.connected || state.paused || state.ready < 2 || state.seeking || state.error !== null) return false
    if (normalBaseline?.attachment !== capability.attachment) {
      normalBaseline = { attachment: capability.attachment, time: state.time, decodedFrames: state.decodedFrames }
    }
    return state.time > normalBaseline.time + 1 && state.decodedFrames > normalBaseline.decodedFrames
  }, 'a real attachment using the restored normal signing lifetime', 20000)
  await recordClientControl('capability-renewal-normal-lifetime', Date.now())
}

async function proveProgressOutages(owner) {
  const deadline = Date.now() + 60000
  const remaining = maximum => {
    assert(Date.now() < deadline, 'Progress outage proof exceeded its total time budget')
    return Math.max(1, Math.min(maximum, deadline - Date.now()))
  }
  const waitFor = (check, description) => until(check, description, remaining(15000))
  const seekCount = () => playbackControls.filter(control => control.action === 'seek').length
  const seeksBefore = seekCount()
  const unchangedExecution = () => {
    samePlayback(owner)
    assert.equal(seekCount(), seeksBefore, 'A progress outage must not submit source seeks')
  }
  const positionRoute = `**/api/transcode/sessions/${owner.sessionId}/position`
  for (const mode of ['before-forwarding', 'accepted-response-loss']) {
    let passed = false
    for (let attempt = 1; attempt <= 3 && !passed; attempt++) {
      let baseline
      let before
      await waitFor(async () => {
        unchangedExecution()
        const state = await mediaState()
        const capability = attachedCapabilities.get(attachmentNumber)
        if (!state.connected || state.paused || state.ready < 2 || state.seeking || state.error !== null
          || state.decodedFrames <= 0 || !snapshot?.ready || snapshot.status !== 'running'
          || !capability || new URL(capability.url).searchParams.get('set') !== snapshot.renditionSet
          || !currentSource(state)) return false
        if (baseline?.attachment !== attachmentNumber) baseline = { attachment: attachmentNumber, ...state }
        if (state.time > baseline.time + 0.2 && state.decodedFrames > baseline.decodedFrames) before = state
        return Boolean(before)
      }, 'decoded running media before a progress transport failure')
      const originalAttachment = attachmentNumber
      const renditionSet = snapshot.renditionSet
      const proof = { mode, attempt, status: 'observing', startedAt: Date.now(), attachment: originalAttachment,
        renditionSet, before: { time: before.time, decodedFrames: before.decodedFrames } }
      progressOutages.push(proof)
      let routeError
      let failedRequest
      let failureObserved = false
      let finalBaseline
      const pendingRoutes = new Set()
      const requestFailed = request => {
        if (request === failedRequest) {
          failureObserved = true
          proof.failureObservedAt = Date.now()
        }
      }
      const interceptProgress = route => {
        const task = (async () => {
          const request = route.request()
          const payload = request.method() === 'POST' ? request.postDataJSON() : null
          if (payload?.action !== 'progress') {
            await route.continue()
            return
          }
          const submitted = { at: Date.now(), position: payload.position,
            generation: payload.generation, revision: payload.revision }
          if (!failedRequest) {
            failedRequest = request
            proof.fault = submitted
            if (mode === 'accepted-response-loss') {
              assert(snapshot.position < submitted.position,
                'The lost-response progress cursor must advance the previously observed canonical position')
              proof.canonicalBefore = { at: Date.now(), position: snapshot.position, revision: snapshot.revision }
              const acceptanceDeadline = submitted.at + 4000
              const response = await route.fetch({ timeout: remaining(3500) })
              proof.accepted = { at: Date.now(), status: response.status() }
              await response.dispose()
              assert.equal(proof.accepted.status, 200, 'The lost progress response must come from the real server')
              assert(Date.now() < acceptanceDeadline, 'Real progress forwarding must leave time to observe server acceptance')
              await until(() => {
                if (superseded()) return true
                const canonical = playbackSnapshots.find(item => item.accepted && item.at >= proof.accepted.at
                  && item.jobId === owner.jobId && item.sessionId === owner.sessionId
                  && item.generation === owner.generation && item.status === 'running'
                  && item.renditionSet === renditionSet && item.position >= submitted.position
                  && item.revision > submitted.revision)
                if (canonical) proof.acceptedCanonical = { at: canonical.at,
                  position: canonical.position, revision: canonical.revision }
                return Boolean(canonical)
              }, 'authenticated state to prove the lost-response progress write was applied',
              remaining(acceptanceDeadline - Date.now()))
            }
            await route.abort('failed')
            proof.abortedAt = Date.now()
            if (mode === 'accepted-response-loss') assert(proof.abortedAt - submitted.at < 4500,
              'Forwarding, canonical acceptance and response loss must finish below the client timeout')
          } else {
            const response = await route.fetch({ timeout: remaining(4500) })
            if (response.status() === 200 && !proof.success) {
              proof.success = { ...submitted, responseAt: Date.now(), status: response.status() }
            }
            await route.fulfill({ response })
          }
        })().catch(async error => {
          routeError = error
          await route.abort().catch(() => {})
        })
        pendingRoutes.add(task)
        task.finally(() => pendingRoutes.delete(task))
        return task
      }
      const freshPublications = () => playbackSnapshots.filter(item => item.accepted && item.ready
        && item.at >= proof.startedAt && item.jobId === owner.jobId && item.sessionId === owner.sessionId
        && item.generation === owner.generation)
      const publicationChange = () => freshPublications().find(item => item.renditionSet !== renditionSet)
      const checkMediaEvents = async () => {
        const mediaEvents = await page.evaluate(() => window.__twoCommandMediaEvents ?? [])
        const changed = publicationChange()
        const boundary = changed ? changed.receivedAt ?? changed.at : Infinity
        assert(!mediaEvents.some(event => !event.probe && event.at >= proof.startedAt
          && (event.event === 'error' || (event.event === 'emptied' && event.at < boundary))),
        'A progress failure must not tear down decoded media before a fresh publication change')
      }
      const superseded = () => {
        unchangedExecution()
        if (routeError) throw routeError
        if (proof.preserved) return false
        const changed = publicationChange()
        if (!changed) return false
        proof.status = 'superseded-by-publication'
        proof.publication = { at: changed.at, renditionSet: changed.renditionSet }
        return true
      }
      page.on('requestfailed', requestFailed)
      await page.route(positionRoute, interceptProgress)
      try {
        await waitFor(async () => {
          await checkMediaEvents()
          if (superseded()) return true
          if (!failureObserved) return false
          const sampledAttachment = attachmentNumber
          const state = await mediaState()
          if (superseded()) {
            await checkMediaEvents()
            return true
          }
          if (sampledAttachment !== attachmentNumber) return false
          assert.equal(state.error, null, 'A progress failure must not report a media error')
          if (!proof.preserved) {
            assert.equal(attachmentNumber, originalAttachment, 'A progress failure must initially retain its attachment')
            assert.equal(state.source, before.source, 'A progress failure must initially retain its media source')
            assert(state.connected && !state.paused && state.ready >= 2 && !state.seeking,
              'A failed progress request must leave decoded running media usable')
            proof.faultBaseline ??= { at: Date.now(), time: state.time, decodedFrames: state.decodedFrames }
            if (!(state.time > proof.faultBaseline.time + 0.2
              && state.decodedFrames > proof.faultBaseline.decodedFrames)) return false
            proof.preserved = { at: Date.now(), attachment: originalAttachment, renditionSet,
              time: state.time, decodedFrames: state.decodedFrames }
          }
          const publications = freshPublications()
          let previousSet = renditionSet
          let previousAttachmentAt = attachedCapabilities.get(originalAttachment).at
          for (const [number, capability] of attachedCapabilities) {
            if (number <= originalAttachment) continue
            const set = new URL(capability.url).searchParams.get('set')
            assert(set !== previousSet && publications.some(item => item.renditionSet === set
              && (item.receivedAt ?? item.at) > previousAttachmentAt
              && (item.receivedAt ?? item.at) <= capability.at),
            'Every post-failure replacement must follow an authenticated rendition change')
            previousSet = set
            previousAttachmentAt = capability.at
          }
          if (attachmentNumber === originalAttachment) assert.equal(state.source, before.source,
            'An unchanged attachment must retain its decoded media source')
          if (!proof.success || proof.success.position <= proof.fault.position) return false
          const capability = attachedCapabilities.get(attachmentNumber)
          const delivered = capability && new URL(capability.url).searchParams.get('set') === snapshot.renditionSet
            && mediaResponses.some(item => item.attachment === attachmentNumber && item.status === 200
              && item.path.endsWith('/master.m3u8'))
            && ['video', 'audio'].every(track => mediaResponses.some(item => item.attachment === attachmentNumber
              && item.status === 200 && item.track === track && item.path.endsWith('/segment.m4s')))
          if (!(state.connected && !state.paused && state.ready >= 2 && !state.seeking && currentSource(state)
            && snapshot?.ready && snapshot.status === 'running' && delivered)) return false
          if (finalBaseline?.attachment !== attachmentNumber) finalBaseline = { at: Date.now(),
            attachment: attachmentNumber, time: state.time, decodedFrames: state.decodedFrames }
          const canonical = publications.find(item => item.at >= proof.success.responseAt
            && item.status === 'running' && item.renditionSet === snapshot.renditionSet
            && item.position >= proof.success.position)
          if (!(state.time > finalBaseline.time + 0.2 && state.decodedFrames > finalBaseline.decodedFrames
            && state.time > before.time + 1 && canonical)) return false
          proof.canonical = { at: canonical.at, position: canonical.position, revision: canonical.revision }
          proof.finalBaseline = finalBaseline
          proof.after = { at: Date.now(), attachment: attachmentNumber, renditionSet: snapshot.renditionSet,
            time: state.time, decodedFrames: state.decodedFrames }
          proof.status = 'passed'
          passed = true
          return true
        }, `${mode} progress failure to preserve decoding and recover genuine writes`)
      } finally {
        await page.unroute(positionRoute, interceptProgress)
        await Promise.all([...pendingRoutes])
        page.off('requestfailed', requestFailed)
      }
      await checkMediaEvents()
      if (routeError) throw routeError
      await recordClientControl(`progress-outage-${mode}-${proof.status}`, proof.startedAt, { attempt })
    }
    assert(passed, `Progress outage ${mode} did not retain a stable rendition set within three attempts`)
  }
}

function qualifySourceDetachments(events, capabilities, publications, original) {
  const receipts = []
  let previous = original
  for (const event of events) {
    const preceding = capabilities.filter(item => item.attachment >= previous.attachment && item.at < event.at).at(-1) ?? previous
    const successor = capabilities.find(item => item.attachment > preceding.attachment && item.at >= event.at)
    const previousSet = new URL(preceding.url).searchParams.get('set')
    const eligible = publications.filter(item => item.receivedAt > preceding.at && item.receivedAt <= event.at
      && item.renditionSet !== previousSet)
    assert(eligible.length > 0, 'Source detachment must follow a new authenticated rendition change for its actual attachment')
    const publication = successor
      ? eligible.find(item => item.renditionSet === new URL(successor.url).searchParams.get('set')) : eligible.at(-1)
    assert(publication, 'Source detachment must match the rendition set of its successor master')
    receipts.push({ at: event.at, previousAttachment: preceding.attachment,
      publicationAt: publication.receivedAt, renditionSet: publication.renditionSet,
      successorAttachment: successor?.attachment ?? null, successorRequestedAt: successor?.at ?? null })
    previous = successor ?? preceding
  }
  return receipts
}

async function proveStatusOutages(owner) {
  const deadline = Date.now() + 60000
  const remaining = maximum => {
    assert(Date.now() < deadline, 'Status outage proof exceeded its total time budget')
    return Math.max(1, Math.min(maximum, deadline - Date.now()))
  }
  const waitFor = (check, description) => until(check, description, remaining(15000))
  const positionRoute = `**/api/transcode/sessions/${owner.sessionId}/position`
  assert.equal(shortSigningLifetime, false, 'Status outage observations require normal signing lifetimes')
  await page.evaluate(() => {
    window.__twoCommandSigningReceipts = []
    window.__twoCommandObserveSigningReceipts = true
  })
  try {
    for (const [action, mode] of [['pause', 'before-forwarding'], ['pause', 'accepted-response-loss'],
      ['resume', 'before-forwarding'], ['resume', 'accepted-response-loss']]) {
      const initialStatus = action === 'pause' ? 'running' : 'paused'
      const acceptedStatus = action === 'pause' ? 'paused' : 'running'
      const expectedStatus = mode === 'before-forwarding' ? initialStatus : acceptedStatus
      let before
      await waitFor(async () => {
        samePlayback(owner)
        const state = await mediaState()
        if (snapshot?.ready && snapshot.status === initialStatus && state.connected
          && state.paused === (initialStatus === 'paused') && state.ready >= 2 && hasDecodedFrame(state)
          && !state.seeking && state.error === null && currentSource(state)) before = state
        return Boolean(before)
      }, `decoded ${initialStatus} media before uncertain ${action}`)
      const originalAttachment = attachmentNumber
      const originalCapability = attachedCapabilities.get(originalAttachment)
      const renditionSet = new URL(originalCapability.url).searchParams.get('set')
      assert.equal(renditionSet, snapshot.renditionSet)
      const proof = { action, mode, expectedStatus, startedAt: Date.now(), status: 'observing',
        attachment: originalAttachment, renditionSet,
        before: { time: before.time, decodedFrames: before.decodedFrames, canonicalStatus: snapshot.status } }
      statusOutages.push(proof)
      const commands = () => playbackControls.filter(item => item.at >= proof.startedAt && item.action !== 'progress')
      const checkOwnership = () => {
        samePlayback(owner)
        assert(commands().length <= 1 && commands().every(item => item.action === action),
          'Uncertain status delivery must not replay a command, echo its opposite or submit a seek')
      }
      const publications = () => playbackSnapshots.filter(item => item.accepted && item.ready
        && item.at >= proof.startedAt && item.jobId === owner.jobId && item.sessionId === owner.sessionId
        && item.generation === owner.generation)
      const checkMedia = async () => {
        const { events, receipts } = await page.evaluate(() => ({
          events: window.__twoCommandMediaEvents ?? [], receipts: window.__twoCommandSigningReceipts ?? [],
        }))
        const fresh = receipts.filter(item => item.receivedAt >= proof.startedAt && item.ready
          && item.jobId === owner.jobId && item.sessionId === owner.sessionId && item.generation === owner.generation)
        proof.publications = fresh
        const changed = fresh.find(item => item.renditionSet !== renditionSet)
        const boundary = changed ? changed.receivedAt : Infinity
        assert(!events.some(event => !event.probe && event.at >= proof.startedAt
          && (event.event === 'error' || (event.event === 'emptied' && event.at < boundary))),
        'A status transport failure must not cause a media error or unexplained teardown')
        proof.sourceDetachments = qualifySourceDetachments(events.filter(event => !event.probe
          && event.at >= proof.startedAt && event.event === 'source-detached'),
        [...attachedCapabilities.values()], fresh, originalCapability)
        let previousSet = renditionSet
        let previousAt = originalCapability.at
        for (const [number, capability] of attachedCapabilities) {
          if (number <= originalAttachment) continue
          const set = new URL(capability.url).searchParams.get('set')
          assert(set !== previousSet && fresh.some(item => item.renditionSet === set
            && item.receivedAt > previousAt && item.receivedAt <= capability.at),
          'Every status reconciliation replacement must follow an authenticated rendition change')
          previousSet = set
          previousAt = capability.at
        }
        return proof.sourceDetachments.every(item => item.successorAttachment !== null)
      }
      let faultRequest
      let routeError
      let convergenceBaseline
      const pendingRoutes = new Set()
      const requestFailed = request => {
        if (request === faultRequest) proof.failureObservedAt = Date.now()
      }
      const interceptStatus = route => {
        const task = (async () => {
          const request = route.request()
          const payload = request.method() === 'POST' ? request.postDataJSON() : null
          if (payload?.action !== action || faultRequest) { await route.continue(); return }
          faultRequest = request
          proof.fault = { at: Date.now(), position: payload.position, revisionBefore: snapshot.revision }
          if (mode === 'accepted-response-loss') {
            const acceptanceDeadline = proof.fault.at + 4000
            const response = await route.fetch({ timeout: remaining(3500) })
            proof.accepted = { at: Date.now(), status: response.status() }
            await response.dispose()
            assert.equal(proof.accepted.status, 200, 'Lost status response must come from the real server')
            assert(Date.now() < acceptanceDeadline, 'Status forwarding must leave time to prove acceptance')
            await until(() => {
              checkOwnership()
              const canonical = publications().find(item => item.requestedAt >= proof.accepted.at
                && item.status === acceptedStatus && item.revision > proof.fault.revisionBefore
                && Math.abs(item.position - proof.fault.position) < 0.00001)
              if (canonical) proof.acceptedCanonical = { at: canonical.at, requestedAt: canonical.requestedAt,
                status: canonical.status, position: canonical.position, revision: canonical.revision }
              return Boolean(canonical)
            }, 'signed state to prove the status command was applied before response loss',
            remaining(acceptanceDeadline - Date.now()))
          }
          await route.abort('failed')
          proof.abortedAt = Date.now()
          assert(proof.abortedAt - proof.fault.at < 4500, 'Status response loss must finish below the client timeout')
        })().catch(async error => { routeError = error; await route.abort().catch(() => {}) })
        pendingRoutes.add(task)
        task.finally(() => pendingRoutes.delete(task))
        return task
      }
      page.on('requestfailed', requestFailed)
      await page.route(positionRoute, interceptStatus)
      try {
        await video.evaluate(async (element, { requested, allowAbort, timeout }) => {
          if (requested === 'pause') { element.pause(); return }
          let timer
          try {
            await Promise.race([element.play(), new Promise((_, reject) => {
              timer = setTimeout(() => reject(new Error('Status gesture did not start playback in time')), timeout)
            })])
          } catch (error) {
            if (!allowAbort || error.name !== 'AbortError') throw error
          } finally { clearTimeout(timer) }
        }, { requested: action, allowAbort: expectedStatus === 'paused', timeout: remaining(5000) })
        await waitFor(async () => {
          checkOwnership()
          if (routeError) throw routeError
          if (!await checkMedia()) return false
          if (!proof.failureObservedAt) return false
          const canonical = publications().filter(item => item.requestedAt >= proof.failureObservedAt
            && item.status === expectedStatus)
          if (canonical.length === 0 || snapshot?.status !== expectedStatus) return false
          const sampledAttachment = attachmentNumber
          const state = await mediaState()
          if (sampledAttachment !== attachmentNumber) return false
          assert.equal(state.error, null, 'Status reconciliation must not report a media error')
          const capability = attachedCapabilities.get(attachmentNumber)
          const delivered = capability && new URL(capability.url).searchParams.get('set') === snapshot.renditionSet
            && mediaResponses.some(item => item.attachment === attachmentNumber && item.status === 200
              && item.path.endsWith('/master.m3u8'))
            && ['video', 'audio'].every(track => mediaResponses.some(item => item.attachment === attachmentNumber
              && item.status === 200 && item.track === track && item.path.endsWith('/segment.m4s')))
          if (!(snapshot.ready && delivered && state.connected && state.ready >= 2 && hasDecodedFrame(state)
            && !state.seeking && currentSource(state) && state.paused === (expectedStatus === 'paused')
            && state.seekable.some(([start, end]) => state.time >= start && state.time <= end))) return false
          const position = snapshot.startTime + state.time
          const latest = canonical.at(-1)
          if (!convergenceBaseline || convergenceBaseline.attachment !== attachmentNumber) {
            convergenceBaseline = { at: Date.now(), attachment: attachmentNumber, position,
              time: state.time, decodedFrames: state.decodedFrames, canonicalAt: latest.at }
          }
          if (expectedStatus === 'paused') {
            assert(Math.abs(position - convergenceBaseline.position) < 0.1,
              'Canonical paused reconciliation must hold the converged local cursor')
            assert(!playbackControls.some(item => item.at >= convergenceBaseline.at && item.action === 'progress'),
              'Paused reconciliation must not report running progress')
            const pausedPolls = canonical.filter(item => item.at > convergenceBaseline.canonicalAt
              && item.requestedAt > convergenceBaseline.at)
            if (pausedPolls.length < 2) return false
            proof.pausedCanonicalPolls = pausedPolls.slice(0, 2).map(item => ({ at: item.at,
              requestedAt: item.requestedAt, status: item.status, revision: item.revision }))
          } else if (!(state.time > convergenceBaseline.time + 0.2
            && state.decodedFrames > convergenceBaseline.decodedFrames)) return false
          assert.equal(commands().length, 1, 'Each uncertain status gesture must submit exactly one explicit command')
          proof.status = 'passed'
          proof.canonical = { at: latest.at, requestedAt: latest.requestedAt,
            status: latest.status, position: latest.position, revision: latest.revision }
          proof.convergenceBaseline = convergenceBaseline
          proof.after = { at: Date.now(), attachment: attachmentNumber, renditionSet: snapshot.renditionSet,
            time: state.time, position, decodedFrames: state.decodedFrames, paused: state.paused, pausedFrame: state.pausedFrame }
          proof.commands = commands()
          return true
        }, `uncertain ${action} (${mode}) to reconcile canonical status without command replay`)
      } finally {
        await page.unroute(positionRoute, interceptStatus)
        await Promise.all([...pendingRoutes])
        page.off('requestfailed', requestFailed)
      }
      if (routeError) throw routeError
      checkOwnership()
      await waitFor(checkMedia, 'every source detachment to receive its authenticated successor master')
      await recordClientControl(`status-outage-${action}-${mode}`, proof.startedAt)
    }
  } finally {
    await page.evaluate(() => { window.__twoCommandObserveSigningReceipts = false })
  }
}

async function reconnectPausedPlayback(owner, prefix) {
  await pausePlayback(owner, `${prefix}-pause`)
  let before
  await until(async () => {
    const state = await mediaState()
    const usable = state.connected && state.paused && state.ready >= 2 && hasDecodedFrame(state)
      && !state.seeking && state.error === null && currentSource(state)
      && state.seekable.some(([start, end]) => state.time >= start && state.time <= end)
    if (usable) before = state
    return usable
  }, 'a decoded paused attachment matching the current signed rendition set')
  samePlayback(owner)
  const beforeAttachment = attachmentNumber
  const beforeRenditionSet = snapshot.renditionSet
  const beforePosition = snapshot.startTime + before.time
  const seeksBefore = playbackControls.filter(control => control.action === 'seek').length
  const networkStarted = Date.now()
  await recordClientControl(`${prefix}-ready-paused`, networkStarted, { attachment: beforeAttachment })
  const failureCount = networkInterruptions.length
  let reconnected
  const preserved = state => state.connected && state.paused && state.ready >= 2
    && state.decodedFrames >= before.decodedFrames && hasDecodedFrame(state) && !state.seeking && state.error === null
    && state.source === before.source && attachmentNumber === beforeAttachment
    && Math.abs(state.time - before.time) < 0.1
    && state.seekable.some(([start, end]) => state.time >= start && state.time <= end)
  try {
    await context.setOffline(true)
    await until(() => networkInterruptions.length > failureCount, 'a real stream refresh failure while offline', 10000)
    assert(preserved(await mediaState()), 'Offline refresh failure must retain the already decoded paused attachment and cursor')
    await recordClientControl(`${prefix}-offline-paused`, networkStarted, { attachment: beforeAttachment })
  } finally {
    reconnected = Date.now()
    await context.setOffline(false)
  }
  let reconnectionEvidence
  await until(async () => {
    samePlayback(owner)
    assert.equal(playbackControls.filter(control => control.action === 'seek').length, seeksBefore,
      'Network reconnection must not submit source seeks')
    const fresh = playbackSnapshots.filter(item => item.accepted && item.at >= reconnected
      && item.ready && item.jobId === owner.jobId && item.sessionId === owner.sessionId
      && item.generation === owner.generation && item.status === 'paused')
    if (fresh.length === 0) return false
    const state = await mediaState()
    const publicationChanged = fresh.some(item => item.renditionSet !== beforeRenditionSet)
    const capability = attachedCapabilities.get(attachmentNumber)
    const deliveredCurrentSet = capability && new URL(capability.url).searchParams.get('set') === snapshot.renditionSet
      && mediaResponses.some(item => item.attachment === attachmentNumber && item.status === 200
        && item.path.endsWith('/master.m3u8'))
      && ['video', 'audio'].every(track => mediaResponses.some(item => item.attachment === attachmentNumber
        && item.status === 200 && item.track === track && item.path.endsWith('/segment.m4s')))
    const restored = state.connected && state.paused && state.ready >= 2 && hasDecodedFrame(state)
      && !state.seeking && state.error === null && snapshot?.ready && snapshot.status === 'paused'
      && Math.abs(snapshot.startTime + state.time - beforePosition) < 0.1
      && state.seekable.some(([start, end]) => state.time >= start && state.time <= end)
      && deliveredCurrentSet
    if (!(snapshot?.ready && snapshot.status === 'paused'
      && currentSource(state) && (publicationChanged ? restored : preserved(state)))) return false
    reconnectionEvidence = { reason: publicationChanged ? 'fresh-rendition-set-change' : 'unchanged-rendition-set',
      previousAttachment: beforeAttachment, attachment: attachmentNumber,
      previousRenditionSet: beforeRenditionSet, renditionSet: snapshot.renditionSet,
      acceptedRenditionSets: [...new Set(fresh.map(item => item.renditionSet))] }
    return true
  }, 'decoded current signed media at the preserved paused cursor after network reconnection')
  samePlayback(owner)
  await recordClientControl(`${prefix}-reconnected-paused`, networkStarted, reconnectionEvidence)
  await resumeAndDecode(owner, `${prefix}-resume`)
}

async function commitSourceSeek(position, sourceDuration = snapshot.duration) {
  const timeline = page.getByRole('slider', { name: 'Seek movie' })
  assert.equal(Number(await timeline.getAttribute('max')), sourceDuration, 'Source seeking must span the full source duration')
  assert(position >= 0 && position < sourceDuration, 'The source target must fit the source duration')
  if (position === 0) {
    await timeline.focus()
    await timeline.press('Home')
    return 0
  }
  const box = await timeline.boundingBox()
  assert(box, 'The source timeline must be visible')
  await page.mouse.move(box.x + box.width * position / sourceDuration, box.y + box.height / 2)
  await page.mouse.down()
  try {
    // Read the browser's actual range value before pointer-up commits the draft.
    return Number(await timeline.inputValue())
  } finally {
    await page.mouse.up()
  }
}

async function seekSourceAndDecode(owner, position, action, paused = false) {
  const started = Date.now()
  const controlsBefore = playbackControls.length
  const committed = await commitSourceSeek(position)
  await until(() => playbackControls.slice(controlsBefore).some(control => control.action === 'seek'), 'the source control to submit a generation seek')
  const requested = playbackControls.slice(controlsBefore).find(control => control.action === 'seek').position
  assert.equal(requested, committed, 'The backend seek must use the committed source slider position')
  await until(async () => {
    const state = await mediaState()
    return snapshot?.ready && snapshot.generation === owner.generation + 1
      && state.connected && state.paused === paused && state.ready >= 2 && state.error === null && hasDecodedFrame(state)
      && state.seekable.some(([start, end]) => state.time >= start && state.time <= end)
      && (paused ? Math.abs(snapshot.startTime + state.time - requested) < 2
        : snapshot.startTime + state.time > requested + 1 && snapshot.startTime + state.time < requested + 8)
      && currentSource(state)
  }, `${action} to restore the source cursor and decode on its new generation`)
  samePlayback(owner, owner.generation + 1)
  const firstVideo = mediaRequestOrder.find(item => item.attachment === attachmentNumber && item.track === 'video' && item.index !== null)
  const localTarget = requested - snapshot.startTime
  const playlist = manifestEvidence.find(item => item.attachment === attachmentNumber
    && item.path.includes('/tracks/video/') && item.generation === String(snapshot.generation))
  assert(playlist, 'The current attachment must retain its actual published video playlist')
  let elapsed = 0
  let expectedIndex = null
  const lines = playlist.body.split('\n')
  for (let index = 0; index < lines.length; index++) {
    if (!lines[index].startsWith('#EXTINF:')) continue
    const duration = Number(lines[index].slice(8).split(',')[0])
    if (localTarget >= elapsed && localTarget < elapsed + duration) {
      expectedIndex = Number(new URL(lines[index + 1], 'https://media.baander.app').searchParams.get('index'))
      break
    }
    elapsed += duration
  }
  assert(expectedIndex !== null && firstVideo && Number(firstVideo.index) <= expectedIndex
    && Number(firstVideo.index) >= Math.max(0, expectedIndex - 1),
  'The MSE attachment must load the requested fragment or its immediate preroll using actual published durations')
  assert.equal(playbackControls.slice(controlsBefore).filter(control => control.action === 'seek').length, 1,
    'Source restoration and native clamping must not create additional backend seeks')
  owner.generation = snapshot.generation
  await recordClientControl(action, started, { requested })
  return requested
}

async function proveMovieCompletion() {
  const proof = { startedAt: Date.now() }
  movieCompletions.push(proof)
  const deadline = proof.startedAt + 60000
  const remaining = () => {
    assert(Date.now() < deadline, 'Movie completion proof exceeded its total time budget')
    return Math.max(1, deadline - Date.now())
  }
  const waitFor = (check, description) => until(check, description, remaining())
  const sample = async () => {
    const attachment = attachmentNumber
    const state = await mediaState()
    return attachment === attachmentNumber ? { attachment, state } : null
  }
  const owner = ownedPlayback()
  const sourceEnd = snapshot.duration
  assert(Number.isFinite(sourceEnd) && sourceEnd > 30, 'Completion requires the known full source duration')
  const controlsStart = playbackControls.length
  const controls = () => playbackControls.slice(controlsStart)
  const routePattern = `**/api/transcode/sessions/${owner.sessionId}/position`
  let droppedPause = null
  let failureObservedAt = null
  let routeError = null
  const pendingRoutes = new Set()
  const failed = request => {
    if (request === droppedPause) failureObservedAt = Date.now()
  }
  const intercept = route => {
    const task = (async () => {
      const request = route.request()
      const payload = request.method() === 'POST' ? request.postDataJSON() : null
      if (payload?.action !== 'pause' || droppedPause) { await route.continue(); return }
      const state = await mediaState()
      assert(state.ended, 'Only the genuine native ending pause may be interrupted')
      assert(Math.abs(payload.position - sourceEnd) <= 0.1, 'Ending pause must use the full source end')
      droppedPause = request
      proof.droppedPause = { at: Date.now(), position: payload.position, revision: payload.revision }
      await route.abort('failed')
    })().catch(async error => { routeError = error; await route.abort().catch(() => {}) })
    pendingRoutes.add(task)
    task.finally(() => pendingRoutes.delete(task))
  }
  const assertOwner = generation => {
    if (routeError) throw routeError
    samePlayback(owner, generation)
  }
  page.on('requestfailed', failed)
  await page.route(routePattern, intercept)
  shortSigningLifetime = true
  try {
    const requested = await commitSourceSeek(sourceEnd - 12)
    await waitFor(async () => {
      const sampled = await sample()
      if (!sampled) return false
      const { state } = sampled
      return snapshot?.ready && snapshot.generation === owner.generation + 1 && currentSource(state)
        && !state.paused && !state.ended && !state.seeking && state.error === null
        && state.ready >= 2 && state.decodedFrames > 0
        && snapshot.startTime + state.time >= requested && snapshot.startTime + state.time < sourceEnd - 1
    }, 'the real tail seek to decode before natural completion')
    owner.generation = snapshot.generation
    assertOwner(owner.generation)
    let initialSample
    await waitFor(async () => {
      initialSample = await sample()
      return initialSample && !initialSample.state.ended && !initialSample.state.paused && initialSample.state.decodedFrames > 0
    }, 'a stable attached tail decoder baseline')
    const playing = initialSample.state
    proof.tail = { requested, sourceEnd, generation: owner.generation, attachment: initialSample.attachment,
      startTime: snapshot.startTime, time: playing.time, decodedFrames: playing.decodedFrames }
    let ended
    let completedAttachment
    let tailBaseline = { attachment: initialSample.attachment, time: playing.time, decodedFrames: playing.decodedFrames, source: playing.source }
    await waitFor(async () => {
      assertOwner(owner.generation)
      const sampled = await sample()
      if (!sampled) return false
      const { state, attachment } = sampled
      assert.equal(state.error, null, 'Tail playback must remain free of media errors')
      assert.equal(state.playbackRate, 1, 'Natural tail completion must run at normal speed')
      if (!state.ended) {
        if (tailBaseline.attachment !== attachment) {
          tailBaseline = { attachment, time: state.time, decodedFrames: state.decodedFrames, source: state.source }
        }
        return false
      }
      assert(state.paused && !state.seeking && state.source === tailBaseline.source, 'Completion must belong to the current stopped attachment')
      assert(Math.abs(snapshot.startTime + state.time - sourceEnd) <= 0.1, 'Natural completion must reach the full source end')
      assert(Math.abs(state.time - state.duration) <= 0.1, 'The actual media timeline must reach its end')
      assert(tailBaseline.attachment === attachment && state.time > tailBaseline.time
        && state.decodedFrames > tailBaseline.decodedFrames, 'The completing attachment must decode advancing tail frames')
      proof.tail.baseline = { ...tailBaseline, source: new URL(tailBaseline.source).pathname }
      completedAttachment = attachment
      ended = state
      return true
    }, 'actual natural movie completion')
    await waitFor(async () => {
      const events = await page.evaluate(() => window.__twoCommandMediaEvents ?? [])
      proof.endedEvent = events.findLast(event => !event.probe && event.event === 'ended'
        && event.at >= proof.startedAt && event.ended && event.source === new URL(ended.source).pathname
        && Math.abs(event.time - ended.time) <= 0.1)
      return Boolean(proof.endedEvent)
    }, 'the genuine native ended event')
    assert.equal(attachmentNumber, completedAttachment, 'The ended event must retain its sampled attachment')
    const capability = attachedCapabilities.get(completedAttachment)
    assert(capability?.expires && Date.now() < (capability.expires + 1) * 1000
      && capability.expires * 1000 - Date.now() <= 12000,
      'The actually attached completion capability must have a bounded short lifetime')
    const signedCompletion = playbackSnapshots.findLast(item => item.accepted && item.capability === capability.capability)
    assert(signedCompletion && signedCompletion.jobId === owner.jobId && signedCompletion.sessionId === owner.sessionId
      && signedCompletion.generation === owner.generation, 'Completed capability must belong to the owned execution')
    const completedSet = new URL(capability.url).searchParams.get('set')
    assert(completedSet, 'Completed capability must retain its signed rendition set')
    proof.completed = { at: Date.now(), attachment: completedAttachment, startTime: snapshot.startTime, renditionSet: completedSet, jobId: owner.jobId, sessionId: owner.sessionId, generation: owner.generation,
      time: ended.time, duration: ended.duration, decodedFrames: ended.decodedFrames,
      pausedFrame: ended.pausedFrame, expires: capability.expires, capability: capability.capability }
    proof.finalResources = []
    await waitFor(() => {
      proof.finalResources = ['video', 'audio'].map(track => {
        const playlists = manifestEvidence.filter(item => item.attachment === completedAttachment
          && item.path.includes(`/tracks/${track}/`) && item.body.includes('#EXT-X-ENDLIST'))
        for (const playlist of playlists) {
          const resource = playlist.body.split('\n').filter(line => line && !line.startsWith('#')).at(-1)
          if (!resource) continue
          const url = new URL(resource, 'https://media.baander.app')
          const match = /^\/api\/transcode\/([^/]+)\/tracks\/(video|audio)\/segment\.m4s$/.exec(url.pathname)
          if (!match || !completedSet.split(',').includes(`${match[1]}.${url.searchParams.get('generation')}`)) continue
          const response = mediaResponses.find(item => item.attachment === completedAttachment && item.status === 200
            && item.path === url.pathname && item.generation === url.searchParams.get('generation')
            && item.index === url.searchParams.get('index'))
          if (response) return { track, playlist: playlist.path, path: response.path,
            generation: response.generation, index: response.index, at: response.at }
        }
        return null
      }).filter(Boolean)
      return proof.finalResources.length === 2
    }, 'completed current-set audio/video playlists and delivered final fragments')
    await waitFor(async () => {
      assertOwner(owner.generation)
      const sampled = await sample()
      assert(sampled, 'The completed attachment must remain stable during observation')
      const { state } = sampled
      assert.equal(sampled.attachment, completedAttachment, 'Polling and expiry must preserve the completed attachment')
      assert(state.ended && state.paused && !state.seeking && state.error === null,
        'Fresh running state must never restart a completed movie')
      assert(Math.abs(state.time - ended.time) <= 0.1, 'The completed source cursor must remain fixed')
      assert.equal(controls().filter(item => item.action === 'seek').length, 1, 'Completion must not create additional seeks')
      const polls = playbackSnapshots.filter(item => item.accepted && item.requestedAt > failureObservedAt
        && item.jobId === owner.jobId && item.sessionId === owner.sessionId
        && item.generation === owner.generation && item.status === 'running')
      proof.runningPolls = polls.slice(-2).map(item => ({ at: item.at, requestedAt: item.requestedAt,
        generation: item.generation, revision: item.revision, status: item.status }))
      return failureObservedAt && polls.length >= 2 && Date.now() > (capability.expires + 1) * 1000
    }, 'completed playback to remain stopped across failed pause, fresh running polls and actual capability expiry')
    assert.equal(controls().filter(item => item.action === 'pause').length, 1, 'Natural completion must issue one ending pause')
    proof.heldUntil = Date.now()
    shortSigningLifetime = false
    const replayStarted = Date.now()
    await video.evaluate(element => {
      window.__twoCommandReplayPlay = { requestedAt: Date.now(), settled: false }
      element.play().then(() => {
        window.__twoCommandReplayPlay = { ...window.__twoCommandReplayPlay, settled: true, at: Date.now() }
      }, error => {
        window.__twoCommandReplayPlay = { ...window.__twoCommandReplayPlay, settled: true, at: Date.now(), error: error.name }
      })
    })
    let replayBaseline
    await waitFor(async () => {
      if (routeError) throw routeError
      proof.replayPlay = await page.evaluate(() => window.__twoCommandReplayPlay)
      assert(!proof.replayPlay.error || proof.replayPlay.error === 'AbortError',
        `Native replay Play failed unexpectedly: ${proof.replayPlay.error}`)
      const sampled = await sample()
      if (!sampled) return false
      const { state } = sampled
      if (!snapshot?.ready || snapshot.generation !== owner.generation + 1 || !currentSource(state)
        || state.paused || state.ended || state.seeking || state.error !== null || state.ready < 2
        || state.decodedFrames <= 0) return false
      assert.equal(snapshot.startTime, 0, 'Replay must attach the full source beginning')
      if (!replayBaseline || replayBaseline.attachment !== attachmentNumber) {
        replayBaseline = { attachment: attachmentNumber, at: Date.now(), time: state.time, decodedFrames: state.decodedFrames }
        return false
      }
      if (!proof.replayPlay.settled || state.time <= replayBaseline.time + 1
        || state.decodedFrames <= replayBaseline.decodedFrames) return false
      proof.replay = { startedAt: replayStarted, baseline: replayBaseline, at: Date.now(),
        attachment: attachmentNumber, generation: snapshot.generation, startTime: snapshot.startTime,
        time: state.time, decodedFrames: state.decodedFrames }
      return true
    }, 'native Play to replay a new source-zero execution with advancing decoded frames')
    assertOwner(owner.generation + 1)
    const seeks = controls().filter(item => item.action === 'seek')
    assert.deepEqual(seeks.map(item => item.position), [requested, 0], 'Tail setup and explicit source-zero replay must be the only seeks')
    assert.equal(controls().filter(item => item.action === 'pause').length, 1, 'Replay retirement must not echo another pause')
    assert(controls().filter(item => item.action === 'resume').length <= 1, 'Replay may resume at most once')
    for (const track of ['video', 'audio']) {
      assert(mediaResponses.some(item => {
        const match = /^\/api\/transcode\/([^/]+)\/tracks\/(video|audio)\/segment\.m4s$/.exec(item.path)
        return item.at >= replayStarted && item.attachment === attachmentNumber && item.status === 200
          && match?.[2] === track && snapshot.renditionSet.split(',').includes(`${match[1]}.${item.generation}`)
      }), `Replay must receive actual current-set ${track}`)
    }
    proof.controls = controls().filter(item => item.action !== 'progress')
    proof.finishedAt = Date.now()
  } finally {
    shortSigningLifetime = false
    await page.unroute(routePattern, intercept)
    await Promise.allSettled([...pendingRoutes])
    page.off('requestfailed', failed)
    await savePlaybackTimeline()
  }
}

async function proveSeekOutages(owner, expectedPosition) {
  const deadline = Date.now() + 90000
  const remaining = maximum => {
    assert(Date.now() < deadline, 'Seek outage proof exceeded its total time budget')
    return Math.max(1, Math.min(maximum, deadline - Date.now()))
  }
  const waitFor = (check, description, maximum = 15000) => until(check, description, remaining(maximum))
  const positionRoute = `**/api/transcode/sessions/${owner.sessionId}/position`
  for (const mode of ['before-forwarding', 'accepted-response-loss']) {
    await pausePlayback(owner, `seek-outage-${mode}-pause`, remaining(10000))
    await waitFor(async () => {
      const state = await mediaState()
      return snapshot?.ready && snapshot.status === 'paused' && state.connected && state.paused
        && state.ready >= 2 && hasDecodedFrame(state) && !state.seeking && state.error === null && currentSource(state)
    }, 'decoded paused playback before an uncertain source seek')
    const generation = owner.generation
    const sourceDuration = Number(await page.getByRole('slider', { name: 'Seek movie' }).getAttribute('max'))
    assert.equal(sourceDuration, snapshot.duration, 'The captured source range must match the ready source duration')
    const initialSliderPosition = Number(await page.getByRole('slider', { name: 'Seek movie' }).inputValue())
    const candidates = mode === 'before-forwarding'
      ? [expectedPosition + 24, expectedPosition + 48] : [expectedPosition - 8, expectedPosition + 8]
    const firstTarget = candidates.filter(value => value >= 0 && value < sourceDuration)
      .sort((left, right) => Math.abs(right - initialSliderPosition) - Math.abs(left - initialSliderPosition))[0]
    assert(firstTarget !== undefined && Math.abs(firstTarget - initialSliderPosition) >= 8,
      'The uncertain seek must move the actual source thumb to a different target')
    const targets = mode === 'before-forwarding' ? [firstTarget] : [firstTarget, expectedPosition - 4, expectedPosition]
    assert(targets.every(value => value >= 0 && value < sourceDuration), 'Every queued target must fit the captured source range')
    const logicalSeeks = mode === 'before-forwarding' ? 1 : 2
    const proof = { mode, status: 'observing', startedAt: Date.now(), generation, initialSliderPosition,
      sourceDuration, targets, committedTargets: [], attempts: [] }
    seekOutages.push(proof)
    let firstPayload
    let faultRequest
    let routeError
    let queuedTargetsReady = mode === 'before-forwarding'
    const pendingRoutes = new Set()
    const checkOwnership = () => {
      assert.equal(snapshot.jobId, owner.jobId, 'Seek recovery must retain the owned job')
      assert.equal(snapshot.sessionId, owner.sessionId, 'Seek recovery must retain the owned session')
      assert(snapshot.generation >= generation && snapshot.generation <= generation + logicalSeeks,
        'Only accepted logical seeks may advance the recovery generation')
      assert.equal(snapshot.status, 'paused', 'Uncertain source seeks must preserve paused intent')
      if (routeError) throw routeError
    }
    const requestFailed = request => {
      if (request === faultRequest) proof.failureObservedAt = Date.now()
    }
    const interceptSeek = route => {
      const task = (async () => {
        const request = route.request()
        const payload = request.method() === 'POST' ? request.postDataJSON() : null
        if (payload?.action !== 'seek') { await route.continue(); return }
        const attempt = { at: Date.now(), payload: Object.fromEntries(
          ['action', 'position', 'generation', 'requestId'].map(key => [key, payload[key]])) }
        proof.attempts.push(attempt)
        const number = proof.attempts.length
        assert(number <= logicalSeeks + 1, 'Each uncertain seek permits only one identical transport resend')
        if (number === 1) {
          firstPayload = payload
          faultRequest = request
          assert.equal(payload.generation, generation)
          assert.match(payload.requestId, /^[0-9a-f-]{36}$/i, 'Submitted source seeks must carry a UUID')
          if (mode === 'accepted-response-loss') {
            const holdDeadline = attempt.at + 4000
            const response = await route.fetch({ timeout: remaining(3500) })
            const body = await response.json()
            proof.firstResponse = { at: Date.now(), status: response.status(), requestId: body.data?.requestId,
              acceptedGeneration: body.data?.acceptedGeneration }
            await response.dispose()
            assert.equal(proof.firstResponse.status, 200)
            assert.equal(proof.firstResponse.requestId, payload.requestId)
            assert.equal(proof.firstResponse.acceptedGeneration, generation + 1,
              'The first logical seek must really advance its execution generation')
            assert(Date.now() < holdDeadline, 'Real seek forwarding must leave time to prove acceptance')
            await until(() => {
              checkOwnership()
              const canonical = playbackSnapshots.find(item => item.accepted && item.requestedAt >= proof.firstResponse.at
                && item.jobId === owner.jobId && item.sessionId === owner.sessionId
                && item.generation === generation + 1 && item.status === 'paused'
                && Math.abs(item.position - payload.position) < 0.00001)
              if (canonical) proof.acceptedCanonical = { at: canonical.at, requestedAt: canonical.requestedAt,
                ...safePlayback(canonical) }
              return Boolean(canonical && queuedTargetsReady)
            }, 'signed desired state and queued slider targets before dropping the accepted response',
            remaining(holdDeadline - Date.now()))
          }
          await route.abort('failed')
          proof.abortedAt = Date.now()
          assert(proof.abortedAt - attempt.at < 4500, 'Forwarding and seek response loss must finish below the client timeout')
          return
        }
        if (number === 2) assert.deepEqual(payload, firstPayload, 'The one resend must preserve the entire original seek payload')
        else {
          assert.equal(payload.position, proof.committedTargets.at(-1), 'Only the latest unsent slider target may follow reconciliation')
          assert.notEqual(payload.requestId, firstPayload.requestId, 'The successor is a new logical seek with a new UUID')
          assert.equal(payload.generation, generation + 1)
          const observed = await page.evaluate(() => ({ receipts: window.__twoCommandSeekReceipts,
            signing: window.__twoCommandSigningReceipts, requests: window.__twoCommandSeekRequestOrders }))
          const retryReceipt = observed.receipts.find(item => item.requestId === firstPayload.requestId)
          assert(retryReceipt && !Object.hasOwn(retryReceipt, 'acceptedGeneration'),
            'The delivered duplicate must omit continuation acknowledgement')
          assert.equal(observed.requests.length, 3, 'Only first, identical resend and latest seek may reach the browser transport')
          const latestOrder = observed.requests.at(-1).requestedOrder
          const canonical = observed.signing.find(item => item.jobId === owner.jobId && item.sessionId === owner.sessionId
            && item.generation === payload.generation && item.status === 'paused'
            && item.requestedOrder > retryReceipt.receivedOrder && item.receivedOrder < latestOrder)
          assert(canonical, 'The latest seek must follow a canonical read started after the browser received the duplicate')
          proof.continuation = { retryReceipt, canonical, latestRequestedOrder: latestOrder }
        }
        const response = await route.fetch({ timeout: remaining(4500) })
        const body = await response.json()
        attempt.response = { at: Date.now(), status: response.status(), requestId: body.data?.requestId,
          ...(Object.hasOwn(body.data ?? {}, 'acceptedGeneration') ? { acceptedGeneration: body.data.acceptedGeneration } : {}) }
        assert.equal(attempt.response.status, 200)
        assert.equal(attempt.response.requestId, payload.requestId)
        if (number === 2 && mode === 'accepted-response-loss') {
          assert(!Object.hasOwn(attempt.response, 'acceptedGeneration'), 'A duplicate seek must not fabricate an accepted generation')
        } else assert.equal(attempt.response.acceptedGeneration, generation + logicalSeeks)
        await route.fulfill({ response })
      })().catch(async error => { routeError = error; await route.abort().catch(() => {}) })
      pendingRoutes.add(task)
      task.finally(() => pendingRoutes.delete(task))
      return task
    }
    await page.evaluate(() => {
      window.__twoCommandSigningReceipts = []
      window.__twoCommandSeekReceipts = []
      window.__twoCommandSeekRequestOrders = []
      window.__twoCommandObserveSigningReceipts = true
      window.__twoCommandObserveSeekReceipts = true
    })
    page.on('requestfailed', requestFailed)
    await page.route(positionRoute, interceptSeek)
    try {
      proof.committedTargets.push(await commitSourceSeek(targets[0], sourceDuration))
      if (mode === 'accepted-response-loss') {
        await waitFor(() => { checkOwnership(); return proof.firstResponse }, 'the genuine first seek response to be held')
        for (const target of targets.slice(1)) proof.committedTargets.push(await commitSourceSeek(target, sourceDuration))
        assert.equal(proof.attempts.length, 1, 'All newer slider targets must queue while the first transport attempt is pending')
        queuedTargetsReady = true
        proof.queuedAt = Date.now()
        assert.equal(new Set(proof.committedTargets).size, 3, 'The three gestures must commit distinct source positions')
      }
      await waitFor(async () => {
        checkOwnership()
        const lastAttempt = proof.attempts.at(-1)
        if (!proof.failureObservedAt || proof.attempts.length !== logicalSeeks + 1 || !lastAttempt?.response) return false
        const sampledAttachment = attachmentNumber
        const state = await mediaState()
        if (sampledAttachment !== attachmentNumber) return false
        const capability = attachedCapabilities.get(attachmentNumber)
        const delivered = capability && new URL(capability.url).searchParams.get('set') === snapshot.renditionSet
          && mediaResponses.some(item => item.attachment === attachmentNumber && item.status === 200
            && item.path.endsWith('/master.m3u8'))
          && ['video', 'audio'].every(track => mediaResponses.some(item => {
            const match = /^\/api\/transcode\/([^/]+)\/tracks\/(video|audio)\/segment\.m4s$/.exec(item.path)
            return item.attachment === attachmentNumber && item.status === 200 && match?.[2] === track
              && snapshot.renditionSet.split(',').includes(`${match[1]}.${item.generation}`)
          }))
        if (!(snapshot.ready && snapshot.generation === generation + logicalSeeks && state.connected && state.paused
          && state.ready >= 2 && hasDecodedFrame(state) && !state.seeking && state.error === null
          && currentSource(state) && delivered && state.seekable.some(([start, end]) => state.time >= start && state.time <= end)
          && Math.abs(snapshot.startTime + state.time - proof.committedTargets.at(-1)) < 2)) return false
        const requests = playbackControls.filter(item => item.at >= proof.startedAt && item.action !== 'progress')
        assert.deepEqual(requests.map(item => item.position), mode === 'before-forwarding'
          ? [proof.committedTargets[0], proof.committedTargets[0]]
          : [proof.committedTargets[0], proof.committedTargets[0], proof.committedTargets[2]])
        assert(requests.every(item => item.action === 'seek'), 'Seek recovery must not echo pause/resume controls')
        assert.equal(new Set(requests.map(item => item.requestId)).size, logicalSeeks)
        proof.status = 'passed'
        proof.restored = { at: Date.now(), attachment: attachmentNumber, ...safePlayback(snapshot),
          time: state.time, decodedFrames: state.decodedFrames, pausedFrame: state.pausedFrame }
        return true
      }, `${mode} source seek recovery to restore genuine paused media`, 45000)
    } finally {
      await page.unroute(positionRoute, interceptSeek)
      await Promise.all([...pendingRoutes])
      page.off('requestfailed', requestFailed)
      await page.evaluate(() => {
        window.__twoCommandObserveSigningReceipts = false
        window.__twoCommandObserveSeekReceipts = false
      })
    }
    if (routeError) throw routeError
    owner.generation = generation + logicalSeeks
    samePlayback(owner)
    await recordClientControl(`seek-outage-${mode}-restored`, proof.startedAt)
    await resumeAndDecode(owner, `seek-outage-${mode}-resume`, remaining(10000))
  }
}

async function checkpoint({ phase, baseUrl, credentials }) {
  if (phase === 'authenticated') {
    browserInfo.capabilities = await page.evaluate(() => {
      const video = document.createElement('video')
      const h264 = 'video/mp4; codecs="avc1.42E01E"'
      const aac = 'audio/mp4; codecs="mp4a.40.2"'
      return { nativeHls: video.canPlayType('application/vnd.apple.mpegurl'),
        h264: video.canPlayType(h264), aac: video.canPlayType(aac),
        mseVideo: typeof MediaSource !== 'undefined' && MediaSource.isTypeSupported(h264),
        mseAudio: typeof MediaSource !== 'undefined' && MediaSource.isTypeSupported(aac) }
    })
    assert(browserInfo.capabilities.mseVideo && browserInfo.capabilities.mseAudio,
      'The browser must provide H.264 and AAC Media Source Extensions for the product HLS player')
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
      return state.connected && !state.paused && state.ready >= 2 && state.time > 1
        && state.decodedFrames > 0 && state.error === null && snapshot?.ready
    }, 'the product HLS player to decode and advance')
    const owner = ownedPlayback()
    const initial = await mediaState()
    const publishedEnd = snapshot.startTime + Math.max(...initial.seekable.map(range => range[1]), 0)
    const target = Math.min(snapshot.duration - 18, Math.max(90, publishedEnd + 24))
    assert(target > publishedEnd + 6, 'The fixture must leave source media beyond the currently published native window')
    assert(initial.source.startsWith('blob:'), 'The selected browser must use the supported MSE HLS path')
    assert(initial.seekable.length > 0, 'MSE must expose an actual published seekable window')
    const requested = await seekSourceAndDecode(owner, target, 'source-seek-beyond-window')
    assert(requested > publishedEnd, 'The movie source control must seek beyond the old native window')
    await observeSubtitles('source-sought', 'Dialogue sought')
    await pausePlayback(owner, 'source-pause')
    await seekSourceAndDecode(owner, Math.min(snapshot.duration - 24, requested + 24), 'source-seek-forward-paused', true)
    await observeSubtitles('source-sought-paused', 'Dialogue sought')
    await seekSourceAndDecode(owner, 0, 'source-seek-return-to-start-paused', true)
    await resumeAndDecode(owner, 'source-resume-after-return')
    await observeSubtitles('attached', 'Dialogue early')
    await renewPlaybackCapabilities(owner)
    await reconnectPausedPlayback(owner, 'source-network')
    initialGeneration = snapshot.generation
    await page.screenshot({ path: `${evidence}/desktop.png` })
    await page.setViewportSize({ width: 390, height: 844 })
    await page.screenshot({ path: `${evidence}/mobile.png` })
    await page.setViewportSize({ width: 1280, height: 800 })
  } else if (phase === 'client-controls') {
    const owner = ownedPlayback()
    await proveProgressOutages(owner)
    await proveStatusOutages(owner)
    await proveSeekOutages(owner, credentials.expectedSeekPosition)
    await pausePlayback(owner, 'pause')
    const started = Date.now()
    const initialSliderPosition = Number(await page.getByRole('slider', { name: 'Seek movie' }).inputValue())
    const firstTarget = [credentials.expectedSeekPosition - 8, credentials.expectedSeekPosition + 8]
      .filter(position => position >= 0 && position < snapshot.duration)
      .sort((left, right) => Math.abs(right - initialSliderPosition) - Math.abs(left - initialSliderPosition))[0]
    assert(firstTarget !== undefined && Math.abs(firstTarget - initialSliderPosition) >= 8,
      'The first rapid gesture must move the actual source thumb far enough to change its value')
    const targets = [firstTarget, credentials.expectedSeekPosition - 4, credentials.expectedSeekPosition]
    assert(targets.every(position => position >= 0 && position < snapshot.duration),
      'All rapid seek targets must fit the source duration')
    const committedTargets = []
    const controlsBeforeSeek = playbackControls.length
    const positionRoute = `**/api/transcode/sessions/${owner.sessionId}/position`
    let firstResponse
    let firstRouteTask
    let firstRouteError
    let responseHeldAt
    let responseHoldMs
    let responseReleased = false
    let releaseResponse
    let releaseTimer
    const heldResponse = new Promise(resolve => { releaseResponse = resolve })
    const release = () => {
      responseReleased = true
      releaseResponse()
    }
    const holdFirstSeek = async route => {
      if (route.request().method() !== 'POST' || route.request().postDataJSON()?.action !== 'seek' || firstRouteTask) {
        await route.continue()
        return
      }
      // Start the deadline before forwarding: backend latency consumes the same budget.
      responseHeldAt = Date.now()
      releaseTimer = setTimeout(release, 3500)
      firstRouteTask = (async () => {
        const response = await route.fetch({ timeout: 3500 })
        firstResponse = await response.json()
        await heldResponse
        await route.fulfill({ response })
        responseHoldMs = Date.now() - responseHeldAt
        assert(responseHoldMs < 4500, 'Forwarding and response hold must finish below the five-second client timeout')
      })().catch(async error => {
        firstRouteError = error
        await route.abort().catch(() => {})
      })
      await firstRouteTask
    }
    await page.route(positionRoute, holdFirstSeek)
    try {
      for (const [index, position] of targets.entries()) {
        committedTargets.push(await commitSourceSeek(position))
        if (index === 0) {
          await until(() => {
            if (firstRouteError) throw firstRouteError
            return firstResponse
          }, 'the first real seek acceptance to be held', 2000)
          assert(Number.isInteger(firstResponse?.data?.acceptedGeneration), 'The real seek must acknowledge its accepted generation')
        }
        assert(!responseReleased, 'Every rapid target must be committed while the first response is pending')
      }
    } finally {
      clearTimeout(releaseTimer)
      release()
      try {
        await firstRouteTask
      } finally {
        await page.unroute(positionRoute, holdFirstSeek)
      }
    }
    if (firstRouteError) throw firstRouteError
    assert.equal(new Set(committedTargets).size, 3, 'Rapid scrubbing must commit three distinct source positions')
    await pausedAt(committedTargets[2], owner.generation + 2)
    samePlayback(owner, owner.generation + 2)
    const rapidSeeks = playbackControls.slice(controlsBeforeSeek).filter(control => control.action === 'seek')
    assert.deepEqual(rapidSeeks.map(control => control.position), [committedTargets[0], committedTargets[2]], 'Rapid scrubbing must send only the first and latest targets')
    assert.equal(rapidSeeks[0].generation, owner.generation, 'The first source seek must use its attached generation')
    assert.equal(rapidSeeks[1].generation, firstResponse.data.acceptedGeneration, 'The latest target must use its predecessor acceptance')
    await recordClientControl('seek-paused', started, { initialSliderPosition, targets, committedTargets, responseHoldMs, acceptedGeneration: firstResponse.data.acceptedGeneration })
    owner.generation = snapshot.generation
    await resumeAndDecode(owner, 'resume-after-seek')
    await reconnectPausedPlayback(owner, 'network')
    await pausePlayback(owner, 'pause-before-remount')
    let pausedPosition
    await until(async () => {
      const state = await mediaState()
      const usable = state.connected && state.paused && state.ready >= 2 && hasDecodedFrame(state)
        && !state.seeking && state.error === null && snapshot?.ready && snapshot.status === 'paused'
        && currentSource(state) && state.seekable.some(([start, end]) => state.time >= start && state.time <= end)
      if (usable) pausedPosition = snapshot.startTime + state.time
      return usable
    }, 'decoded seekable paused media matching the signed rendition set before reload')
    samePlayback(owner)

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
    await recordClientControl('remounted-paused', remountStarted, { pausedPosition })
    await resumeAndDecode(owner, 'resume-after-remount')
    await pausePlayback(owner, 'final-pause')
    // Leave a different paused cursor so the following operator seek changes position.
    await seekSourceAndDecode(owner, credentials.expectedSeekPosition + 5, 'final-seek-paused', true)
  } else if (phase === 'sought-paused') {
    await pausedAt(credentials.expectedSeekPosition, initialGeneration + 1)
    soughtGeneration = snapshot.generation
    await observeSubtitles('sought-paused', credentials.expectedSeekPosition >= 90 ? 'Dialogue sought' : 'Dialogue late')
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
      return state.connected && !state.paused && state.ready >= 2 && state.error === null
        && state.time > before.time + 1 && state.decodedFrames > before.decodedFrames
        && snapshot?.status === 'running'
    }, 'the same player to resume after worker recovery')
    for (const track of ['video', 'audio']) {
      assert(mediaResponses.some(item => {
        const match = /^\/api\/transcode\/([^/]+)\/tracks\/(video|audio)\/segment\.m4s$/.exec(item.path)
        return item.status === 200 && match?.[2] === track
          && snapshot.renditionSet.split(',').includes(`${match[1]}.${item.generation}`)
      }), `Recovered playback must receive ${track} from the current signed rendition set`)
    }
    await proveMovieCompletion()
  } else if (phase === 'complete') {
    assert.deepEqual(phases, ['authenticated', 'attached', ...(phases.includes('client-controls') ? ['client-controls'] : []), 'sought-paused', 'web-restarted', 'worker-contained', 'worker-recovered'])
  } else {
    throw new Error(`Unexpected browser checkpoint: ${phase}`)
  }
  phases.push(phase)
  // No credentials or signed URLs are written to the evidence artifact.
  await writeFile(`${evidence}/result.json`, JSON.stringify({ browser: browserInfo, phases, generation: snapshot?.generation, mediaResponses, nativeResponseEvidence, clientControls, subtitleObservations, networkInterruptions, manifestEvidence, mediaRequestOrder, capabilityRenewals, progressOutages, statusOutages, seekOutages, movieCompletions }, null, 2))
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
      browser: browserInfo, error: error.message, state, playback, playbackSnapshots, clientControls, subtitleObservations, networkInterruptions, mediaEvents: [...previousMediaEvents, ...mediaEvents], mediaResponses, apiResponses, nativeResponseEvidence, manifestEvidence, mediaRequestOrder, capabilityRenewals, progressOutages, statusOutages, seekOutages, movieCompletions,
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
