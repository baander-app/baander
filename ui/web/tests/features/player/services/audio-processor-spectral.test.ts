import { describe, expect, it, vi } from 'vitest'
import { AudioProcessor } from '@/features/player/services/audio-processor'
import type { SpectralFeaturesApi } from '@/features/player/services/wasm-types'

function fixture() {
  const api: SpectralFeaturesApi = {
    memory: new WebAssembly.Memory({ initial: 1 }),
    malloc: vi.fn(() => 128),
    free: vi.fn(),
    init: vi.fn(),
    computeFromMag: vi.fn(),
    getCentroidHz: vi.fn(() => 1000),
    getRolloffHz: vi.fn(() => 2000),
    getFlux: vi.fn(() => 0),
    getFlatness: vi.fn(() => 0),
    getPeakIndex: vi.fn(() => 100),
    getBandEnergies: vi.fn(),
  }
  const processor = Object.assign(Object.create(AudioProcessor.prototype), {
    spectralAPI: api,
    dspReady: true,
    FFT_SIZE: 2048,
    audioContext: { sampleRate: 48000 },
  }) as { computeSpectralFeatures(data: Uint8Array): void; peakFrequency: number }
  return { api, processor }
}

describe('spectral input ownership', () => {
  it('rejects reduced or oversized spectra before calling native code', () => {
    const { api, processor } = fixture()
    for (const size of [0, 128, 1023, 1025, 2048]) processor.computeSpectralFeatures(new Uint8Array(size))
    expect(api.malloc).not.toHaveBeenCalled()
    expect(api.computeFromMag).not.toHaveBeenCalled()
  })

  it('copies a complete spectrum and frees its native allocation', () => {
    const { api, processor } = fixture()
    const data = Uint8Array.from({ length: 1024 }, (_, i) => i % 256)
    processor.computeSpectralFeatures(data)
    expect(new Uint8Array(api.memory.buffer, 128, 1024)).toEqual(data)
    expect(api.computeFromMag).toHaveBeenCalledWith(128)
    expect(api.free).toHaveBeenCalledWith(128)
    expect(processor.peakFrequency).toBe(100 * 48000 / 2048)
  })

  it('frees native input even when a getter throws', () => {
    const { api, processor } = fixture()
    vi.mocked(api.getCentroidHz).mockImplementation(() => { throw new Error('native getter failed') })
    const warning = vi.spyOn(console, 'warn').mockImplementation(() => {})
    try {
      processor.computeSpectralFeatures(new Uint8Array(1024))
      expect(api.free).toHaveBeenCalledExactlyOnceWith(128)
    } finally {
      warning.mockRestore()
    }
  })
})
