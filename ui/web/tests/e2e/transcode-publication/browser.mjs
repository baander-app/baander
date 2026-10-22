import assert from 'node:assert/strict'
import { mkdir, readFile, writeFile } from 'node:fs/promises'
import { chromium } from 'playwright-core'

const fixture = JSON.parse(await readFile('/work/ready.json', 'utf8'))
const evidence = '/work/evidence'
await mkdir(evidence, { recursive: true })
const browser = await chromium.launch({ channel: 'chromium', headless: true,
  args: ['--mute-audio', '--host-resolver-rules=MAP publication.baander.app 127.0.0.1', '--no-proxy-server'] })
const context = await browser.newContext()
await context.tracing.start({ screenshots: true, snapshots: true })
const page = await context.newPage()
const failures = []
const manifests = []
const samples = []
let status
let state
let requests = []
const receipt = {
  browser: browser.version(), playwright: '1.60.0', dashjs: '5.2.1', ffmpeg: fixture.ffmpegVersion,
  settings: { utcSynchronization: { enabled: false }, startTime: 0, browserMuteAudio: true },
  expectedDuration: fixture.expectedDuration, frameRate: fixture.frameRate,
}

await page.route('**/*', async route => {
  if (new URL(route.request().url()).origin !== fixture.origin) {
    failures.push({ kind: 'external-request', url: route.request().url() })
    await route.abort()
  } else await route.continue()
})
page.on('pageerror', error => failures.push({ kind: 'page-error', message: error.message }))
page.on('requestfailed', request => failures.push({ kind: 'request-failed', path: new URL(request.url()).pathname,
  message: request.failure()?.errorText }))
page.on('response', async response => {
  const path = new URL(response.url()).pathname
  if (response.status() >= 400) failures.push({ kind: 'http-error', path, status: response.status() })
  if (!path.endsWith('/manifest.mpd') || response.status() !== 200) return
  try {
    const body = await response.text()
    const type = /<MPD\b[^>]*\btype=["'](dynamic|static)["']/.exec(body)?.[1]
    manifests.push({ at: Date.now(), type, segments: (body.match(/<SegmentURL\b/g) ?? []).length })
    await writeFile(`${evidence}/manifest-${manifests.length}-${type ?? 'unknown'}.mpd`, body)
  } catch (error) {
    failures.push({ kind: 'manifest-read', message: error.message })
  }
})

const json = path => page.evaluate(async resource => {
  const response = await fetch(resource, { cache: 'no-store' })
  if (!response.ok) throw new Error(`Fixture ${resource} returned HTTP ${response.status}`)
  return response.json()
}, path)
const snapshot = () => page.evaluate(() => window.publicationState())
const until = async (check, description, timeout = 65000) => {
  const deadline = Date.now() + timeout
  while (Date.now() < deadline) {
    if (await check()) return
    await new Promise(resolve => setTimeout(resolve, 200))
  }
  throw new Error(`Timed out waiting for ${description}`)
}

try {
  await page.goto(`${fixture.origin}/__publication/`)
  await page.evaluate(manifest => { window.publicationManifest = manifest }, fixture.manifestUrl)
  assert.equal((await snapshot()).version, '5.2.1', 'The reference player must use the pinned dash.js version')
  await page.evaluate(async () => {
    const response = await fetch('/__publication/start', { method: 'POST' })
    if (response.status !== 202) throw new Error('The real producer did not start')
  })
  await until(async () => {
    status = await json('/__publication/status')
    if (status.producer?.exitCode !== null && status.producer?.exitCode !== undefined && status.producer.exitCode !== 0) {
      throw new Error(`The real producer failed with exit code ${status.producer.exitCode}`)
    }
    return status.ready
  }, 'the first closed native presentation', 30000)
  assert.equal(status.complete, false, 'The browser must attach during real encoding')
  assert.equal(status.producer.running, true)
  receipt.initial = status
  await page.getByRole('button', { name: 'Play publication' }).click()
  await until(async () => {
    state = await snapshot()
    status = await json('/__publication/status')
    samples.push({ at: Date.now(), state: { time: state.time, duration: state.duration, decodedFrames: state.decodedFrames,
      decodedAudioBytes: state.decodedAudioBytes, peakAudioRms: state.peakAudioRms, ended: state.ended }, status })
    assert.equal(state.mediaError, null, 'The native media element reported a decoding error')
    assert.deepEqual(state.errors, [], 'The reference DASH player reported an error')
    assert.deepEqual(failures, [], 'Playback must not require failed or external requests')
    return state.ended
  }, 'unforced natural DASH end of stream')
  requests = await json('/__publication/requests')
  assert.equal(status.complete, true, 'Natural EOF must follow the native static presentation')
  assert.equal(status.producer.running, false)
  assert.equal(status.producer.exitCode, 0)
  assert.equal(state.width, fixture.width)
  assert.equal(state.height, fixture.height)
  assert(state.firstPlayingTime !== null && state.firstPlayingTime < 1, 'Playback must begin near the source start')
  assert(state.decodedFrames >= fixture.expectedDuration * fixture.frameRate * 0.9, 'The reference player must decode the source video')
  assert(state.duration !== null && Math.abs(state.duration - fixture.expectedDuration) <= 0.15, 'Natural duration must match the independently probed source')
  assert(Math.abs(state.time - state.duration) <= 0.1, 'The native ended event must occur at the real presentation end')
  const audioEvidence = state.decodedAudioBytes > 0 ? 'decoded-audio-bytes'
    : state.peakAudioRms > 0.001 && state.audioSamples >= 3 ? 'web-audio-signal' : null
  assert(audioEvidence, 'Playback must decode audio bytes or produce a measured nonzero audio signal')
  assert.equal(manifests[0]?.type, 'dynamic')
  assert(manifests.some(manifest => manifest.type === 'static'), 'The same player must consume the dynamic-to-static transition')
  const dynamic = manifests.filter(manifest => manifest.type === 'dynamic')
  assert(dynamic.length >= 2 && manifests.length <= 30, 'Active MPD refreshes must be present and bounded')
  assert(new Set(dynamic.map(manifest => manifest.segments)).size > 1, 'The player must observe growing media before completion')
  assert(requests.every(request => request.status === 200 && request.closed), 'Only signed, closed media may be delivered')
  for (const track of ['video', 'audio']) {
    const media = requests.filter(request => request.path.endsWith(`/tracks/${track}/segment.m4s`))
    assert(new Set(media.filter(request => !request.complete).map(request => request.index)).size >= 2,
      `New ${track} media must be fetched during active encoding`)
    const indices = new Set(media.map(request => request.index))
    for (let index = 0; index < status[`${track}Segments`]; index++) {
      assert(indices.has(index), `The player must consume ${track} segment ${index} before natural EOF`)
    }
  }
  Object.assign(receipt, { passed: true, audioEvidence, final: state, producer: status, manifests, requests, samples })
  await page.screenshot({ path: `${evidence}/ended.png` })
} catch (error) {
  Object.assign(receipt, { passed: false, error: error.message, final: await snapshot().catch(() => null),
    producer: status, manifests, requests: await json('/__publication/requests').catch(() => requests), samples, failures })
  await page.screenshot({ path: `${evidence}/failure.png` }).catch(() => {})
  process.exitCode = 1
} finally {
  await writeFile(`${evidence}/result.json`, JSON.stringify(receipt, null, 2))
  await context.tracing.stop({ path: `${evidence}/trace.zip` })
  await browser.close()
}
