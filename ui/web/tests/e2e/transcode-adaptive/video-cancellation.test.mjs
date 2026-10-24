import assert from 'node:assert/strict'
import test from 'node:test'
import { canDeferVideoCancellation, classifyVideoCancellations } from './video-cancellation.mjs'

const origin = 'http://adaptive.baander.app:18778'
const signature = 'a'.repeat(64)
const mediaUrl = (job, index = 4, generation = 1) => `${origin}/api/transcode/${job}/tracks/video/segment.m4s?index=${index}&generation=${generation}&sig=${signature}&exp=2000`
function evidence() {
  const fixture = { origin, manifestUrl: `${origin}/api/transcode/movie/master.m3u8?set=high.1%2Clow.1&sig=${signature}&exp=2000`,
    tiers: { high: { jobId: 'high', name: '720p' }, low: { jobId: 'low', name: '360p' } } }
  const abandoned = { id: 'abandoned', method: 'GET', url: mediaUrl('high'), started: 10, wallTime: 1000,
    failed: 12, canceled: true, error: 'net::ERR_ABORTED' }
  const replacement = { id: 'replacement', method: 'GET', url: mediaUrl('low'), started: 12.05, wallTime: 1002.05,
    finished: 12.2, status: 200, bytes: 5050, fromDiskCache: false, fromServiceWorker: false }
  const receipt = (transfer, jobId, tier, time) => ({ path: new URL(transfer.url).pathname + new URL(transfer.url).search,
    time, jobId, tier, track: 'video', index: 4, status: 200, closed: true, bytes: 4841, segmentStart: 24, segmentEnd: 30 })
  return { fixture, cancellations: [{ ...abandoned, kind: 'transport' }], network: [abandoned, replacement],
    requests: [receipt(abandoned, 'high', '720p', 1001.99), receipt(replacement, 'low', '360p', 1002.1)] }
}
function rejects(name, mutate) {
  test(name, () => {
    const data = evidence()
    mutate(data)
    assert.throws(() => classifyVideoCancellations(data), /Unexplained video cancellation/)
  })
}
function replacementTime(data, started) {
  data.network[1].started = started
  data.network[1].wallTime = 990 + started
  data.network[1].finished = started + 0.15
  data.requests[1].time = 990 + started + 0.05
}

test('a completed signed replacement retains both authenticated server receipts and timing', () => {
  const data = evidence()
  assert.equal(canDeferVideoCancellation(data.cancellations[0], data.fixture), true)
  const [result] = classifyVideoCancellations(data)
  assert.equal(result.abandoned.serverReceipt, data.requests[0])
  assert.equal(result.replacement.serverReceipt, data.requests[1])
  assert.equal(result.replacement.transfer, data.network[1])
  assert.equal(result.authenticatedSet, 'high.1,low.1')
  assert(Math.abs(result.replacement.secondsFromAbort - 0.05) < 0.00001)
})
test('no cancellations need no replacement evidence', () => assert.deepEqual(classifyVideoCancellations({ cancellations: [] }), []))
test('the negative half-second overlap boundary is inclusive', () => {
  const data = evidence()
  replacementTime(data, 11.5)
  assert.equal(classifyVideoCancellations(data).length, 1)
})
test('a replacement just below twelve seconds remains in bounds', () => {
  const data = evidence()
  replacementTime(data, 23.999)
  assert.equal(classifyVideoCancellations(data).length, 1)
})
rejects('overlap earlier than half a second is rejected', data => replacementTime(data, 11.499))
rejects('the twelve-second upper boundary is exclusive', data => replacementTime(data, 24))
rejects('a completed replacement started before the abandoned request is not a successor', data => replacementTime(data, 9.9))

for (const [name, update] of [
  ['not explicitly canceled', { canceled: false }], ['another network error', { error: 'net::ERR_CONNECTION_RESET' }],
  ['a blocked request', { blockedReason: 'inspector' }], ['another method', { method: 'POST' }],
  ['an audio segment', { url: mediaUrl('high').replace('/video/', '/audio/') }],
  ['an initialization segment', { url: mediaUrl('high').replace('segment.m4s', 'init.mp4') }],
  ['a media playlist', { url: mediaUrl('high').replace('segment.m4s', 'media.m3u8') }],
  ['an external origin', { url: mediaUrl('high').replace('adaptive.baander.app', 'external.baander.app') }],
  ['an unknown rendition', { url: mediaUrl('unknown') }],
  ['an unsigned resource', { url: mediaUrl('high').replace(`&sig=${signature}`, '') }],
  ['a malformed signature', { url: mediaUrl('high').replace(signature, 'invalid') }],
]) test(`immediate failure: ${name}`, () => {
  const data = evidence()
  Object.assign(data.cancellations[0], update)
  assert.equal(canDeferVideoCancellation(data.cancellations[0], data.fixture), false)
  assert.throws(() => classifyVideoCancellations(data))
})

rejects('missing completion is rejected', data => { delete data.network[1].finished })
rejects('a failed successor is rejected', data => { data.network[1].failed = 12.1 })
rejects('a non-200 successor is rejected', data => { data.network[1].status = 503 })
rejects('disk cache evidence is rejected', data => { data.network[1].fromDiskCache = true })
rejects('service-worker evidence is rejected', data => { data.network[1].fromServiceWorker = true })
rejects('missing explicit cache provenance is rejected', data => { delete data.network[1].fromDiskCache })
rejects('an empty transfer is rejected', data => { data.network[1].bytes = 0 })
rejects('same-rendition replacement is rejected', data => { data.network[1].url = mediaUrl('high') })
rejects('another generation is rejected', data => { data.network[1].url = mediaUrl('low', 4, 2) })
rejects('another authenticated set is rejected', data => { data.fixture.manifestUrl = data.fixture.manifestUrl.replace('low.1', 'other.1') })
rejects('a duplicate authenticated member is rejected', data => { data.fixture.manifestUrl = data.fixture.manifestUrl.replace('low.1', 'high.1') })
rejects('another capability expiry is rejected', data => { data.network[1].url = data.network[1].url.replace('exp=2000', 'exp=1900') })
rejects('a tampered signature without its validated receipt is rejected', data => { data.network[1].url = data.network[1].url.replace(signature, 'b'.repeat(64)) })
rejects('a wrong native interval is rejected', data => { data.requests[1].segmentEnd = 29 })
rejects('a wrong tier receipt is rejected', data => { data.requests[1].tier = '720p' })
rejects('a wrong selected file index is rejected', data => { data.requests[1].index = 3 })
rejects('a nonclosed file is rejected', data => { data.requests[1].closed = false })
rejects('a failed signature validation is rejected', data => { data.requests[1].status = 403 })
rejects('a missing canceled server receipt is rejected', data => { data.requests.shift() })
rejects('a missing successor server receipt is rejected', data => { data.requests.pop() })
rejects('a stale server receipt is rejected', data => { data.requests[1].time -= 100 })
rejects('a cross-run wall clock is rejected', data => { data.network[1].wallTime += 100; data.requests[1].time += 100 })
rejects('missing failed-event timing is rejected', data => { delete data.network[0].failed })
rejects('inconsistent failed-event timing is rejected', data => { data.cancellations[0].failed = 11 })
rejects('missing request wall time is rejected', data => { delete data.network[0].wallTime })
rejects('a duplicated server receipt is ambiguous', data => { data.requests.push({ ...data.requests[1] }) })
rejects('two successful transfers are ambiguous', data => { data.network.push({ ...data.network[1], id: 'second-replacement' }) })
rejects('a duplicate abandoned transfer is ambiguous', data => { data.network.push({ ...data.network[0] }) })

test('classifying evidence does not mutate its inputs', () => {
  const data = evidence()
  const before = structuredClone(data)
  classifyVideoCancellations(data)
  assert.deepEqual(data, before)
})
test('native interval differences below the existing fifty-millisecond allowance remain bounded', () => {
  const data = evidence()
  data.requests[1].segmentStart += 0.049
  assert.equal(classifyVideoCancellations(data).length, 1)
})
rejects('the native interval tolerance upper boundary is exclusive', data => { data.requests[1].segmentStart += 0.05 })
rejects('nonfinite abort timing is rejected', data => { data.network[0].failed = NaN; data.cancellations[0].failed = NaN })
rejects('nonfinite completion timing is rejected', data => { data.network[1].finished = Infinity })
rejects('missing service-worker provenance is rejected', data => { delete data.network[1].fromServiceWorker })
rejects('a blocked successor is rejected', data => { data.network[1].blockedReason = 'inspector' })
rejects('an unrelated canceled error cannot become a successor', data => { data.network[1].canceled = true })
rejects('a missing successful signature on the master is rejected', data => {
  data.fixture.manifestUrl = data.fixture.manifestUrl.replace(`&sig=${signature}`, '')
})
