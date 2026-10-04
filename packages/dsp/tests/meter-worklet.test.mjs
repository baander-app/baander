import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import vm from 'node:vm'
import { loadWasm } from './wasm-fixture.mjs'

async function fixture(rate = 48000, fault) {
  const modules = await Promise.all(['loudness_r128', 'dynamics_meter'].map(loadWasm))
  const messages = [], calls = [[], []], allocations = [[], []], freed = [[], []], initRates = []
  let count = 0, Processor
  const wrapped = modules.map((exp, index) => {
    const result = { ...exp, _initialize() {},
      process_frames(ptr, frames, channels) {
        if (['process', 'free'].includes(fault) && index === 0) throw new Error('process failed')
        calls[index].push(Array.from(new Float32Array(exp.memory.buffer, ptr, frames * channels)))
        exp.process_frames(ptr, frames, channels)
      },
      [index === 0 ? 'init_loudness' : 'init_meters'](...args) {
        initRates.push(index === 0 ? args[0] : args[2])
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
    return result
  })
  vm.runInNewContext(await readFile(new URL('../../../public/audio-worklets/magic-soup-processor.js', import.meta.url), 'utf8'), {
    AudioWorkletProcessor: class { constructor() { this.port = { postMessage: m => messages.push(structuredClone(m)) } } },
    registerProcessor: (_name, value) => { Processor = value },
    WebAssembly: { instantiate: async bytes => { count++; return { instance: { exports: wrapped[bytes[0]] } } } },
    Float32Array, sampleRate: rate, console: { log() {}, warn() {} },
  })
  const processor = new Processor()
  const initialize = () => processor.initDSPFromMessage({ loudnessWasm: new Uint8Array([0]), dynamicsWasm: new Uint8Array([1]) })
  await initialize()
  await new Promise(resolve => setImmediate(resolve))
  return { processor, messages, calls, modules, allocations, freed, initRates, initialize, count: () => count }
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
  reference[0].init_loudness(rate, 2)
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

for (const fault of ['missing', 'allocation']) test(`invalid ${fault} disables meters without fixed pointer writes`, async () => {
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
  const peak = f.modules[0].get_true_peak_dbfs()
  for (let block = 1; block < 16; block++) feed(f, new Float32Array(128))
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
