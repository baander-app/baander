import assert from 'node:assert/strict'
import { createHash } from 'node:crypto'
import { createServer } from 'node:http'
import { mkdir, writeFile } from 'node:fs/promises'
import { chromium } from 'playwright-core'

const evidence = '/work/evidence'
await mkdir(evidence, { recursive: true })
const browser = await chromium.launch({ channel: 'chromium', headless: true, args: ['--mute-audio'] })
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
      playbackSnapshots, playbackControls, clientControls, subtitleObservations, networkInterruptions, manifestEvidence, mediaRequestOrder, capabilityRenewals, mediaEvents: [...previousMediaEvents, ...mediaEvents],
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
      appendBounded(playbackSnapshots, { at: Date.now(), accepted, ...safePlayback(next),
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
  return video.evaluate(element => ({
    ...window.__twoCommandMediaState(element),
    connected: element.isConnected, time: element.currentTime, paused: element.paused,
    ready: element.readyState, error: element.error?.code ?? null, source: element.currentSrc,
    nativeHls: element.canPlayType('application/vnd.apple.mpegurl'),
    h264: element.canPlayType('video/mp4; codecs="avc1.42E01E"'),
  }))
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
    return state.connected && state.paused && state.ready >= 2 && state.decodedFrames > 0 && !state.seeking
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
        && state.ready >= 2 && state.decodedFrames > 0 && !state.seeking
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
          && state.connected && state.ready >= 2 && state.error === null && !state.seeking
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
        decodedFrames: renewed.state.decodedFrames, paused: renewed.state.paused }
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

async function reconnectPausedPlayback(owner, prefix) {
  await pausePlayback(owner, `${prefix}-pause`)
  let before
  await until(async () => {
    const state = await mediaState()
    const usable = state.connected && state.paused && state.ready >= 2 && state.decodedFrames > 0
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
    && state.decodedFrames >= before.decodedFrames && !state.seeking && state.error === null
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
    const restored = state.connected && state.paused && state.ready >= 2 && state.decodedFrames > 0
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

async function commitSourceSeek(position) {
  const timeline = page.getByRole('slider', { name: 'Seek movie' })
  assert.equal(Number(await timeline.getAttribute('max')), snapshot.duration, 'Source seeking must span the full source duration')
  assert(position >= 0 && position < snapshot.duration, 'The source target must fit the source duration')
  if (position === 0) {
    await timeline.focus()
    await timeline.press('Home')
    return 0
  }
  const box = await timeline.boundingBox()
  assert(box, 'The source timeline must be visible')
  await page.mouse.move(box.x + box.width * position / snapshot.duration, box.y + box.height / 2)
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
      && state.connected && state.paused === paused && state.ready >= 2 && state.error === null && state.decodedFrames > 0
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
      return state.connected && !state.paused && state.ready >= 2 && state.time > 1
        && state.decodedFrames > 0 && state.error === null && snapshot?.ready
    }, 'native HLS to decode and advance')
    const owner = ownedPlayback()
    const initial = await mediaState()
    const publishedEnd = snapshot.startTime + Math.max(...initial.seekable.map(range => range[1]), 0)
    const target = Math.min(snapshot.duration - 18, Math.max(90, publishedEnd + 24))
    assert(target > publishedEnd + 6, 'The fixture must leave source media beyond the currently published native window')
    assert(initial.source.startsWith('blob:'), 'Pinned Chromium must use the supported MSE HLS path')
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
      const usable = state.connected && state.paused && state.ready >= 2 && state.decodedFrames > 0
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
  } else if (phase === 'complete') {
    assert.deepEqual(phases, ['authenticated', 'attached', ...(phases.includes('client-controls') ? ['client-controls'] : []), 'sought-paused', 'web-restarted', 'worker-contained', 'worker-recovered'])
  } else {
    throw new Error(`Unexpected browser checkpoint: ${phase}`)
  }
  phases.push(phase)
  // No credentials or signed URLs are written to the evidence artifact.
  await writeFile(`${evidence}/result.json`, JSON.stringify({ browser: browserInfo, phases, generation: snapshot?.generation, mediaResponses, nativeResponseEvidence, clientControls, subtitleObservations, networkInterruptions, manifestEvidence, mediaRequestOrder, capabilityRenewals }, null, 2))
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
      browser: browserInfo, error: error.message, state, playback, playbackSnapshots, clientControls, subtitleObservations, networkInterruptions, mediaEvents: [...previousMediaEvents, ...mediaEvents], mediaResponses, apiResponses, nativeResponseEvidence, manifestEvidence, mediaRequestOrder, capabilityRenewals,
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
