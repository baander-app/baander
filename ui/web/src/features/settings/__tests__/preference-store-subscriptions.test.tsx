import { act, cleanup, render, screen } from '@testing-library/react'
import { AxiosError } from 'axios'
import { MemoryRouter } from 'react-router-dom'
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
import { registerContextPanelHandlers } from '@/features/layout/stores/context-panel-handlers'
import { useContextPanelStore } from '@/features/layout/stores/context-panel-store'
import { useContextPanelSelection } from '@/features/layout/hooks/use-context-panel-selection'
import { audioPreferenceFixture } from './audio-preference-fixture'
import { PreferenceSyncProvider } from '../hooks/use-preference-bootstrap'

registerEqHandlers()
registerPlayerHandlers()
registerContextPanelHandlers()

const initialBands = useEqBandsStore.getState()
const initialProcessing = useEqProcessingStore.getState()
const initialPlayer = usePlayerStore.getState()
const initialLayout = useContextPanelStore.getState()
const audioUrl = '/api/user/audio-preferences/'
const playerUrl = '/api/user/player-preferences/'
const layoutUrl = '/api/user/layout-preferences/'

function authenticate(publicId: string) {
  useAuthStore.setState({
    isAuthenticated: true,
    user: { publicId, email: `${publicId}@baander.app`, name: null, roles: ['ROLE_USER'] },
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
      usePlayerStore.getState().runtimeActions.setIsPlaying(true)
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

/** The panel's automatic selection, mounted beside the preference session as the app shell does. */
function PanelSelection() {
  useContextPanelSelection()
  return null
}

async function mountShell() {
  const view = render(
    <MemoryRouter>
      <PreferenceSyncProvider><PanelSelection /></PreferenceSyncProvider>
    </MemoryRouter>,
  )
  await act(async () => { await Promise.resolve() })
  return view
}

describe('the layout preference\'s first read', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers()
    localStorage.clear()
    localStorage.setItem('baander-theme-mood', 'dark')
    usePlayerStore.setState(initialPlayer, true)
    useContextPanelStore.setState(initialLayout, true)
    authenticate('account-a')
    http.get.mockResolvedValue({ data: {} })
    http.put.mockResolvedValue({ data: { data: { version: 7 } } })
  })

  afterEach(() => {
    cleanup()
    vi.useRealTimers()
    useAuthStore.setState({ isAuthenticated: false, user: null })
  })

  function answerLayout(answer: () => Promise<unknown>) {
    http.get.mockImplementation((url: string) => url === layoutUrl ? answer() : Promise.resolve({ data: {} }))
  }

  it.each([
    { found: 'a saved layout', answer: () => Promise.resolve({ data: { data: { payload: { mode: 'compact', activeTab: 'lyrics' }, version: 3 } } }) },
    { found: 'no saved layout', answer: () => Promise.reject(Object.assign(new AxiosError('Not found'), { response: { status: 404 } })) },
    { found: 'an error', answer: () => Promise.reject(Object.assign(new AxiosError('Unavailable'), { response: { status: 503 } })) },
  ])('is reported once it has finished and found $found', async ({ answer }) => {
    answerLayout(answer)
    expect(useContextPanelStore.getState().layoutPreferencesRead).toBe(false)

    await mountProvider()
    await act(async () => { await vi.advanceTimersByTimeAsync(0) })

    expect(useContextPanelStore.getState().layoutPreferencesRead).toBe(true)
  })

  it('is forgotten when the preference session ends, so the next sign-in waits again', async () => {
    const view = await mountProvider()
    await act(async () => { await vi.advanceTimersByTimeAsync(0) })
    expect(useContextPanelStore.getState().layoutPreferencesRead).toBe(true)

    act(() => useAuthStore.setState({ isAuthenticated: false, user: null }))

    expect(useContextPanelStore.getState().layoutPreferencesRead).toBe(false)
    view.unmount()
  })

  it('selects a track that arrives before it without switching or saving the tab, then applies the saved layout', async () => {
    let answer: (value: unknown) => void = () => undefined
    answerLayout(() => new Promise((resolve) => { answer = resolve }))
    await mountShell()

    act(() => usePlayerStore.setState({ currentTrack: { publicId: 'song-1', title: 'Song', available: true } }))
    await flushSave()

    expect(useContextPanelStore.getState()).toMatchObject({
      selectedItem: { type: 'song', publicId: 'song-1' }, activeTab: 'queue', isOpen: true,
    })

    await act(async () => {
      answer({ data: { data: { payload: { mode: 'compact', activeTab: 'lyrics' }, version: 6 } } })
      await vi.advanceTimersByTimeAsync(0)
    })
    await flushSave()

    expect(useContextPanelStore.getState()).toMatchObject({
      selectedItem: { type: 'song', publicId: 'song-1' }, mode: 'compact', activeTab: 'lyrics',
    })
    expect(http.put).not.toHaveBeenCalled()
    expect(screen.queryByText('Settings conflict')).toBeNull()
  })

  it('shows a track that arrives after it on the Details tab and saves that on the version it returned', async () => {
    answerLayout(() => Promise.resolve({ data: { data: { payload: { mode: 'compact', activeTab: 'queue' }, version: 6 } } }))
    await mountShell()
    await act(async () => { await vi.advanceTimersByTimeAsync(0) })

    act(() => usePlayerStore.setState({ currentTrack: { publicId: 'song-1', title: 'Song', available: true } }))
    await flushSave()

    expect(useContextPanelStore.getState()).toMatchObject({
      selectedItem: { type: 'song', publicId: 'song-1' }, mode: 'compact', activeTab: 'details',
    })
    expect(http.put).toHaveBeenCalledExactlyOnceWith(layoutUrl, {
      version: 6,
      payload: { mode: 'compact', activeTab: 'details' },
    }, expect.objectContaining({ signal: expect.any(AbortSignal) }))
  })

  it('still counts a selection the user makes before it as an edit of the layout', async () => {
    answerLayout(() => new Promise(() => undefined))
    await mountShell()

    act(() => useContextPanelStore.getState().setSelectedItem({ type: 'album', publicId: 'album-1' }))
    await flushSave()

    expect(useContextPanelStore.getState().activeTab).toBe('details')
    expect(http.put).toHaveBeenCalledExactlyOnceWith(layoutUrl, {
      version: 0,
      payload: { mode: 'expanded', activeTab: 'details' },
    }, expect.objectContaining({ signal: expect.any(AbortSignal) }))
  })
})

describe('the player preference\'s first read', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers()
    localStorage.clear()
    localStorage.setItem('baander-theme-mood', 'dark')
    usePlayerStore.setState(initialPlayer, true)
    useContextPanelStore.setState(initialLayout, true)
    authenticate('account-a')
    http.get.mockResolvedValue({ data: {} })
    http.put.mockResolvedValue({ data: { data: { version: 6 } } })
  })

  afterEach(() => {
    cleanup()
    vi.useRealTimers()
    useAuthStore.setState({ isAuthenticated: false, user: null })
  })

  const savedPlayer = (volume: number) => ({ data: { data: { version: 5, payload: {
    volume, shuffle: false, repeat: 'off', muted: false, crossfadeEnabled: false, crossfadeDuration: 5,
  } } } })

  it('shows a session volume that arrives before it without saving it', async () => {
    let answer: (value: unknown) => void = () => undefined
    http.get.mockImplementation((url: string) => url === playerUrl
      ? new Promise((resolve) => { answer = resolve })
      : Promise.resolve({ data: {} }))
    await mountProvider()

    act(() => usePlayerStore.getState().runtimeActions.observeListeningVolume(60))
    await flushSave()

    expect(usePlayerStore.getState().volume).toBe(60)
    expect(http.put).not.toHaveBeenCalled()

    await act(async () => {
      answer(savedPlayer(0.4))
      await vi.advanceTimersByTimeAsync(0)
    })
    await flushSave()

    expect(http.put).not.toHaveBeenCalled()
    expect(screen.queryByText('Settings conflict')).toBeNull()
  })

  it('saves a session volume that arrives after it on the version it returned', async () => {
    http.get.mockImplementation((url: string) => Promise.resolve(url === playerUrl ? savedPlayer(0.4) : { data: {} }))
    await mountProvider()
    await act(async () => { await vi.advanceTimersByTimeAsync(0) })

    act(() => usePlayerStore.getState().runtimeActions.observeListeningVolume(60))
    await flushSave()

    expect(http.put).toHaveBeenCalledExactlyOnceWith(playerUrl, {
      version: 5,
      payload: expect.objectContaining({ volume: 0.6 }),
    }, expect.objectContaining({ signal: expect.any(AbortSignal) }))
  })

  it('still saves another player setting changed before it, as an edit', async () => {
    http.get.mockImplementation((url: string) => url === playerUrl ? new Promise(() => undefined) : Promise.resolve({ data: {} }))
    await mountProvider()

    act(() => usePlayerStore.getState().setCrossfadeDuration(9))
    await flushSave()

    expect(http.put).toHaveBeenCalledExactlyOnceWith(playerUrl, {
      version: 0,
      payload: expect.objectContaining({ crossfadeDuration: 9 }),
    }, expect.objectContaining({ signal: expect.any(AbortSignal) }))
  })
})
