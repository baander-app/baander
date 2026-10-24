import assert from 'node:assert/strict'
import { mkdir, readFile, writeFile } from 'node:fs/promises'
import { request as httpRequest } from 'node:http'
import { chromium } from 'playwright-core'

const fixture = JSON.parse(await readFile('/work/ready.json', 'utf8'))
const versions = JSON.parse(await readFile('/work/versions.json', 'utf8'))
assert(['sparse-fresh', 'sparse-seek', 'matroska-delayed-fresh', 'matroska-delayed-seek'].includes(fixture.scenario))
const delayed = fixture.scenario.startsWith('matroska-delayed-')
if (delayed) {
  assert.equal(fixture.sourceTimestampOrigin, 4)
  assert.equal(Number(fixture.rawSourceDuration), 34)
  assert.equal(fixture.expectedAudioDelay, 8 - fixture.sourceStartTime)
}
assert.equal(fixture.audioProfile, 'streaming_stereo')
assert.equal(fixture.sourceFrameRate, 0.1)
const evidence = '/work/evidence'
await mkdir(evidence, { recursive: true })
const started = Date.now()
const deadline = started + 120000
const browser = await chromium.launch({ channel: 'chromium', headless: true,
  args: ['--mute-audio', '--host-resolver-rules=MAP adaptive.baander.app 127.0.0.1', '--no-proxy-server'] })
const context = await browser.newContext()
await context.tracing.start({ screenshots: true, snapshots: true })
const page = await context.newPage()
const cdp = await context.newCDPSession(page)
await cdp.send('Network.enable')
await cdp.send('Network.setCacheDisabled', { cacheDisabled: true })
const failures = []
const network = []
const manifests = []
const samples = []
const colorFrames = []
const subtitleFiles = []
let status
let requests = []
let state
const receipt = { ...versions, scenario: fixture.scenario, fixture, browser: browser.version(),
  settings: { deadlineSeconds: 120, readinessSeconds: 45, cacheDisabled: true, browserMuteAudio: true } }
cdp.on('Network.requestWillBeSent', event => {
  if (network.length >= 2000) return failures.push({ kind: 'request-bound' })
  network.push({ id: event.requestId, url: event.request.url, started: event.timestamp })
})
cdp.on('Network.responseReceived', event => Object.assign(network.find(item => item.id === event.requestId) ?? {},
  { status: event.response.status, fromDiskCache: event.response.fromDiskCache, fromServiceWorker: event.response.fromServiceWorker }))
cdp.on('Network.loadingFinished', event => Object.assign(network.find(item => item.id === event.requestId) ?? {},
  { finished: event.timestamp, bytes: event.encodedDataLength }))
cdp.on('Network.loadingFailed', event => failures.push({ kind: 'transport', url: network.find(item => item.id === event.requestId)?.url,
  error: event.errorText, canceled: event.canceled }))
page.on('pageerror', error => failures.push({ kind: 'page-error', message: error.message }))
await page.route('**/*', async route => {
  if (new URL(route.request().url()).origin !== fixture.origin) {
    failures.push({ kind: 'external-request', url: route.request().url() })
    await route.abort()
  } else await route.continue()
})
const pendingManifests = new Set()
page.on('response', response => {
  if (response.status() >= 400) failures.push({ kind: 'http-error', url: response.url(), status: response.status() })
  if ((!response.url().includes('.m3u8') && !response.url().includes('.vtt')) || response.status() !== 200) return
  const pending = (async () => {
    try {
      const body = await response.text()
      assert(manifests.length < 150, 'Playlist evidence exceeded its bound')
      const collection = response.url().includes('.vtt') ? subtitleFiles : manifests
      collection.push({ at: Date.now(), url: response.url(), body })
    } catch (error) { failures.push({ kind: 'playlist-body', message: error.message }) }
  })()
  pendingManifests.add(pending)
  pending.finally(() => pendingManifests.delete(pending))
})
const json = (path, method = 'GET', expectedStatus = 200) => new Promise((resolve, reject) => {
  const origin = new URL(fixture.origin)
  const request = httpRequest({ hostname: '127.0.0.1', port: origin.port, path, method,
    headers: { Host: origin.host, 'Cache-Control': 'no-store' } }, response => {
    let bytes = 0
    const chunks = []
    response.on('data', chunk => {
      bytes += chunk.length
      if (bytes > 4000000) return response.destroy(new Error(`Control response ${path} exceeded its bound`))
      chunks.push(chunk)
    })
    response.on('error', reject)
    response.on('end', () => {
      if (response.statusCode !== expectedStatus) return reject(new Error(`Fixture ${path} returned HTTP ${response.statusCode}`))
      try { resolve(JSON.parse(Buffer.concat(chunks).toString('utf8'))) } catch (error) { reject(error) }
    })
  })
  const timeout = setTimeout(() => request.destroy(new Error(`Control request ${path} exceeded 5 seconds`)), 5000)
  request.on('close', () => clearTimeout(timeout))
  request.on('error', reject)
  request.end()
})
const snapshot = () => page.evaluate(() => ({ ...window.adaptiveState(), colorFrames: [...window.sparseColorFrames],
  cueEvents: [...window.sparseCueEvents],
  textTracks: Array.from(document.querySelector('#video').textTracks).map(track => ({ mode: track.mode,
    cues: Array.from(track.cues ?? []).map(cue => ({ start: cue.startTime, end: cue.endTime, text: cue.text })) })),
}))
const poll = async (check, description, timeout = 120000) => {
  const end = Math.min(deadline, Date.now() + timeout)
  while (Date.now() < end) {
    if (await check()) return
    await new Promise(resolve => setTimeout(resolve, 200))
  }
  throw new Error(`Timed out waiting for ${description}`)
}
function allowedColors(sourceTime) {
  if (Math.abs(sourceTime - 10) <= 0.05) return ['red', 'green']
  if (Math.abs(sourceTime - 20) <= 0.05) return ['green', 'blue']
  return [sourceTime < 10 ? 'red' : sourceTime < 20 ? 'green' : 'blue']
}
function verifyPlayback() {
  assert.deepEqual(failures, [], 'All media and transport operations must succeed')
  assert.deepEqual(state.errors, [], 'The production adapter must not report an error')
  assert.equal(state.mediaError, null)
  assert.equal(state.sameVideo, true)
  assert.equal(state.attachments, 1)
  assert(state.readyCount <= 1)
  assert(state.sourceChanges <= 1)
  assert.equal(state.playbackRate, 1)
  const firstPlaying = state.events.find(event => event.name === 'playing')
  assert(!state.events.some(event => firstPlaying && event.at > firstPlaying.at
    && ['seeking', 'seeked', 'emptied'].includes(event.name)), 'Playback must not seek or reset')
  const previous = samples.at(-1)
  if (previous) {
    assert(state.time >= previous.time - 0.01, 'The native clock must progress monotonically')
    assert(state.time - previous.time <= (state.at - previous.at) / 1000 + 0.25, 'The native clock must not jump ahead')
    assert(state.decodedFrames >= previous.decodedFrames)
  }
  for (const frame of state.colorFrames.slice(colorFrames.length)) {
    const sourceTime = frame.time + fixture.sourceStartTime
    const channels = { red: frame.rgb[0], green: frame.rgb[1], blue: frame.rgb[2] }
    const actual = Object.keys(channels).sort((left, right) => channels[right] - channels[left])[0]
    assert(channels[actual] > 70 && Object.entries(channels).every(([color, value]) => color === actual || value < 35),
      `The decoded frame must contain an unambiguous color: ${frame.rgb}`)
    assert(allowedColors(sourceTime).includes(actual), `Decoded color at local ${frame.time}, source ${sourceTime}`)
    const sourceClock = frame.clock + fixture.sourceStartTime
    assert(allowedColors(sourceClock).includes(actual), `Displayed color at native source clock ${sourceClock}`)
    // Frame PTS and the audio-backed native clock can differ while buffering resumes.
    assert(frame.time - frame.clock <= 1.05 && frame.clock - frame.time <= 1.15,
      'Presented frame timestamps must stay within one native frame of the playback clock')
    colorFrames.push({ ...frame, color: actual, sourceTime })
  }
}

try {
  assert.equal(versions.hls, '1.7.3')
  assert.equal(versions.playwright, '1.60.0')
  await page.goto(`${fixture.origin}/__adaptive/`)
  await page.evaluate(({ manifest, delayed }) => {
    window.adaptiveManifest = manifest
    window.sparseColorFrames = []
    const video = document.querySelector('#video')
    window.sparseCueEvents = []
    if (delayed) {
      video.textTracks.addEventListener('addtrack', event => {
        const track = event.track
        track.mode = 'showing'
        track.addEventListener('cuechange', () => {
          window.sparseCueEvents.push({ at: performance.now(), time: video.currentTime,
            active: Array.from(track.activeCues ?? []).map(cue => ({ start: cue.startTime, end: cue.endTime, text: cue.text })) })
        })
      })
    }
    const canvas = document.createElement('canvas')
    canvas.width = canvas.height = 1
    const canvasContext = canvas.getContext('2d', { willReadFrequently: true })
    const observe = (at, metadata) => {
      canvasContext.drawImage(video, 0, 0, 1, 1)
      window.sparseColorFrames.push({ at, time: metadata.mediaTime, clock: video.currentTime,
        presentedFrames: metadata.presentedFrames, presentationTime: metadata.presentationTime,
        expectedDisplayTime: metadata.expectedDisplayTime,
        rgb: [...canvasContext.getImageData(0, 0, 1, 1).data].slice(0, 3) })
      video.requestVideoFrameCallback(observe)
    }
    video.requestVideoFrameCallback(observe)
  }, { manifest: fixture.manifestUrl, delayed })
  await json('/__adaptive/start', 'POST', 202)
  await poll(async () => {
    status = await json('/__adaptive/status')
    for (const tier of Object.values(status.tiers)) assert(tier.producerExitCode === null || tier.producerExitCode === 0)
    return status.ready
  }, 'closed native segments in both renditions', 45000)
  assert.equal(status.complete, false, 'Attach while the presentation is still publishing')
  assert(Object.values(status.tiers).every(tier => tier.producerRunning && !tier.complete))
  receipt.initial = status
  await page.getByRole('button', { name: 'Play adaptive movie' }).click()
  await poll(async () => {
    state = await snapshot()
    verifyPlayback()
    const recentAudio = state.audio.filter(audio => state.at - audio.at < 1000)
    samples.push({ at: state.at, time: state.time, decodedFrames: state.decodedFrames,
      audio: recentAudio, ended: state.ended })
    if (state.ended) return true
    return false
  }, 'natural completion of the complete held-frame movie')
  status = await json('/__adaptive/status')
  requests = await json('/__adaptive/requests')
  await Promise.all([...pendingManifests])
  verifyPlayback()
  assert.equal(state.readyCount, 1)
  assert.equal(state.sourceChanges, 1)
  assert.equal(status.complete, true)
  assert(Math.abs(state.time - fixture.expectedDuration) <= 0.15, 'Natural end must retain the complete movie tail')
  assert(Math.abs(state.duration - fixture.expectedDuration) <= 0.15)
  assert(state.events.some(event => event.name === 'ended'))
  assert(state.firstPlayingTime !== null && state.firstPlayingTime < 1)
  assert(state.decodedFrames >= fixture.expectedDuration - 2, 'Native decoding must advance throughout held frames')
  for (const [color, sourceBegin, sourceEnd] of [['red', fixture.sourceStartTime, 10], ['green', 10, 20], ['blue', 20, 30]]) {
    const frames = colorFrames.filter(frame => frame.color === color)
    assert(frames.length >= sourceEnd - sourceBegin - 2, `Retain native displayed ${color} frames throughout its hold`)
    assert(frames.some(frame => frame.sourceTime < sourceBegin + 1.15), `Observe the beginning of ${color}`)
    assert(frames.some(frame => frame.sourceTime >= sourceEnd - 1.15), `Observe the tail of ${color}`)
    const audibleFrames = delayed
      ? frames.filter(frame => frame.clock >= fixture.expectedAudioDelay + 0.25) : frames
    assert(audibleFrames.some(frame => samples.some(sample => sample.audio.some(audio => audio.rms > 0.001
      && Math.abs(audio.at - frame.at) < 500))), `Decoded ${color} after audio onset must coincide with recent audible samples`)
  }
  const master = manifests.find(manifest => manifest.body.includes('#EXT-X-STREAM-INF:'))
  assert(master, 'Retain the signed production master playlist')
  assert.equal(master.body.split('\n').filter(line => line.startsWith('#EXT-X-STREAM-INF:')).length, 2)
  assert(master.body.includes('mp4a.40.2'))
  const media = manifests.filter(manifest => manifest.body.includes('#EXT-X-TARGETDURATION:')
    && (!delayed || /\/tracks\/(?:video|audio)\//.test(new URL(manifest.url).pathname)))
  if (delayed) {
    for (const track of ['video', 'audio']) assert(media.some(manifest => manifest.url.includes(`/tracks/${track}/`)))
  }
  assert(media.some(manifest => !manifest.body.includes('#EXT-X-ENDLIST')), 'Retain a growing playlist')
  for (const manifest of media) assert.equal(/#EXT-X-TARGETDURATION:(\d+)/.exec(manifest.body)?.[1], '7')
  const delivered = requests.filter(request => /\/(?:init\.mp4|segment\.m4s)(?:\?|$)/.test(request.path))
  assert(delivered.length > 0 && delivered.every(request => request.status === 200 && request.closed))
  for (const request of delivered) {
    const url = new URL(request.path, fixture.origin)
    assert(url.searchParams.has('sig') && url.searchParams.has('exp'), 'Every resource must be signed')
    const transfer = network.find(item => item.url === url.href && item.finished && item.status === 200)
    assert(transfer && !transfer.fromDiskCache && !transfer.fromServiceWorker, 'Media must arrive freshly over the network')
  }
  assert(delivered.some(request => request.track === 'video'))
  assert(delivered.some(request => request.track === 'audio'))
  assert(delivered.filter(request => request.track === 'audio').every(request => request.jobId === fixture.canonicalAudioJobId))
  if (delayed) {
    const silence = samples.filter(sample => sample.time >= 0.25 && sample.time < fixture.expectedAudioDelay - 0.25 && sample.audio.length > 0)
    assert(silence.length >= 3, 'Observe the played initial silence before the delayed audio begins')
    assert(silence.every(sample => sample.audio.every(audio => audio.rms < 0.001)), 'Initial audio must remain near-silent')
    const onset = samples.find(sample => sample.audio.some(audio => audio.rms > 0.001))
    assert(onset && onset.time >= fixture.expectedAudioDelay - 0.15 && onset.time <= fixture.expectedAudioDelay + 0.75,
      'Measured audio must begin at its intended source-relative delay')
    assert(master.body.includes('TYPE=SUBTITLES'), 'The production master must advertise embedded subtitles')
    const subtitleManifests = manifests.filter(manifest => manifest.url.includes('/subtitles/'))
    assert(subtitleManifests.length > 0 && subtitleFiles.length > 0, 'The real HLS adapter must load signed subtitles')
    for (const manifest of subtitleManifests) {
      const url = new URL(manifest.url)
      assert.equal(url.searchParams.get('generation'), '1')
      assert(url.searchParams.has('sig') && url.searchParams.has('exp'))
      assert.equal(Number(/#EXT-X-TARGETDURATION:(\d+)/.exec(manifest.body)?.[1]), fixture.expectedDuration)
      assert.equal(Number(/#EXTINF:([\d.]+)/.exec(manifest.body)?.[1]), fixture.expectedDuration)
    }
    for (const file of subtitleFiles) {
      const url = new URL(file.url)
      assert.equal(url.searchParams.get('generation'), '1')
      assert(url.searchParams.has('sig') && url.searchParams.has('exp'))
      const transfer = network.find(item => item.url === file.url && item.finished && item.status === 200)
      assert(transfer && !transfer.fromDiskCache && !transfer.fromServiceWorker)
      const served = requests.find(request => new URL(request.path, fixture.origin).href === file.url)
      assert(served?.closed && served.status === 200, 'Subtitle bytes must come from the current closed generation file')
    }
    const cues = state.textTracks.flatMap(track => track.cues)
    for (const expected of fixture.expectedCues) {
      assert(cues.some(cue => cue.text === expected.text && Math.abs(cue.start - expected.start) < 0.05
        && Math.abs(cue.end - expected.end) < 0.05), `The real text track must expose ${expected.text} at the local source time`)
      const active = state.cueEvents.find(event => event.active.some(cue => cue.text === expected.text))
      assert(active && active.time >= expected.start - 0.15 && active.time <= expected.start + 0.75,
        `The real text track must activate ${expected.text} at its local cue time`)
      assert(state.cueEvents.some(event => event.time >= expected.end - 0.05 && event.time <= expected.end + 0.75
        && !event.active.some(cue => cue.text === expected.text)), `The text track must clear ${expected.text} at its end`)
    }
    assert.deepEqual([...new Set(cues.map(cue => cue.text))].sort(), fixture.expectedCues.map(cue => cue.text).sort(),
      'Only the source-relative retained cues may reach the real text track')
    receipt.audioDelay = { expected: fixture.expectedAudioDelay, observedOnset: onset.time, silentSamples: silence.length }
    receipt.subtitles = { files: subtitleFiles, manifests: subtitleManifests, cues, events: state.cueEvents }
  }
  await page.screenshot({ path: `${evidence}/complete.png` })
  Object.assign(receipt, { passed: true, final: state, producer: status })
} catch (error) {
  Object.assign(receipt, { passed: false, error: error.stack, final: await snapshot().catch(() => null), producer: status })
  await page.screenshot({ path: `${evidence}/failure.png` }).catch(() => {})
  process.exitCode = 1
} finally {
  requests = await json('/__adaptive/requests').catch(() => requests)
  await Promise.all([...pendingManifests])
  Object.assign(receipt, { elapsedSeconds: (Date.now() - started) / 1000, colorFrames, samples, requests, network, manifests, subtitleFiles, failures })
  await writeFile(`${evidence}/result.json`, JSON.stringify(receipt, null, 2))
  await context.tracing.stop({ path: `${evidence}/trace.zip` })
  await browser.close()
}
