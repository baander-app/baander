import assert from 'node:assert/strict'
import { mkdir, readFile, writeFile } from 'node:fs/promises'
import { request as httpRequest } from 'node:http'
import { chromium } from 'playwright-core'

const fixture = JSON.parse(await readFile('/work/ready.json', 'utf8'))
const versions = JSON.parse(await readFile('/work/versions.json', 'utf8'))
assert(['sparse-fresh', 'sparse-seek', 'matroska-delayed-fresh', 'matroska-delayed-seek', 'ordinary-delayed-fresh', 'ordinary-delayed-seek', 'default-audio-fresh', 'default-audio-seek'].includes(fixture.scenario))
const matroska = fixture.scenario.startsWith('matroska-delayed-')
const defaultAudio = fixture.scenario.startsWith('default-audio-')
const ordinary = defaultAudio || fixture.scenario.startsWith('ordinary-delayed-')
const delayed = matroska || ordinary
const hasSubtitles = fixture.hasSubtitles
assert.equal(hasSubtitles, matroska)
if (matroska) {
  assert.equal(fixture.sourceTimestampOrigin, 4)
  assert.equal(Number(fixture.rawSourceDuration), 34)
}
if (delayed) assert.equal(fixture.expectedAudioDelay, 8 - fixture.sourceStartTime)
if (ordinary) {
  assert.equal(fixture.sourceTimestampOrigin, null)
  assert.equal(fixture.sourceVideoFirstPts, 0)
  assert.equal(fixture.sourceAudioFirstPts, 8)
  assert.equal(fixture.sourceDuration, 30)
  assert.deepEqual(fixture.expectedCues, [])
}
if (defaultAudio) {
  assert.equal(fixture.selectedAudioOrdinal, 1)
  assert.equal(fixture.selectedAudioGlobalIndex, 2)
  assert.deepEqual(fixture.sourceAudioStreams.map(stream => stream.index), [1, 2])
  assert.equal(fixture.sourceAudioStreams[0].disposition.default, 0)
  assert.equal(fixture.sourceAudioStreams[1].disposition.default, 1)
  assert.equal(fixture.selectedAudio.language, 'dan')
  assert.equal(fixture.selectedAudio.channels, 6)
  assert.equal(fixture.selectedAudio.sampleRate, 48000)
  assert.equal(fixture.selectedAudio.channelLayout, '5.1(side)')
  assert.equal(fixture.expectedAudioFrequency, 880)
  assert.equal(fixture.rejectedAudioFrequency, 220)
}
assert.equal(fixture.audioProfile, 'streaming_stereo')
assert.equal(fixture.sourceFrameRate, ordinary ? 25 : 0.1)
const evidence = '/work/evidence'
await mkdir(evidence, { recursive: true })
const started = Date.now()
const deadline = started + 120000
const launchFlags = ['--mute-audio', '--host-resolver-rules=MAP adaptive.baander.app 127.0.0.1', '--no-proxy-server']
const browser = await chromium.launch({ channel: 'chromium', headless: true, args: launchFlags })
const context = await browser.newContext()
await context.tracing.start({ screenshots: true, snapshots: true })
const page = await context.newPage()
const cdp = await context.newCDPSession(page)
await cdp.send('Network.enable')
await cdp.send('Network.setCacheDisabled', { cacheDisabled: true })
const browserCdp = await browser.newBrowserCDPSession()
const systemInfo = await browserCdp.send('SystemInfo.getInfo')
const boundedValue = value => typeof value === 'string' ? value.slice(0, 1024) : value
const rendering = {
  devices: (systemInfo.gpu.devices ?? []).slice(0, 4).map(device => Object.fromEntries(Object.entries(device)
    .filter(([, value]) => ['string', 'number', 'boolean'].includes(typeof value)).slice(0, 16)
    .map(([key, value]) => [key, boundedValue(value)]))),
  features: Object.fromEntries(Object.entries(systemInfo.gpu.featureStatus ?? {})
    .filter(([, value]) => ['string', 'number', 'boolean'].includes(typeof value)).slice(0, 64)
    .map(([key, value]) => [key, boundedValue(value)])),
  auxiliary: Object.fromEntries(Object.entries(systemInfo.gpu.auxAttributes ?? {})
    .filter(([key, value]) => /renderer|vendor|version|display|sandbox|process|implementation|decoder/i.test(key)
      && ['string', 'number', 'boolean'].includes(typeof value)).slice(0, 32)
    .map(([key, value]) => [key, boundedValue(value)])),
  commandLine: systemInfo.commandLine?.slice(0, 8192),
}
const mediaDiagnostics = { players: [], properties: [], events: [], droppedProperties: 0, droppedEvents: 0 }
cdp.on('Media.playersCreated', event => {
  for (const player of event.players) {
    if (mediaDiagnostics.players.length >= 16) break
    mediaDiagnostics.players.push(typeof player === 'string' ? player.slice(0, 128)
      : { playerId: String(player.playerId).slice(0, 128), domNodeId: player.domNodeId })
  }
})
cdp.on('Media.playerPropertiesChanged', event => {
  if (mediaDiagnostics.properties.length >= 64) { mediaDiagnostics.droppedProperties++; return }
  mediaDiagnostics.properties.push({ playerId: event.playerId, properties: event.properties.slice(0, 16)
    .map(property => ({ name: property.name.slice(0, 128), value: property.value.slice(0, 1024) })) })
})
cdp.on('Media.playerEventsAdded', event => {
  for (const item of event.events) {
    if (mediaDiagnostics.events.length >= 128) { mediaDiagnostics.droppedEvents++; continue }
    mediaDiagnostics.events.push({ playerId: event.playerId, timestamp: item.timestamp, value: item.value.slice(0, 1024) })
  }
})
await cdp.send('Media.enable')
const failures = []
const network = []
const manifests = []
const samples = []
const colorFrames = []
const subtitleFiles = []
const subtitleScreenshots = []
let status
let requests = []
let state
const receipt = { ...versions, scenario: fixture.scenario, fixture, browser: browser.version(),
  settings: { deadlineSeconds: 120, readinessSeconds: 45, cacheDisabled: true, browserMuteAudio: true,
    launchFlags }, rendering, mediaDiagnostics }
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
  frameObserver: { ...window.sparseFrameObserver },
  cueEvents: [...window.sparseCueEvents], cueSamples: [...window.sparseCueSamples],
  textTracks: Array.from(document.querySelector('#video').textTracks).map(track => window.sparseTrackState(track)),
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
  assert.deepEqual(state.frameObserver.errors, [], 'Native frame capture must not fail or lose observations')
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
  await page.evaluate(({ manifest, hasSubtitles, defaultAudio }) => {
    window.adaptiveManifest = manifest
    window.adaptiveFrequencyAnalysis = defaultAudio
    window.sparseColorFrames = []
    const video = document.querySelector('#video')
    window.sparseCueEvents = []
    window.sparseCueSamples = []
    const cueIds = new WeakMap()
    let nextCueId = 0
    window.sparseCueState = cue => {
      if (!cueIds.has(cue)) cueIds.set(cue, ++nextCueId)
      return { id: cueIds.get(cue), start: cue.startTime, end: cue.endTime, text: cue.text }
    }
    const trackIds = new WeakMap()
    let nextTrackId = 0
    window.sparseTrackState = track => {
      if (!trackIds.has(track)) trackIds.set(track, ++nextTrackId)
      return { id: trackIds.get(track), mode: track.mode,
        cues: Array.from(track.cues ?? []).map(cue => window.sparseCueState(cue)),
        active: Array.from(track.activeCues ?? []).map(cue => window.sparseCueState(cue)) }
    }
    window.sparseObserveCues = true
    if (hasSubtitles) {
      video.textTracks.addEventListener('addtrack', event => {
        const track = event.track
        track.mode = 'showing'
        track.addEventListener('cuechange', () => {
          window.sparseCueEvents.push({ at: performance.now(), time: video.currentTime, trackId: window.sparseTrackState(track).id,
            active: Array.from(track.activeCues ?? []).map(cue => window.sparseCueState(cue)) })
        })
      })
      setInterval(() => {
        if (!window.sparseObserveCues) return
        if (window.sparseCueSamples.length >= 1250) throw new Error('Native subtitle observations exceeded their bound')
        window.sparseCueSamples.push({ at: performance.now(), time: video.currentTime,
          tracks: Array.from(video.textTracks).map(track => window.sparseTrackState(track)),
        })
      }, 100)
    }
    const observer = window.sparseFrameObserver = {
      errors: [], droppedErrors: 0, constructed: 0, closed: 0, pending: 0, maxPending: 0, callbacks: 0, stopped: false,
    }
    const ownedFrames = new Set()
    const pendingCopies = new Set()
    const completed = new Map()
    let nextPublished = 0
    let callbackId
    const stop = () => {
      observer.stopped = true
      if (callbackId !== undefined) video.cancelVideoFrameCallback(callbackId)
    }
    const fail = (phase, error, sequence) => {
      stop()
      if (observer.errors.length < 64) observer.errors.push({ phase, sequence, message: String(error).slice(0, 1024) })
      else observer.droppedErrors++
    }
    const closeOwned = frame => {
      if (!ownedFrames.delete(frame)) return
      try { frame.close(); observer.closed++ } catch (error) { fail('close', error) }
    }
    window.sparseStopFrameObserver = async () => {
      stop()
      let timeout
      try {
        await Promise.race([
          Promise.all([...pendingCopies]),
          new Promise((_, reject) => { timeout = setTimeout(() => reject(new Error('Native frame capture did not drain within two seconds')), 2000) }),
        ])
      } catch (error) { fail('drain', error) } finally {
        clearTimeout(timeout)
        for (const frame of ownedFrames) closeOwned(frame)
      }
      return { ...observer }
    }
    const observe = (at, metadata) => {
      if (observer.stopped) return
      callbackId = video.requestVideoFrameCallback(observe)
      const sequence = observer.callbacks++
      const entryAt = performance.now()
      const entryClock = video.currentTime
      let frame
      let phase = 'constructor'
      try {
        if (ownedFrames.size >= 4) throw new Error('Native frame capture exceeded four outstanding snapshots')
        frame = new VideoFrame(video)
        ownedFrames.add(frame)
        observer.constructed++
        const afterConstructAt = performance.now()
        const afterConstructClock = video.currentTime
        phase = 'timestamp'
        const snapshotTime = frame.timestamp / 1000000
        if (!Number.isFinite(snapshotTime) || Math.abs(snapshotTime - metadata.mediaTime) > 0.000001) {
          throw new Error(`Native snapshot timestamp ${snapshotTime} does not match callback ${metadata.mediaTime}`)
        }
        phase = 'crop'
        const visible = frame.visibleRect
        if (!visible || ![visible.x, visible.y, visible.width, visible.height, frame.codedWidth, frame.codedHeight].every(Number.isInteger)
          || visible.width < 2 || visible.height < 2) throw new Error('Native snapshot has invalid visible dimensions')
        const rect = { x: Math.floor((visible.x + visible.width / 2) / 2) * 2,
          y: Math.floor((visible.y + visible.height / 2) / 2) * 2, width: 2, height: 2 }
        if (rect.x < visible.x || rect.y < visible.y || rect.x + 2 > visible.x + visible.width
          || rect.y + 2 > visible.y + visible.height || rect.x < 0 || rect.y < 0
          || rect.x + 2 > frame.codedWidth || rect.y + 2 > frame.codedHeight) throw new Error('Native snapshot crop exceeds its frame')
        const options = { format: 'RGBA', colorSpace: 'srgb', rect }
        if (frame.allocationSize(options) !== 16) throw new Error('Native snapshot crop must occupy sixteen bytes')
        const pixels = new Uint8Array(16)
        const record = { sequence, at, time: metadata.mediaTime, clock: entryClock, snapshotTime,
          presentedFrames: metadata.presentedFrames, presentationTime: metadata.presentationTime,
          expectedDisplayTime: metadata.expectedDisplayTime,
          stages: { entryAt, entryClock, afterConstructAt, afterConstructClock } }
        observer.pending++
        observer.maxPending = Math.max(observer.pending, observer.maxPending)
        let task
        task = (async () => {
          try {
            record.stages.copyStartedAt = performance.now()
            const copying = frame.copyTo(pixels, options)
            record.stages.afterCopyCallAt = performance.now()
            const layout = await copying
            record.stages.completedAt = performance.now()
            if (layout.length !== 1 || layout[0].offset !== 0 || layout[0].stride !== 8 || pixels.byteLength !== 16) {
              throw new Error('Native snapshot returned an unexpected RGBA plane layout')
            }
            record.rgb = [...pixels.slice(0, 3)]
            completed.set(sequence, record)
            while (completed.has(nextPublished)) {
              window.sparseColorFrames.push(completed.get(nextPublished))
              completed.delete(nextPublished++)
            }
          } catch (error) { fail('copy', error, sequence) } finally { closeOwned(frame) }
        })().finally(() => { pendingCopies.delete(task); observer.pending-- })
        pendingCopies.add(task)
      } catch (error) {
        fail(phase, error, sequence)
        if (frame) closeOwned(frame)
      }
    }
    callbackId = video.requestVideoFrameCallback(observe)
  }, { manifest: fixture.manifestUrl, hasSubtitles, defaultAudio })
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
    if (hasSubtitles) {
      for (const [index, cue] of fixture.expectedCues.entries()) {
        const phase = state.time >= cue.start + 0.25 && state.time < cue.end - 0.05 ? 'active'
          : state.time >= cue.end + 0.25 && state.time <= cue.end + 0.75 ? 'cleared' : null
        if (!phase || subtitleScreenshots.some(item => item.cue === cue.text && item.phase === phase)) continue
        const file = `subtitle-${index + 1}-${phase}.png`
        await page.screenshot({ path: `${evidence}/${file}` })
        subtitleScreenshots.push({ cue: cue.text, phase, localTime: state.time, file })
      }
    }
    if (state.ended) return true
    return false
  }, 'natural completion of the complete held-frame movie')
  await page.evaluate(async () => { window.sparseObserveCues = false; await window.sparseStopFrameObserver() })
  state = await snapshot()
  assert.equal(state.frameObserver.pending, 0, 'Every native frame copy must finish before acceptance')
  assert.equal(state.frameObserver.constructed, state.frameObserver.closed, 'Every native snapshot must be closed')
  receipt.naturalEnd = Object.freeze(structuredClone(state))
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
  assert(state.decodedFrames >= fixture.expectedDuration * (ordinary ? 25 : 1) - 2, 'Native decoding must advance throughout held frames')
  if (ordinary) {
    const frameTicks = colorFrames.map(frame => frame.time * 25)
    assert(frameTicks.every(tick => Math.abs(tick - Math.round(tick)) < 0.02), 'Displayed movie frames must follow the twenty-five fps presentation grid')
    const uniqueFrames = new Set(frameTicks.map(Math.round))
    assert(uniqueFrames.size >= fixture.expectedDuration * 25 * 0.6, 'Observe ordinary-rate displayed frames despite occasional missed callbacks')
    assert(frameTicks.some((tick, index) => index > 0 && Math.abs(tick - frameTicks[index - 1] - 1) < 0.02),
      'The actual native frame callbacks must expose the forty-millisecond movie cadence')
    receipt.frameCadence = { sourceRate: 25, expectedFrames: fixture.expectedDuration * 25,
      decodedFrames: state.decodedFrames, callbacks: colorFrames.length, uniquePresentedFrames: uniqueFrames.size }
  }
  for (const [color, sourceBegin, sourceEnd] of [['red', fixture.sourceStartTime, 10], ['green', 10, 20], ['blue', 20, 30]]) {
    const frames = colorFrames.filter(frame => frame.color === color)
    assert(frames.length >= (ordinary ? (sourceEnd - sourceBegin) * 25 * 0.6 : sourceEnd - sourceBegin - 2), `Retain native displayed ${color} frames throughout its hold`)
    assert(frames.some(frame => frame.sourceTime < sourceBegin + (ordinary ? 0.15 : 1.15)), `Observe the beginning of ${color}`)
    assert(frames.some(frame => frame.sourceTime >= sourceEnd - (ordinary ? 0.15 : 1.15)), `Observe the tail of ${color}`)
    const audibleFrames = delayed
      ? frames.filter(frame => frame.clock >= fixture.expectedAudioDelay + 0.25) : frames
    assert(audibleFrames.some(frame => samples.some(sample => sample.audio.some(audio => audio.rms > 0.001
      && Math.abs(audio.at - frame.at) < 500))), `Decoded ${color} after audio onset must coincide with recent audible samples`)
  }
  const master = manifests.find(manifest => manifest.body.includes('#EXT-X-STREAM-INF:'))
  assert(master, 'Retain the signed production master playlist')
  assert.equal(master.body.split('\n').filter(line => line.startsWith('#EXT-X-STREAM-INF:')).length, 2)
  assert(master.body.includes('mp4a.40.2'))
  if (defaultAudio) {
    const audioDeclarations = master.body.split('\n').filter(line => line.startsWith('#EXT-X-MEDIA:TYPE=AUDIO,'))
    assert.equal(audioDeclarations.length, 1, 'The selected source must retain one canonical published audio rendition')
    assert(/(?:^|,)LANGUAGE="dan"(?:,|$)/.test(audioDeclarations[0]), 'The production master must advertise selected Danish dialogue')
  }
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
  if (defaultAudio) {
    const observations = [...new Map(samples.flatMap(sample => sample.audio).map(audio => [audio.at, audio])).values()]
      .filter(audio => audio.time >= fixture.expectedAudioDelay + 0.5 && audio.rms > 0.001)
    assert(observations.length > 0, 'Measure the audible source identity after the selected dialogue begins')
    assert.equal(typeof state.audioContextSampleRate, 'number')
    for (const audio of observations) {
      assert.equal(audio.sampleRate, state.audioContextSampleRate)
      assert(Number.isFinite(audio.frequencyBinHz) && audio.frequencyBinHz > 0)
      assert(Number.isFinite(audio.dominantFrequency) && Math.abs(audio.dominantFrequency - fixture.expectedAudioFrequency) <= audio.frequencyBinHz * 1.5,
        `The browser must hear selected dialogue near 880 Hz, observed ${audio.dominantFrequency} Hz`)
      assert(Math.abs(audio.dominantFrequency - fixture.rejectedAudioFrequency) > audio.frequencyBinHz * 1.5,
        'The commentary tone must not become the dominant audible source')
    }
    const windows = [
      { name: 'post-onset', start: fixture.expectedAudioDelay + 0.5, end: fixture.expectedAudioDelay + 1.5 },
      { name: 'middle-hold', start: fixture.expectedDuration * 0.55, end: fixture.expectedDuration * 0.55 + 1 },
      { name: 'late-tail', start: fixture.expectedDuration - 2, end: fixture.expectedDuration - 0.25 },
    ].map(window => {
      const measured = observations.filter(audio => audio.time >= window.start && audio.time <= window.end)
      assert(measured.length >= 3, `Retain several actual frequency measurements in the ${window.name} window`)
      return { ...window, samples: measured.map(audio => ({ at: audio.at, time: audio.time, frequency: audio.dominantFrequency,
        binHz: audio.frequencyBinHz, sampleRate: audio.sampleRate, rms: audio.rms })) }
    })
    receipt.audioIdentity = { expectedFrequency: 880, rejectedFrequency: 220, toleranceBins: 1.5,
      audioContextSampleRate: state.audioContextSampleRate, audibleSamples: observations.length, windows }
  }
  if (delayed) {
    const silence = samples.filter(sample => sample.time >= 0.25 && sample.time < fixture.expectedAudioDelay - 0.25 && sample.audio.length > 0)
    assert(silence.length >= 3, 'Observe the played initial silence before the delayed audio begins')
    assert(silence.every(sample => sample.audio.every(audio => audio.rms < 0.001)), 'Initial audio must remain near-silent')
    const onset = samples.find(sample => sample.audio.some(audio => audio.rms > 0.001))
    assert(onset && onset.time >= fixture.expectedAudioDelay - 0.15 && onset.time <= fixture.expectedAudioDelay + 0.75,
      'Measured audio must begin at its intended source-relative delay')
    receipt.audioDelay = { expected: fixture.expectedAudioDelay, observedOnset: onset.time, silentSamples: silence.length }
  }
  if (hasSubtitles) {
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
    assert.equal(cues.length, fixture.expectedCues.length, 'Every retained cue must have exactly one native object')
    for (const expected of fixture.expectedCues) {
      assert(cues.some(cue => cue.text === expected.text && Math.abs(cue.start - expected.start) < 0.05
        && Math.abs(cue.end - expected.end) < 0.05), `The real text track must expose ${expected.text} at the local source time`)
      const cue = cues.find(cue => cue.text === expected.text)
      const owner = state.textTracks.find(track => track.cues.some(candidate => candidate.id === cue.id))
      const activeAt = sample => sample.tracks.some(track => track.id === owner.id && track.mode === 'showing' && track.active.some(active => active.id === cue.id))
      const active = state.cueSamples.find(activeAt)
      assert(active && active.time >= expected.start - 0.15 && active.time <= expected.start + 0.75,
        `The real text track must activate ${expected.text} at its local cue time`)
      assert(state.cueSamples.some(sample => sample.time >= expected.end - 0.05 && sample.time <= expected.end + 0.75
        && !activeAt(sample)), `The text track must clear ${expected.text} at its end`)
      for (const sample of state.cueSamples) {
        if (sample.time >= expected.start - 0.15) {
          const track = sample.tracks.find(track => track.id === owner.id)
          assert(track && track.mode === 'showing', 'The selected native subtitle track must stay present and showing')
          assert.deepEqual(track.cues, owner.cues, 'Native cue identity, count and timestamps must remain unchanged through playback')
        }
        if (sample.time < expected.start - 0.15 || sample.time > expected.end + 0.75) {
          assert(!activeAt(sample), `The native track must not display ${expected.text} outside its cue interval`)
        }
        if (sample.time >= expected.start + 0.75 && sample.time < expected.end - 0.05) {
          assert(activeAt(sample), `The native track must retain ${expected.text} through its intended interval`)
        }
      }
    }
    assert.deepEqual([...new Set(cues.map(cue => cue.text))].sort(), fixture.expectedCues.map(cue => cue.text).sort(),
      'Only the source-relative retained cues may reach the real text track')
    assert.equal(subtitleScreenshots.length, fixture.expectedCues.length * 2, 'Retain visible and cleared subtitle screenshots for every cue')
    receipt.subtitles = { files: subtitleFiles, manifests: subtitleManifests, cues, events: state.cueEvents, nativeSamples: state.cueSamples, screenshots: subtitleScreenshots }
  }
  if (ordinary) {
    assert(!master.body.includes('TYPE=SUBTITLES'))
    assert.equal(subtitleFiles.length, 0)
    assert.deepEqual(state.textTracks, [])
  }
  await page.screenshot({ path: `${evidence}/complete.png` })
  if (hasSubtitles) {
    receipt.subtitleSeekProof = []
    for (const expected of fixture.expectedCues) {
      const proof = await page.evaluate(async expected => {
        const video = document.querySelector('#video')
        video.pause()
        const track = Array.from(video.textTracks).find(track => track.mode === 'showing')
        const original = Array.from(track.cues ?? []).find(cue => cue.text === expected.text)
        const originalState = window.sparseCueState(original)
        const seek = time => new Promise((resolve, reject) => {
          const timeout = setTimeout(() => reject(new Error('Subtitle seek verification exceeded two seconds')), 2000)
          video.addEventListener('seeked', () => { clearTimeout(timeout); setTimeout(resolve, 100) }, { once: true })
          video.currentTime = time
        })
        const capture = () => ({ time: video.currentTime, trackId: window.sparseTrackState(track).id, mode: track.mode,
          active: Array.from(track.activeCues ?? []).map(cue => ({ ...window.sparseCueState(cue), sameOriginalObject: cue === original })),
          cues: Array.from(track.cues ?? []).map(cue => window.sparseCueState(cue)),
          originalObjectCount: Array.from(track.cues ?? []).filter(cue => cue === original).length })
        await seek(expected.start + 0.5)
        const backward = capture()
        await seek(expected.end)
        const exactEnd = capture()
        await seek(expected.end + 1)
        return { original: originalState, backward, exactEnd, pastEnd: capture(), adapter: window.adaptiveState() }
      }, expected)
      receipt.subtitleSeekProof.push(proof)
      assert(proof.backward.active.some(cue => cue.id === proof.original.id && cue.sameOriginalObject),
        'Backward seeking must reactivate the same native cue object')
      assert(!proof.pastEnd.active.some(cue => cue.id === proof.original.id), 'Seeking past the cue must clear its native active state')
      for (const observation of [proof.backward, proof.exactEnd, proof.pastEnd]) {
        assert.equal(observation.trackId, state.textTracks.find(track => track.cues.some(cue => cue.id === proof.original.id)).id)
        assert.equal(observation.mode, 'showing')
        assert.equal(observation.originalObjectCount, 1)
        assert.deepEqual(observation.cues, state.textTracks.flatMap(track => track.cues), 'Seeking must retain cue identities and original timestamps')
      }
      assert.equal(proof.adapter.attachments, 1)
      assert.equal(proof.adapter.sourceChanges, 1)
      assert.equal(proof.adapter.sameVideo, true)
      assert.deepEqual(proof.adapter.errors, [])
      assert.equal(proof.adapter.mediaError, null)
      await page.screenshot({ path: `${evidence}/subtitle-${proof.original.id}-after-seek.png` })
    }
    assert.deepEqual(failures, [], 'Subtitle seek verification must preserve successful transport and playback')
  }
  Object.assign(receipt, { passed: true, final: state, producer: status })
} catch (error) {
  Object.assign(receipt, { passed: false, error: error.stack, final: receipt.naturalEnd ?? await snapshot().catch(() => null), producer: status })
  await page.screenshot({ path: `${evidence}/failure.png` }).catch(() => {})
  process.exitCode = 1
} finally {
  receipt.frameObserverCleanup = await page.evaluate(() => window.sparseStopFrameObserver?.()).catch(error => ({ error: String(error) }))
  const observerCleanup = receipt.frameObserverCleanup
  if (observerCleanup?.error || (observerCleanup && (observerCleanup.errors.length > 0 || observerCleanup.pending !== 0
    || observerCleanup.constructed !== observerCleanup.closed || !observerCleanup.stopped))) {
    receipt.passed = false
    process.exitCode = 1
  }
  requests = await json('/__adaptive/requests').catch(() => requests)
  await Promise.all([...pendingManifests])
  Object.assign(receipt, { elapsedSeconds: (Date.now() - started) / 1000, colorFrames, samples, requests, network, manifests, subtitleFiles, failures })
  await writeFile(`${evidence}/result.json`, JSON.stringify(receipt, null, 2))
  await context.tracing.stop({ path: `${evidence}/trace.zip` })
  await browser.close()
}
