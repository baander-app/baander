import { act, renderHook } from '@testing-library/react'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { mediator } from '@/shared/lib/mediator/bus'
import { useEqBandsStore, DEFAULT_Q } from '@/features/equalizer/stores/eq-bands-store'
import { useEqProcessingStore } from '@/features/equalizer/stores/eq-processing-store'
import { registerEqHandlers } from '@/features/equalizer/stores/eq-handlers'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { registerPlayerHandlers } from '@/features/player/stores/player-handlers'
import { useAudioPreferences } from '../hooks/use-audio-preferences'
import { usePlayerPreferences } from '../hooks/use-player-preferences'

vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: { get: vi.fn(), put: vi.fn(), post: vi.fn() },
}))
vi.mock('@/features/player/services/audio-service', () => ({
  audioService: { getProcessor: () => null },
}))

const audioPayload = {
  enabled: true,
  bands: [1, -2],
  bandsV2: [{ gain: 3, q: 1.1 }, { gain: -4, q: 0.9 }],
  preset: 'ROCK',
  visualizerMode: 'spectrogram',
  compressionEnabled: true,
  compressorThreshold: -32,
  compressorRatio: 6,
  compressorKnee: 12,
  compressorAttack: 7,
  compressorRelease: 180,
  masterGain: -3,
  normalizationEnabled: true,
  targetLufs: -18,
  stereoEnabled: true,
  stereoWidth: 1.4,
  stereoMode: 'side',
  crossfeedEnabled: true,
  crossfeedPreset: 'heavy',
  loudnessContourEnabled: true,
  chainOrder: ['stereo', 'eq', 'compressor', 'crossfeed', 'loudness', 'masterGain'],
}

describe('remote preference restores through hooks and mediator handlers', () => {
  beforeAll(() => {
    registerEqHandlers()
    registerPlayerHandlers()
  })

  beforeEach(() => {
    vi.clearAllMocks()
    mediator.clearLog()
    useEqBandsStore.setState(useEqBandsStore.getInitialState(), true)
    useEqProcessingStore.setState(useEqProcessingStore.getInitialState(), true)
    usePlayerStore.setState(usePlayerStore.getInitialState(), true)
  })

  it('restores compressor parameters, chain order, and all other audio preferences', async () => {
    vi.mocked(AXIOS_INSTANCE.get).mockResolvedValueOnce({
      data: { data: { payload: audioPayload, version: 3 } },
    })
    const { result } = renderHook(() => useAudioPreferences())
    await act(async () => { expect(await result.current.fetchFromServer()).toBe(true) })

    const { bands, bandsV2, enabled, preset, visualizerMode, ...processing } = audioPayload
    expect(useEqBandsStore.getState()).toMatchObject({ enabled, preset, visualizerMode, bands: bandsV2 })
    expect(useEqBandsStore.getState().bands.map((band) => band.gain)).not.toEqual(bands)
    expect(useEqProcessingStore.getState()).toMatchObject(processing)
    expect(mediator.getActionLog().at(-1)?.errors).toEqual([])
  })

  it('restores legacy bands with default Q while retaining visualizer fallback', async () => {
    const payload = { ...audioPayload, bandsV2: undefined, visualizerMode: 'retired-mode' }
    vi.mocked(AXIOS_INSTANCE.post).mockResolvedValueOnce({
      data: { data: { payload, version: 4 } },
    })
    const { result } = renderHook(() => useAudioPreferences())
    await act(async () => { expect(await result.current.rollback(2)).toBe(true) })

    expect(useEqBandsStore.getState()).toMatchObject({
      bands: payload.bands.map((gain) => ({ gain, q: DEFAULT_Q })), visualizerMode: 'spectrum',
    })
    expect(useEqProcessingStore.getState().chainOrder).toEqual(payload.chainOrder)
  })

  it.each([true, false])('restores muted=%s with volume scaling and other player preferences', async (muted) => {
    usePlayerStore.setState({ muted: !muted })
    const payload = {
      shuffle: true, repeat: 'one', volume: 0.37, muted, crossfadeEnabled: true, crossfadeDuration: 8,
    }
    vi.mocked(AXIOS_INSTANCE.get).mockResolvedValueOnce({
      data: { data: { payload, version: 5 } },
    })
    const { result } = renderHook(() => usePlayerPreferences())
    await act(async () => { expect(await result.current.fetchFromServer()).toBe(true) })

    expect(usePlayerStore.getState()).toMatchObject({ ...payload, volume: 37 })
    expect(mediator.getActionLog().at(-1)?.errors).toEqual([])
  })
})
