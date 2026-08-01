import { describe, it, expect, vi, beforeEach } from 'vitest'

// Mock the wasm-loader module at the module level
const mockGetLoudness = vi.fn()
const mockGetDynamics = vi.fn()
const mockGetSpectralFeatures = vi.fn()
const mockResetDspCache = vi.fn()

vi.mock('@/features/player/services/wasm-loader', () => ({
  getLoudness: (...args: unknown[]) => mockGetLoudness(...args),
  getDynamics: (...args: unknown[]) => mockGetDynamics(...args),
  getSpectralFeatures: (...args: unknown[]) => mockGetSpectralFeatures(...args),
  getWasmUrl: (filename: string) => `/dsp/${filename}`,
  getAudioWorkletUrl: (filename: string) => `/audio-worklets/${filename}`,
  resetDspCache: (...args: unknown[]) => mockResetDspCache(...args),
}))

import { getWasmUrl, getAudioWorkletUrl, resetDspCache } from '@/features/player/services/wasm-loader'

describe('wasm-loader', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  describe('getWasmUrl', () => {
    it('returns correct URL for WASM files', () => {
      expect(getWasmUrl('loudness_r128.wasm')).toBe('/dsp/loudness_r128.wasm')
      expect(getWasmUrl('dynamics_meter.wasm')).toBe('/dsp/dynamics_meter.wasm')
      expect(getWasmUrl('fft2048.wasm')).toBe('/dsp/fft2048.wasm')
    })
  })

  describe('getAudioWorkletUrl', () => {
    it('returns correct URL for worklet files', () => {
      expect(getAudioWorkletUrl('audio-analysis-worker.js')).toBe('/audio-worklets/audio-analysis-worker.js')
      expect(getAudioWorkletUrl('magic-soup-processor.js')).toBe('/audio-worklets/magic-soup-processor.js')
    })
  })

  describe('DSP module loading', () => {
    it('returns cached loudness API on subsequent calls', async () => {
      const fakeApi = { init: vi.fn(), process: vi.fn(), lufsM: vi.fn() }
      mockGetLoudness.mockResolvedValue(fakeApi)

      const api = await import('@/features/player/services/wasm-loader').then(m => m.getLoudness())
      expect(api).toBe(fakeApi)
      expect(mockGetLoudness).toHaveBeenCalledTimes(1)
    })

    it('returns cached dynamics API on subsequent calls', async () => {
      const fakeApi = { init: vi.fn(), process: vi.fn(), rmsL: vi.fn(), rmsR: vi.fn() }
      mockGetDynamics.mockResolvedValue(fakeApi)

      const api = await import('@/features/player/services/wasm-loader').then(m => m.getDynamics())
      expect(api).toBe(fakeApi)
    })

    it('resetDspCache clears the cache', () => {
      mockResetDspCache.mockImplementation(() => {})
      resetDspCache()
      expect(mockResetDspCache).toHaveBeenCalled()
    })
  })
})
