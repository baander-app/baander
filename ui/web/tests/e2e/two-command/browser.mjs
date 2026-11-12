import assert from 'node:assert/strict'
import { createHash } from 'node:crypto'
import { createServer } from 'node:http'
import { mkdir, writeFile } from 'node:fs/promises'
import { connect, createServer as createTcpServer } from 'node:net'
import { chromium, firefox } from 'playwright-core'

const evidence = '/work/evidence'
const requestedEngine = process.env.BAANDER_TEST_BROWSER_ENGINE ?? 'chromium'
const browserInfo = { engine: requestedEngine, version: null }
let browser
let context
try {
  assert(['chromium', 'firefox'].includes(requestedEngine), `Unsupported browser engine: ${requestedEngine}`)
  await mkdir(evidence, { recursive: true })
  // The web listens only at its loopback origin. On a shared Docker network the browser
  // reaches it through its own loopback, forwarded to the web's network alias, which the
  // drill resolves again for every connection, so a replaced web container is followed.
  const upstream = process.env.BAANDER_TEST_WEB_UPSTREAM ?? ''
  if (upstream !== '') {
    const [upstreamHost, upstreamPort] = upstream.split(':')
    assert(upstreamHost && /^\d+$/.test(upstreamPort ?? ''), `Invalid web upstream: ${upstream}`)
    const forwarder = createTcpServer(client => {
      const target = connect(Number(upstreamPort), upstreamHost)
      const close = () => { client.destroy(); target.destroy() }
      for (const socket of [client, target]) { socket.on('error', close); socket.on('close', close) }
      client.pipe(target).pipe(client)
    })
    await new Promise((resolve, reject) => {
      forwarder.once('error', reject)
      forwarder.listen(Number(upstreamPort), '127.0.0.1', resolve)
    })
  }
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
const pageErrors = []
page.on('pageerror', error => { appendBounded(pageErrors, { at: Date.now(), kind: 'pageerror', message: error.message }) })
page.on('console', message => {
  if (message.type() === 'error' || message.type() === 'warning') appendBounded(pageErrors, { at: Date.now(), kind: message.type(), message: message.text().slice(0, 500) })
})
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
// The paused cursor the browser leaves on its own session before the operator phases.
let restingCursor
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
const cursorAuthority = []
let movieEndpointProof = null
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
      playbackSnapshots, playbackControls, clientControls, subtitleObservations, networkInterruptions, manifestEvidence, mediaRequestOrder, capabilityRenewals, progressOutages, statusOutages, seekOutages, movieCompletions, cursorAuthority, mediaEvents: [...previousMediaEvents, ...mediaEvents],
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
    // A refused resume is explained by the session requests before it, so those keep what
    // they asked or reported. No identifier or capability is kept.
    let sessionRequest = {}
    if (url.pathname.startsWith('/api/session/') && response.request().method() === 'POST') {
      try {
        const body = response.request().postDataJSON()
        sessionRequest = { action: body?.action, outcome: body?.outcome, observedState: body?.observedState }
      } catch { /* A body that is not JSON carries nothing to keep. */ }
    }
    appendBounded(apiResponses, { at: Date.now(), path: url.pathname, status: response.status(), ...sessionRequest })
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

async function subtitleState() {
  return video.evaluate(element => {
    const tracks = Array.from(element.textTracks)
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
  })
}

async function selectSubtitles(phase, selection, deadline = Date.now() + 5000) {
  assert(Date.now() < deadline, 'Subtitle selection must stay within its playback proof budget')
  await until(async () => (await subtitleState()).tracks.length === 3,
    'all source subtitle tracks before a single explicit selection', Math.min(5000, deadline - Date.now()))
  assert(Date.now() < deadline, 'Subtitle selection must stay within its playback proof budget')
  await video.evaluate((element, choice) => {
    const tracks = Array.from(element.textTracks)
    const selected = choice === 'Off' ? null : tracks.find(track => track.label.includes(choice))
    if (choice !== 'Off' && !selected) throw new Error(`Missing subtitle track: ${choice}`)
    // This is one TextTrack API gesture, not an interaction with a native caption menu.
    for (const track of tracks) track.mode = track === selected ? 'showing' : 'disabled'
  }, selection)
  subtitleObservations.push({ phase, status: 'selected', selection, at: Date.now(),
    attachment: attachmentNumber, generation: snapshot?.generation, ...await subtitleState() })
}

async function observeSubtitles(phase, expectedText, selection = 'Dialogue', deadline = Date.now() + 15000) {
  assert(Date.now() < deadline, 'Subtitle observation must stay within its playback proof budget')
  // Native HLS capability does not establish native subtitle support. Retain
  // that distinction explicitly instead of treating an empty track list as a pass.
  let state = await subtitleState()
  const engine = (await mediaState()).source.startsWith('blob:') ? 'mse-hls' : 'native-hls'
  const trackDeadline = Math.min(deadline, Date.now() + 5000)
  while (state.tracks.length < 3 && Date.now() < trackDeadline) {
    await new Promise(resolve => setTimeout(resolve, 100))
    state = await subtitleState()
  }
  if (state.tracks.length === 0 && engine === 'native-hls') {
    if (subtitleObservations.some(observation => observation.status === 'active-cue')) {
      subtitleObservations.push({ phase, engine, status: 'failed', selection, expectedText, ...state })
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
    let disabledPolls = 0
    let observedAttachment = attachmentNumber
    const disabledObservations = []
    await until(async () => {
      state = await subtitleState()
      const selected = state.tracks.length === 3
        && state.tracks.filter(track => track.mode === 'showing').length === (selection === 'Off' ? 0 : 1)
        && state.tracks.some(track => selection === 'Off' || track.label.includes(selection))
        && state.tracks.every(track => {
          if (selection === 'Off' || !track.label.includes(selection)) return track.mode === 'disabled'
          return track.mode === 'showing'
            && track.activeCues.some(cue => cue.text?.includes(expectedText))
        })
      if (selection !== 'Off') return selected
      if (!selected || observedAttachment !== attachmentNumber) {
        disabledPolls = 0
        disabledObservations.length = 0
      }
      observedAttachment = attachmentNumber
      if (selected) {
        disabledPolls++
        disabledObservations.push({ at: Date.now(), attachment: attachmentNumber, ...state })
      }
      return disabledPolls >= 2
    }, selection === 'Off' ? 'all subtitle tracks to remain disabled'
      : `the exclusive ${selection} track to activate "${expectedText}"`, Math.min(10000, deadline - Date.now()))
    subtitleObservations.push({ phase, engine, status: selection === 'Off' ? 'disabled' : 'active-cue',
      selection, expectedText, at: Date.now(), attachment: attachmentNumber,
      generation: snapshot?.generation, ...(selection === 'Off' ? { disabledObservations } : {}), ...state })
    // The cue assertion establishes TextTrack activation; this screenshot supports
    // inspection of painted captions without claiming a pixel-level assertion.
    assert(Date.now() < deadline, 'Subtitle evidence must stay within its playback proof budget')
    await page.screenshot({ path: `${evidence}/subtitles-${phase}.png`, timeout: deadline - Date.now() })
  } catch (error) {
    subtitleObservations.push({ phase, engine, status: 'failed', selection, expectedText, ...state })
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

// The fixture's dialogue track carries cues for 0-15 s, 15-40 s and 90-150 s of the source.
function dialogueCueAt(position) {
  if (position < 15) return 'Dialogue early'
  if (position < 40) return 'Dialogue late'
  if (position >= 90 && position < 150) return 'Dialogue sought'
  return null
}

// The final paused seek is a pointer press on the source timeline. A press inside the thumb
// starts a drag without changing the value, so it commits no seek, and where the thumb
// rests depends on how long the earlier phases played. The target therefore lies ten
// seconds behind the paused thumb, far outside the thumb. It lies four seconds further
// back where it would otherwise meet the operator's seek position, so the browser's and
// the operator's cursors stay distinguishable, and it never passes five seconds beyond
// that position, which keeps it on the 15-40 s dialogue cue the next phase's subtitle
// proof requires.
function finalSeekTarget(pausedThumb, operatorPosition) {
  return [10, 14].map(distance => Math.min(Math.round(pausedThumb) - distance, operatorPosition + 5))
    .find(target => Math.abs(target - operatorPosition) >= 2)
}

// A page registers its playback endpoint over a newly opened WebSocket, and the server
// answers with the session snapshot that carries the registration. This resolves with that
// first answer: its revision, the runner it names as active, and how long after the
// registration it arrived. The frames are only observed, so the page receives them
// unchanged; the capability in the answer is not kept. The listeners are removed once the
// answer is seen or the time is up, and without an answer in time it resolves with null.
function observeRegistrationAnswer(milliseconds = 45000) {
  return new Promise(resolve => {
    const observed = new Map()
    const finish = answer => {
      clearTimeout(timer)
      page.off('websocket', observe)
      for (const [socket, listeners] of observed) {
        socket.off('framesent', listeners.sent)
        socket.off('framereceived', listeners.received)
      }
      observed.clear()
      resolve(answer)
    }
    const observe = socket => {
      let registeredAt = null
      const sent = ({ payload }) => {
        if (registeredAt === null && typeof payload === 'string' && payload.includes('"session.endpoint"')) registeredAt = Date.now()
      }
      const received = ({ payload }) => {
        if (typeof payload !== 'string' || !payload.includes('"session.snapshot"')) return
        let message
        try { message = JSON.parse(payload) } catch { return }
        if (message?.type !== 'session.snapshot' || !message.data?.endpoint) return
        const at = Date.now()
        finish({ at, sinceRegistrationMs: registeredAt === null ? null : at - registeredAt,
          revision: message.data.revision, activeIncarnationId: message.data.activeIncarnationId })
      }
      observed.set(socket, { sent, received })
      socket.on('framesent', sent)
      socket.on('framereceived', received)
    }
    const timer = setTimeout(() => finish(null), milliseconds)
    page.on('websocket', observe)
  })
}

// The source position of paused, decoded media the element can seek within.
async function seekablePausedPosition(label) {
  let position
  await until(async () => {
    const state = await mediaState()
    const usable = state.connected && state.paused && state.ready >= 2 && hasDecodedFrame(state)
      && !state.seeking && state.error === null && snapshot?.ready && snapshot.status === 'paused'
      && currentSource(state) && state.seekable.some(([start, end]) => state.time >= start && state.time <= end)
    if (usable) position = snapshot.startTime + state.time
    return usable
  }, `decoded seekable paused media matching the signed rendition set ${label}`)
  return position
}

// Which session requests a page sends around a reload tells which rule released its runner: a
// close report as the page goes away, a local recovery before the next page registers, or
// neither, in which case the registration from another page of the same tab did it. Requests are
// only observed. An unloading page's request may escape the driver, so "not seen" is weaker
// evidence than "seen".
function observeReleaseRequests() {
  const seen = { closeReport: false, localRecovery: false }
  const observe = request => {
    const path = new URL(request.url()).pathname
    if (path === '/api/session/close') seen.closeReport = true
    if (path === '/api/session/recover-local') seen.localRecovery = true
  }
  context.on('request', observe)
  return { seen, stop: () => { context.off('request', observe) } }
}

async function restingCursorNow() {
  const state = await mediaState()
  assert(snapshot?.ready, 'The browser must hold a ready owned session before the operator phases')
  const paused = state.paused && snapshot.status === 'paused'
  return { position: paused ? snapshot.startTime + state.time : null, generation: snapshot.generation, sessionId: snapshot.sessionId, jobId: snapshot.jobId }
}

// After a reload or a web replacement, recovery releases the browser's runner: the page
// lists the movie as paused, its Play button reopens the overlay, and the overlay's Play
// resumes playback locally from the recovered cursor. The signed playback snapshot only
// arrives once a local player attaches again, so the paused position is read from the
// overlay's timeline.
//
// While the page is still recovering it shows no Play button, or only the overlay's
// disabled one. Which Play button is on screen is read once one is enabled: beside the seek
// slider it is the overlay's, which resumes playback; without the slider it is the page's,
// which reopens the overlay. A reopen click chosen earlier would wait through the recovery
// and land on the overlay's Play, queueing a second play behind the resume click.
async function resumeReleasedPlayback(owner, position, label, started, beforeResume = async () => {}) {
  const enabledPlay = page.getByRole('button', { name: 'Play', exact: true, disabled: false })
  let overlayOpen
  await until(async () => {
    await enabledPlay.waitFor({ timeout: 45000 })
    overlayOpen = await seekSlider().count() > 0
    // The reading stands only while that one enabled Play button is still on screen.
    return await enabledPlay.count() === 1
  }, `one enabled Play button after ${label}`)
  if (!overlayOpen) await enabledPlay.click()
  let reopenedPosition
  try {
    await until(async () => {
      if (await seekSlider().count() === 0) return false
      reopenedPosition = await sliderValue()
      return Math.abs(reopenedPosition - position) < 2
    }, `the overlay to show the recovered paused position after ${label}`)
  } catch (error) {
    throw new Error(`${error.message} (paused at ${position}, overlay shows ${reopenedPosition})`)
  }
  assert.equal(await page.locator('video').count(), 0, `Playback must not mount a video after ${label} before it resumes locally`)
  clientControls.push({ action: `${label}-released-paused`, at: Date.now(), elapsedMs: Date.now() - started, position, reopenedPosition, playback: null })
  await beforeResume()
  const resumeStarted = Date.now()
  await page.getByRole('button', { name: 'Play', exact: true }).click()
  video = await page.locator('video').elementHandle()
  assert(video, `Resuming after ${label} must mount a new video element`)
  await until(async () => {
    const state = await mediaState()
    return state.connected && !state.paused && state.ready >= 2 && state.error === null && !state.seeking
      && hasDecodedFrame(state) && snapshot?.ready && snapshot.status === 'running' && currentSource(state)
      && snapshot.startTime + state.time >= position - 2
  }, `resuming after ${label} to decode from the recovered position`)
  // The session hands playback to this browser once the runner acknowledges the start; a
  // native pause before that acknowledgement would leave the transfer unconfirmed.
  await until(async () => await page.getByRole('button', { name: 'Pause', exact: true }).count() > 0,
    `the playback session to confirm the local playback resumed after ${label}`)
  // The fresh player places its cursor at the recovered position with at most one source seek.
  assert(snapshot.generation >= owner.generation && snapshot.generation <= owner.generation + 1,
    `Resuming after ${label} may place the cursor with at most one source seek (generation ${owner.generation} to ${snapshot.generation})`)
  samePlayback(owner, snapshot.generation)
  owner.generation = snapshot.generation
  await recordClientControl(`${label}-resumed`, resumeStarted, { position, reopenedPosition })
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
  await selectSubtitles('capability-renewal-paused-choice', 'Forced', deadline)
  await pausePlayback(owner, 'capability-renewal-pause', Math.max(1, Math.min(15000, deadline - Date.now())))
  await observeSubtitles('capability-renewal-paused-before', 'Forced early', 'Forced', deadline)
  await crossDeadline('paused')
  await observeSubtitles('capability-renewal-paused-after', 'Forced early', 'Forced', deadline)
  await selectSubtitles('capability-renewal-running-choice', 'Off', deadline)
  await observeSubtitles('capability-renewal-running-before', null, 'Off', deadline)
  await resumeAndDecode(owner, 'capability-renewal-resume', Math.max(1, Math.min(15000, deadline - Date.now())))
  await crossDeadline('running')
  await observeSubtitles('capability-renewal-running-after', null, 'Off', deadline)
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
  await observeSubtitles('capability-renewal-normal-lifetime', null, 'Off', deadline)
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
  await page.evaluate(() => {
    window.__twoCommandSigningReceipts = []
    window.__twoCommandObserveSigningReceipts = true
  })
  try {
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
        const originalCapability = attachedCapabilities.get(originalAttachment)
        const renditionSet = snapshot.renditionSet
        const proof = { mode, attempt, status: 'observing', startedAt: Date.now(), attachment: originalAttachment,
          renditionSet, before: { time: before.time, decodedFrames: before.decodedFrames } }
        progressOutages.push(proof)
        let routeError
        let failedRequest
        let failureObserved = false
        let finalBaseline
        let browserPublications = []
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
        const freshCanonical = () => playbackSnapshots.filter(item => item.accepted && item.ready
          && item.at >= proof.startedAt && item.jobId === owner.jobId && item.sessionId === owner.sessionId
          && item.generation === owner.generation)
        const publicationChange = () => browserPublications.find(item => item.renditionSet !== renditionSet)
        const checkMediaEvents = async () => {
          const capabilities = [...attachedCapabilities.values()]
          const { events, receipts } = await page.evaluate(() => ({
            events: window.__twoCommandMediaEvents ?? [], receipts: window.__twoCommandSigningReceipts ?? [],
          }))
          browserPublications = receipts.filter(item => item.receivedAt >= proof.startedAt && item.ready
            && item.jobId === owner.jobId && item.sessionId === owner.sessionId && item.generation === owner.generation)
          proof.publications = browserPublications
          const changed = publicationChange()
          const boundary = changed ? changed.receivedAt : Infinity
          assert(!events.some(event => !event.probe && event.at >= proof.startedAt
            && (event.event === 'error' || (event.event === 'emptied' && event.at < boundary))),
          'A progress failure must not tear down decoded media before a fresh publication change')
          proof.sourceDetachments = qualifySourceDetachments(events.filter(event => !event.probe
            && event.at >= proof.startedAt && event.event === 'source-detached'),
          capabilities, browserPublications, originalCapability)
          let previousSet = renditionSet
          let previousAttachmentAt = originalCapability.at
          for (const capability of capabilities) {
            if (capability.attachment <= originalAttachment) continue
            const set = new URL(capability.url).searchParams.get('set')
            assert(set !== previousSet && browserPublications.some(item => item.renditionSet === set
              && item.receivedAt > previousAttachmentAt && item.receivedAt <= capability.at),
            'Every post-failure replacement must follow an authenticated rendition change')
            previousSet = set
            previousAttachmentAt = capability.at
          }
          return proof.sourceDetachments.every(item => item.successorAttachment !== null)
        }
        const superseded = () => {
          unchangedExecution()
          if (routeError) throw routeError
          if (proof.preserved) return false
          const changed = publicationChange()
          if (!changed) return false
          proof.status = 'superseded-by-publication'
          proof.publication = { receivedAt: changed.receivedAt, renditionSet: changed.renditionSet }
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
            const mediaQualified = await checkMediaEvents()
            if (superseded()) return true
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
            if (attachmentNumber === originalAttachment) assert.equal(state.source, before.source,
              'An unchanged attachment must retain its decoded media source')
            if (!mediaQualified || !proof.success || proof.success.position <= proof.fault.position) return false
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
            const canonical = freshCanonical().find(item => item.at >= proof.success.responseAt
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
        await waitFor(checkMediaEvents, 'every progress source detachment to receive its authenticated successor master')
        if (routeError) throw routeError
        await recordClientControl(`progress-outage-${mode}-${proof.status}`, proof.startedAt, { attempt })
      }
      assert(passed, `Progress outage ${mode} did not retain a stable rendition set within three attempts`)
    }
  } finally {
    await page.evaluate(() => { window.__twoCommandObserveSigningReceipts = false })
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
        const capabilities = [...attachedCapabilities.values()]
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
        capabilities, fresh, originalCapability)
        let previousSet = renditionSet
        let previousAt = originalCapability.at
        for (const capability of capabilities) {
          if (capability.attachment <= originalAttachment) continue
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

// The movie seek control is a Radix slider: the element with the slider role is its
// thumb, which carries the accessible bounds and value, while pointer gestures land on
// the root that Radix renders with a text direction attribute.
function seekSlider() {
  return page.getByRole('slider', { name: 'Seek movie' })
}

async function sliderMax(slider = seekSlider()) {
  return Number(await slider.getAttribute('aria-valuemax'))
}

async function sliderValue(slider = seekSlider()) {
  return Number(await slider.getAttribute('aria-valuenow'))
}

// The player marks each seek commit, seek entry, queued intent and transport send with a
// performance mark; the marks are ordered evidence of what the player did with a gesture.
async function playerMarks() {
  return page.evaluate(() => performance.getEntriesByType('mark')
    .filter(entry => entry.name.startsWith('baander:'))
    .map(entry => ({ name: entry.name, at: Math.round(entry.startTime), ...entry.detail })))
}

async function sliderTrackBox(slider = seekSlider()) {
  const box = await slider.locator('xpath=ancestor::*[@dir][1]').boundingBox()
  assert(box, 'The source timeline must be visible')
  return box
}

async function commitSourceSeek(position, sourceDuration = snapshot.duration) {
  const timeline = seekSlider()
  assert.equal(await sliderMax(timeline), sourceDuration, 'Source seeking must span the full source duration')
  assert(position >= 0 && position < sourceDuration, 'The source target must fit the source duration')
  if (position === 0) {
    await timeline.focus()
    await timeline.press('Home')
    return 0
  }
  const box = await sliderTrackBox(timeline)
  await page.mouse.move(box.x + box.width * position / sourceDuration, box.y + box.height / 2)
  await page.mouse.down()
  try {
    // Read the slider's actual value before pointer-up commits the draft.
    return await sliderValue(timeline)
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
  const acceptSeek = async (target, generation) => {
    let submission
    let acknowledgement
    await waitFor(async () => {
      submission = controls().find(item => item.action === 'seek' && item.position === target)
      if (!submission) return false
      assert.equal(submission.generation, generation, 'The seek must submit its currently owned generation')
      assert.equal(typeof submission.requestId, 'string', 'Each logical seek must retain its request identity')
      const receipts = await page.evaluate(() => window.__twoCommandSeekReceipts ?? [])
      acknowledgement = receipts.find(item => item.requestId === submission.requestId)
      if (!acknowledgement) return false
      assert.equal(acknowledgement.acceptedGeneration, generation + 1, 'The actual seek ACK must advance its submitted generation once')
      return true
    }, 'the actual source seek acknowledgement')
    return { submission, acknowledgement, preparing: [] }
  }
  const readyExecution = seek => {
    const accepted = seek.acknowledgement.acceptedGeneration
    assert.equal(snapshot.jobId, owner.jobId, 'Seek execution must retain the owned job')
    assert.equal(snapshot.sessionId, owner.sessionId, 'Seek execution must retain the owned session')
    const canonical = playbackSnapshots.filter(item => item.accepted && item.at >= seek.submission.at
      && item.jobId === owner.jobId && item.sessionId === owner.sessionId
      && (item.generation === accepted || item.generation === accepted + 1))
    seek.preparing = canonical.filter(item => !item.ready).map(item => ({ at: item.at,
      requestedAt: item.requestedAt, ...safePlayback(item) }))
    if (!snapshot.ready || (snapshot.generation !== accepted && snapshot.generation !== accepted + 1)) return false
    const ready = canonical.findLast(item => item.ready && item.generation === snapshot.generation)
    if (!ready) return false
    seek.ready = { at: ready.at, requestedAt: ready.requestedAt, ...safePlayback(ready) }
    return true
  }
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
  await page.evaluate(() => {
    window.__twoCommandSeekReceipts = []
    window.__twoCommandObserveSeekReceipts = true
  })
  shortSigningLifetime = true
  try {
    const requested = await commitSourceSeek(sourceEnd - 12)
    proof.tailSeek = await acceptSeek(requested, owner.generation)
    await waitFor(async () => {
      const sampled = await sample()
      if (!sampled) return false
      const { state } = sampled
      return readyExecution(proof.tailSeek) && currentSource(state)
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
    proof.replaySeek = await acceptSeek(0, owner.generation)
    let replayBaseline
    let replayGeneration
    await waitFor(async () => {
      if (routeError) throw routeError
      proof.replayPlay = await page.evaluate(() => window.__twoCommandReplayPlay)
      assert(!proof.replayPlay.error || proof.replayPlay.error === 'AbortError',
        `Native replay Play failed unexpectedly: ${proof.replayPlay.error}`)
      const sampled = await sample()
      if (!sampled) return false
      const { state } = sampled
      if (replayGeneration !== undefined) assertOwner(replayGeneration)
      if (!readyExecution(proof.replaySeek) || !currentSource(state)
        || state.paused || state.ended || state.seeking || state.error !== null || state.ready < 2
        || state.decodedFrames <= 0) return false
      assert.equal(snapshot.startTime, 0, 'Replay must attach the full source beginning')
      replayGeneration ??= snapshot.generation
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
    assertOwner(replayGeneration)
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
    await page.evaluate(() => { window.__twoCommandObserveSeekReceipts = false })
    await page.unroute(routePattern, intercept)
    await Promise.allSettled([...pendingRoutes])
    page.off('requestfailed', failed)
    await savePlaybackTimeline()
  }
  return proof
}

function renditionExecutions(renditionSet) {
  return renditionSet.split(',').map(item => {
    const separator = item.lastIndexOf('.')
    return [item.slice(0, separator), item.slice(separator + 1)]
  })
}

// A movie's playlists grow while its encoders run ahead of playback, so the published
// edge moves away from the playhead. That edge is a delivery window, never a playback
// target: the playhead may wait at it, but only a committed seek may move the clock
// faster than the wall clock or restart the encoders into a new generation. The given
// committed seek restarted the encoders; this window observes the playback that follows.
async function proveCursorAuthority(seek) {
  const windowMs = 24000
  // Slower production never leaves the playhead behind, and the proof would pass idly.
  const requiredProducerRate = 1.5
  // The media clock and the wall clock are sampled in one page task, so the bound absorbs
  // only clock granularity and the player's stall nudges, which total at most 0.6 s. It
  // stays far below one six-second segment, the smallest step toward the published edge.
  const toleranceSeconds = 1
  // The player corrects buffer gaps and stalls by assigning the media clock itself. Whether
  // it does so in a given run depends on timing, so the window makes one such assignment.
  const correctionSeconds = 0.5
  const owner = ownedPlayback()
  const executions = new Map(renditionExecutions(snapshot.renditionSet))
  const proof = { startedAt: Date.now(), windowSeconds: windowMs / 1000, requiredProducerRate, toleranceSeconds,
    seek: { at: seek.submission.at, position: seek.submission.position, requestId: seek.submission.requestId,
      acceptedGeneration: seek.acknowledgement.acceptedGeneration },
    jobId: owner.jobId, sessionId: owner.sessionId, generation: owner.generation, startTime: snapshot.startTime,
    renditionSet: snapshot.renditionSet, samples: [], corrections: [], publications: [], marks: [] }
  cursorAuthority.push(proof)
  const round = value => Math.round(value * 1000) / 1000
  const seekMarks = () => page.evaluate(since => performance.getEntriesByType('mark')
    .filter(entry => /^baander:(?:native-seek|seek-)/.test(entry.name) && performance.timeOrigin + entry.startTime >= since)
    .map(entry => ({ name: entry.name, at: Math.round(performance.timeOrigin + entry.startTime), ...entry.detail })), seek.submission.at)
  const assertExecution = () => {
    const uncommitted = playbackControls.filter(control => control.action === 'seek'
      && control.at >= seek.submission.at && control.requestId !== seek.submission.requestId)
    assert.equal(uncommitted.length, 0,
      `Only a committed seek may reach the server; the player sent ${uncommitted.length} without one, the first to ${uncommitted[0]?.position}`)
    assert.equal(snapshot.jobId, owner.jobId, 'Playback against a growing presentation must retain the owned job')
    assert.equal(snapshot.sessionId, owner.sessionId, 'Playback against a growing presentation must retain the owned session')
    const observed = [...playbackSnapshots.filter(item => item.accepted && item.at >= proof.startedAt
      && item.jobId === owner.jobId && typeof item.renditionSet === 'string'), snapshot]
    for (const item of observed) {
      assert.equal(item.generation, owner.generation, 'No execution generation may advance without a committed seek')
      for (const [job, generation] of renditionExecutions(item.renditionSet)) {
        const known = executions.get(job)
        assert(known === undefined || known === generation,
          `A rendition restarted from generation ${known} into ${generation} without a committed seek`)
        executions.set(job, generation)
      }
    }
  }
  // The clock may never gain on the wall clock since any earlier observation, starting
  // with the committed seek itself: its position at the moment it was sent.
  let wallOffsetFloor = seek.submission.position - seek.submission.at / 1000
  let corrected = 0
  let first = null
  let last = null
  let furthest = -Infinity
  try {
    while (Date.now() < proof.startedAt + windowMs) {
      assertExecution()
      const attachment = attachmentNumber
      const state = await mediaState()
      if (attachment === attachmentNumber) {
        assert.equal(state.error, null, 'Playback against a growing presentation must stay free of media errors')
        assert.equal(state.playbackRate, 1, 'The window must play at normal speed for the wall clock to bound it')
        const clock = snapshot.startTime + state.time
        const wallOffset = clock - state.at / 1000 - corrected
        const ahead = wallOffset - wallOffsetFloor
        last = { at: state.at, attachment, clock: round(clock), aheadOfWall: round(ahead), paused: state.paused,
          seeking: state.seeking, ready: state.ready, decodedFrames: state.decodedFrames }
        proof.samples.push(last)
        assert(ahead <= toleranceSeconds,
          `The player clock at ${clock.toFixed(2)} s ran ${ahead.toFixed(2)} s ahead of wall-clock playback plus committed seeks`)
        wallOffsetFloor = Math.min(wallOffsetFloor, wallOffset)
        if (!state.paused && !state.seeking && state.ready >= 3) {
          first ??= last
          furthest = Math.max(furthest, clock)
        }
        const pending = proof.corrections.at(-1)
        if (pending && !pending.recorded) {
          pending.recorded = (await seekMarks()).find(mark => mark.name === 'baander:native-seek'
            && mark.at >= pending.at - 50 && Math.abs(mark.currentTime - pending.to) <= 0.05) ?? null
        }
        if (Date.now() >= proof.startedAt + windowMs / 2 && proof.corrections.length < 3
          && (!pending || (!pending.recorded && Date.now() >= pending.at + 2000))) {
          const correction = await video.evaluate((element, offset) => {
            const from = element.currentTime
            const buffered = Array.from({ length: element.buffered.length }, (_, index) => [element.buffered.start(index), element.buffered.end(index)])
            if (element.paused || element.seeking || element.readyState < 3
              || !buffered.some(([start, end]) => from >= start && from + offset + 1 <= end)) return null
            element.currentTime = from + offset
            return { at: Date.now(), from, to: element.currentTime }
          }, correctionSeconds)
          if (correction) {
            corrected += correction.to - correction.from
            proof.corrections.push({ ...correction, attachment: attachmentNumber, recorded: null })
          }
        }
      }
      await new Promise(resolve => setTimeout(resolve, 200))
    }
    assertExecution()
    proof.marks = await seekMarks()
    const sent = proof.marks.filter(mark => mark.name === 'baander:seek-send' && mark.at >= proof.startedAt)
    assert.equal(sent.length, 0, 'The player must not send a seek during a window without a committed seek')
    const correction = proof.corrections.find(item => item.recorded)
    assert(correction, 'The window must apply one player-internal correction that the player records as a native seek')
    assert(first && last.decodedFrames > first.decodedFrames && furthest - first.clock >= windowMs / 2000,
      `Playback must advance through at least half the window (from ${first?.clock} to ${furthest})`)
    proof.playedSeconds = round(furthest - first.clock)
    proof.publications = manifestEvidence.filter(item => item.at >= seek.submission.at && item.path.includes('/tracks/video/')
      && executions.get(/^\/api\/transcode\/([^/]+)\//.exec(item.path)?.[1]) === item.generation).map(item => {
      const publishedSeconds = item.body.split('\n').filter(line => line.startsWith('#EXTINF:'))
        .reduce((total, line) => total + Number(line.slice(8).split(',')[0]), 0)
      const elapsedSeconds = (item.at - seek.submission.at) / 1000
      return { at: item.at, attachment: item.attachment, path: item.path, generation: item.generation,
        publishedSeconds: round(publishedSeconds), elapsedSeconds: round(elapsedSeconds), rate: round(publishedSeconds / elapsedSeconds) }
    })
    // Publication trails encoding, so no observation overstates the rate since the restart.
    const fastest = proof.publications.reduce((best, item) => !best || item.rate > best.rate ? item : best, null)
    proof.producerRate = fastest?.rate ?? null
    assert(fastest && fastest.rate >= requiredProducerRate,
      `The producer must outrun playback at least ${requiredProducerRate} times to qualify this window; it published at most ${fastest?.publishedSeconds ?? 0} s in ${fastest?.elapsedSeconds ?? 0} s`)
    const publishedSeconds = Math.max(...proof.publications.map(item => item.publishedSeconds))
    proof.leadSeconds = round(proof.startTime + publishedSeconds - last.clock)
    assert(proof.leadSeconds > 0, 'The published edge must lead the playhead at the end of the window')
    proof.finishedAt = Date.now()
  } catch (error) {
    proof.failure = error.message
    proof.marks = await seekMarks().catch(() => proof.marks)
    throw error
  } finally {
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
    const sourceDuration = await sliderMax()
    assert.equal(sourceDuration, snapshot.duration, 'The captured source range must match the ready source duration')
    const initialSliderPosition = await sliderValue()
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
      // Each commit also records what the slider shows afterwards: the thumb settles on the
      // pending seek position the player accepted, so a dropped commit is visible here.
      const commitAndRecord = async target => {
        const committed = await commitSourceSeek(target, sourceDuration)
        proof.committedTargets.push(committed)
        const [shown, text] = await Promise.all([sliderValue(), seekSlider().getAttribute('aria-valuetext')])
        ;(proof.commits ??= []).push({ at: Date.now(), target, committed, shown, text })
        proof.intents = await playerMarks()
      }
      await commitAndRecord(targets[0])
      if (mode === 'accepted-response-loss') {
        await waitFor(() => { checkOwnership(); return proof.firstResponse }, 'the genuine first seek response to be held')
        for (const target of targets.slice(1)) await commitAndRecord(target)
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

async function proveMovieEndpoint(baseUrl, credentials) {
  assert.equal(credentials.scenario, 'movie-endpoint')
  assert.deepEqual(credentials.endpoint, { videoEnd: 42, containerEnd: 48 })
  const sourceEnd = credentials.endpoint.videoEnd
  const proof = { startedAt: Date.now(), oracle: credentials.endpoint, seeks: [], replays: [] }
  movieEndpointProof = proof
  const deadline = proof.startedAt + 80000
  const remaining = () => {
    assert(Date.now() < deadline, 'Movie endpoint proof exceeded its total time budget')
    return deadline - Date.now()
  }
  const waitFor = (check, description) => until(check, description, remaining())
  const persist = () => writeFile(`${evidence}/movie-endpoint.json`, JSON.stringify(proof, null, 2))
  const safeMedia = state => ({ ...state, source: state.source ? new URL(state.source).pathname : null })
  await page.goto(`${baseUrl}/movies/${credentials.moviePublicId}`)
  await page.getByRole('button', { name: 'Play', exact: true }).click()
  video = await page.locator('video').elementHandle()
  assert(video, 'The actual movie page must attach its product player')
  await waitFor(async () => {
    const state = await mediaState()
    return snapshot?.ready && state.connected && !state.paused && state.ready >= 2
      && state.time > 0.25 && state.decodedFrames > 0 && state.error === null && currentSource(state)
  }, 'the actual movie player to decode its signed source')
  const timeline = seekSlider()
  proof.initial = { at: Date.now(), playback: safePlayback(snapshot),
    signing: playbackSnapshots.filter(item => item.accepted).map(item => ({ at: item.at, ...safePlayback(item) })),
    slider: { max: await timeline.getAttribute('aria-valuemax'), value: await timeline.getAttribute('aria-valuenow'),
      valueText: await timeline.getAttribute('aria-valuetext') }, media: safeMedia(await mediaState()) }
  // Retain the real old-resolver response and range before the endpoint assertion can fail.
  await persist()
  assert.equal(snapshot.duration, sourceEnd, 'Actual signing duration must describe the selected video endpoint')
  assert.equal(Number(proof.initial.slider.max), sourceEnd, 'The real movie slider must stop at the selected video endpoint')
  const owner = ownedPlayback()
  await page.evaluate(() => {
    window.__twoCommandSeekReceipts = []
    window.__twoCommandObserveSeekReceipts = true
  })
  const acceptSeek = async (position, startedAt, previousGeneration) => {
    let submission
    let acknowledgement
    await waitFor(async () => {
      submission = playbackControls.find(item => item.at >= startedAt && item.action === 'seek' && item.position === position)
      if (!submission) return false
      assert.equal(submission.generation, previousGeneration)
      const receipts = await page.evaluate(() => window.__twoCommandSeekReceipts)
      acknowledgement = receipts.find(item => item.requestId === submission.requestId)
      if (!acknowledgement) return false
      assert.equal(acknowledgement.acceptedGeneration, previousGeneration + 1)
      return true
    }, `the real endpoint seek to ${position} to receive its owned generation acknowledgement`)
    const before = playbackSnapshots.findLast(item => item.accepted && item.at <= submission.at
      && item.jobId === owner.jobId && item.sessionId === owner.sessionId && item.generation === previousGeneration)
    assert(before, 'Each endpoint seek must retain its preceding canonical revision')
    const seek = { position, submission, acknowledgement, before: { at: before.at, ...safePlayback(before) } }
    proof.seeks.push(seek)
    return seek
  }
  const waitReady = async (seek, predicate) => {
    const accepted = seek.acknowledgement.acceptedGeneration
    await waitFor(async () => {
      assert.equal(snapshot.jobId, owner.jobId, 'Seek restoration must retain the owned job')
      assert.equal(snapshot.sessionId, owner.sessionId, 'Seek restoration must retain the canonical session')
      assert(snapshot.generation <= accepted + 1, 'Seek restoration must reject unrelated execution generations')
      if (!snapshot.ready || (snapshot.generation !== accepted && snapshot.generation !== accepted + 1)) return false
      const canonical = playbackSnapshots.filter(item => item.accepted
        && item.requestedAt >= seek.submission.at && item.at >= seek.acknowledgement.receivedAt
        && item.jobId === owner.jobId && item.sessionId === owner.sessionId
        && (item.generation === accepted || item.generation === accepted + 1))
      const intent = canonical.find(item => item.position === seek.position && item.revision > seek.before.revision)
      if (!intent) return false
      const ready = canonical.findLast(item => item.ready && item.generation === snapshot.generation
        && item.revision >= intent.revision)
      if (!ready || snapshot.revision < intent.revision) return false
      assert.equal(snapshot.duration, sourceEnd)
      const state = await mediaState()
      if (!state.connected || state.error !== null || state.seeking || !currentSource(state)) return false
      if (!predicate(state)) return false
      seek.intent = { at: intent.at, requestedAt: intent.requestedAt, ...safePlayback(intent) }
      seek.ready = { at: ready.at, requestedAt: ready.requestedAt, ...safePlayback(ready) }
      seek.decodedGeneration = snapshot.generation
      seek.restored = { at: Date.now(), playback: safePlayback(snapshot), media: safeMedia(state) }
      return true
    }, `the accepted endpoint seek to ${seek.position} to restore real media`)
    owner.generation = seek.decodedGeneration
    await persist()
  }
  const replay = async reason => {
    const startedAt = Date.now()
    const generation = owner.generation
    await video.evaluate(element => {
      window.__twoCommandEndpointPlay = { settled: false }
      element.play().then(() => { window.__twoCommandEndpointPlay = { settled: true } }, error => {
        window.__twoCommandEndpointPlay = { settled: true, error: error.name }
      })
    })
    const seek = await acceptSeek(0, startedAt, generation)
    let baseline
    await waitReady(seek, state => {
      if (state.paused || state.ended || state.ready < 2 || snapshot.startTime !== 0 || state.decodedFrames <= 0) return false
      if (!baseline || baseline.attachment !== attachmentNumber) {
        baseline = { attachment: attachmentNumber, time: state.time, decodedFrames: state.decodedFrames }
        return false
      }
      return state.time > baseline.time + 1 && state.decodedFrames > baseline.decodedFrames
    })
    const play = await page.evaluate(() => window.__twoCommandEndpointPlay)
    assert(play.settled && (!play.error || play.error === 'AbortError'), 'Native Play must settle while source-zero replay decodes')
    assert.equal(snapshot.status, 'running')
    for (const track of ['video', 'audio']) {
      assert(mediaResponses.some(item => item.at >= startedAt && item.attachment === attachmentNumber
        && item.status === 200 && item.track === track && item.path.endsWith('/segment.m4s')),
      'Replay must receive current signed audio and video media')
    }
    proof.replays.push({ reason, startedAt, baseline, seek })
    await persist()
  }
  try {
    await pausePlayback(owner, 'endpoint-before-exact-seek', remaining())
    const exactStarted = Date.now()
    await timeline.focus()
    await timeline.press('End')
    const exact = await acceptSeek(sourceEnd, exactStarted, owner.generation)
    await waitReady(exact, state => state.paused && snapshot.status === 'paused' && state.ready >= 2
      && Math.abs(snapshot.startTime + state.time - sourceEnd) <= 0.1)
    await waitFor(async () => await sliderValue(timeline) === sourceEnd,
      'exact-end restoration to retain the global slider endpoint')
    await replay('exact-end')
    const tailStarted = Date.now()
    const requested = await commitSourceSeek(sourceEnd - 12)
    const tail = await acceptSeek(requested, tailStarted, owner.generation)
    await waitReady(tail, state => !state.paused && state.ready >= 2 && state.decodedFrames > 0
      && snapshot.startTime + state.time >= requested && snapshot.status === 'running')
    let baseline
    let initialCapability
    await waitFor(async () => {
      const initialAttachment = attachmentNumber
      const state = await mediaState()
      if (attachmentNumber !== initialAttachment || !currentSource(state)) return false
      initialCapability = attachedCapabilities.get(initialAttachment)
      assert(initialCapability, 'Tail playback must retain its actual signed attachment')
      baseline = { attachment: initialAttachment, ...state }
      return true
    }, 'a stable owned attachment to establish the initial tail baseline')
    proof.tailAttachments = [{ attachment: baseline.attachment, reason: 'accepted-seek',
      capability: initialCapability.capability, renditionSet: snapshot.renditionSet,
      playback: safePlayback(snapshot), baseline: safeMedia(baseline) }]
    let ended
    await waitFor(async () => {
      samePlayback(owner)
      const sampledAttachment = attachmentNumber
      const state = await mediaState()
      if (attachmentNumber !== sampledAttachment) return false
      assert.equal(state.error, null)
      assert.equal(state.playbackRate, 1)
      if (!currentSource(state)) return false
      if (sampledAttachment !== baseline.attachment) {
        const replacementAttachment = sampledAttachment
        const capability = attachedCapabilities.get(replacementAttachment)
        assert(capability, 'A replacement must retain its actual signed capability')
        const renditionSet = new URL(capability.url).searchParams.get('set')
        assert.equal(renditionSet, snapshot.renditionSet, 'Replacement media must use the current canonical rendition set')
        assert(renditionSet.split(',').includes(`${owner.jobId}.${owner.generation}`),
          'A replacement must retain the same owned execution generation')
        const events = await page.evaluate(() => window.__twoCommandMediaEvents ?? [])
        if (attachmentNumber !== replacementAttachment) return false
        const metadata = events.find(event => !event.probe && event.event === 'loadedmetadata'
          && event.at >= capability.at && event.at >= tailStarted && !event.ended && event.errorCode === null
          && event.source === new URL(state.source).pathname)
        if (!metadata) return false
        const previous = proof.tailAttachments.at(-1)
        const previousPairs = previous.renditionSet.split(',')
        const currentPairs = renditionSet.split(',')
        const reason = renditionSet === previous.renditionSet ? 'capability-renewal' : 'rendition-set-expansion'
        if (reason === 'capability-renewal') {
          assert.notEqual(capability.capability, previous.capability, 'Renewal must carry a fresh signed capability')
        } else {
          assert(currentPairs.length > previousPairs.length && previousPairs.every(pair => currentPairs.includes(pair)),
            'A tail rendition replacement must only add ready siblings to the owned set')
        }
        // Native metadata records the new decoder before its first frames, even when replacement is close to EOF.
        baseline = { ...metadata, attachment: replacementAttachment, source: state.source }
        proof.tailAttachments.push({ attachment: replacementAttachment, previousAttachment: previous.attachment,
          reason, capability: capability.capability, renditionSet, playback: safePlayback(snapshot),
          baseline: safeMedia(baseline) })
        await persist()
        if (attachmentNumber !== replacementAttachment) return false
      }
      if (attachmentNumber !== sampledAttachment) return false
      samePlayback(owner)
      if (!state.ended || !state.paused || state.seeking) return false
      assert.equal(attachmentNumber, baseline.attachment, 'Natural endpoint completion must retain its evidenced owned attachment')
      assert.equal(state.source, baseline.source, 'Native completion must belong to the decoder whose baseline was retained')
      assert(Math.abs(snapshot.startTime + state.time - sourceEnd) <= 0.1)
      assert(Math.abs(state.time - state.duration) <= 0.1, 'Natural completion must reach the native presentation end')
      assert(state.time > baseline.time && state.decodedFrames > baseline.decodedFrames,
        'Natural completion must decode advancing tail frames')
      ended = state
      return true
    }, 'natural playback to reach the actual selected video endpoint')
    await waitFor(async () => {
      const events = await page.evaluate(() => window.__twoCommandMediaEvents ?? [])
      return events.some(event => !event.probe && event.event === 'ended' && event.at >= tailStarted
        && event.source === new URL(ended.source).pathname
        && event.ended && Math.abs(event.time - ended.time) <= 0.1)
    }, 'the genuine native endpoint event')
    await waitFor(() => snapshot.status === 'paused' && Math.abs(snapshot.position - sourceEnd) <= 0.1,
      'canonical completion to pause the source at its exact video endpoint')
    const endingPauses = playbackControls.filter(item => item.at >= tailStarted && item.action === 'pause')
    assert.equal(endingPauses.length, 1, 'Natural completion must submit one canonical ending pause')
    assert.equal(endingPauses[0].position, sourceEnd)
    proof.natural = { baseline: safeMedia(baseline), ended: safeMedia(ended),
      playback: safePlayback(snapshot), endingPause: endingPauses[0] }
    await replay('natural-end')
    samePlayback(owner)
    proof.controls = playbackControls.filter(item => item.at >= proof.startedAt && item.action !== 'progress')
    assert.deepEqual(proof.controls.filter(item => item.action === 'seek').map(item => item.position),
      [sourceEnd, 0, requested, 0], 'Exact-end and natural-end proofs must each replay through one source-zero seek')
    proof.finishedAt = Date.now()
    proof.elapsedMs = proof.finishedAt - proof.startedAt
    await persist()
    await savePlaybackTimeline()
  } finally {
    await page.evaluate(() => { window.__twoCommandObserveSeekReceipts = false })
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
  } else if (phase === 'movie-endpoint') {
    assert.deepEqual(phases, ['authenticated'])
    await proveMovieEndpoint(baseUrl, credentials)
  } else if (phase === 'endpoint-complete') {
    assert.deepEqual(phases, ['authenticated', 'movie-endpoint'])
    assert(movieEndpointProof?.finishedAt, 'Endpoint teardown requires completed exact and natural replay proofs')
    await page.getByRole('button', { name: 'Back', exact: true }).click()
    await page.locator('video').waitFor({ state: 'detached' })
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
    await selectSubtitles('source-initial-dialogue-choice', 'Dialogue')
    await observeSubtitles('source-initial-dialogue', 'Dialogue early')
    await selectSubtitles('source-seek-forced-choice', 'Forced')
    await observeSubtitles('source-seek-forced-before', 'Forced early', 'Forced')
    const requested = await seekSourceAndDecode(owner, target, 'source-seek-beyond-window')
    assert(requested > publishedEnd, 'The movie source control must seek beyond the old native window')
    await observeSubtitles('source-seek-forced-after', 'Forced sought', 'Forced')
    await selectSubtitles('source-sought-dialogue-choice', 'Dialogue')
    await observeSubtitles('source-sought', 'Dialogue sought')
    await pausePlayback(owner, 'source-pause')
    await selectSubtitles('source-seek-paused-off-choice', 'Off')
    await observeSubtitles('source-seek-paused-off-before', null, 'Off')
    await seekSourceAndDecode(owner, Math.min(snapshot.duration - 24, requested + 24), 'source-seek-forward-paused', true)
    await observeSubtitles('source-seek-paused-off-after', null, 'Off')
    await selectSubtitles('source-sought-paused-dialogue-choice', 'Dialogue')
    await observeSubtitles('source-sought-paused', 'Dialogue sought')
    await seekSourceAndDecode(owner, 0, 'source-seek-return-to-start-paused', true)
    await resumeAndDecode(owner, 'source-resume-after-return')
    await observeSubtitles('attached', 'Dialogue early')
    await renewPlaybackCapabilities(owner)
    await reconnectPausedPlayback(owner, 'source-network')
    restingCursor = await restingCursorNow()
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
    const initialSliderPosition = await sliderValue()
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
    // Recovery restores the position the playback session last recorded. A native pause
    // reaches the session only with the next presence observation, so the pause before
    // the reload goes through the overlay, whose command is acknowledged with its position.
    const sessionAcks = () => apiResponses.filter(item => item.path === '/api/session/ack' && item.status === 200).length
    const pauseButton = page.getByRole('button', { name: 'Pause', exact: true })
    await until(async () => await pauseButton.count() > 0, 'the playback session to observe the resumed playback', 20000)
    const acksBeforePause = sessionAcks()
    const pauseStarted = Date.now()
    await pauseButton.click()
    await until(async () => (await mediaState()).paused && snapshot?.status === 'paused' && sessionAcks() > acksBeforePause,
      'the overlay pause to commit and be acknowledged to the playback session')
    samePlayback(owner)
    await recordClientControl('pause-before-remount', pauseStarted)
    const pausedPosition = await seekablePausedPosition('before reload')
    samePlayback(owner)

    const remountStarted = Date.now()
    previousMediaEvents.push(...await page.evaluate(() => window.__twoCommandMediaEvents ?? []))
    const previousVideo = video
    snapshot = undefined
    // The reload leaves a runner whose pause the session confirmed, and the reloaded page
    // registers a new endpoint for the same tab. The answer to that registration must
    // already show the session without an active runner, in every engine: an immediate
    // Play is otherwise refused until a later snapshot releases the runner. The answer is
    // kept as evidence whether or not the phase gets as far as checking it.
    const registration = observeRegistrationAnswer().then(answer => {
      const at = answer?.at ?? Date.now()
      clientControls.push({ action: 'remount-registration', at, elapsedMs: at - remountStarted, answered: answer !== null,
        sinceRegistrationMs: answer?.sinceRegistrationMs ?? null, revision: answer?.revision ?? null,
        activeIncarnationId: answer ? answer.activeIncarnationId : undefined, playback: null })
      return answer
    })
    await page.reload()
    await previousVideo.dispose()
    // The overlay's Play is enabled only after the page has handled the registration answer,
    // so reading that answer here does not delay the resume click that follows.
    await resumeReleasedPlayback(owner, pausedPosition, 'remount', remountStarted, async () => {
      const answer = await registration
      assert(answer, 'The reloaded page must receive an answer to its endpoint registration')
      assert.equal(answer.activeIncarnationId, null, 'The registration answer after a reload must already show the confirmed paused runner released')
    })

    // A second reload, this time with a start pending: the session shows the page a play, and
    // the page goes away before the session hears its answer. No acknowledged pause stands
    // behind such a runner any more, so only the reload itself can release it: the page's close
    // report, or its next page registering for the same tab. The pause that precedes it goes
    // through the overlay and is acknowledged, so the session has a recorded cursor to fall back to.
    await until(async () => await pauseButton.count() > 0, 'the playback session to observe the playback resumed after the reload', 20000)
    const acksBeforePendingStart = sessionAcks()
    const pendingStartPause = Date.now()
    // The page takes its next control only once it has read the outcome of this one, so the
    // Play below waits for the pause's own result, not just for its acknowledgement.
    const pauseSettled = page.waitForResponse(async response => {
      if (new URL(response.url()).pathname !== '/api/session/control' || response.request().postDataJSON()?.action !== 'pause') return false
      const body = await response.json().catch(() => null)
      return body?.data?.requestResult?.outcome === 'paused'
    }, { timeout: 45000 })
    // Awaited below; a failure of the step before it must not leave this one unhandled.
    pauseSettled.catch(() => {})
    await pauseButton.click()
    await until(async () => (await mediaState()).paused && snapshot?.status === 'paused' && sessionAcks() > acksBeforePendingStart,
      'the overlay pause before the pending start to commit and be acknowledged to the playback session')
    await pauseSettled
    samePlayback(owner)
    await recordClientControl('pause-before-pending-start', pendingStartPause)
    await seekablePausedPosition('before the pending start')
    samePlayback(owner)
    // The page's answers are held back and never forwarded: the session must not learn that
    // the play it showed was carried out.
    const answerRoute = '**/api/session/ack'
    const heldAnswers = []
    const holdAnswer = route => { heldAnswers.push(route) }
    const releaseRequests = observeReleaseRequests()
    let pendingStartPosition
    let pendingStartReload
    let pendingStartRegistration
    await page.route(answerRoute, holdAnswer)
    try {
      const playShown = Date.now()
      await page.getByRole('button', { name: 'Play', exact: true }).click()
      await until(() => heldAnswers.length > 0, 'the page to answer the play the session showed it', 20000)
      const heldAnswer = heldAnswers[0].request().postDataJSON()
      assert.equal(heldAnswer?.outcome, 'playing', 'The answer held back before the reload must be the page\'s answer to a play')
      // The page played from the acknowledged pause until now; a close report carries this cursor.
      const playing = await mediaState()
      assert(snapshot?.ready, 'The page must hold its playback when it reloads with the start pending')
      pendingStartPosition = snapshot.startTime + playing.time
      clientControls.push({ action: 'pending-start-shown', at: Date.now(), elapsedMs: Date.now() - playShown, position: pendingStartPosition,
        commandId: heldAnswer.commandId ?? null, revision: heldAnswer.revision ?? null, playback: safePlayback(snapshot) })

      pendingStartReload = Date.now()
      previousMediaEvents.push(...await page.evaluate(() => window.__twoCommandMediaEvents ?? []))
      const pendingStartVideo = video
      snapshot = undefined
      pendingStartRegistration = observeRegistrationAnswer().then(answer => {
        const at = answer?.at ?? Date.now()
        clientControls.push({ action: 'pending-start-registration', at, elapsedMs: at - pendingStartReload, answered: answer !== null,
          sinceRegistrationMs: answer?.sinceRegistrationMs ?? null, revision: answer?.revision ?? null,
          activeIncarnationId: answer ? answer.activeIncarnationId : undefined,
          closeReportRequested: releaseRequests.seen.closeReport, localRecoveryRequested: releaseRequests.seen.localRecovery, playback: null })
        return answer
      })
      await page.reload()
      await pendingStartVideo.dispose()
    } finally {
      await page.unroute(answerRoute, holdAnswer)
      // The reload has already cancelled a held request; aborting it again is expected to fail.
      await Promise.all(heldAnswers.map(route => route.abort().catch(() => {})))
    }
    try {
      await resumeReleasedPlayback(owner, pendingStartPosition, 'pending-start', pendingStartReload, async () => {
        const answer = await pendingStartRegistration
        assert(answer, 'The page reloaded with a start pending must receive an answer to its endpoint registration')
        assert.equal(answer.activeIncarnationId, null, 'The registration answer after a reload with a shown play unanswered must already show the runner released')
      })
    } finally {
      releaseRequests.stop()
    }
    await pausePlayback(owner, 'final-pause')
    // Leave a paused cursor that differs from the operator's seek position of the next phase.
    const pausedThumb = await sliderValue()
    const finalTarget = finalSeekTarget(pausedThumb, credentials.expectedSeekPosition)
    assert(finalTarget >= 0 && pausedThumb - finalTarget >= 9.5 && dialogueCueAt(finalTarget) !== null,
      `The final seek target ${finalTarget} must lie on a dialogue cue, clear of the paused thumb at ${pausedThumb}`)
    await seekSourceAndDecode(owner, finalTarget, 'final-seek-paused', true)
    restingCursor = await restingCursorNow()
  } else if (phase === 'sought-paused') {
    // Video admission belongs to a client session: the operator's pause and seek act on
    // the operator's own session and must leave the browser's owned session untouched.
    assert.equal(snapshot.sessionId, restingCursor.sessionId, 'An operator seek on another session must not replace the browser\'s owned session')
    assert.equal(snapshot.generation, restingCursor.generation, 'An operator seek on another session must not advance the browser\'s generation')
    if (restingCursor.position === null) {
      // Without client controls the browser left its playback running; it pauses here itself.
      assert(!(await mediaState()).paused && snapshot.status === 'running', 'An operator pause on another session must not pause the browser\'s playback')
      await pausePlayback(ownedPlayback(), 'sought-paused-pause')
      restingCursor = await restingCursorNow()
    }
    await pausedAt(restingCursor.position, restingCursor.generation)
    const cue = dialogueCueAt(restingCursor.position)
    assert(cue !== null, `The resting cursor at ${restingCursor.position} must sit on a dialogue cue so the subtitle proof runs`)
    await selectSubtitles('sought-paused-dialogue-choice', 'Dialogue')
    await observeSubtitles('sought-paused', cue)
  } else if (phase === 'web-restarted') {
    // Replacing web drops the live connection. Recovery releases the browser's runner, and
    // once web returns the browser resumes its own session from the recovered cursor.
    const started = Date.now()
    const owner = { jobId: restingCursor.jobId, sessionId: restingCursor.sessionId, generation: restingCursor.generation }
    await resumeReleasedPlayback(owner, restingCursor.position, 'web-restart', started)
    await pausePlayback(owner, 'web-restart-pause')
    restingCursor = await restingCursorNow()
  } else if (phase === 'worker-contained') {
    assert((await mediaState()).connected, 'Worker containment must retain the attached player')
  } else if (phase === 'worker-recovered') {
    // Worker recovery re-executes an interrupted copy under a new generation; a copy that
    // had already sealed keeps its generation.
    await pausedAt(restingCursor.position, restingCursor.generation)
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
    const completion = await proveMovieCompletion()
    await proveCursorAuthority(completion.replaySeek)
  } else if (phase === 'complete') {
    assert.deepEqual(phases, ['authenticated', 'attached', ...(phases.includes('client-controls') ? ['client-controls'] : []), 'sought-paused', 'web-restarted', 'worker-contained', 'worker-recovered'])
    assert(cursorAuthority.length === 1 && cursorAuthority[0].finishedAt,
      'The drill must retain one completed proof that the playhead keeps its own clock under an outrunning producer')
  } else {
    throw new Error(`Unexpected browser checkpoint: ${phase}`)
  }
  phases.push(phase)
  // No credentials or signed URLs are written to the evidence artifact.
  await writeFile(`${evidence}/result.json`, JSON.stringify({ browser: browserInfo, phases, generation: snapshot?.generation, mediaResponses, nativeResponseEvidence, clientControls, subtitleObservations, networkInterruptions, manifestEvidence, mediaRequestOrder, capabilityRenewals, progressOutages, statusOutages, seekOutages, movieCompletions, cursorAuthority }, null, 2))
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
    const marksAtFailure = await playerMarks().catch(() => null)
    const alertsAtFailure = await page.getByRole('alert').allTextContents().catch(() => null)
    const statusAtFailure = await page.getByRole('status').allTextContents().catch(() => null)
    await writeFile(`${evidence}/failure.json`, JSON.stringify({
      browser: browserInfo, error: error.message, state, playback, playbackSnapshots, clientControls, subtitleObservations, networkInterruptions, mediaEvents: [...previousMediaEvents, ...mediaEvents], mediaResponses, apiResponses, nativeResponseEvidence, manifestEvidence, mediaRequestOrder, capabilityRenewals, progressOutages, statusOutages, seekOutages, movieCompletions, cursorAuthority, marks: marksAtFailure,
      alerts: alertsAtFailure, statuses: statusAtFailure, pageErrors,
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
