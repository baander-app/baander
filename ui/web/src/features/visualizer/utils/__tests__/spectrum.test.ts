import { describe, expect, it } from 'vitest'
import { aggregateSpectrum, frequencyToPosition, getFrequencyRange, frameSmoothing } from '../spectrum'

function spectrum(sampleRate = 48000) {
  return { sampleRate, fftSize: 2048, frequencyData: new Uint8Array(1024) }
}

describe('logarithmic spectrum bands', () => {
  it.each([44100, 48000])('preserves a quiet tone between former sampled bins at %i Hz', (sampleRate) => {
    const data = spectrum(sampleRate)
    data.frequencyData[19] = 12
    const bands = aggregateSpectrum(data)
    const band = Math.floor(frequencyToPosition(19 * sampleRate / 2048, sampleRate) * 64)
    expect(bands[band]).toBeCloseTo(12 / 255)
    expect(bands.filter(value => value > 0)).toHaveLength(1)
    expect(bands[0]).toBe(0)
  })

  it('takes peak rather than average and excludes DC and frequencies above the axis', () => {
    const data = spectrum()
    data.frequencyData[0] = 255
    data.frequencyData[900] = 255
    data.frequencyData[500] = 20
    data.frequencyData[501] = 80
    const bands = aggregateSpectrum(data)
    expect(Math.max(...bands)).toBeCloseTo(80 / 255)
  })

  it('caps the shared axis at Nyquist or 20 kHz', () => {
    expect(getFrequencyRange(22050)).toEqual({ min: 20, max: 11025 })
    expect(getFrequencyRange(48000)).toEqual({ min: 20, max: 20000 })
    expect(frequencyToPosition(20, 44100)).toBe(0)
    expect(frequencyToPosition(20000, 44100)).toBe(1)
    expect(frequencyToPosition(11025, 22050)).toBe(1)
  })

  it('produces the same smoothing after equal elapsed time at 30 and 120 fps', () => {
    function smooth(frameCount: number) {
      let value = 1
      for (let i = 0; i < frameCount; i++) value *= 1 - frameSmoothing(0.35, 100 / frameCount)
      return value
    }
    expect(smooth(3)).toBeCloseTo(smooth(12), 12)
  })
})
