import { act, renderHook } from '@testing-library/react'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { mediator } from '@/shared/lib/mediator/bus'
import { useEqBandsStore, EQ_PRESETS } from '@/features/equalizer/stores/eq-bands-store'
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

import { ENGINE_MODES, LEGACY_MODES } from '@/features/visualizer/types'
import { audioPreferenceFixture } from './audio-preference-fixture'

const audioPayload = audioPreferenceFixture()

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

    const { bands, enabled, preset, visualizerMode, ...processing } = audioPayload
    expect(useEqBandsStore.getState()).toMatchObject({ enabled, preset, visualizerMode, bands })
    expect(useEqProcessingStore.getState()).toMatchObject(processing)
    expect(mediator.getActionLog().at(-1)?.errors).toEqual([])
  })

  const invalidPayloads: Array<[string, Record<string, unknown>]> = [
    ['extra field', { ...audioPayload, bandsV2: audioPayload.bands }],
    ['numeric bands', { ...audioPayload, bands: Array(10).fill(1) }],
    ['short bands', { ...audioPayload, bands: audioPayload.bands.slice(0, 9) }],
    ['invalid Q', { ...audioPayload, bands: audioPayload.bands.map((b, i) => i === 9 ? { ...b, q: 0 } : b) }],
    ['invalid band property', { ...audioPayload, bands: audioPayload.bands.map((b, i) => i === 9 ? { ...b, frequency: 100 } : b) }],
    ['invalid width', { ...audioPayload, stereoWidth: 100 }],
    ['nonfinite gain', { ...audioPayload, masterGain: Number.NaN }],
    ['missing field', Object.fromEntries(Object.entries(audioPayload).filter(([key]) => key !== 'compressorRelease'))],
    ['wrong boolean', { ...audioPayload, enabled: 1 }],
    ['unknown preset', { ...audioPayload, preset: 'UNKNOWN' }],
    ['unknown visualizer', { ...audioPayload, visualizerMode: 'retired-mode' }],
    ['duplicate chain', { ...audioPayload, chainOrder: Array(6).fill('eq') }],
    ['partial chain', { ...audioPayload, chainOrder: ['eq', 'compressor'] }],
  ]

  const numericBounds = [
    ['compressorThreshold', -50, 0], ['compressorRatio', 1, 20],
    ['compressorKnee', 0, 40], ['compressorAttack', 0.1, 100],
    ['compressorRelease', 10, 1000], ['masterGain', -12, 12], ['stereoWidth', 0, 2],
  ] as const
  for (const [field, min, max] of numericBounds) {
    for (const value of [min - 0.01, max + 0.01, Infinity, -Infinity, NaN, '1', null]) {
      invalidPayloads.push([`${field}=${String(value)}`, { ...audioPayload, [field]: value }])
    }
  }
  for (const field of ['enabled', 'compressionEnabled', 'normalizationEnabled', 'stereoEnabled', 'crossfeedEnabled', 'loudnessContourEnabled']) {
    invalidPayloads.push([`${field} string`, { ...audioPayload, [field]: 'true' }])
  }
  for (const field of Object.keys(audioPayload)) {
    invalidPayloads.push([`${field} missing`, Object.fromEntries(Object.entries(audioPayload).filter(([key]) => key !== field))])
  }
  for (const [field, value] of [['targetLufs', -20], ['targetLufs', '-18'], ['stereoMode', 'mono'], ['crossfeedPreset', 'custom']]) {
    invalidPayloads.push([`${field} unknown`, { ...audioPayload, [field]: value }])
  }
  for (const band of [null, [], { gain: 0 }, { q: 1 }, { gain: 13, q: 1 }, { gain: -13, q: 1 }, { gain: 0, q: 10.1 }, { gain: Infinity, q: 1 }, { gain: 0, q: NaN }]) {
    invalidPayloads.push(['invalid band', { ...audioPayload, bands: [...audioPayload.bands.slice(0, 9), band] }])
  }
  invalidPayloads.push(['long bands', { ...audioPayload, bands: [...audioPayload.bands, audioPayload.bands[0]] }])
  invalidPayloads.push(['nonarray bands', { ...audioPayload, bands: {} }])
  invalidPayloads.push(['unknown chain module', { ...audioPayload, chainOrder: [...audioPayload.chainOrder.slice(0, 5), 'unknown'] }])

  it.each([...ENGINE_MODES, ...LEGACY_MODES])('restores supported visualizer %s', async (visualizerMode) => {
    const payload = { ...audioPayload, visualizerMode }
    vi.mocked(AXIOS_INSTANCE.post).mockResolvedValueOnce({ data: { data: { payload, version: 4 } } })
    const { result } = renderHook(() => useAudioPreferences())
    await act(async () => { expect(await result.current.rollback(2)).toBe(true) })
    expect(useEqBandsStore.getState().visualizerMode).toBe(visualizerMode)
    expect(result.current.versionRef.current).toBe(4)
  })

  it.each(Object.keys(EQ_PRESETS))('restores supported preset %s', async (preset) => {
    const payload = { ...audioPayload, preset }
    vi.mocked(AXIOS_INSTANCE.get).mockResolvedValueOnce({ data: { data: { payload, version: 3 } } })
    const { result } = renderHook(() => useAudioPreferences())
    await act(async () => { expect(await result.current.fetchFromServer()).toBe(true) })
    expect(useEqBandsStore.getState().preset).toBe(preset)
  })

  it.each(['minimum', 'maximum'])('accepts inclusive %s processing and band bounds', async (limit) => {
    const maximum = limit === 'maximum'
    const payload = {
      ...audioPayload,
      ...Object.fromEntries(numericBounds.map(([field, min, max]) => [field, maximum ? max : min])),
      bands: audioPayload.bands.map(() => ({ gain: maximum ? 12 : -12, q: maximum ? 10 : 0.1 })),
    }
    vi.mocked(AXIOS_INSTANCE.get).mockResolvedValueOnce({ data: { data: { payload, version: 3 } } })
    const { result } = renderHook(() => useAudioPreferences())
    await act(async () => { expect(await result.current.fetchFromServer()).toBe(true) })
    expect(useEqBandsStore.getState().bands).toEqual(payload.bands)
    expect(useEqProcessingStore.getState().compressorAttack).toBe(payload.compressorAttack)
  })

  describe.each(['fetch', 'rollback'] as const)('%s validation', (operation) => {
    it.each(invalidPayloads)('rejects %s atomically', async (_name, payload) => {
      const { result } = renderHook(() => useAudioPreferences())
      vi.mocked(AXIOS_INSTANCE.get).mockResolvedValueOnce({ data: { data: { payload: audioPayload, version: 3 } } })
      await act(async () => { expect(await result.current.fetchFromServer()).toBe(true) })
      const bandsBefore = useEqBandsStore.getState()
      const processingBefore = useEqProcessingStore.getState()
      mediator.clearLog()
      const response = { data: { data: { payload, version: 8 } } }
      if (operation === 'fetch') vi.mocked(AXIOS_INSTANCE.get).mockResolvedValueOnce(response)
      else vi.mocked(AXIOS_INSTANCE.post).mockResolvedValueOnce(response)
      await act(async () => {
        expect(await (operation === 'fetch' ? result.current.fetchFromServer() : result.current.rollback(2))).toBe(false)
      })
      expect(useEqBandsStore.getState()).toBe(bandsBefore)
      expect(useEqProcessingStore.getState()).toBe(processingBefore)
      expect(mediator.getActionLog()).toEqual([])
      expect(result.current.versionRef.current).toBe(3)
    })
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
