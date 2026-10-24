import assert from 'node:assert/strict'
import { mkdir, readFile, writeFile } from 'node:fs/promises'
import { request as httpRequest } from 'node:http'
import { chromium } from 'playwright-core'

const fixture = JSON.parse(await readFile('/work/ready.json', 'utf8'))
const versions = JSON.parse(await readFile('/work/versions.json', 'utf8'))
const evidence = '/work/evidence'
await mkdir(evidence, { recursive: true })
const started = Date.now()
const deadline = started + 180000
const browser = await chromium.launch({ channel: 'chromium', headless: true,
  args: ['--mute-audio', '--host-resolver-rules=MAP adaptive.baander.app 127.0.0.1', '--no-proxy-server'] })
const context = await browser.newContext()
await context.tracing.start({ screenshots: true, snapshots: true })
const page = await context.newPage()
const cdp = await context.newCDPSession(page)
await cdp.send('Network.enable')
await cdp.send('Network.setCacheDisabled', { cacheDisabled: true })
const failures = []
const metrics = new Map()
const samples = []
const phases = []
const manifests = []
let currentPhase = 'startup'
let status
let state
let requests = []
const receipt = { ...versions, browser: browser.version(), ffmpeg: fixture.ffmpegVersion,
  expectedDuration: fixture.expectedDuration, frameRate: fixture.frameRate,
  settings: { generousBitsPerSecond: 20000000, constrainedBitsPerSecond: 1800000,
    deadlineSeconds: 180,
    transitionDeadlineSeconds: { 'generous-initial': 65, constrained: 65, 'generous-restored': 90 },
    browserMuteAudio: true, cacheDisabled: true } }

cdp.on('Network.requestWillBeSent', event => {
  if (metrics.size >= 4000) {
    failures.push({ kind: 'network-receipt-overflow' })
    return
  }
  metrics.set(event.requestId, { id: event.requestId, url: event.request.url, phase: currentPhase,
    started: event.timestamp, wallTime: event.wallTime, dataBytes: 0, encodedDataBytes: 0, chunks: 0 })
})
cdp.on('Network.responseReceived', event => {
  Object.assign(metrics.get(event.requestId) ?? {}, { status: event.response.status, mimeType: event.response.mimeType,
    responseAt: event.timestamp, fromDiskCache: event.response.fromDiskCache, fromServiceWorker: event.response.fromServiceWorker })
})
cdp.on('Network.dataReceived', event => {
  const metric = metrics.get(event.requestId)
  if (!metric) return
  metric.firstBodyAt ??= event.timestamp
  metric.lastBodyAt = event.timestamp
  metric.dataBytes += event.dataLength
  metric.encodedDataBytes += event.encodedDataLength
  metric.chunks++
})
cdp.on('Network.loadingFinished', event => {
  Object.assign(metrics.get(event.requestId) ?? {}, { finished: event.timestamp, transferredBytes: event.encodedDataLength })
})
cdp.on('Network.loadingFailed', event => {
  Object.assign(metrics.get(event.requestId) ?? {}, { failed: event.timestamp, canceled: event.canceled,
    error: event.errorText, blockedReason: event.blockedReason })
})
await page.route('**/*', async route => {
  if (new URL(route.request().url()).origin !== fixture.origin) {
    failures.push({ kind: 'external-request', url: route.request().url() })
    await route.abort()
  } else await route.continue()
})
page.on('pageerror', error => failures.push({ kind: 'page-error', message: error.message }))
page.on('response', async response => {
  if (response.status() >= 400) failures.push({ kind: 'http-error', url: response.url(), status: response.status() })
  if (!response.url().includes('.m3u8') || response.status() !== 200 || manifests.length >= 100) return
  try {
    const body = await response.text()
    manifests.push({ at: Date.now(), phase: currentPhase, url: response.url(), body })
  } catch (error) {
    // A canceled playlist is evaluated with all transport failures below.
    manifests.push({ at: Date.now(), phase: currentPhase, url: response.url(), error: error.message })
  }
})
// Control-plane polling stays on disposable loopback and does not consume the
// browser's media bandwidth or make telemetry bodies part of the measurement.
const json = (path, method = 'GET', expectedStatus = 200) => new Promise((resolve, reject) => {
  const origin = new URL(fixture.origin)
  const request = httpRequest({ hostname: '127.0.0.1', port: origin.port, path, method,
    headers: { Host: origin.host, 'Cache-Control': 'no-store' } }, response => {
    let bytes = 0
    const chunks = []
    response.on('data', chunk => {
      bytes += chunk.length
      if (bytes > 4000000) {
        response.destroy(new Error(`Control response ${path} exceeded its byte bound`))
        return
      }
      chunks.push(chunk)
    })
    response.on('error', reject)
    response.on('end', () => {
      if (response.statusCode !== expectedStatus) {
        reject(new Error(`Fixture ${path} returned HTTP ${response.statusCode}`))
        return
      }
      try { resolve(JSON.parse(Buffer.concat(chunks).toString('utf8'))) } catch (error) { reject(error) }
    })
  })
  const timeout = setTimeout(() => request.destroy(new Error(`Control request ${path} exceeded 5 seconds`)), 5000)
  request.on('close', () => clearTimeout(timeout))
  request.on('error', reject)
  request.end()
})
const snapshot = () => page.evaluate(() => window.adaptiveState())
const poll = async (check, description, timeout = 65000) => {
  const end = Math.min(deadline, Date.now() + timeout)
  while (Date.now() < end) {
    if (await check()) return
    await new Promise(resolve => setTimeout(resolve, 250))
  }
  throw new Error(`Timed out waiting for ${description}`)
}
function verifyPlayback() {
  assert.deepEqual(failures, [], 'No external requests, page errors or HTTP errors are permitted')
  assert.deepEqual(state.errors, [], 'The production adapter must not report fatal errors')
  assert.equal(state.mediaError, null)
  assert.equal(state.sameVideo, true)
  assert.equal(state.attachments, 1)
  assert.equal(state.readyCount, 1)
  assert.equal(state.playbackRate, 1)
  assert(state.sourceChanges <= 1, 'The MediaSource identity must remain stable')
  const firstPlaying = state.events.find(event => event.name === 'playing')
  assert(!state.events.some(event => firstPlaying && event.at > firstPlaying.at
    && ['seeking', 'seeked', 'emptied'].includes(event.name)), 'Adaptation must not reset or seek the source')
  for (let index = 1; index < state.frames.length; index++) {
    const step = state.frames[index].time - state.frames[index - 1].time
    const elapsed = (state.frames[index].at - state.frames[index - 1].at) / 1000
    assert(step >= -0.01 && step <= Math.max(0.5, elapsed + 0.25), `Presented source clock jumped by ${step}s in ${elapsed}s`)
  }
}
function verifyGrowing(previous) {
  assert.equal(status.complete, false, 'Adaptation must occur during publication')
  let advanced = true
  for (const tier of ['low', 'high']) {
    const current = status.tiers[fixture.tiers[tier].name]
    assert.equal(current.producerRunning, true, `${tier} producer must still be running`)
    assert.equal(current.complete, false)
    if (previous && current.videoEnd <= previous.tiers[fixture.tiers[tier].name].videoEnd) advanced = false
  }
  return advanced && status.tiers[fixture.tiers.low.name].videoEnd > status.tiers[fixture.tiers.high.name].videoEnd
}
async function runPhase(name, tier, bitsPerSecond, beginPlayback) {
  // Conservative wall-clock bounds exclude the CDP command's application window.
  if (phases.length) phases[phases.length - 1].transportEndWall = Date.now()
  currentPhase = name
  await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 0,
    downloadThroughput: bitsPerSecond / 8, uploadThroughput: -1, connectionType: 'ethernet' })
  const transportStartWall = Date.now()
  await page.evaluate(value => window.adaptivePhase(value), name)
  const beginning = await snapshot()
  const startingRequests = (await json('/__adaptive/requests')).length
  const record = { name, tier, bitsPerSecond, started: Date.now(), transportStartWall, beginning, startingRequests }
  phases.push(record)
  if (beginPlayback) await beginPlayback()
  await poll(async () => {
    state = await snapshot()
    assert.deepEqual(state.errors, [], 'The production adapter must not report fatal errors, including before manifest readiness')
    assert.deepEqual(failures, [], 'No external requests, page errors or HTTP errors are permitted')
    assert.equal(state.mediaError, null)
    status = await json('/__adaptive/status')
    requests = await json('/__adaptive/requests')
    if (samples.length < 800) samples.push({ at: Date.now(), phase: name, time: state.time,
      decodedFrames: state.decodedFrames, width: state.width, height: state.height,
      audioPeak: state.phaseAudioPeak, audioSamples: state.phaseAudioSamples, status })
    // Manifest parsing is asynchronous immediately after the single attachment.
    if (state.readyCount) verifyPlayback()
    const signed = requests.slice(startingRequests).filter(request => request.jobId === fixture.tiers[tier].jobId
      && request.track === 'video' && request.index !== null && request.index !== undefined
      && request.status === 200 && request.closed)
    const presented = state.frames.filter(frame => frame.phase === name && frame.width === fixture.tiers[tier].width
      && frame.height === fixture.tiers[tier].height && frame.time > beginning.time + 0.5)
    if (signed.length === 0 || presented.length < 12 || state.decodedFrames <= beginning.decodedFrames + 12
      || state.phaseAudioSamples < 3 || state.phaseAudioPeak <= 0.001) return false
    // A fragment must arrive before the matching rendition is observed on screen.
    const causalFragments = signed.flatMap(request => {
      const metric = [...metrics.values()].find(candidate => candidate.phase === name && candidate.finished
        && candidate.url === new URL(request.path, fixture.origin).href)
      if (!metric) return []
      const finishedAt = (metric.wallTime + metric.finished - metric.started) * 1000
      const frame = presented.find(candidate => state.timeOrigin + candidate.at > finishedAt
        && candidate.time >= request.segmentStart - 0.05 && candidate.time < request.segmentEnd + 0.05
        && state.audio.some(audio => audio.phase === name && audio.rms > 0.001
          && state.at - audio.at < 1000 && Math.abs(audio.at - candidate.at) < 500))
      return frame ? [{ request, metricId: metric.id, finishedAt, frame }] : []
    })
    if (!causalFragments.length) return false
    if (!verifyGrowing(phases.length > 1 ? phases[phases.length - 2].status : receipt.initial)) return false
    Object.assign(record, { completed: Date.now(), state, status, signed, causalFragments })
    await page.screenshot({ path: `${evidence}/${name}.png` })
    return true
  }, `${name} signed ${tier} fragments, decoded frames and audio`, receipt.settings.transitionDeadlineSeconds[name] * 1000)
}

try {
  assert.equal(versions.hls, '1.7.3')
  assert.equal(versions.playwright, '1.60.0')
  await page.goto(`${fixture.origin}/__adaptive/`)
  await page.evaluate(manifest => { window.adaptiveManifest = manifest }, fixture.manifestUrl)
  await json('/__adaptive/start', 'POST', 202)
  await poll(async () => {
    status = await json('/__adaptive/status')
    for (const tier of Object.values(status.tiers)) {
      assert(tier.producerExitCode === null || tier.producerExitCode === 0, 'A native producer failed')
    }
    return status.ready && status.tiers[fixture.tiers.low.name].videoSegments >= 3
      && status.tiers[fixture.tiers.high.name].videoSegments >= 2
      && status.tiers[fixture.tiers.low.name].videoEnd > status.tiers[fixture.tiers.high.name].videoEnd
  }, 'closed, independently growing native presentations', 40000)
  assert(verifyGrowing(), 'The independent high-tier producer must demonstrably lag')
  receipt.initial = status
  // Begin observation before attachment; all throughput changes affect actual bodies.
  await runPhase('generous-initial', 'high', receipt.settings.generousBitsPerSecond,
    () => page.getByRole('button', { name: 'Play adaptive movie' }).click())
  await runPhase('constrained', 'low', receipt.settings.constrainedBitsPerSecond)
  await runPhase('generous-restored', 'high', receipt.settings.generousBitsPerSecond)
  const master = manifests.find(manifest => manifest.body?.includes('#EXT-X-STREAM-INF:'))
  assert(master, 'Retain the production HLS master playlist')
  const advertised = master.body.split('\n').filter(line => line.startsWith('#EXT-X-STREAM-INF:')).map(line => ({
    bandwidth: Number(/(?:^|[:,])BANDWIDTH=(\d+)/.exec(line)?.[1]),
    width: Number(/RESOLUTION=(\d+)x(\d+)/.exec(line)?.[1]),
    height: Number(/RESOLUTION=(\d+)x(\d+)/.exec(line)?.[2]),
  }))
  const lowBandwidth = advertised.find(level => level.height === fixture.tiers.low.height)?.bandwidth
  const highBandwidth = advertised.find(level => level.height === fixture.tiers.high.height)?.bandwidth
  assert(lowBandwidth < receipt.settings.constrainedBitsPerSecond && highBandwidth > receipt.settings.constrainedBitsPerSecond,
    'The constrained transport must exceed advertised low-tier bandwidth and fall below high-tier bandwidth')
  receipt.advertised = advertised
  assert(phases[2].state.time > phases[1].state.time + 0.5)
  assert(state.firstPlayingTime !== null && state.firstPlayingTime < 1)
  assert.equal(state.sourceChanges, 1)
  const deliveredMedia = requests.filter(request => /\/(?:init\.mp4|segment\.m4s)(?:\?|$)/.test(request.path))
  assert(deliveredMedia.length > 0 && deliveredMedia.every(request => request.status === 200 && request.closed),
    'Every signed delivery must serve a closed native file successfully')
  assert(deliveredMedia.filter(request => request.track === 'audio').length > 0)
  assert(deliveredMedia.filter(request => request.track === 'audio').every(request => request.jobId === fixture.canonicalAudioJobId),
    'All audio must remain on the canonical job across video switches')
  const transportFailures = [...metrics.values()].filter(metric => metric.failed)
  const acceptedCancellations = []
  for (const metric of transportFailures) {
    const url = new URL(metric.url)
    const signed = requests.find(request => metric.url === new URL(request.path, fixture.origin).href
      && request.track === 'video' && request.index !== null)
    const successor = [...metrics.values()].find(other => other.started > metric.started && other.finished && !other.failed
      && other.url.includes('/tracks/video/') && other.url.includes('/segment.m4s')
      && signed && !other.url.includes(signed.jobId)
      && other.started - metric.failed >= -0.5 && other.started - metric.failed < 12
      && requests.some(request => other.url === new URL(request.path, fixture.origin).href
        && request.status === 200 && request.closed
        && Math.abs(request.segmentStart - signed.segmentStart) < 0.05
        && Math.abs(request.segmentEnd - signed.segmentEnd) < 0.05))
    assert(metric.canceled && metric.error === 'net::ERR_ABORTED' && signed && successor,
      `Unexplained transport failure: ${metric.error} ${url.pathname}`)
    acceptedCancellations.push({ ...metric, successor: successor.url, reason: 'Video download abandoned before a replacement fragment' })
  }
  const constrainedInterval = phases.find(phase => phase.name === 'constrained')
  const constrainedBodies = [...metrics.values()].filter(metric => metric.phase === 'constrained'
    && metric.finished && !metric.failed && metric.dataBytes >= 131072 && metric.chunks > 1
    && metric.finished - metric.responseAt >= 0.5
    && (metric.wallTime + metric.responseAt - metric.started) * 1000 >= constrainedInterval.transportStartWall
    && (metric.wallTime + metric.finished - metric.started) * 1000 <= constrainedInterval.transportEndWall
    && deliveredMedia.some(request => metric.url === new URL(request.path, fixture.origin).href
      && request.track === 'video' && request.index !== null))
    .map(metric => ({ id: metric.id, url: metric.url, bytes: metric.dataBytes,
      seconds: metric.finished - metric.responseAt,
      bitsPerSecond: metric.dataBytes * 8 / (metric.finished - metric.responseAt) }))
  assert(constrainedBodies.length > 0, 'Retain completed large signed video bodies under constrained throughput')
  // Include transfer time before the first dataReceived chunk. Allow 30% for CDP
  // event batching and 75% loss to concurrent audio and browser scheduling.
  assert(constrainedBodies.every(body => body.bitsPerSecond <= receipt.settings.constrainedBitsPerSecond * 1.3),
    'Constrained signed video body transfers must obey the actual transport ceiling')
  assert(constrainedBodies.some(body => body.bitsPerSecond >= receipt.settings.constrainedBitsPerSecond * 0.25),
    'At least one completed body must measure the configured transport order of magnitude')
  Object.assign(receipt, { passed: true, final: state, producer: status, acceptedCancellations, constrainedBodies })
} catch (error) {
  Object.assign(receipt, { passed: false, error: error.message,
    final: await snapshot().catch(() => null), producer: status })
  await page.screenshot({ path: `${evidence}/failure.png` }).catch(() => {})
  process.exitCode = 1
} finally {
  requests = await json('/__adaptive/requests').catch(() => requests)
  const finalState = receipt.final
  const rebufferPeriods = []
  let hasPlayed = false
  let waiting
  for (const event of finalState?.events ?? []) {
    if (event.name === 'waiting' && hasPlayed && !waiting) waiting = event
    if (event.name === 'playing') {
      if (waiting) {
        rebufferPeriods.push({ startedAt: waiting.at, endedAt: event.at,
          seconds: (event.at - waiting.at) / 1000, sourceTime: waiting.time, resumedSourceTime: event.time,
          phase: phases.findLast(phase => phase.beginning.at <= waiting.at)?.name ?? 'startup', open: false })
        waiting = undefined
      }
      hasPlayed = true
    }
  }
  if (waiting) rebufferPeriods.push({ startedAt: waiting.at, endedAt: null,
    seconds: (finalState.at - waiting.at) / 1000, sourceTime: waiting.time, resumedSourceTime: null,
    phase: phases.findLast(phase => phase.beginning.at <= waiting.at)?.name ?? 'startup', open: true })
  receipt.rebuffering = { periods: rebufferPeriods, count: rebufferPeriods.length,
    totalSeconds: rebufferPeriods.reduce((total, period) => total + period.seconds, 0) }
  const network = [...metrics.values()].map(metric => ({ ...metric,
    bodySeconds: metric.finished > metric.responseAt ? metric.finished - metric.responseAt : null,
    bodyBitsPerSecond: metric.finished > metric.responseAt
      ? metric.dataBytes * 8 / (metric.finished - metric.responseAt) : null,
    chunkSpanSeconds: metric.lastBodyAt > metric.firstBodyAt ? metric.lastBodyAt - metric.firstBodyAt : null }))
  Object.assign(receipt, { elapsedSeconds: (Date.now() - started) / 1000, phases, samples, requests, network, manifests, failures })
  await writeFile(`${evidence}/result.json`, JSON.stringify(receipt, null, 2))
  await context.tracing.stop({ path: `${evidence}/trace.zip` })
  await browser.close()
}
