import { act, renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), post: vi.fn() }))
vi.mock('@/shared/api-client/axios-instance', () => ({ AXIOS_INSTANCE: http }))
vi.mock('@/features/player/services/audio-service', () => ({ audioService: { getProcessor: () => null } }))

import { useEqBandsStore } from '@/features/equalizer/stores/eq-bands-store'
import { useEqProcessingStore } from '@/features/equalizer/stores/eq-processing-store'
import { useAudioPreferences } from '../hooks/use-audio-preferences'
import { audioPreferenceFixture } from './audio-preference-fixture'

describe('useAudioPreferences store snapshots', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers()
    useEqBandsStore.setState({ ...useEqBandsStore.getInitialState(), bands: useEqBandsStore.getInitialState().bands.map((band) => ({ ...band })) }, true)
    useEqProcessingStore.setState({ ...useEqProcessingStore.getInitialState(), chainOrder: [...useEqProcessingStore.getInitialState().chainOrder] }, true)
    http.put.mockResolvedValue({ data: { data: { version: 2 } } })
  })

  afterEach(() => vi.useRealTimers())

  it('pushes the current EQ and processing stores without a caller payload', async () => {
    const { result } = renderHook(() => useAudioPreferences())
    const { bands, enabled, preset, visualizerMode, ...processing } = audioPreferenceFixture()
    useEqBandsStore.setState({ bands, enabled, preset, visualizerMode })
    useEqProcessingStore.setState(processing)
    act(() => result.current.pushToServer())
    await act(async () => { await vi.advanceTimersByTimeAsync(500) })
    expect(http.put).toHaveBeenCalledWith('/api/user/audio-preferences/', {
      version: 0, payload: audioPreferenceFixture(),
    }, expect.anything())
  })

  it('captures copies of bands and chain at push time', async () => {
    const { result } = renderHook(() => useAudioPreferences())
    const beforeBands = useEqBandsStore.getState().bands.map((band) => ({ ...band }))
    const beforeChain = [...useEqProcessingStore.getState().chainOrder]
    act(() => result.current.pushToServer())
    useEqBandsStore.getState().bands[0].gain = 5
    useEqProcessingStore.getState().chainOrder.reverse()
    await act(async () => { await vi.advanceTimersByTimeAsync(500) })
    expect(http.put).toHaveBeenCalledWith('/api/user/audio-preferences/', {
      version: 0, payload: expect.objectContaining({ bands: beforeBands, chainOrder: beforeChain }),
    }, expect.anything())
  })

  it('resolves mine from the latest stores after the conflict arose', async () => {
    http.put.mockRejectedValueOnce({ response: { status: 409, data: { error: { details: { currentVersion: 9 } } } } })
    const { result } = renderHook(() => useAudioPreferences())
    act(() => result.current.pushToServer())
    await act(async () => { await vi.advanceTimersByTimeAsync(500) })
    expect(result.current.conflict.type).toBe('conflict')

    const bands = audioPreferenceFixture().bands.map((band) => ({ ...band, gain: -4, q: 0.9 }))
    useEqBandsStore.setState({ bands })
    useEqProcessingStore.setState({ masterGain: 2 })
    await act(async () => { await result.current.resolveConflict('mine') })
    expect(http.put).toHaveBeenLastCalledWith('/api/user/audio-preferences/', {
      version: 9, payload: expect.objectContaining({ bands, masterGain: 2 }),
    }, expect.anything())
    expect(result.current.conflict.type).toBe('none')
  })
})
