import assert from 'node:assert/strict'
import test from 'node:test'
import { playersAdvancing, summarizeSlot, validateConfiguration } from './driver.mjs'

const config = () => ({
  baseURL: 'http://household.baander.app:8080', scenario: 'mixed-overlap', durationSeconds: 1800,
  email: 'first@baander.app', password: 'private-first',
  slots: [
    { kind: 'audio', publicId: 'original-song', songTitle: 'Household original', expectedDelivery: 'original' },
    { kind: 'video', publicId: 'original-video', pagePath: '/movies/original-movie', expectedDelivery: 'original', email: 'second@baander.app', password: 'private-second' },
  ],
  outputPath: '/tmp/baander.app-observation.jsonl', readyPath: '/tmp/baander.app-ready.json', stopPath: '/tmp/baander.app-stop',
})

const samples = (kind = 'video') => Array.from({ length: 1801 }, (_, index) => ({
  epochMs: 100000 + index * 1000, monotonicMs: index * 1000, presentationPosition: index, delivery: 'copy',
  media: { currentTime: index, paused: false, ended: false, errorCode: null, readyState: 4,
    decodedFrames: kind === 'video' ? index * 30 : null },
}))

test('normalizes per-slot credentials and keeps movie route distinct from delivery media ID', () => {
  const parsed = validateConfiguration(config())
  assert.equal(parsed.slots[0].email, 'first@baander.app')
  assert.equal(parsed.slots[1].email, 'second@baander.app')
  assert.equal(parsed.slots[1].publicId, 'original-video')
  assert.equal(parsed.slots[1].pagePath, '/movies/original-movie')
  assert.equal(parsed.startupTimeoutSeconds, 180)
})

test('rejects a shared canonical account, short qualification and escaping navigation', () => {
  const shared = config()
  shared.slots[1].email = shared.email
  assert.throws(() => validateConfiguration(shared), /distinct accounts/)
  assert.throws(() => validateConfiguration({ ...config(), durationSeconds: 90 }), /at least 1800/)
  const escaping = config()
  escaping.slots[1].pagePath = '//outside.baander.app/movie'
  assert.throws(() => validateConfiguration(escaping), /pagePath/)
  assert.throws(() => validateConfiguration({ ...config(), scenario: 'fresh-copy-overlap' }), /disagree/)
})

test('accepts a full advancing native-video window with frame evidence', () => {
  const result = summarizeSlot(samples(), 'copy', 'video', 1800)
  assert.equal(result.passed, true)
  assert.equal(result.advancingCoverage, 1)
  assert.equal(result.decodedFrameAdvancingIntervals, 1800)
})

test('advancing playback time cannot hide frozen decoded video frames', () => {
  const values = samples()
  for (const sample of values.slice(100, 111)) sample.media.decodedFrames = 3000
  const result = summarizeSlot(values, 'copy', 'video', 1800)
  assert.equal(result.passed, false)
  assert.ok(result.failures.includes('continuous-stall'))
})

test('weights missing observation time and rejects early stop', () => {
  const values = samples('audio').filter(sample => sample.monotonicMs < 100000 || sample.monotonicMs > 140000)
  assert.ok(summarizeSlot(values, 'copy', 'audio', 1800).failures.includes('sampler-gap'))
  assert.ok(summarizeSlot(values.slice(0, 60), 'copy', 'audio', 1800).failures.includes('incomplete-measurement-window'))
})

test('delivery replacement, paused media and native errors remain failures', () => {
  const values = samples('audio')
  values[20].delivery = 'encoded'
  values[30].media.errorCode = 3
  for (const sample of values.slice(40, 50)) sample.media.paused = true
  const result = summarizeSlot(values, 'copy', 'audio', 1800)
  assert.ok(result.failures.includes('unexpected-delivery'))
  assert.ok(result.failures.includes('native-media-error'))
  assert.ok(result.failures.includes('continuous-stall'))
})

test('startup needs two prior advancing samples and never treats missing history as ready', () => {
  const slots = [{ kind: 'video', expectedDelivery: 'copy' }, { kind: 'video', expectedDelivery: 'copy' }]
  const before = [samples()[0], samples()[0]]
  const after = [samples()[1], samples()[1]]
  assert.equal(playersAdvancing(after, null, slots), false)
  assert.equal(playersAdvancing(before, before, slots), false)
  assert.equal(playersAdvancing(after, before, slots), true)
})

test('small advances cannot qualify a stream running far slower than real time', () => {
  const values = samples('audio')
  for (const sample of values) sample.presentationPosition /= 2
  assert.ok(summarizeSlot(values, 'copy', 'audio', 1800).failures.includes('playback-progress-loss'))
})

test('post-ready waiting intervals fail even if a later position jump hides the gap', () => {
  const values = samples('audio')
  values[10].events = [{ waitingIntervals: [{ startEpochMs: 108000, endEpochMs: 110000, seconds: 2 }] }]
  const result = summarizeSlot(values, 'copy', 'audio', 1800)
  assert.equal(result.progressLossSeconds, 0)
  assert.ok(result.failures.includes('post-ready-waiting-or-stalled'))
  assert.equal(result.longestPostReadyWaitingSeconds, 2)
})

test('startup waiting history does not contaminate steady playback qualification', () => {
  const values = samples('audio')
  values[0].events = [{ waitingIntervals: [{ startEpochMs: 50000, endEpochMs: 99000, seconds: 49 }] }]
  assert.equal(summarizeSlot(values, 'copy', 'audio', 1800).passed, true)
})

test('video startup cannot claim readiness without decoded frame advancement', () => {
  const slots = [{ kind: 'video', expectedDelivery: 'copy' }, { kind: 'video', expectedDelivery: 'copy' }]
  const before = [samples()[0], samples()[0]]
  const after = [samples()[1], samples()[1]]
  after[0].media.decodedFrames = 0
  assert.equal(playersAdvancing(after, before, slots), false)
})

test('mixed playback pairs original music with an original video', () => {
  const copyVideo = config()
  copyVideo.slots[1].expectedDelivery = 'copy'
  assert.throws(() => validateConfiguration(copyVideo), /Slot deliveries disagree with scenario/)
  const twoVideos = config()
  twoVideos.slots[0] = { ...twoVideos.slots[1], email: 'first@baander.app', password: 'private-first' }
  assert.throws(() => validateConfiguration(twoVideos), /one audio and one video/)
})

test('one player runs a single slot and needs no second account', () => {
  const single = { ...config(), scenario: 'original-overlap', players: 1,
    slots: [{ kind: 'video', publicId: 'original-video', pagePath: '/movies/original-movie', expectedDelivery: 'original' }] }
  const parsed = validateConfiguration(single)
  assert.equal(parsed.players, 1)
  assert.equal(parsed.slots.length, 1)
  assert.throws(() => validateConfiguration({ ...single, slots: [...single.slots, { ...single.slots[0], email: 'second@baander.app', password: 'private-second' }] }), /one playback slot per player/)
  assert.throws(() => validateConfiguration({ ...single, players: 3 }), /players must be 1 or 2/)
  const slots = [{ kind: 'video', expectedDelivery: 'copy' }]
  assert.equal(playersAdvancing([samples()[1]], [samples()[0]], slots), true)
  assert.equal(playersAdvancing([samples()[1], samples()[1]], [samples()[0], samples()[0]], slots), false)
})

test('two players remain the default', () => {
  assert.equal(validateConfiguration(config()).players, 2)
  const single = config()
  single.slots = single.slots.slice(0, 1)
  assert.throws(() => validateConfiguration(single), /one playback slot per player/)
})

test('recovery and insufficient-memory runs play original media', () => {
  for (const scenario of ['recovery', 'insufficient-memory']) {
    const value = { ...config(), scenario }
    value.slots = value.slots.map(slot => ({ ...slot, kind: 'video', publicId: 'original-video', pagePath: '/movies/original-movie' }))
    assert.equal(validateConfiguration(value).scenario, scenario)
    value.slots[0].expectedDelivery = 'copy'
    assert.throws(() => validateConfiguration(value), /disagree/)
  }
  assert.throws(() => validateConfiguration({ ...config(), scenario: 'deployment-replacement' }), /Unknown playback scenario/)
})
