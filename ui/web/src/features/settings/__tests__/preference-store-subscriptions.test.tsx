import { act, cleanup, render } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), post: vi.fn() }))

vi.mock('@/shared/api-client/axios-instance', () => ({ AXIOS_INSTANCE: http }))
// Keep the actual stores and sync hooks; no audio hardware is needed for settings.
vi.mock('@/features/player/services/audio-service', () => ({
  audioService: { getProcessor: () => null },
}))

import { useAuthStore } from '@/features/auth/stores/auth-store'
import { useEqBandsStore } from '@/features/equalizer/stores/eq-bands-store'
import { useEqProcessingStore } from '@/features/equalizer/stores/eq-processing-store'
import { registerEqHandlers } from '@/features/equalizer/stores/eq-handlers'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { registerPlayerHandlers } from '@/features/player/stores/player-handlers'
import { useContextPanelStore } from '@/features/layout/stores/context-panel-store'
import { audioPreferenceFixture } from './audio-preference-fixture'
import { PreferenceSyncProvider } from '../hooks/use-preference-bootstrap'

registerEqHandlers()
registerPlayerHandlers()

const initialBands = useEqBandsStore.getState()
const initialProcessing = useEqProcessingStore.getState()
const initialPlayer = usePlayerStore.getState()
const initialLayout = useContextPanelStore.getState()
const audioUrl = '/api/user/audio-preferences/'
const playerUrl = '/api/user/player-preferences/'

function authenticate(uuid: string) {
  useAuthStore.setState({
    isAuthenticated: true,
    user: { uuid, email: `${uuid}@baander.app`, publicId: uuid, name: null, roles: ['ROLE_USER'] },
  })
}

async function mountProvider() {
  const view = render(<PreferenceSyncProvider><span>Content</span></PreferenceSyncProvider>)
  await act(async () => { await Promise.resolve() })
  return view
}

async function flushSave() {
  await act(async () => { await vi.advanceTimersByTimeAsync(500) })
}

const processingChanges: Array<{ field: string; value: unknown; change: () => void }> = [
  { field: 'compressorThreshold', value: -30, change: () => useEqProcessingStore.getState().setCompressorParams({ threshold: -30 }) },
  { field: 'compressorRatio', value: 6, change: () => useEqProcessingStore.getState().setCompressorParams({ ratio: 6 }) },
  { field: 'compressorKnee', value: 20, change: () => useEqProcessingStore.getState().setCompressorParams({ knee: 20 }) },
  { field: 'compressorAttack', value: 8, change: () => useEqProcessingStore.getState().setCompressorParams({ attack: 8 }) },
  { field: 'compressorRelease', value: 400, change: () => useEqProcessingStore.getState().setCompressorParams({ release: 400 }) },
  { field: 'stereoEnabled', value: true, change: () => useEqProcessingStore.getState().setStereoEnabled(true) },
  { field: 'stereoWidth', value: 1.5, change: () => useEqProcessingStore.getState().setStereoWidth(1.5) },
  { field: 'stereoMode', value: 'mid', change: () => useEqProcessingStore.getState().setStereoMode('mid') },
  { field: 'crossfeedEnabled', value: true, change: () => useEqProcessingStore.getState().setCrossfeedEnabled(true) },
  { field: 'crossfeedPreset', value: 'heavy', change: () => useEqProcessingStore.getState().setCrossfeedPreset('heavy') },
  { field: 'loudnessContourEnabled', value: true, change: () => useEqProcessingStore.getState().setLoudnessContourEnabled(true) },
  { field: 'chainOrder', value: [...initialProcessing.chainOrder].reverse(), change: () => useEqProcessingStore.getState().setChainOrder([...initialProcessing.chainOrder].reverse()) },
]

describe('PreferenceSyncProvider store persistence', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers()
    localStorage.clear()
    localStorage.setItem('baander-theme-mood', 'dark')
    useEqBandsStore.setState(initialBands, true)
    useEqProcessingStore.setState(initialProcessing, true)
    usePlayerStore.setState(initialPlayer, true)
    useContextPanelStore.setState(initialLayout, true)
    authenticate('account-a')
    http.get.mockResolvedValue({ data: {} })
    http.put.mockResolvedValue({ data: { data: { version: 1 } } })
  })

  afterEach(() => {
    cleanup()
    vi.useRealTimers()
    useAuthStore.setState({ isAuthenticated: false, user: null })
  })

  it.each(processingChanges)('autosaves an isolated $field control change', async ({ field, value, change }) => {
    await mountProvider()
    act(change)
    await flushSave()
    expect(http.put).toHaveBeenCalledTimes(1)
    expect(http.put).toHaveBeenCalledWith(audioUrl, {
      version: 0,
      payload: expect.objectContaining({ [field]: value }),
    }, expect.objectContaining({ signal: expect.any(AbortSignal) }))
  })

  it.each([
    { field: 'crossfadeEnabled', value: true, change: () => usePlayerStore.getState().setCrossfadeEnabled(true) },
    { field: 'crossfadeDuration', value: 8, change: () => usePlayerStore.getState().setCrossfadeDuration(8) },
  ])('autosaves an isolated $field control change', async ({ field, value, change }) => {
    await mountProvider()
    act(change)
    await flushSave()
    expect(http.put).toHaveBeenCalledTimes(1)
    expect(http.put).toHaveBeenCalledWith(playerUrl, {
      version: 0,
      payload: expect.objectContaining({ [field]: value }),
    }, expect.objectContaining({ signal: expect.any(AbortSignal) }))
  })

  it('ignores playback and local panel state that are absent from the server payload', async () => {
    await mountProvider()
    act(() => {
      usePlayerStore.getState().setIsPlaying(true)
      usePlayerStore.getState().setDuration(180)
      useEqBandsStore.getState().toggleSystemPanel()
    })
    await flushSave()
    expect(http.put).not.toHaveBeenCalled()
  })

  it('applies remote processing and crossfade changes without echoing them to the server', async () => {
    const processing = Object.fromEntries(processingChanges.map(({ field, value }) => [field, value]))
    http.get.mockImplementation(async (url: string) => {
      const payload = url === audioUrl ? {
        ...audioPreferenceFixture(), ...processing,
        compressionEnabled: true, masterGain: -2, normalizationEnabled: true, targetLufs: -18,
        enabled: true, preset: 'ROCK', visualizerMode: 'spectrum',
        bands: initialBands.bands.map(() => ({ gain: 3, q: 1.2 })),
      } : url === playerUrl ? {
        volume: 0.4, shuffle: true, repeat: 'all', muted: true,
        crossfadeEnabled: true, crossfadeDuration: 7,
      } : undefined
      return payload ? { data: { data: { version: 4, payload } } } : { data: {} }
    })
    await mountProvider()
    expect(useEqProcessingStore.getState()).toMatchObject(processing)
    expect(useEqBandsStore.getState().bands[0]).toEqual({ gain: 3, q: 1.2 })
    expect(usePlayerStore.getState()).toMatchObject({
      crossfadeEnabled: true, crossfadeDuration: 7, muted: true,
    })
    await flushSave()
    expect(http.put).not.toHaveBeenCalled()

    act(() => usePlayerStore.getState().setCrossfadeDuration(9))
    await flushSave()
    expect(http.put).toHaveBeenCalledWith(playerUrl, {
      version: 4,
      payload: expect.objectContaining({ crossfadeDuration: 9 }),
    }, expect.anything())
  })

  it('cancels the old account pending save and persists the new account control change', async () => {
    await mountProvider()
    act(() => useEqProcessingStore.getState().setStereoWidth(1.3))
    act(() => authenticate('account-b'))
    await flushSave()
    expect(http.put).not.toHaveBeenCalled()

    act(() => useEqProcessingStore.getState().setStereoWidth(1.8))
    await flushSave()
    expect(http.put).toHaveBeenCalledTimes(1)
    expect(http.put).toHaveBeenCalledWith(audioUrl, {
      version: 0, payload: expect.objectContaining({ stereoWidth: 1.8 }),
    }, expect.anything())

    act(() => useAuthStore.setState({ isAuthenticated: false, user: null }))
    act(() => usePlayerStore.getState().setCrossfadeDuration(10))
    await flushSave()
    expect(http.put).toHaveBeenCalledTimes(1)
  })
})
