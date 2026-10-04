import { act, renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({
  bands: {
    enabled: true, bands: [{ gain: 0, q: 0.7 }], preset: 'FLAT', visualizerMode: 'spectrum',
  },
  processing: {
    compressionEnabled: false, compressorThreshold: -20, compressorRatio: 4,
    compressorKnee: 4, compressorAttack: 0.003, compressorRelease: 0.1,
    masterGain: 0, normalizationEnabled: false, targetLufs: -16,
    stereoEnabled: false, stereoWidth: 100, stereoMode: 'normal',
    crossfeedEnabled: false, crossfeedPreset: 'normal', loudnessContourEnabled: false,
    chainOrder: ['eq', 'compressor'],
  },
  get: vi.fn(), put: vi.fn(), post: vi.fn(),
}))

vi.mock('@/features/equalizer/stores/eq-bands-store', () => ({
  DEFAULT_Q: 0.7, useEqBandsStore: { getState: () => mocks.bands },
}))
vi.mock('@/features/equalizer/stores/eq-processing-store', () => ({
  useEqProcessingStore: { getState: () => mocks.processing },
}))
vi.mock('@/shared/lib/mediator/bus', () => ({ mediator: { dispatch: vi.fn() } }))
vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: { get: mocks.get, put: mocks.put, post: mocks.post },
}))

import { useAudioPreferences } from '../hooks/use-audio-preferences'

describe('useAudioPreferences store snapshots', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers()
    mocks.bands.bands = [{ gain: 0, q: 0.7 }]
    mocks.bands.preset = 'FLAT'
    mocks.processing.masterGain = 0
    mocks.processing.chainOrder = ['eq', 'compressor']
    mocks.put.mockResolvedValue({ data: { data: { version: 2 } } })
  })

  afterEach(() => vi.useRealTimers())

  it('pushes the current EQ and processing stores without a caller payload', async () => {
    const { result } = renderHook(() => useAudioPreferences())
    mocks.bands.bands = [{ gain: 6, q: 1.2 }]
    mocks.bands.preset = 'ROCK'
    mocks.processing.masterGain = -3
    act(() => result.current.pushToServer())
    await act(async () => { await vi.advanceTimersByTimeAsync(500) })
    expect(mocks.put).toHaveBeenCalledWith('/api/user/audio-preferences/', {
      version: 0,
      payload: expect.objectContaining({
        bands: [6], bandsV2: [{ gain: 6, q: 1.2 }], preset: 'ROCK', masterGain: -3,
        compressorThreshold: -20, stereoWidth: 100, chainOrder: ['eq', 'compressor'],
      }),
    }, expect.anything())
  })

  it('resolves mine from the latest stores after the conflict arose', async () => {
    mocks.put.mockRejectedValueOnce({ response: { status: 409, data: { error: { details: { currentVersion: 9 } } } } })
    const { result } = renderHook(() => useAudioPreferences())
    act(() => result.current.pushToServer())
    await act(async () => { await vi.advanceTimersByTimeAsync(500) })
    expect(result.current.conflict.type).toBe('conflict')

    mocks.bands.bands = [{ gain: -4, q: 0.9 }]
    mocks.processing.masterGain = 2
    await act(async () => { await result.current.resolveConflict('mine') })
    expect(mocks.put).toHaveBeenLastCalledWith('/api/user/audio-preferences/', {
      version: 9,
      payload: expect.objectContaining({ bands: [-4], bandsV2: [{ gain: -4, q: 0.9 }], masterGain: 2 }),
    }, expect.anything())
    expect(result.current.conflict.type).toBe('none')
  })
})
