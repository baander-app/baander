import test from 'node:test'
import assert from 'node:assert/strict'
import { loadWasm } from './wasm-fixture.mjs'

const size = 2048

async function fft(window = 0) {
  const api = await loadWasm('fft2048')
  api.init_fft(window)
  const input = api.wasm_malloc(size * 4)
  const magnitude = api.wasm_malloc(size / 2)
  const waveform = api.wasm_malloc(size)
  return {
    api, input, magnitude, waveform,
    process(samples) {
      new Float32Array(api.memory.buffer, input, size).set(samples)
      api.process_spectrum(input, magnitude, waveform)
      return {
        magnitude: new Uint8Array(api.memory.buffer, magnitude, size / 2).slice(),
        waveform: new Uint8Array(api.memory.buffer, waveform, size).slice(),
      }
    },
    close() { for (const pointer of [input, magnitude, waveform]) api.wasm_free(pointer) },
  }
}

for (const bin of [1, 17, 37, 100, 255, 511, 900, 1023]) {
  test(`FFT resolves a bin-centred sine at ${bin}`, async () => {
    const subject = await fft()
    try {
      const samples = Float32Array.from({ length: size }, (_, n) => .5 * Math.sin(2 * Math.PI * bin * n / size))
      const { magnitude } = subject.process(samples)
      assert.equal(magnitude.indexOf(Math.max(...magnitude)), bin)
      assert.ok(Math.abs(magnitude[bin] - 128) <= 1)
      assert.ok(magnitude.every((value, k) => k === bin || value <= 1), 'no spurious spectral peaks')
    } finally { subject.close() }
  })
}

test('FFT matches an independent direct DFT for a mixed, non-bin-centred signal with Hann window', async () => {
  const subject = await fft(1)
  try {
    const samples = Float32Array.from({ length: size }, (_, n) =>
      .3 * Math.cos(2 * Math.PI * 17.25 * n / size) + .15 * Math.sin(2 * Math.PI * 230.5 * n / size))
    const { magnitude, waveform } = subject.process(samples)
    for (let k = 0; k < size / 2; k++) {
      let real = 0, imaginary = 0
      for (let n = 0; n < size; n++) {
        const sample = samples[n] * .5 * (1 - Math.cos(2 * Math.PI * n / (size - 1)))
        const phase = 2 * Math.PI * k * n / size
        real += sample * Math.cos(phase)
        imaginary -= sample * Math.sin(phase)
      }
      const expected = Math.min(255, Math.round(Math.hypot(real, imaginary) * 2 / size * 255))
      assert.ok(Math.abs(magnitude[k] - expected) <= 1, `DFT mismatch at bin ${k}: ${magnitude[k]} vs ${expected}`)
    }
    for (let n = 0; n < size; n++) {
      assert.ok(Math.abs(waveform[n] - Math.round((samples[n] * .5 + .5) * 255)) <= 1)
    }
  } finally { subject.close() }
})

test('silence and impulse have their analytic spectra', async () => {
  const subject = await fft()
  try {
    const silence = subject.process(new Float32Array(size))
    assert.ok(silence.magnitude.every(value => value === 0))
    assert.ok(silence.waveform.every(value => value === 128))
    const impulse = new Float32Array(size)
    impulse[731] = 4
    const result = subject.process(impulse)
    assert.ok(result.magnitude.every(value => value === 1), 'impulse spectrum must be uniform')
    assert.equal(result.waveform[731], 255)
  } finally { subject.close() }
})
