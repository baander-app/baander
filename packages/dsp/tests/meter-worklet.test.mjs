import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import vm from 'node:vm'
import { loadWasm } from './wasm-fixture.mjs'

async function fixture(rate = 48000, fault, options = {}) {
  const modules = await Promise.all(['loudness_r128', 'dynamics_meter'].map(loadWasm))
  const messages = [], calls = [[], []], allocations = [[], []], freed = [[], []], initRates = [], oversampling = []
  let count = 0, Processor
  const pending = [], resets = [0, 0]
  const wrapped = modules.map((exp, index) => {
    const result = { ...exp, _initialize() {},
      process_frames(ptr, frames, channels) {
        if (['process', 'free'].includes(fault) && index === 0) throw new Error('process failed')
        calls[index].push(Array.from(new Float32Array(exp.memory.buffer, ptr, frames * channels)))
        exp.process_frames(ptr, frames, channels)
      },
      [index === 0 ? 'init_loudness' : 'init_meters'](...args) {
        initRates.push(index === 0 ? args[0] : args[2])
        if (index === 0) oversampling.push(args[1])
        return exp[index === 0 ? 'init_loudness' : 'init_meters'](...args)
      },
    }
    if (exp.malloc) result.malloc = size => {
      if (fault === 'allocation') return 0
      const ptr = exp.malloc(size + 64)
      new Uint8Array(exp.memory.buffer, ptr + size, 64).fill(0xa5)
      allocations[index].push({ ptr, size })
      return ptr
    }
    if (exp.free) result.free = ptr => { freed[index].push(ptr); if (fault === 'free') throw new Error('free failed'); exp.free(ptr) }
    if (fault === 'getter') result[index === 0 ? 'get_lufs_momentary' : 'get_crest_right'] = () => { throw new Error('getter failed') }
    if (fault === 'missing') delete result.malloc
    const resetName = index === 0 ? 'reset_loudness' : 'reset_meters'
    result[resetName] = () => {
      resets[index]++
      if (fault === `reset-${index === 0 ? 'loudness' : 'dynamics'}`) throw new Error('reset failed')
      exp[resetName]()
    }
    if (fault === 'missing-reset') delete result[resetName]
    return result
  })
  vm.runInNewContext(await readFile(new URL('../../../public/audio-worklets/magic-soup-processor.js', import.meta.url), 'utf8'), {
    AudioWorkletProcessor: class { constructor() { this.port = { postMessage: m => messages.push(structuredClone(m)) } } },
    registerProcessor: (_name, value) => { Processor = value },
    WebAssembly: { instantiate: async bytes => {
      count++
      if (options.deferred) await new Promise(resolve => pending.push(resolve))
      return { instance: { exports: wrapped[bytes[0]] } }
    } },
    Float32Array, sampleRate: rate, console: { log() {}, warn() {} },
  })
  const processor = new Processor()
  const initialize = () => processor.initDSPFromMessage({ loudnessWasm: new Uint8Array([0]), dynamicsWasm: new Uint8Array([1]) })
  if (options.initialize !== false) await initialize()
  await new Promise(resolve => setImmediate(resolve))
  return { processor, messages, calls, modules, allocations, freed, initRates, oversampling, initialize, resets,
    releaseInitialization: () => pending.splice(0).forEach(resolve => resolve()), count: () => count }
}

function feed(f, left, right = left) {
  const output = [new Float32Array(left.length), new Float32Array(left.length)]
  assert.equal(f.processor.process([[left, right]], [output]), true)
  assert.deepEqual(output[0], left)
  assert.deepEqual(output[1], right)
}

for (const rate of [44100, 48000]) test(`meter processes every frame and silence at ${rate} Hz with bounded buffers`, async () => {
  const f = await fixture(rate)
  const expected = []
  for (let block = 0; block < 64; block++) {
    const size = [1, 17, 128, 333, 4096][block % 5]
    const left = new Float32Array(size)
    const right = Float32Array.from(left, (_, i) => block < 32 ? Math.sin((i + block) * 0.07) * 0.5 : 0)
    feed(f, left, right)
    expected.push(...Array.from(left, (value, i) => [value, right[i]]).flat())
    if (block === 31) assert.equal(f.processor.isPlaying, true, 'right-only input must be detected')
  }
  assert.deepEqual(f.initRates, [rate, rate])
  assert.deepEqual(f.oversampling, [4])
  for (let index = 0; index < 2; index++) {
    assert.deepEqual(f.calls[index].flat(), expected)
    assert.ok(f.calls[index].every(block => block.length <= 256))
    assert.deepEqual(f.allocations[index].map(a => a.size), [1024])
    for (const { ptr, size } of f.allocations[index]) assert.ok(new Uint8Array(f.modules[index].memory.buffer, ptr + size, 64).every(value => value === 0xa5))
  }
  const reports = f.messages.filter(m => m.type === 'analysis')
  assert.equal(reports.length, 4)
  assert.equal(reports.at(-1).isPlaying, false)
  assert.ok(reports.at(-1).rms < 0.001, 'silent frames must release the RMS envelope')
  const reference = await Promise.all(['loudness_r128', 'dynamics_meter'].map(loadWasm))
  reference[0].init_loudness(rate, 4)
  reference[1].init_meters(10, 100, rate)
  for (const exp of reference) {
    const ptr = exp.malloc(1024)
    for (let offset = 0; offset < expected.length; offset += 256) {
      const block = expected.slice(offset, offset + 256)
      new Float32Array(exp.memory.buffer, ptr, block.length).set(block)
      exp.process_frames(ptr, block.length / 2, 2)
    }
    exp.free(ptr)
  }
  assert.equal(reports.at(-1).lufs, reference[0].get_lufs_momentary())
  assert.equal(reports.at(-1).rightChannel, Math.min(100, reference[1].get_rms_right() * 100))
})

test('duplicate initialization reuses modules and allocations', async () => {
  const f = await fixture()
  await Promise.all([f.initialize(), f.initialize()])
  assert.equal(f.count(), 2)
  assert.deepEqual(f.allocations.map(a => a.length), [1, 1])
})

for (const fault of ['missing', 'allocation', 'missing-reset']) test(`invalid ${fault} disables meters without fixed pointer writes`, async () => {
  const f = await fixture(48000, fault)
  assert.equal(f.processor.loudnessReady, false)
  assert.equal(f.processor.dynamicsReady, false)
  for (let i = 0; i < 16; i++) feed(f, new Float32Array([0, 0.5, 0]))
  assert.deepEqual(f.calls, [[], []])
  assert.equal(f.messages.filter(m => m.type === 'analysis').length, 1)
})

test('processing errors release failed meter and leave pass-through and other meter running', async () => {
  const f = await fixture(48000, 'process')
  for (let i = 0; i < 16; i++) feed(f, new Float32Array(128).fill(0.25))
  assert.equal(f.processor.loudnessReady, false)
  assert.equal(f.freed[0].length, 1)
  assert.equal(f.calls[1].length, 16)
  assert.ok(f.messages.find(m => m.type === 'analysis').rms > 0)
})

test('publication retains true peak from earlier blocks in the interval', async () => {
  const f = await fixture()
  feed(f, new Float32Array(128).fill(0.75))
  let peak = f.modules[0].get_true_peak_dbfs()
  for (let block = 1; block < 16; block++) {
    feed(f, new Float32Array(128))
    peak = Math.max(peak, f.modules[0].get_true_peak_dbfs())
  }
  assert.equal(f.messages.find(m => m.type === 'analysis').truePeak, peak)
})

test('getter traps release both meters once and publish fallback while pass-through continues', async () => {
  const f = await fixture(48000, 'getter')
  for (let i = 0; i < 32; i++) feed(f, new Float32Array(128).fill(0.25))
  assert.equal(f.processor.loudnessReady, false)
  assert.equal(f.processor.dynamicsReady, false)
  assert.deepEqual(f.freed.map(a => a.length), [1, 1])
  const reports = f.messages.filter(m => m.type === 'analysis')
  assert.equal(reports.length, 2)
  assert.equal(reports[0].rms, 0.25)
  assert.equal(reports[1].rms, 0.25)
})

test('cleanup traps cannot interrupt pass-through or the healthy meter', async () => {
  const f = await fixture(48000, 'free')
  for (let i = 0; i < 16; i++) feed(f, new Float32Array(128).fill(0.25))
  assert.equal(f.processor.loudnessReady, false)
  assert.equal(f.freed[0].length, 1)
  assert.equal(f.calls[1].length, 16)
})

// Tech 3341 case 16: fs/4 at 45 degrees, with 10 ms fades.
test('player publishes reconstructed intersample peaks', async () => {
  const f = await fixture()
  let samplePeak = 0
  for (let block = 0; block < 16; block++) {
    const input = Float32Array.from({ length: 128 }, (_, i) => {
      const n = block * 128 + i
      return 0.5 * Math.sin(Math.PI * n / 2 + Math.PI / 4) * Math.min(1, n / 480, (2047 - n) / 480)
    })
    for (const value of input) samplePeak = Math.max(samplePeak, Math.abs(value))
    feed(f, input)
  }
  const peak = f.messages.find(m => m.type === 'analysis').truePeak
  assert.ok(peak >= -6.4 && peak <= -5.8, `EBU case 16: ${peak}`)
  assert.ok(peak > 20 * Math.log10(samplePeak) + 2)
})

function resetProgramme(f, generation) {
  f.processor.port.onmessage({ data: { type: 'reset-programme', programmeGeneration: generation } })
}

test('programme reset clears publication interval and accepts only newer valid generations', async () => {
  const f = await fixture()
  for (let block = 0; block < 15; block++) feed(f, new Float32Array(128).fill(0.9))
  resetProgramme(f, 4)
  assert.equal(f.processor.frameCounter, 0)
  assert.equal(f.processor.isPlaying, false)
  assert.equal(f.processor.intervalTruePeak, -Infinity)
  assert.deepEqual({ ...f.processor.outputMessage }, {
    type: 'analysis', programmeGeneration: 4, lufs: -60, leftChannel: 0, rightChannel: 0,
    rms: 0, isPlaying: false, truePeak: -60, crestL: 0, crestR: 0,
  })
  feed(f, new Float32Array(128).fill(0.25))
  for (const generation of [4, 3, 0, -1, 5.5, NaN, Infinity, Number.MAX_SAFE_INTEGER + 1, '5', undefined]) {
    resetProgramme(f, generation)
  }
  assert.deepEqual(f.resets, [1, 1])
  assert.equal(f.processor.frameCounter, 1, 'stale/invalid resets must retain current programme frames')
  for (let block = 1; block < 15; block++) feed(f, new Float32Array(128))
  assert.equal(f.messages.filter(m => m.type === 'analysis').length, 0)
  feed(f, new Float32Array(128))
  const report = f.messages.find(m => m.type === 'analysis')
  assert.equal(report.programmeGeneration, 4)
  assert.ok(report.truePeak < -10, 'old programme interval peak must not be published')
  assert.equal(report.isPlaying, false)
  assert.deepEqual(f.allocations.map(a => a.length), [1, 1])
})

test('programme reset clears actual WASM gated history, filters, FIR and dynamics to a fresh instance', async () => {
  const f = await fixture(), fresh = await fixture()
  for (let offset = 0; offset < 48000 * 12; offset += 4096) {
    const samples = Float32Array.from({ length: Math.min(4096, 48000 * 12 - offset) }, (_, i) =>
      (offset + i < 48000 * 6 ? 0.6 : 0.06) * Math.sin(2 * Math.PI * 1000 * (offset + i) / 48000))
    feed(f, samples)
  }
  assert.ok(f.modules[0].get_lra() > 5, 'old programme must populate a varied gated distribution')
  assert.ok(f.modules[0].get_lufs_integrated() > -20)
  // Leave nonzero FIR history immediately before the reset.
  feed(f, new Float32Array([0.8]))
  resetProgramme(f, 1)
  resetProgramme(fresh, 1)
  const loudnessGetters = ['get_lufs_momentary', 'get_lufs_shortterm', 'get_lufs_integrated', 'get_lra', 'get_true_peak_dbfs']
  const dynamicsGetters = ['get_rms_left', 'get_rms_right', 'get_peak_left', 'get_peak_right', 'get_crest_left', 'get_crest_right']
  const compare = () => {
    for (const [index, getters] of [loudnessGetters, dynamicsGetters].entries()) {
      for (const getter of getters) assert.equal(f.modules[index][getter](), fresh.modules[index][getter](), getter)
    }
  }
  compare()
  const silence = new Float32Array(12)
  feed(f, silence)
  feed(fresh, silence)
  compare()
  for (let offset = 0; offset < 48000 * 4; offset += 4096) {
    const samples = Float32Array.from({ length: Math.min(4096, 48000 * 4 - offset) }, (_, i) =>
      0.015 * Math.sin(2 * Math.PI * 1000 * (offset + i) / 48000))
    feed(f, samples)
    feed(fresh, samples)
  }
  compare()
  assert.ok(f.modules[0].get_lufs_integrated() < -30, 'new programme must exclude old accepted blocks')
  assert.deepEqual(f.messages.filter(m => m.type === 'analysis' && m.programmeGeneration === 1),
    fresh.messages.filter(m => m.type === 'analysis'))
})

test('resets before and during asynchronous initialization retain newest programme generation', async () => {
  const f = await fixture(48000, undefined, { initialize: false, deferred: true })
  resetProgramme(f, 2)
  const initialization = f.initialize()
  resetProgramme(f, 3)
  for (let block = 0; block < 16; block++) feed(f, new Float32Array(128).fill(0.5))
  assert.equal(f.messages.find(m => m.type === 'analysis').programmeGeneration, 3)
  resetProgramme(f, 4)
  f.releaseInitialization()
  await initialization
  assert.equal(f.processor.loudnessReady, true)
  assert.equal(f.processor.dynamicsReady, true)
  assert.deepEqual(f.resets, [0, 0], 'pending meters are already fresh')
  assert.equal(f.modules[0].get_lufs_integrated(), -70)
  assert.equal(f.modules[1].get_rms_left(), 0)
  for (let block = 0; block < 16; block++) feed(f, new Float32Array(128))
  assert.equal(f.messages.filter(m => m.type === 'analysis').at(-1).programmeGeneration, 4)
})

for (const kind of ['loudness', 'dynamics']) test(`${kind} reset trap disables only the affected meter and preserves pass-through`, async () => {
  const f = await fixture(48000, `reset-${kind}`)
  feed(f, new Float32Array(128).fill(0.75))
  resetProgramme(f, 1)
  assert.equal(f.processor[`${kind}Ready`], false)
  assert.equal(f.processor[`${kind === 'loudness' ? 'dynamics' : 'loudness'}Ready`], true)
  assert.deepEqual(f.freed.map(a => a.length), kind === 'loudness' ? [1, 0] : [0, 1])
  for (let block = 0; block < 16; block++) feed(f, new Float32Array(128).fill(0.25))
  const report = f.messages.find(m => m.type === 'analysis')
  assert.equal(report.programmeGeneration, 1)
  assert.ok(report.rms > 0)
  assert.deepEqual(f.resets, [1, 1])
})
