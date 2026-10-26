import assert from 'node:assert/strict'
import test from 'node:test'
import vm from 'node:vm'
import { installFrameObserver } from './frame-observer.mjs'

const plain = value => JSON.parse(JSON.stringify(value))
const flush = async () => { for (let index = 0; index < 8; index++) await Promise.resolve() }
function harness(specifications = []) {
  const callbacks = new Map()
  const timers = new Map()
  const frames = []
  const events = []
  let nextCallback = 0
  let nextTimer = 0
  let now = 10
  let currentMetadata
  let nextSpecification = 0
  let autoTimeout = false
  const environment = {
    sparseColorFrames: [],
    performance: { now: () => now++ },
    setTimeout(callback, milliseconds) {
      assert.equal(milliseconds, 2000, 'The actual observer must retain its two-second drain deadline')
      const id = ++nextTimer
      timers.set(id, callback)
      if (autoTimeout) queueMicrotask(callback)
      return id
    },
    clearTimeout: id => timers.delete(id),
  }
  const video = {
    currentTime: 24.6,
    requestVideoFrameCallback(callback) {
      events.push('request')
      callbacks.set(++nextCallback, callback)
      return nextCallback
    },
    cancelVideoFrameCallback(id) { events.push('cancel'); callbacks.delete(id) },
  }
  environment.document = { querySelector: selector => { assert.equal(selector, '#video'); return video } }
  environment.VideoFrame = class {
    constructor(input) {
      assert.equal(input, video)
      events.push('constructor')
      const spec = specifications[nextSpecification++] ?? {}
      if (spec.constructorError) throw new Error(spec.constructorError)
      this.spec = spec
      this.timestamp = spec.timestamp ?? currentMetadata.mediaTime * 1000000
      this.duration = 40000
      this.format = 'I420'
      this.codedWidth = this.displayWidth = 64
      this.codedHeight = this.displayHeight = 48
      this.visibleRect = { x: 0, y: 0, width: 64, height: 48 }
      this.closeCount = 0
      this.copyCount = 0
      frames.push(this)
      if (spec.afterConstructClock !== undefined) video.currentTime = spec.afterConstructClock
    }
    allocationSize() { return this.spec.allocationSize ?? 16 }
    copyTo(pixels) {
      this.copyCount++
      events.push('copy')
      pixels.set([250, 0, 0, 255])
      if (this.spec.copy === 'throw') throw new Error('Synchronous copy failure')
      if (this.spec.copy === 'reject') return Promise.reject(new Error('Rejected copy'))
      const layout = this.spec.layout ?? [{ offset: 0, stride: 8 }]
      if (this.spec.copy === 'defer') {
        return new Promise((resolve, reject) => {
          this.resolve = () => resolve(layout)
          this.reject = reject
        })
      }
      return Promise.resolve(layout)
    }
    close() {
      events.push('close')
      this.closeCount++
      this.timestamp = -1
      this.visibleRect.x = 999
      if (this.spec.mutateMetadataOnClose) currentMetadata.mediaTime = -1
    }
  }
  // Serialization removes the module closure just as Playwright's evaluate does.
  vm.runInNewContext(`(${installFrameObserver.toString()})(environment)`, { environment })
  const fire = (metadata = {}) => {
    currentMetadata = { mediaTime: 24.6, presentedFrames: 607, presentationTime: 101,
      expectedDisplayTime: 102, width: 64, height: 48, processingDuration: 0.003, ...metadata }
    const [id, callback] = callbacks.entries().next().value
    callbacks.delete(id)
    callback(100, currentMetadata)
    return currentMetadata
  }
  return { environment, video, frames, events, callbacks, timers, fire,
    observer: environment.sparseFrameObserver,
    stop: () => environment.sparseStopFrameObserver(),
    timeoutAutomatically: value => { autoTimeout = value } }
}

test('timestamp mismatch stays fatal and retains detached callback, native and clock diagnostics before close', async () => {
  const h = harness([{ timestamp: 24640000, afterConstructClock: 24.64, mutateMetadataOnClose: true }])
  const metadata = h.fire()
  assert.deepEqual(h.events, ['request', 'request', 'constructor', 'cancel', 'close'])
  const error = plain(h.observer.errors[0])
  assert.equal(error.phase, 'timestamp')
  assert.equal(error.message, 'Error: Native snapshot timestamp 24.64 does not match callback 24.6')
  assert.equal(error.diagnostic.sequence, 0)
  assert.equal(error.diagnostic.at, 100)
  assert.equal(error.diagnostic.callback.mediaTime, 24.6)
  assert.equal(error.diagnostic.callback.presentedFrames, 607)
  assert.equal(error.diagnostic.callback.captureTime, null)
  assert.deepEqual(error.diagnostic.stages, { entryAt: 10, entryClock: 24.6,
    afterConstructAt: 11, afterConstructClock: 24.64, copyStartedAt: null, afterCopyCallAt: null, completedAt: null })
  assert.deepEqual(error.diagnostic.native, { timestamp: 24640000, snapshotTime: 24.64,
    duration: 40000, format: 'I420', codedWidth: 64, codedHeight: 48, displayWidth: 64, displayHeight: 48,
    visibleRect: { x: 0, y: 0, width: 64, height: 48 } })
  assert.equal(metadata.mediaTime, -1)
  assert.equal(h.frames[0].timestamp, -1)
  assert.equal(h.frames[0].visibleRect.x, 999)
  assert.equal(h.frames[0].copyCount, 0)
  assert.equal(h.frames[0].closeCount, 1)
  assert.equal(h.environment.sparseColorFrames.length, 0)
  const cleanup = await h.stop()
  assert.equal(cleanup.constructed, cleanup.closed)
  assert.equal(cleanup.pending, 0)
  assert.equal(cleanup.stopped, true)
  assert.deepEqual(plain(h.observer.errors[0]), error)
})

test('constructor failure keeps entry evidence and explicit unavailable stages without claiming a frame', async () => {
  const h = harness([{ constructorError: 'No native frame' }])
  h.fire()
  const error = plain(h.observer.errors[0])
  assert.equal(error.phase, 'constructor')
  assert.equal(error.diagnostic.callback.mediaTime, 24.6)
  assert.equal(error.diagnostic.native, null)
  assert.deepEqual(error.diagnostic.stages, { entryAt: 10, entryClock: 24.6,
    afterConstructAt: null, afterConstructClock: null, copyStartedAt: null, afterCopyCallAt: null, completedAt: null })
  assert.equal(h.observer.constructed, 0)
  assert.equal(h.observer.closed, 0)
  assert.equal((await h.stop()).pending, 0)
})

for (const copy of ['throw', 'reject']) {
  test(`${copy} copy failure retains available stages, stops and closes exactly once`, async () => {
    const h = harness([{ copy }])
    h.fire()
    await flush()
    const error = plain(h.observer.errors[0])
    assert.equal(error.phase, 'copy')
    assert.equal(error.diagnostic.native.timestamp, 24600000)
    assert.equal(error.diagnostic.stages.copyStartedAt, 12)
    assert.equal(error.diagnostic.stages.afterCopyCallAt, copy === 'throw' ? null : 13)
    assert.equal(error.diagnostic.stages.completedAt, null)
    assert.equal(h.environment.sparseColorFrames.length, 0)
    assert.equal(h.frames[0].closeCount, 1)
    assert.equal((await h.stop()).pending, 0)
    assert.equal(h.frames[0].closeCount, 1)
  })
}

test('matching frames publish successful fields in callback order despite out-of-order copies', async () => {
  const h = harness([{ copy: 'defer' }, {}, {}])
  h.fire()
  h.fire({ mediaTime: 24.64, presentedFrames: 608 })
  h.fire({ mediaTime: 24.68, presentedFrames: 609 })
  await flush()
  assert.equal(h.environment.sparseColorFrames.length, 0)
  h.frames[0].resolve()
  await flush()
  const samples = plain(h.environment.sparseColorFrames)
  assert.deepEqual(samples.map(sample => sample.sequence), [0, 1, 2])
  assert.deepEqual(samples.map(sample => sample.time), [24.6, 24.64, 24.68])
  assert.equal(samples[0].at, 100)
  assert.equal(samples[0].clock, 24.6)
  assert.equal(samples[0].snapshotTime, 24.6)
  assert.equal(samples[0].presentedFrames, 607)
  assert.equal(samples[0].presentationTime, 101)
  assert.equal(samples[0].expectedDisplayTime, 102)
  assert.deepEqual(samples[0].rgb, [250, 0, 0])
  assert.equal(samples[0].stages.copyStartedAt, 12)
  assert(samples[0].stages.completedAt >= samples[0].stages.afterCopyCallAt)
  assert.equal(h.observer.errors.length, 0)
  assert(h.frames.every(frame => frame.closeCount === 1))
  const cleanup = await h.stop()
  assert.equal(cleanup.pending, 0)
  assert.equal(cleanup.constructed, 3)
  assert.equal(cleanup.closed, 3)
})

test('crop and completed-copy validation retain constructed diagnostics without successful samples', async () => {
  for (const spec of [{ allocationSize: 17 }, { layout: [{ offset: 1, stride: 8 }] }]) {
    const h = harness([spec])
    h.fire()
    await flush()
    const error = plain(h.observer.errors[0])
    assert.equal(error.phase, spec.allocationSize ? 'crop' : 'copy')
    assert.equal(error.diagnostic.native.timestamp, 24600000)
    assert.equal(error.diagnostic.stages.completedAt === null, Boolean(spec.allocationSize))
    assert.equal(h.environment.sparseColorFrames.length, 0)
    await h.stop()
    assert.equal(h.frames[0].closeCount, 1)
  }
})

test('four-owned-frame limit remains fatal and stopping cancels callbacks while copies drain', async () => {
  const h = harness(Array.from({ length: 4 }, () => ({ copy: 'defer' })))
  for (let index = 0; index < 4; index++) h.fire()
  const lateCallback = h.callbacks.values().next().value
  h.fire()
  assert.equal(h.frames.length, 4)
  assert.equal(h.observer.maxPending, 4)
  assert.match(h.observer.errors[0].message, /four outstanding snapshots/)
  assert.equal(h.observer.errors[0].diagnostic.native, null)
  assert.equal(h.callbacks.size, 0)
  lateCallback(100, { mediaTime: 24.6 })
  assert.equal(h.observer.callbacks, 5)
  let stopped = false
  const stopping = h.stop().then(value => { stopped = true; return value })
  await flush()
  assert.equal(stopped, false)
  h.frames.forEach(frame => frame.resolve())
  const cleanup = await stopping
  assert.equal(cleanup.pending, 0)
  assert.equal(cleanup.constructed, cleanup.closed)
  assert(h.frames.every(frame => frame.closeCount === 1))
  assert.deepEqual(plain(h.environment.sparseColorFrames).map(sample => sample.sequence), [0, 1, 2, 3])
})

test('two-second failed drain closes ownership once and error receipts stay bounded', async () => {
  const h = harness([{ copy: 'defer' }])
  h.fire()
  h.timeoutAutomatically(true)
  for (let index = 0; index < 66; index++) await h.stop()
  assert.equal(h.observer.errors.length, 64)
  assert.equal(h.observer.droppedErrors, 2)
  assert(h.observer.errors.every(error => error.phase === 'drain'))
  assert.equal(h.frames[0].closeCount, 1)
  assert.equal(h.observer.closed, 1)
  h.timeoutAutomatically(false)
  h.frames[0].resolve()
  await flush()
  const cleanup = await h.stop()
  assert.equal(cleanup.pending, 0)
  assert.equal(cleanup.constructed, cleanup.closed)
  assert.equal(h.frames[0].closeCount, 1)
})
