import assert from 'node:assert/strict'
import test from 'node:test'
import { loadWasm } from './wasm-fixture.mjs'

const fftSize = 2048

async function fixture() {
  const api = await loadWasm('fft2048')
  const input = api.wasm_malloc(fftSize * 4)
  const magnitude = api.wasm_malloc(fftSize / 2)
  const waveform = api.wasm_malloc(fftSize)
  return {
    compute(signal) {
      new Float32Array(api.memory.buffer, input, fftSize).set(signal)
      api.process_display_spectrum(input, magnitude, waveform)
      return new Uint8Array(api.memory.buffer, magnitude, fftSize / 2).slice()
    },
    free() {
      for (const ptr of [input, magnitude, waveform]) api.wasm_free(ptr)
    },
  }
}

// Independent direct DFT of one bin, using the Web Audio periodic Blackman definition.
function referenceDb(signal, bin) {
  let real = 0
  let imaginary = 0
  for (let i = 0; i < signal.length; i++) {
    const phase = 2 * Math.PI * i / signal.length
    const window = 0.42 - 0.5 * Math.cos(phase) + 0.08 * Math.cos(2 * phase)
    real += signal[i] * window * Math.cos(phase * bin)
    imaginary -= signal[i] * window * Math.sin(phase * bin)
  }
  return 20 * Math.log10(Math.hypot(real, imaginary) / signal.length)
}

for (const sampleRate of [44100, 48000]) {
  test(`display FFT preserves levels and frequencies at ${sampleRate} Hz`, async () => {
    const f = await fixture()
    try {
      for (const bin of [3, 43, 1021]) {
        const levels = []
        for (const amplitude of [1, 0.1, 0.01, 0.001]) {
          const frequency = bin * sampleRate / fftSize
          const signal = Float32Array.from({ length: fftSize }, (_, i) => amplitude * Math.sin(2 * Math.PI * frequency * i / sampleRate))
          const bytes = f.compute(signal)
          const peak = bytes.indexOf(Math.max(...bytes))
          assert.equal(peak, bin)
          const level = -100 + bytes[peak] * 100 / 255
          assert.ok(Math.abs(level - (20 * Math.log10(amplitude * 0.21))) < 0.4, `bin ${bin}, amplitude ${amplitude}: ${level}`)
          assert.ok(Math.abs(level - referenceDb(signal, bin)) < 0.4)
          levels.push(level)
        }
        for (let i = 1; i < levels.length; i++) assert.ok(Math.abs(levels[i - 1] - levels[i] - 20) < 0.4)
      }
    } finally {
      f.free()
    }
  })

  test(`off-bin display FFT agrees with direct DFT at ${sampleRate} Hz`, async () => {
    const f = await fixture()
    try {
      for (const bin of [3.4, 43.4, 1020.6]) {
        const frequency = bin * sampleRate / fftSize
        const signal = Float32Array.from({ length: fftSize }, (_, i) => 0.01 * Math.sin(2 * Math.PI * frequency * i / sampleRate))
        const bytes = f.compute(signal)
        const peak = bytes.indexOf(Math.max(...bytes))
        assert.ok(Math.abs(peak - bin) < 0.6)
        assert.ok(Math.abs(-100 + bytes[peak] * 100 / 255 - referenceDb(signal, peak)) < 0.4)
      }
    } finally {
      f.free()
    }
  })
}

test('display silence maps to the -100 dB floor', async () => {
  const f = await fixture()
  try {
    assert.ok(f.compute(new Float32Array(fftSize)).every(value => value === 0))
  } finally {
    f.free()
  }
})
