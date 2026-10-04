import { act, renderHook } from '@testing-library/react'
import { registerEqHandlers } from '../eq-handlers'
import { useAudioPreferences } from '@/features/settings/hooks/use-audio-preferences'
import { audioPreferenceFixture } from '@/features/settings/__tests__/audio-preference-fixture'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useEqBandsStore } from '../eq-bands-store'
import { useEqProcessingStore } from '../eq-processing-store'
import { reapplyAllEqState } from '../eq-reapply'

const audio = vi.hoisted(() => ({
  get: vi.fn(), post: vi.fn(),
  available: true,
  processor: {
    updateEQBands: vi.fn(), setCompression: vi.fn(), setCompressorParams: vi.fn(),
    setMasterGain: vi.fn(), setStereoWidth: vi.fn(), setCrossfeed: vi.fn(),
    setLoudnessContour: vi.fn(), applyVolumeNormalization: vi.fn(), rebuildChain: vi.fn(),
  },
}))
vi.mock('@/features/player/services/audio-service', () => ({
  audioService: { getProcessor: () => audio.available ? audio.processor : null },
}))

vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: { get: audio.get, post: audio.post },
}))
registerEqHandlers()

describe('processor state reapplication', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    audio.available = true
    useEqBandsStore.setState(useEqBandsStore.getInitialState(), true)
    useEqProcessingStore.setState(useEqProcessingStore.getInitialState(), true)
  })

  it.each([
    { stereoEnabled: true, stereoMode: 'mid' as const, expected: 0 },
    { stereoEnabled: true, stereoMode: 'side' as const, expected: 2 },
    { stereoEnabled: true, stereoMode: 'normal' as const, expected: 1.4 },
    { stereoEnabled: true, stereoMode: 'normal' as const, expected: 2, stereoWidth: 2 },
    { stereoEnabled: false, stereoMode: 'side' as const, expected: 1 },
  ])('restores $stereoMode with stereo enabled=$stereoEnabled', ({ expected, stereoWidth = 1.4, ...state }) => {
    useEqProcessingStore.setState({ ...state, stereoWidth })
    reapplyAllEqState()
    expect(audio.processor.setStereoWidth).toHaveBeenCalledWith(expected, state.stereoEnabled ? state.stereoMode : 'normal')
  })

  it('rebuilds the saved processing order after applying compressor state', () => {
    const chainOrder = ['stereo', 'crossfeed', 'eq', 'compressor', 'loudness', 'masterGain'] as const
    useEqProcessingStore.setState({ chainOrder: [...chainOrder], compressionEnabled: true })
    reapplyAllEqState()
    expect(audio.processor.rebuildChain).toHaveBeenCalledWith([...chainOrder])
    expect(audio.processor.rebuildChain.mock.invocationCallOrder[0]).toBeGreaterThan(audio.processor.setCompression.mock.invocationCallOrder[0])
  })

  it('waits for processor availability without changing stored preferences', () => {
    audio.available = false
    const state = useEqProcessingStore.getState()
    reapplyAllEqState()
    expect(audio.processor.setCompression).not.toHaveBeenCalled()
    expect(useEqProcessingStore.getState()).toBe(state)
    audio.available = true
    reapplyAllEqState()
    expect(audio.processor.rebuildChain).toHaveBeenCalledWith(state.chainOrder)
  })

  it.each(['fetch', 'rollback'] as const)('applies a remote %s to an existing processor after both stores are updated', async (operation) => {
    const payload = audioPreferenceFixture()
    audio.get.mockResolvedValue({ data: { data: { payload, version: 5 } } })
    audio.post.mockResolvedValue({ data: { data: { payload, version: 5 } } })
    const { result } = renderHook(() => useAudioPreferences())
    await act(async () => {
      const success = operation === 'fetch' ? await result.current.fetchFromServer() : await result.current.rollback(2)
      expect(success).toBe(true)
    })
    expect(audio.processor.updateEQBands).toHaveBeenCalledWith(payload.bands)
    expect(audio.processor.setStereoWidth).toHaveBeenCalledWith(2, 'side')
    expect(audio.processor.setCompressorParams).toHaveBeenCalledWith({ threshold: -32, ratio: 6, knee: 12, attack: 7, release: 180 })
    expect(audio.processor.rebuildChain).toHaveBeenCalledWith(payload.chainOrder)
    expect(result.current.versionRef.current).toBe(5)
  })

})
