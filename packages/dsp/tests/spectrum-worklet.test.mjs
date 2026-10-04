import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import vm from 'node:vm'
import { loadWasm } from './wasm-fixture.mjs'

const publicSource = new URL('../../../public/audio-worklets/wasm-spectrum.js', import.meta.url)
const canonicalSource = new URL('../fft2048/wasm-spectrum.js', import.meta.url)

async function fixture() {
  const exp = await loadWasm('fft2048')
  const allocations = []
  const frames = []
  let instantiateCount = 0
  let Processor
  const messages = []
  const wrapped = {
    ...exp,
    wasm_malloc(size) {
      const ptr = exp.wasm_malloc(size + 64)
      new Uint8Array(exp.memory.buffer, ptr + size, 64).fill(0xa5)
      allocations.push({ ptr, size })
      return ptr
    },
    process_spectrum(input, mag, wave) {
      frames.push(Array.from(new Float32Array(exp.memory.buffer, input, 2048)))
      exp.process_spectrum(input, mag, wave)
    },
  }
  vm.runInNewContext(await readFile(publicSource, 'utf8'), {
    AudioWorkletProcessor: class { constructor() { this.port = { postMessage: message => messages.push(structuredClone(message)) } } },
    registerProcessor: (_name, value) => { Processor = value },
    Float32Array, Uint8Array,
    WebAssembly: { instantiate: async () => { instantiateCount++; return { instance: { exports: wrapped } } } },
  })
  const processor = new Processor()
  const initialize = () => processor.port.onmessage({ data: { type: 'wasm', bytes: new Uint8Array([1]) } })
  await initialize()
  return { processor, messages, allocations, frames, exp, initialize, instantiateCount: () => instantiateCount }
}

test('FFT worklet and canonical asset remain identical', async () => {
  assert.equal(await readFile(publicSource, 'utf8'), await readFile(canonicalSource, 'utf8'))
})

test('actual WASM uses full ABI buffers, preserves arbitrary blocks, and publishes complete spectra', async () => {
  const f = await fixture()
  assert.deepEqual(f.allocations.map(a => a.size), [8192, 1024, 2048])
  const expected = []
  let sample = 0
  for (const size of [333, 4096, 17]) {
    const left = Float32Array.from({ length: size }, () => ((sample++ % 101) - 50) / 100)
    const right = Float32Array.from(left, value => value * 0.5)
    const outputs = [new Float32Array(size), new Float32Array(size)]
    assert.equal(f.processor.process([[left, right]], [outputs]), true)
    assert.deepEqual(outputs[0], left)
    assert.deepEqual(outputs[1], right)
    expected.push(...Array.from(left, (value, i) => Math.fround((value + right[i]) * 0.5)))
  }
  assert.equal(f.frames.length, 2)
  assert.deepEqual(f.frames.flat(), expected.slice(0, 4096))
  const spectra = f.messages.filter(message => message.type === 'spectrum')
  assert.equal(spectra.length, 1)
  assert.equal(spectra[0].frequencyData.length, 1024)
  assert.equal(spectra[0].timeDomainData.length, 2048)
  for (const { ptr, size } of f.allocations) {
    assert.ok(new Uint8Array(f.exp.memory.buffer, ptr + size, 64).every(value => value === 0xa5), 'WASM must preserve allocation canaries')
  }
})

test('duplicate WASM initialization is idempotent', async () => {
  const f = await fixture()
  await Promise.all([f.initialize(), f.initialize()])
  assert.equal(f.instantiateCount(), 1)
  assert.equal(f.allocations.length, 3)
})
