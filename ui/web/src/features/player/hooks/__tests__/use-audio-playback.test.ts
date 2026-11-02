import { createElement } from 'react'
import type { PlaybackCommandResource, PlaybackSnapshotResource } from '@/shared/api-client/gen/endpoints'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { getCurrentTime, subscribe as subscribeToTime, updateTime } from '../../stores/player-time-tracker'
import { noteStreamRetryAfter } from '../../services/stream-retry-after'
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { renderHook as renderBaseHook, act, cleanup, render, screen, fireEvent, waitFor } from '@testing-library/react'
import { reapplyAllEqState } from '@/features/equalizer/stores/eq-reapply'
import { audioService } from '@/features/player/services/audio-service'
import { useRadioStore } from '@/features/radio/stores/radio-store'
import { activityService } from '@/features/player/services/activity-service'
import { toast } from 'sonner'
import { PLAYBACK_CONNECTION_KEY, PLAYBACK_SESSION_KEY } from '@/features/session/services/playback-session-coordinator'
import { PlayerProgress } from '@/features/layout/components/PlayerProgress'
import { ProgressBar } from '../../components/ProgressBar'
import { getPlaybackAdapter, registerPlaybackIntentHandler } from '@/features/session/services/playback-execution'

const renderHook: typeof renderBaseHook = (callback, options) => {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, queryFn: async () => null } } })
  return renderBaseHook(callback, { ...options, wrapper: ({ children }) => createElement(QueryClientProvider, { client }, children) })
}

// --- Mocks must be hoisted before the hook imports them ---------------------

// Fake AudioProcessor: tracks active source and records swap/crossfade calls.
// getActiveSource() starts at 'A' (matches real AudioProcessor), and the swap
// methods flip it so subsequent preloads target the newly-inactive element.
const processorMock = vi.hoisted(() => {
  let active: 'A' | 'B' = 'A'
  return {
    instance: {
      isActive: false,
      passive: false,
      setVolume: vi.fn(),
      setMuted: vi.fn(),
      getActiveSource: vi.fn(() => active),
      instantSwap: vi.fn(() => { active = active === 'A' ? 'B' : 'A' }),
      crossfadeToInactive: vi.fn((duration: number) => {
        void duration
        active = active === 'A' ? 'B' : 'A'
      }),
      connectAudioElement: vi.fn(() => Promise.resolve()),
      connectDualAudioElements: vi.fn(() => Promise.resolve()),
      setPlayingState: vi.fn(),
      resumeContextIfNeeded: vi.fn(() => Promise.resolve()),
      cancelCrossfade: vi.fn(),
      resetProgramme: vi.fn(),
      destroy: vi.fn(),
    },
    __resetActive: () => { active = 'A' },
  }
})

vi.mock('@/features/player/services/audio-service', () => ({
  audioService: {
    initialize: vi.fn(),
    connectDualAudioElements: vi.fn(),
    resumeContextIfNeeded: vi.fn(() => Promise.resolve()),
    setPlayingState: vi.fn(),
    fenceOutput: vi.fn(),
    rearmOutput: vi.fn(),
    getProcessor: vi.fn(() => processorMock.instance),
    destroy: vi.fn(),
  },
}))

vi.mock('@/features/player/services/activity-service', () => ({
  activityService: { recordPlay: vi.fn(() => Promise.resolve()) },
}))

vi.mock('sonner', () => ({
  toast: { error: vi.fn(), info: vi.fn() },
}))

// The player asks the server's liveness endpoint whether a failed stream means an outage.
const liveness = vi.hoisted(() => ({ get: vi.fn(), catalog: vi.fn() }))
vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: { get: liveness.get },
  customInstance: liveness.catalog,
  apiOrigin: () => 'https://baander.app',
}))

// EQ reapply is dynamically imported inside the hook; stub it to a no-op.
vi.mock('@/features/equalizer/stores/eq-reapply', () => ({
  reapplyAllEqState: vi.fn(),
}))

// Build a minimal HTMLAudioElement that supports addEventListener/dispatchEvent
// (jsdom's HTMLAudioElement does, but `new Audio()` returns a real element whose
// src/preload/etc. work; we wrap it so we can read src and fire events).
type MockAudioElement = { -readonly [Key in keyof HTMLAudioElement]: HTMLAudioElement[Key] }

function createStubAudioElement(): MockAudioElement {
  const listeners = new Map<string, Set<EventListenerOrEventListenerObject>>()
  const el = {
    src: '',
    readyState: 3,
    preload: 'auto' as string,
    currentTime: 0,
    duration: NaN,
    volume: 1,
    muted: false,
    paused: true,
    ended: false,
    error: null,
    seeking: false,
    crossOrigin: '',
    disableRemotePlayback: false,
    canPlayType: vi.fn(() => ''),
    play: vi.fn(() => Promise.resolve()),
    pause: vi.fn(),
    load: vi.fn(),
    addEventListener: vi.fn(
      (type: string, listener: EventListenerOrEventListenerObject) => {
        if (!listeners.has(type)) listeners.set(type, new Set())
        listeners.get(type)!.add(listener)
      },
    ),
    removeEventListener: vi.fn(
      (type: string, listener: EventListenerOrEventListenerObject) => {
        listeners.get(type)?.delete(listener)
      },
    ),
    dispatchEvent: vi.fn((event: Event) => {
      listeners.get(event.type)?.forEach((l) => {
        if ('handleEvent' in l) l.handleEvent(event)
        else l(event)
      })
      return true
    }),
  }
  let position = 0
  Object.defineProperty(el, 'currentTime', {
    configurable: true,
    get: () => position,
    set: (value: number) => {
      position = value
      queueMicrotask(() => el.dispatchEvent(new Event('seeked')))
    },
  })
  return el as unknown as MockAudioElement
}

// Capture the audio elements the hook creates via `new Audio()` so tests can
// drive events on them. A and B in creation order.
let capturedAudioElements: MockAudioElement[] = []
let RealAudio: typeof Audio | undefined
let unregisterCoordinator: (() => void) | undefined

import { useAudioPlayback } from '../use-audio-playback'
import { usePlayerStore, type Track, type PlayerState } from '@/features/player/stores/player-store'

// --- Helpers ----------------------------------------------------------------

function track(publicId: string): Track {
  return { publicId, available: true, title: publicId, artistName: 'A' }
}

function resetStore(overrides: Partial<PlayerState> = {}) {
  updateTime(0)
  usePlayerStore.setState({
    playbackExecutionActive: true,
    queue: [],
    currentIndex: -1,
    currentTrack: null,
    isPlaying: false,
    duration: 0,
    shuffle: false,
    repeat: 'off',
    shuffleBag: [],
    crossfadeEnabled: false,
    crossfadeDuration: 5.0,
    volume: 75,
    muted: false,
    audioElement: null,
  } as Partial<PlayerState>)
  if (Object.keys(overrides).length) usePlayerStore.setState(overrides)
}

function seedQueue(current = 0, n = 3): Track[] {
  const q = [track('t0'), track('t1'), track('t2')].slice(0, n)
  usePlayerStore.setState({
    queue: q,
    currentIndex: current,
    currentTrack: q[current] ?? null,
  })
  return q
}

/** Fire a timeupdate on the active (A) element. Reads threshold from the code:
 *  gapless = 6s, crossfade = crossfadeDuration + 3 (PRELOAD_BUFFER). */
function timeUpdate(el: MockAudioElement, currentTime: number, duration: number) {
  ;el.currentTime = currentTime
  ;el.duration = duration
  el.dispatchEvent(new Event('timeupdate'))
}

/** Genuine native notifications observe the matching live media state. */
function playMedia(audio: MockAudioElement) {
  audio.paused = false
  audio.ended = false
  audio.dispatchEvent(new Event('play'))
}

function endMedia(audio: MockAudioElement) {
  audio.paused = true
  audio.ended = true
  audio.dispatchEvent(new Event('ended'))
}

function errorMedia(audio: MockAudioElement, code = 3) {
  audio.error = {
    code, message: code === 4 ? 'Media source not supported' : 'Media decode failed',
    MEDIA_ERR_ABORTED: 1, MEDIA_ERR_NETWORK: 2, MEDIA_ERR_DECODE: 3, MEDIA_ERR_SRC_NOT_SUPPORTED: 4,
  }
  audio.dispatchEvent(new Event('error'))
}

// --- Suite ------------------------------------------------------------------

describe('useAudioPlayback', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    processorMock.instance.isActive = false
    processorMock.instance.passive = false
    vi.mocked(audioService.getProcessor).mockReturnValue(processorMock.instance as unknown as NonNullable<ReturnType<typeof audioService.getProcessor>>)
    vi.mocked(audioService.connectDualAudioElements).mockImplementation(async () => {
      processorMock.instance.isActive = true
    })
    vi.mocked(audioService.resumeContextIfNeeded).mockResolvedValue(undefined)
    liveness.catalog.mockReset().mockResolvedValue({ data: [] })
    liveness.get.mockReset()
    liveness.get.mockResolvedValue({ status: 200 })
    // vi.clearAllMocks wipes mock implementations; restore the swap state machine.
    // getActiveSource returns the current `active`, instantSwap/crossfade flip it
    // (mirrors the real AudioProcessor behavior).
    let active: 'A' | 'B' = 'A'
    processorMock.instance.getActiveSource.mockImplementation(() => active)
    processorMock.instance.instantSwap.mockImplementation(() => {
      active = active === 'A' ? 'B' : 'A'
    })
    processorMock.instance.crossfadeToInactive.mockImplementation(() => {
      active = active === 'A' ? 'B' : 'A'
    })

    resetStore()
    useRadioStore.getState().stopRadio()
    useRadioStore.getState().setAudioElement(null)
    unregisterCoordinator = registerPlaybackIntentHandler(async intent => {
      const state = usePlayerStore.getState()
      if (intent.action === 'load') {
        await getPlaybackAdapter('song')!.execute({ id: 'command', attemptId: 'attempt', revision: 1, incarnationId: 'browser', epoch: 1,
          type: 'load', expiresAt: '2099-01-01T00:00:00Z', payload: { media: intent.media,
            queue: intent.queue ?? state.queue.map(track => track.publicId), currentTrackIndex: intent.currentTrackIndex ?? 0,
            position: intent.position ?? 0, desiredState: intent.desiredState ?? 'playing' } }, new AbortController().signal)
      } else if (intent.action === 'next') {
        const index = state.repeat === 'one' ? state.currentIndex : state.currentIndex + 1
        const next = state.queue[index]
        if (!next) { state.runtimeActions.setIsPlaying(false); return }
        const adapter = getPlaybackAdapter('song')!
        await adapter.execute({ id: 'command', attemptId: 'attempt', revision: 1, incarnationId: 'browser', epoch: 1,
          type: 'load', expiresAt: '2099-01-01T00:00:00Z', payload: { media: { kind: 'song', publicId: next.publicId },
            queue: state.queue.map(track => track.publicId), currentTrackIndex: index, position: 0, desiredState: 'playing' } }, new AbortController().signal)
      }
    })
    capturedAudioElements = []
    RealAudio = globalThis.Audio
    globalThis.Audio = class {
      constructor() {
        const stub = createStubAudioElement()
        capturedAudioElements.push(stub)
        return stub
      }
    } as unknown as typeof Audio
  })

  afterEach(() => {
    cleanup()
    unregisterCoordinator?.()
    vi.useRealTimers()
    if (RealAudio) globalThis.Audio = RealAudio
  })

  function renderController(overrides: Partial<PlaybackSnapshotResource> = {}) {
    usePlayerStore.setState({ playbackExecutionActive: false })
    const snapshot: PlaybackSnapshotResource = { schemaVersion: 1, publicId: 'session', revision: 1,
      media: { kind: 'song', publicId: 'remote-song' }, queue: ['remote-song'], currentTrackIndex: 0,
      position: 12, desiredState: 'playing', observedState: 'playing', playbackState: 'playing',
      activeIncarnationId: 'remote', activeDestinationId: 'remote-device', selectedDestinationId: 'remote-device',
      transfer: null, destinations: [], commands: [], ...overrides }
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    client.setQueryData(PLAYBACK_SESSION_KEY, snapshot)
    client.setQueryData(PLAYBACK_CONNECTION_KEY, { endpoint: { incarnationId: 'local', destinationId: 'local-device', epoch: 1 }, connected: true, error: null })
    const view = renderBaseHook(() => useAudioPlayback(), { wrapper: ({ children }) => createElement(QueryClientProvider, { client }, children) })
    return { ...view, client, snapshot }
  }

  it('hydrates remote catalogue length into seek duration without playing local media', async () => {
    usePlayerStore.setState({ duration: 999 })
    let resolve!: (value: unknown) => void
    liveness.catalog.mockImplementationOnce(() => new Promise(done => { resolve = done }))
    renderController()
    expect(usePlayerStore.getState().duration).toBe(0)
    await act(async () => {
      resolve({ data: [{ publicId: 'remote-song', title: 'Remote', available: true, length: 240, albumId: 'album' }] })
    })
    expect(usePlayerStore.getState().currentTrack?.duration).toBe(240)
    expect(usePlayerStore.getState().duration).toBe(240)
    expect(capturedAudioElements.every(audio => vi.mocked(audio.play).mock.calls.length === 0)).toBe(true)
    const request = vi.fn(async () => {})
    const stop = registerPlaybackIntentHandler(request)
    render(createElement(ProgressBar))
    const slider = screen.getByRole('slider', { name: 'Seek' })
    expect(slider).toHaveAttribute('aria-valuemax', '240')
    fireEvent.keyDown(slider, { key: 'End' })
    expect(request).toHaveBeenCalledExactlyOnceWith({ action: 'seek', position: 240 })
    stop()
  })

  it('uses the remote duration for a footer seek click', () => {
    const selected = { ...track('remote-song'), duration: 240 }
    usePlayerStore.setState({ queue: [selected], currentTrack: selected, currentIndex: 0, duration: 999 })
    renderController()
    const request = vi.fn(async () => {})
    const stop = registerPlaybackIntentHandler(request)
    render(createElement(PlayerProgress))
    const slider = screen.getByRole('slider', { name: 'Seek' })
    vi.spyOn(slider, 'getBoundingClientRect').mockReturnValue({ left: 0, width: 300 } as DOMRect)
    fireEvent.click(slider, { clientX: 100 })
    expect(request).toHaveBeenCalledExactlyOnceWith({ action: 'seek', position: 80 })
    expect(capturedAudioElements.every(audio => vi.mocked(audio.play).mock.calls.length === 0)).toBe(true)
    stop()
  })

  it('advances only the connected remote playing clock and clamps it to catalogue duration', async () => {
    vi.useFakeTimers()
    const selected = { ...track('remote-song'), duration: 14 }
    usePlayerStore.setState({ queue: [selected], currentTrack: selected, currentIndex: 0 })
    const { client, snapshot } = renderController()
    expect(usePlayerStore.getState().duration).toBe(14)
    act(() => { vi.advanceTimersByTime(1500) })
    expect(getCurrentTime()).toBe(13.5)
    act(() => { vi.advanceTimersByTime(2000) })
    expect(getCurrentTime()).toBe(14)
    await act(async () => { client.setQueryData(PLAYBACK_SESSION_KEY, { ...snapshot, observedState: 'paused', position: 8 }) })
    act(() => { vi.advanceTimersByTime(2000) })
    expect(getCurrentTime()).toBe(8)
    await act(async () => {
      client.setQueryData(PLAYBACK_SESSION_KEY, { ...snapshot, position: 9 })
      client.setQueryData(PLAYBACK_CONNECTION_KEY, { endpoint: null, connected: false, error: 'Disconnected' })
    })
    act(() => { vi.advanceTimersByTime(2000) })
    expect(getCurrentTime()).toBe(9)
    expect(capturedAudioElements.every(audio => vi.mocked(audio.play).mock.calls.length === 0)).toBe(true)
  })

  it('does not apply late catalogue hydration after the remote selection changes', async () => {
    let resolveOld!: (value: unknown) => void
    liveness.catalog.mockImplementationOnce(() => new Promise(done => { resolveOld = done }))
      .mockResolvedValueOnce({ data: [{ publicId: 'next-song', title: 'Next', available: true, length: 180 }] })
    const { client, snapshot } = renderController()
    await act(async () => {
      client.setQueryData(PLAYBACK_SESSION_KEY, { ...snapshot, revision: 2, media: { kind: 'song', publicId: 'next-song' }, queue: ['next-song'] })
    })
    await act(async () => { resolveOld({ data: [{ publicId: 'remote-song', title: 'Old', available: true, length: 240 }] }) })
    await waitFor(() => expect(usePlayerStore.getState().currentTrack?.publicId).toBe('next-song'))
    expect(usePlayerStore.getState().duration).toBe(180)
  })

  const loadCommand = (): PlaybackCommandResource => ({
    id: 'load-command', attemptId: 'attempt', revision: 1, incarnationId: 'browser', epoch: 1,
    type: 'load', expiresAt: '2099-01-01T00:00:00Z',
    payload: { media: { kind: 'song', publicId: 't0' }, queue: ['t0'], currentTrackIndex: 0,
      position: 0, desiredState: 'playing' },
  })

  it('prepares actual source readiness without playing it', async () => {
    seedQueue(0, 1)
    renderHook(() => useAudioPlayback())
    const [audio] = capturedAudioElements
    audio.readyState = 0
    let prepared = false
    const task = getPlaybackAdapter('song')!.prepare(loadCommand().payload, new AbortController().signal).then(() => { prepared = true })
    await Promise.resolve()
    expect(audio.src).toContain('id=t0')
    expect(prepared).toBe(false)
    expect(audio.play).not.toHaveBeenCalled()
    audio.dispatchEvent(new Event('canplay'))
    await task
    expect(prepared).toBe(true)
    expect(audio.play).not.toHaveBeenCalled()
  })

  it('acknowledges playback only after native play settles', async () => {
    seedQueue(0, 1)
    renderHook(() => useAudioPlayback())
    const [audio] = capturedAudioElements
    let finish!: () => void
    vi.mocked(audio.play).mockImplementation(() => new Promise<void>(resolve => {
      finish = () => { audio.paused = false; resolve() }
    }))
    let acknowledged = false
    const task = getPlaybackAdapter('song')!.execute(loadCommand(), new AbortController().signal).then(result => {
      acknowledged = true
      return result
    })
    await act(async () => { await Promise.resolve(); await Promise.resolve(); await Promise.resolve() })
    expect(acknowledged).toBe(false)
    await act(async () => { finish(); await task })
    expect(acknowledged).toBe(true)
    expect((await task).observedState).toBe('playing')
  })

  it('propagates native autoplay refusal without acknowledging success', async () => {
    seedQueue(0, 1)
    renderHook(() => useAudioPlayback())
    const [audio] = capturedAudioElements
    const refusal = new DOMException('A user gesture is required.', 'NotAllowedError')
    vi.mocked(audio.play).mockRejectedValue(refusal)
    await expect(getPlaybackAdapter('song')!.execute(loadCommand(), new AbortController().signal)).rejects.toBe(refusal)
    expect(usePlayerStore.getState().isPlaying).toBe(false)
    expect(usePlayerStore.getState().playbackRecovery).toBe('tap-to-resume')
  })

  it('quiesces both owned elements at the actual cursor and fences a late play', async () => {
    seedQueue(0, 1)
    renderHook(() => useAudioPlayback())
    const [audio, incoming] = capturedAudioElements
    audio.src = '/api/stream/track?id=t0'
    audio.currentTime = 24
    let finish!: () => void
    for (const element of [audio, incoming]) vi.mocked(element.pause).mockImplementation(() => { element.paused = true })
    vi.mocked(audio.play).mockImplementation(() => new Promise<void>(resolve => {
      finish = () => { audio.paused = false; resolve() }
    }))
    const adapter = getPlaybackAdapter('song')!
    const task = adapter.execute({ ...loadCommand(), type: 'play' }, new AbortController().signal)
    const rejected = expect(task).rejects.toMatchObject({ name: 'AbortError' })
    await act(async () => { await Promise.resolve(); await Promise.resolve() })
    const cursor = await adapter.quiesce(new AbortController().signal)
    expect(cursor.position).toBe(24)
    expect(audio.pause).toHaveBeenCalled()
    expect(incoming.pause).toHaveBeenCalled()
    finish()
    await rejected
    expect(audio.paused).toBe(true)
    expect(usePlayerStore.getState().isPlaying).toBe(false)
  })

  it('waits for seeked before acknowledging the actual cursor', async () => {
    seedQueue(0, 1)
    renderHook(() => useAudioPlayback())
    const [audio] = capturedAudioElements
    audio.src = '/api/stream/track?id=t0'
    Object.defineProperty(audio, 'currentTime', { configurable: true, writable: true, value: 0 })
    const command = loadCommand()
    command.type = 'seek'
    command.payload.position = 12
    let acknowledged = false
    const task = getPlaybackAdapter('song')!.execute(command, new AbortController().signal).then(result => {
      acknowledged = true
      return result
    })
    await Promise.resolve()
    expect(acknowledged).toBe(false)
    audio.dispatchEvent(new Event('seeked'))
    expect((await task).position).toBe(12)
  })

  it('acknowledges stop while retaining the source and recovery cursor', async () => {
    seedQueue(0, 1)
    renderHook(() => useAudioPlayback())
    const [audio] = capturedAudioElements
    audio.src = '/api/stream/track?id=t0'
    audio.currentTime = 17
    const result = await getPlaybackAdapter('song')!.execute({ ...loadCommand(), type: 'stop' }, new AbortController().signal)
    expect(result.observedState).toBe('stopped')
    expect(result.position).toBe(17)
    expect(audio.src).toContain('id=t0')
  })

  it('applies queue-only commands without touching playback or its cursor', async () => {
    seedQueue(0, 2)
    renderHook(() => useAudioPlayback())
    const [audio] = capturedAudioElements
    audio.src = '/api/stream/track?id=t0'
    audio.currentTime = 17
    vi.mocked(audio.play).mockClear()
    vi.mocked(audio.pause).mockClear()
    vi.mocked(audio.load).mockClear()
    const command = loadCommand()
    command.type = 'queue'
    command.payload.queue = ['t1', 't0']
    command.payload.currentTrackIndex = 1
    await getPlaybackAdapter('song')!.execute(command, new AbortController().signal)
    expect(usePlayerStore.getState().currentIndex).toBe(1)
    expect(audio.currentTime).toBe(17)
    expect(audio.play).not.toHaveBeenCalled()
    expect(audio.pause).not.toHaveBeenCalled()
    expect(audio.load).not.toHaveBeenCalled()
  })

  it('does not gain execution ownership from a volume command', async () => {
    seedQueue(0, 2)
    usePlayerStore.setState({ playbackExecutionActive: false })
    renderHook(() => useAudioPlayback())
    const [audio] = capturedAudioElements
    audio.src = '/api/stream/track?id=t0'
    const command = loadCommand()
    command.type = 'volume'
    command.payload.volume = 0.5
    await getPlaybackAdapter('song')!.execute(command, new AbortController().signal)
    await act(async () => { endMedia(audio) })
    expect(usePlayerStore.getState().currentIndex).toBe(0)
    expect(audio.play).not.toHaveBeenCalled()
  })

  it('rejects aborted native play immediately and silences its late completion', async () => {
    seedQueue(0, 1)
    renderHook(() => useAudioPlayback())
    const [audio] = capturedAudioElements
    audio.src = '/api/stream/track?id=t0'
    vi.mocked(audio.pause).mockImplementation(() => { audio.paused = true })
    let finish!: () => void
    vi.mocked(audio.play).mockImplementation(() => new Promise<void>(resolve => {
      finish = () => { audio.paused = false; resolve() }
    }))
    const controller = new AbortController()
    const task = getPlaybackAdapter('song')!.execute({ ...loadCommand(), type: 'play' }, controller.signal)
    const rejected = expect(task).rejects.toMatchObject({ name: 'AbortError' })
    await act(async () => { await Promise.resolve(); await Promise.resolve() })
    controller.abort()
    await rejected
    finish()
    await act(async () => { await Promise.resolve() })
    expect(audio.paused).toBe(true)
    expect(usePlayerStore.getState().isPlaying).toBe(false)
  })

  it('keeps the active source untouched when target preparation fails', async () => {
    seedQueue(0, 2)
    processorMock.instance.isActive = true
    renderHook(() => useAudioPlayback())
    const [active, incoming] = capturedAudioElements
    active.src = '/api/stream/track?id=t0'
    active.currentTime = 23
    incoming.readyState = 0
    vi.mocked(active.pause).mockClear()
    const command = loadCommand()
    command.payload = { ...command.payload, media: { kind: 'song', publicId: 't1' }, queue: ['t0', 't1'], currentTrackIndex: 1 }
    const task = getPlaybackAdapter('song')!.prepare(command.payload, new AbortController().signal)
    const rejected = expect(task).rejects.toThrow('could not be prepared')
    await act(async () => { await Promise.resolve() })
    errorMedia(incoming)
    await rejected
    expect(active.src).toContain('id=t0')
    expect(active.currentTime).toBe(23)
    expect(active.pause).not.toHaveBeenCalled()
    expect(active.play).not.toHaveBeenCalled()
  })

  it('keeps playback fenced until fresh registration explicitly rearms it', async () => {
    seedQueue(0, 1)
    renderHook(() => useAudioPlayback())
    const [audio, incoming] = capturedAudioElements
    audio.src = '/api/stream/track?id=t0'
    audio.currentTime = 17
    const adapter = getPlaybackAdapter('song')!
    const before = { volume: usePlayerStore.getState().volume, muted: usePlayerStore.getState().muted }
    const stopped = adapter.localRecovery!.fence()
    expect(stopped).toMatchObject({ observedState: 'stopped', position: 17 })
    expect(audio.pause).toHaveBeenCalled()
    expect(incoming.pause).toHaveBeenCalled()
    expect(audioService.fenceOutput).toHaveBeenCalledOnce()
    await expect(adapter.execute({ ...loadCommand(), type: 'play' }, new AbortController().signal)).rejects.toMatchObject({ name: 'AbortError' })
    expect(audio.play).not.toHaveBeenCalled()
    expect(usePlayerStore.getState()).toMatchObject(before)
    adapter.localRecovery!.rearmAfterRegistration()
    vi.mocked(audio.play).mockImplementation(async () => { audio.paused = false })
    await adapter.execute({ ...loadCommand(), type: 'play' }, new AbortController().signal)
    expect(audio.play).toHaveBeenCalledOnce()
    expect(audioService.rearmOutput).toHaveBeenCalledOnce()
  })

  it('fences a pending play result synchronously before its native promise settles', async () => {
    seedQueue(0, 1)
    renderHook(() => useAudioPlayback())
    const [audio] = capturedAudioElements
    audio.src = '/api/stream/track?id=t0'
    let finish!: () => void
    vi.mocked(audio.pause).mockImplementation(() => { audio.paused = true })
    vi.mocked(audio.play).mockImplementation(() => new Promise<void>(resolve => { finish = () => { audio.paused = false; resolve() } }))
    const adapter = getPlaybackAdapter('song')!
    const playing = adapter.execute({ ...loadCommand(), type: 'play' }, new AbortController().signal)
    const rejection = expect(playing).rejects.toMatchObject({ name: 'AbortError' })
    await act(async () => { await Promise.resolve(); await Promise.resolve() })
    adapter.localRecovery!.fence()
    expect(audio.paused).toBe(true)
    finish()
    await rejection
    expect(audio.paused).toBe(true)
  })

  it('still pauses both elements but refuses a stop proof if graph muting fails', () => {
    renderHook(() => useAudioPlayback())
    const [audio, incoming] = capturedAudioElements
    vi.mocked(audioService.fenceOutput).mockImplementationOnce(() => { throw new Error('Graph failed') })
    expect(getPlaybackAdapter('song')!.localRecovery!.fence()).toBeNull()
    expect(audio.pause).toHaveBeenCalled()
    expect(incoming.pause).toHaveBeenCalled()
  })

  it('does not return a stop proof if an owned element remains playing', () => {
    renderHook(() => useAudioPlayback())
    const [audio] = capturedAudioElements
    audio.paused = false
    expect(getPlaybackAdapter('song')!.localRecovery!.fence()).toBeNull()
  })

  it('qualifies prevented Chromium local output while keeping Safari ineligible', () => {
    const userAgent = vi.spyOn(navigator, 'userAgent', 'get').mockReturnValue('Mozilla/5.0 Chrome/156.0.0.0 Safari/537.36')
    const first = renderHook(() => useAudioPlayback())
    expect(getPlaybackAdapter('song')!.localRecovery!.eligible).toBe(true)
    expect(capturedAudioElements.every(audio => audio.disableRemotePlayback)).toBe(true)
    first.unmount()
    userAgent.mockReturnValue('Mozilla/5.0 Version/26.0 Mobile/15E148 Safari/604.1')
    const second = renderHook(() => useAudioPlayback())
    expect(getPlaybackAdapter('song')!.localRecovery!.eligible).toBe(false)
    second.unmount()
    userAgent.mockRestore()
  })

  it('clears the independent radio source before music starts and when fencing', async () => {
    seedQueue(0, 1)
    const radio = { src: 'https://radio.baander.app/live', pause: vi.fn() } as unknown as HTMLAudioElement
    useRadioStore.getState().setAudioElement(radio)
    renderHook(() => useAudioPlayback())
    const [audio] = capturedAudioElements
    audio.src = '/api/stream/track?id=t0'
    const adapter = getPlaybackAdapter('song')!
    await adapter.execute({ ...loadCommand(), type: 'play' }, new AbortController().signal)
    expect(radio.src).toBe('')
    expect(radio.pause).toHaveBeenCalled()
    radio.src = 'https://radio.baander.app/live'
    adapter.localRecovery!.fence()
    expect(radio.src).toBe('')
  })

  it('registers its owned runtime and removes it on unmount', () => {
    const { unmount } = renderHook(() => useAudioPlayback())
    expect(getPlaybackAdapter('song')?.kind).toBe('song')
    unmount()
    expect(getPlaybackAdapter('song')).toBeUndefined()
  })

  it('recreates playable resources when StrictMode replays setup', () => {
    const { result, unmount } = renderHook(() => useAudioPlayback(), {
      reactStrictMode: true,
    })
    expect(capturedAudioElements).toHaveLength(4)
    const [retired, , active] = capturedAudioElements
    expect(retired.src).toBe('')
    expect(result.current.current).toBe(active)
    expect(usePlayerStore.getState().audioElement).toBe(active)
    usePlayerStore.setState({ currentTrack: track('t0') })
    active.src = '/api/stream/track?id=t0'
    act(() => { active.dispatchEvent(new Event('loadstart')) })
    expect(audioService.connectDualAudioElements).toHaveBeenCalledWith(active, capturedAudioElements[3])
    act(() => { playMedia(active) })
    expect(usePlayerStore.getState().isPlaying).toBe(true)
    unmount()
    expect(result.current.current).toBeNull()
    expect(usePlayerStore.getState().audioElement).toBeNull()
  })

  it('removes loadstart and pending preload listeners on teardown', () => {
    seedQueue(0, 2)
    const { unmount } = renderHook(() => useAudioPlayback())
    const [a, b] = capturedAudioElements
    act(() => { timeUpdate(a, 95, 100) })
    unmount()
    expect(a.removeEventListener).toHaveBeenCalledWith('loadstart', expect.any(Function))
    expect(b.removeEventListener).toHaveBeenCalledWith('canplaythrough', expect.any(Function))
    expect(b.removeEventListener).toHaveBeenCalledWith('error', expect.any(Function))
    a.src = '/api/stream/track?id=t0'
    a.dispatchEvent(new Event('loadstart'))
    expect(audioService.connectDualAudioElements).not.toHaveBeenCalled()
  })

  it('does not publish pause events raised while disposing the owned elements', () => {
    const { unmount } = renderHook(() => useAudioPlayback())
    const [a] = capturedAudioElements
    vi.mocked(a.pause).mockImplementation(() => { a.dispatchEvent(new Event('pause')) })
    act(() => { usePlayerStore.setState({ isPlaying: true }) })
    unmount()
    expect(usePlayerStore.getState().isPlaying).toBe(true)
    expect(usePlayerStore.getState().audioElement).toBeNull()
  })

  it('does not resume a retired element after deferred context resume', async () => {
    let resolveResume!: () => void
    vi.mocked(audioService.resumeContextIfNeeded).mockReturnValue(new Promise<void>((resolve) => { resolveResume = resolve }))
    const { unmount } = renderHook(() => useAudioPlayback())
    const [a] = capturedAudioElements
    a.src = '/api/stream/track?id=t0'
    act(() => { usePlayerStore.setState({ isPlaying: true }) })
    unmount()
    await act(async () => { resolveResume() })
    expect(a.play).not.toHaveBeenCalled()
  })

  it('ignores playback failures delivered after teardown', async () => {
    let rejectPlay!: (reason: Error) => void
    const { unmount } = renderHook(() => useAudioPlayback())
    const [a] = capturedAudioElements
    vi.mocked(a.play).mockReturnValue(new Promise<void>((_, reject) => { rejectPlay = reject }))
    a.src = '/api/stream/track?id=t0'
    await act(async () => { usePlayerStore.setState({ isPlaying: true }) })
    expect(a.play).toHaveBeenCalledOnce()
    unmount()
    await act(async () => { rejectPlay(new Error('retired playback')) })
    expect(usePlayerStore.getState().isPlaying).toBe(true)
  })

  it('does not reapply EQ after a user gesture resume completes after teardown', async () => {
    let resolveResume!: () => void
    vi.mocked(audioService.resumeContextIfNeeded).mockReturnValue(new Promise<void>((resolve) => { resolveResume = resolve }))
    const { unmount } = renderHook(() => useAudioPlayback())
    act(() => { document.dispatchEvent(new Event('click')) })
    unmount()
    await act(async () => { resolveResume() })
    expect(reapplyAllEqState).not.toHaveBeenCalled()
  })

  it('creates dual audio elements, wires the primary (A) element to the store, and inits the audio service', () => {
    const { unmount } = renderHook(() => useAudioPlayback())

    expect(capturedAudioElements).toHaveLength(2)
    const [a] = capturedAudioElements
    expect(usePlayerStore.getState().audioElement).toBe(a)

    expect(audioService.initialize).toHaveBeenCalled()
    unmount()
  })

  // --- Preload scheduler ---------------------------------------------------

  describe('preload scheduler', () => {
    it('primes the inactive element (B) when within the gapless threshold (6s) and moves toward ready on canplaythrough', () => {
      const q = seedQueue(0, 2) // t0 playing, t1 next
      const { result } = renderHook(() => useAudioPlayback())
      const [a, b] = capturedAudioElements

      // Far from end — no preload.
      timeUpdate(a, 10, 100)
      expect(b.src).toBe('')

      // Cross gapless threshold: 100 - 95 = 5 < 6.
      act(() => {
        timeUpdate(a, 95, 100)
      })

      expect(b.src).toBe(`/api/stream/track?id=${q[1].publicId}`)
      expect(b.preload).toBe('auto')

      // canplaythrough promotes preload state to 'ready' (observable only via
      // the ended path, exercised below); fire it to complete the cycle.
      act(() => {
        b.dispatchEvent(new Event('canplaythrough'))
      })

      // A second timeupdate near the end must NOT re-prime (preloadState != idle).
      const srcAfterReady = b.src
      act(() => {
        timeUpdate(a, 97, 100)
      })
      expect(b.src).toBe(srcAfterReady)

      void result
    })

    it('uses crossfadeDuration + 3s buffer as the threshold when crossfade is enabled', () => {
      seedQueue(0, 2)
      resetStore({
        queue: [track('t0'), track('t1')],
        currentIndex: 0,
        currentTrack: track('t0'),
        crossfadeEnabled: true,
        crossfadeDuration: 8.0, // threshold = 8 + 3 = 11
      })
      renderHook(() => useAudioPlayback())
      const [a, b] = capturedAudioElements

      // 100 - 90 = 10 < 11 → should preload.
      act(() => {
        timeUpdate(a, 90, 100)
      })
      expect(b.src).toContain('t1')
    })

    it('does not preload when there is no next track (end of non-repeating queue)', () => {
      seedQueue(1, 2) // at last index, repeat off → resolveNextIndex returns null
      renderHook(() => useAudioPlayback())
      const [a, b] = capturedAudioElements

      act(() => {
        timeUpdate(a, 95, 100)
      })
      expect(b.src).toBe('')
    })

    it('does not preload when duration is not finite', () => {
      seedQueue(0, 2)
      renderHook(() => useAudioPlayback())
      const [a, b] = capturedAudioElements

      // Live stream (Infinity duration) — guard returns early.
      act(() => {
        timeUpdate(a, 50, Infinity)
      })
      expect(b.src).toBe('')
    })

    it('resets preloadState to idle on element error', () => {
      seedQueue(0, 2)
      renderHook(() => useAudioPlayback())
      const [a, b] = capturedAudioElements

      act(() => {
        timeUpdate(a, 95, 100) // → preloading
      })
      expect(b.src).toContain('t1')

      // Fire error → state back to idle. A subsequent timeupdate past threshold
      // should attempt to re-prime (src gets set again).
      act(() => {
        b.dispatchEvent(new Event('error'))
        timeUpdate(a, 96, 100)
      })
      expect(b.src).toContain('t1')
    })
  })

  // --- ended branching -----------------------------------------------------

  describe('programme boundaries', () => {
    it.each(['paused', 'ended'] as const)('ignores a queued play notification while current media is %s', (state) => {
      seedQueue(0, 2)
      renderHook(() => useAudioPlayback())
      const [a] = capturedAudioElements
      a.src = '/api/stream/track?id=t0'
      a.paused = state === 'paused'
      a.ended = state === 'ended'
      act(() => { a.dispatchEvent(new Event('play')) })
      expect(usePlayerStore.getState().isPlaying).toBe(false)
      expect(audioService.setPlayingState).not.toHaveBeenCalledWith(true)
      expect(audioService.connectDualAudioElements).not.toHaveBeenCalled()
    })

    it.each(['replacement', 'rewind'] as const)('ignores a queued ended notification after %s while current media has not ended', async (operation) => {
      const queue = seedQueue(0, 3)
      usePlayerStore.setState({ repeat: operation === 'rewind' ? 'one' : 'off', isPlaying: true })
      renderHook(() => useAudioPlayback())
      const [a] = capturedAudioElements
      a.src = '/api/stream/track?id=t0'
      a.paused = false
      a.ended = false
      if (operation === 'replacement') {
        usePlayerStore.setState({ currentTrack: queue[1], currentIndex: 1 })
        a.src = '/api/stream/track?id=t1'
      } else a.currentTime = 1
      const plays = vi.mocked(a.play).mock.calls.length
      await act(async () => { a.dispatchEvent(new Event('ended')) })
      expect(usePlayerStore.getState().currentTrack).toBe(queue[operation === 'replacement' ? 1 : 0])
      expect(a.src).toBe(`/api/stream/track?id=t${operation === 'replacement' ? 1 : 0}`)
      expect(a.play).toHaveBeenCalledTimes(plays)
      expect(processorMock.instance.resetProgramme).not.toHaveBeenCalled()
      expect(activityService.recordPlay).not.toHaveBeenCalled()
    })

    it('ignores a queued pause from a replaced source while current playback is active', () => {
      seedQueue(0, 2)
      usePlayerStore.setState({ isPlaying: true })
      renderHook(() => useAudioPlayback())
      const [a] = capturedAudioElements
      a.paused = false
      act(() => { a.dispatchEvent(new Event('pause')) })
      expect(usePlayerStore.getState().isPlaying).toBe(true)
      expect(audioService.setPlayingState).not.toHaveBeenCalledWith(false)
      a.paused = true
      act(() => { a.dispatchEvent(new Event('pause')) })
      expect(usePlayerStore.getState().isPlaying).toBe(false)
      expect(audioService.setPlayingState).toHaveBeenCalledWith(false)
    })

    it('resets for active source loads including reloading the same track', () => {
      seedQueue(0, 2)
      renderHook(() => useAudioPlayback())
      const [a, b] = capturedAudioElements
      a.src = '/api/stream/track?id=t0'
      act(() => {
        a.dispatchEvent(new Event('loadstart'))
        a.dispatchEvent(new Event('loadstart'))
      })
      expect(processorMock.instance.resetProgramme).toHaveBeenCalledTimes(2)

      processorMock.instance.resetProgramme.mockClear()
      act(() => {
        timeUpdate(a, 95, 100)
        b.dispatchEvent(new Event('loadstart'))
        b.dispatchEvent(new Event('canplaythrough'))
      })
      expect(b.load).toHaveBeenCalledOnce()
      expect(processorMock.instance.resetProgramme).not.toHaveBeenCalled()

      act(() => {
        a.src = ''
        a.dispatchEvent(new Event('loadstart'))
      })
      expect(processorMock.instance.resetProgramme).not.toHaveBeenCalled()
    })

    it('preserves the programme on play, pause, seek and queue mutation', () => {
      seedQueue(0, 2)
      renderHook(() => useAudioPlayback())
      const [a] = capturedAudioElements
      act(() => {
        playMedia(a)
        a.dispatchEvent(new Event('pause'))
        a.currentTime = 0
        a.dispatchEvent(new Event('seeking'))
        usePlayerStore.setState({ queue: [track('t0'), track('changed')] })
      })
      expect(processorMock.instance.resetProgramme).not.toHaveBeenCalled()
    })

    it('does not reset when incoming playback fails', async () => {
      seedQueue(0, 2)
      renderHook(() => useAudioPlayback())
      const [a, b] = capturedAudioElements
      vi.mocked(b.play).mockRejectedValueOnce(new Error('Playback unavailable'))
      await act(async () => {
        timeUpdate(a, 95, 100)
        b.dispatchEvent(new Event('canplaythrough'))
        endMedia(a)
      })
      expect(processorMock.instance.resetProgramme).not.toHaveBeenCalled()
      expect(processorMock.instance.instantSwap).not.toHaveBeenCalled()
    })
  })

  describe('ended event', () => {
    it('stops repeat-one when its current native playback rejects', async () => {
      seedQueue(0, 2)
      usePlayerStore.setState({ repeat: 'one', isPlaying: true })
      renderHook(() => useAudioPlayback())
      const [a] = capturedAudioElements
      a.src = '/api/stream/track?id=t0'
      vi.mocked(a.play).mockRejectedValueOnce(new Error('Repeat failed'))
      await act(async () => { endMedia(a) })
      expect(usePlayerStore.getState().isPlaying).toBe(false)
      expect(activityService.recordPlay).not.toHaveBeenCalled()
    })

    it('restarts and records the current track when repeat === "one" without reloading', async () => {
      const q = seedQueue(0, 2)
      usePlayerStore.setState({ repeat: 'one', currentTrack: q[0] })
      const { result } = renderHook(() => useAudioPlayback())
      const [a] = capturedAudioElements
      a.currentTime = 42
      a.src = '/api/stream/track?id=t0'
      vi.mocked(a.play).mockImplementation(() => {
        a.paused = false
        a.ended = false
        return Promise.resolve()
      })
      await act(async () => {
        endMedia(a)
      })

      expect(a.currentTime).toBe(0)
      expect(a.play).toHaveBeenCalled()
      expect(processorMock.instance.resetProgramme).toHaveBeenCalledOnce()
      expect(activityService.recordPlay).toHaveBeenCalledExactlyOnceWith({ songId: 't0', albumId: undefined })
      expect(a.src).toBe('/api/stream/track?id=t0')
      expect(a.load).not.toHaveBeenCalled()
      // playNext must NOT be called: index unchanged.
      expect(usePlayerStore.getState().currentIndex).toBe(0)
      void result
    })

    it('instant-swaps to the preloaded element when preload is ready and crossfade is off', async () => {
      seedQueue(0, 2) // t0 → t1
      renderHook(() => useAudioPlayback())
      const [a, b] = capturedAudioElements

      // Prime + ready the preload.
      act(() => {
        timeUpdate(a, 95, 100)
        b.dispatchEvent(new Event('canplaythrough'))
      })

      const playSpy = vi.mocked(b.play)
      playSpy.mockClear()

      await act(async () => {
        endMedia(a)
      })

      // crossfade off → instantSwap on the processor.
      expect(processorMock.instance.instantSwap).toHaveBeenCalledTimes(1)
      expect(processorMock.instance.resetProgramme).toHaveBeenCalledOnce()
      expect(processorMock.instance.resetProgramme.mock.invocationCallOrder[0])
        .toBeLessThan(processorMock.instance.instantSwap.mock.invocationCallOrder[0])
      expect(processorMock.instance.crossfadeToInactive).not.toHaveBeenCalled()
      // Inactive (B) is now played; active (A) paused + rewound.
      expect(playSpy).toHaveBeenCalled()
      expect(a.pause).toHaveBeenCalled()
      expect(a.currentTime).toBe(0)
      // Store advanced to next track.
      expect(usePlayerStore.getState().currentIndex).toBe(1)
    })

    it('crossfades before ended and retains the outgoing element until the fade completes', async () => {
      seedQueue(0, 2)
      resetStore({
        queue: [track('t0'), track('t1')],
        currentIndex: 0,
        currentTrack: track('t0'),
        crossfadeEnabled: true,
        crossfadeDuration: 4.0,
        isPlaying: true,
      })
      renderHook(() => useAudioPlayback())
      const [a, b] = capturedAudioElements

      act(() => {
        timeUpdate(a, 95, 100)
        b.dispatchEvent(new Event('canplaythrough'))
      })

      vi.useFakeTimers()
      await act(async () => { timeUpdate(a, 97, 100) })
      expect(a.pause).not.toHaveBeenCalled()
      expect(usePlayerStore.getState().audioElement).toBe(b)
      expect(b.volume).toBe(1)
      expect(processorMock.instance.setVolume).toHaveBeenLastCalledWith(0.75)
      act(() => { vi.advanceTimersByTime(3000) })
      expect(a.pause).toHaveBeenCalled()
      vi.useRealTimers()

      expect(processorMock.instance.crossfadeToInactive).toHaveBeenCalledWith(3.0)
      expect(processorMock.instance.resetProgramme).toHaveBeenCalledOnce()
      expect(processorMock.instance.resetProgramme.mock.invocationCallOrder[0])
        .toBeLessThan(processorMock.instance.crossfadeToInactive.mock.invocationCallOrder[0])
      expect(processorMock.instance.instantSwap).not.toHaveBeenCalled()
      expect(usePlayerStore.getState().currentIndex).toBe(1)
    })

    it('loads the next track when preload never completed', async () => {
      seedQueue(0, 2)
      renderHook(() => useAudioPlayback())
      const [a] = capturedAudioElements

      // No timeupdate fired → preloadState still 'idle'.
      await act(async () => {
        endMedia(a)
      })

      expect(processorMock.instance.instantSwap).not.toHaveBeenCalled()
      expect(processorMock.instance.crossfadeToInactive).not.toHaveBeenCalled()
      // playNext still advances the queue.
      expect(usePlayerStore.getState().currentIndex).toBe(1)
    })

    it('loads the next track when its preload is not ready', async () => {
      seedQueue(0, 2)
      renderHook(() => useAudioPlayback())
      const [a] = capturedAudioElements

      // Start preload (→ preloading) but do NOT fire canplaythrough.
      act(() => {
        timeUpdate(a, 95, 100)
      })
      // ended before ready → fallback path (processor swap skipped).
      await act(async () => {
        endMedia(a)
      })

      expect(processorMock.instance.instantSwap).not.toHaveBeenCalled()
      expect(usePlayerStore.getState().currentIndex).toBe(1)
    })
  })

  describe('transition ownership and cancellation', () => {
    it('uses B events and controls after handoff, then preloads and adopts A again', async () => {
      seedQueue(0, 3)
      renderHook(() => useAudioPlayback())
      const [a, b] = capturedAudioElements
      a.src = '/api/stream/track?id=t0'
      await act(async () => {
        timeUpdate(a, 95, 100)
        b.dispatchEvent(new Event('canplaythrough'))
        endMedia(a)
      })
      expect(usePlayerStore.getState().audioElement).toBe(b)
      expect(a.src).toContain('t0')
      expect(processorMock.instance.resetProgramme).toHaveBeenCalledOnce()
      act(() => {
        a.dispatchEvent(new Event('loadstart'))
        b.dispatchEvent(new Event('loadstart'))
      })
      expect(processorMock.instance.resetProgramme).toHaveBeenCalledTimes(2)
      act(() => {
        b.duration = 120
        b.dispatchEvent(new Event('durationchange'))
        a.duration = 999
        a.dispatchEvent(new Event('durationchange'))
      })
      expect(usePlayerStore.getState().duration).toBe(120)
      b.paused = false
      act(() => { usePlayerStore.setState({ isPlaying: false }) })
      expect(b.pause).toHaveBeenCalled()
      await act(async () => { usePlayerStore.setState({ isPlaying: true }) })
      await act(async () => {
        timeUpdate(b, 115, 120)
        a.dispatchEvent(new Event('canplaythrough'))
        endMedia(b)
      })
      expect(usePlayerStore.getState().audioElement).toBe(a)
      expect(usePlayerStore.getState().currentIndex).toBe(2)
      expect(a.src).toContain('t2')
      expect(processorMock.instance.resetProgramme).toHaveBeenCalledTimes(3)
    })

    it('retains the prepared next track across the natural pause then ended event sequence', async () => {
      seedQueue(0, 2)
      usePlayerStore.setState({ isPlaying: true })
      renderHook(() => useAudioPlayback())
      const [a, b] = capturedAudioElements
      await act(async () => {
        timeUpdate(a, 95, 100)
        b.dispatchEvent(new Event('canplaythrough'))
        a.ended = true
        a.dispatchEvent(new Event('pause'))
        endMedia(a)
      })
      expect(usePlayerStore.getState().audioElement).toBe(b)
      expect(processorMock.instance.instantSwap).toHaveBeenCalledOnce()
      expect(usePlayerStore.getState().isPlaying).toBe(true)
    })

    it('does not adopt or swap until incoming playback succeeds', async () => {
      seedQueue(0, 2)
      renderHook(() => useAudioPlayback())
      const [a, b] = capturedAudioElements
      let resolvePlay!: () => void
      vi.mocked(b.play).mockReturnValue(new Promise<void>((resolve) => { resolvePlay = resolve }))
      await act(async () => {
        timeUpdate(a, 95, 100)
        b.dispatchEvent(new Event('canplaythrough'))
        endMedia(a)
      })
      expect(usePlayerStore.getState().audioElement).toBe(a)
      expect(processorMock.instance.instantSwap).not.toHaveBeenCalled()
      expect(processorMock.instance.resetProgramme).not.toHaveBeenCalled()
      await act(async () => { resolvePlay() })
      expect(usePlayerStore.getState().audioElement).toBe(b)
      expect(processorMock.instance.resetProgramme).toHaveBeenCalledOnce()
    })

    it.each([true, false])('cancels deferred fade after seeking backward (seeking event: %s)', async (dispatchSeeking) => {
      seedQueue(0, 2)
      usePlayerStore.setState({ isPlaying: true, crossfadeEnabled: true, crossfadeDuration: 4 })
      renderHook(() => useAudioPlayback())
      const [a, b] = capturedAudioElements
      let resolvePlay!: () => void
      vi.mocked(b.play).mockReturnValue(new Promise<void>((resolve) => { resolvePlay = resolve }))
      await act(async () => {
        timeUpdate(a, 97, 100)
        b.dispatchEvent(new Event('canplaythrough'))
      })
      act(() => {
        a.currentTime = 10
        a.seeking = true
        if (dispatchSeeking) a.dispatchEvent(new Event('seeking'))
      })
      await act(async () => { resolvePlay() })
      expect(usePlayerStore.getState().audioElement).toBe(a)
      expect(usePlayerStore.getState().currentIndex).toBe(0)
      expect(processorMock.instance.crossfadeToInactive).not.toHaveBeenCalled()
      expect(processorMock.instance.resetProgramme).not.toHaveBeenCalled()
      expect(b.pause).toHaveBeenCalled()
    })

    it.each(['pause', 'queue', 'unmount'] as const)('invalidates incoming play when interrupted by %s', async (interruption) => {
      seedQueue(0, 2)
      usePlayerStore.setState({ isPlaying: true })
      const { unmount } = renderHook(() => useAudioPlayback())
      const [a, b] = capturedAudioElements
      let resolvePlay!: () => void
      vi.mocked(b.play).mockReturnValue(new Promise<void>((resolve) => { resolvePlay = resolve }))
      await act(async () => {
        timeUpdate(a, 95, 100)
        b.dispatchEvent(new Event('canplaythrough'))
        endMedia(a)
      })
      act(() => {
        if (interruption === 'pause') usePlayerStore.setState({ isPlaying: false })
        if (interruption === 'queue') usePlayerStore.setState({ queue: [track('t0'), track('changed')] })
        if (interruption === 'unmount') unmount()
      })
      await act(async () => { resolvePlay() })
      expect(usePlayerStore.getState().currentIndex).toBe(0)
      expect(processorMock.instance.instantSwap).not.toHaveBeenCalled()
      expect(processorMock.instance.resetProgramme).not.toHaveBeenCalled()
      expect(b.pause).toHaveBeenCalled()
    })

    it('keeps listening volume downstream of both elements after handoff', async () => {
      seedQueue(0, 2)
      usePlayerStore.setState({ volume: 25, muted: true })
      renderHook(() => useAudioPlayback())
      const [a, b] = capturedAudioElements
      await act(async () => {
        timeUpdate(a, 95, 100)
        b.dispatchEvent(new Event('canplaythrough'))
        endMedia(a)
      })
      expect([a.volume, b.volume]).toEqual([1, 1])
      expect([a.muted, b.muted]).toEqual([false, false])
      expect(processorMock.instance.setVolume).toHaveBeenLastCalledWith(0.25)
      expect(processorMock.instance.setMuted).toHaveBeenLastCalledWith(true)
      act(() => {
        usePlayerStore.getState().runtimeActions.setMuted(true)
        usePlayerStore.getState().runtimeActions.setVolume(40)
        usePlayerStore.getState().runtimeActions.setMuted(false)
      })
      expect([a.volume, b.volume]).toEqual([1, 1])
      expect([a.muted, b.muted]).toEqual([false, false])
      expect(processorMock.instance.setVolume).toHaveBeenLastCalledWith(0.4)
      expect(processorMock.instance.setMuted).toHaveBeenLastCalledWith(false)
      act(() => { usePlayerStore.getState().runtimeActions.toggleMute() })
      expect([a.muted, b.muted]).toEqual([false, false])
      expect(processorMock.instance.setMuted).toHaveBeenLastCalledWith(true)
    })
  })

  it('falls back to normal selection when graph connection never becomes active', async () => {
    seedQueue(0, 2)
    vi.mocked(audioService.connectDualAudioElements).mockResolvedValue(undefined)
    renderHook(() => useAudioPlayback())
    const [a, b] = capturedAudioElements
    await act(async () => {
      timeUpdate(a, 95, 100)
      b.dispatchEvent(new Event('canplaythrough'))
      a.ended = true
      endMedia(a)
    })
    expect(usePlayerStore.getState().currentTrack?.publicId).toBe('t1')
    expect(usePlayerStore.getState().audioElement).toBe(a)
    expect(a.src).toContain('t1')
    expect(b.play).not.toHaveBeenCalled()
    expect(processorMock.instance.instantSwap).not.toHaveBeenCalled()
    expect(processorMock.instance.crossfadeToInactive).not.toHaveBeenCalled()
  })

  describe('connection recovery', () => {
    it.each(['loadstart', 'play'])('stops a failed unconnected graph and retries only on a later %s', async (event) => {
      seedQueue(0, 2)
      usePlayerStore.setState({ isPlaying: true, volume: 30, muted: true })
      vi.mocked(audioService.connectDualAudioElements).mockRejectedValueOnce(new DOMException('Source capture failed', 'InvalidStateError'))
      renderHook(() => useAudioPlayback())
      const [a, b] = capturedAudioElements
      await act(async () => {
        a.src = 'https://baander.app/audio.mp3'
        a.dispatchEvent(new Event('loadstart'))
      })
      expect(usePlayerStore.getState().isPlaying).toBe(false)
      expect(a.pause).toHaveBeenCalled()
      expect(b.pause).toHaveBeenCalled()
      expect(audioService.setPlayingState).toHaveBeenLastCalledWith(false)
      expect(audioService.connectDualAudioElements).toHaveBeenCalledTimes(1)
      expect([a.volume, b.volume]).toEqual([.3, .3])
      expect([a.muted, b.muted]).toEqual([true, true])
      await act(async () => {
        usePlayerStore.getState().runtimeActions.setVolume(20)
        if (event === 'play') playMedia(a)
        else a.dispatchEvent(new Event(event))
      })
      expect(audioService.connectDualAudioElements).toHaveBeenCalledTimes(2)
      expect([a.volume, b.volume]).toEqual([1, 1])
      expect([a.muted, b.muted]).toEqual([false, false])
      expect(processorMock.instance.setVolume).toHaveBeenLastCalledWith(.2)
      expect(processorMock.instance.setMuted).toHaveBeenLastCalledWith(true)
    })

    it('preserves a working graph when a connection attempt rejects', async () => {
      seedQueue(0, 2)
      usePlayerStore.setState({ isPlaying: true })
      vi.mocked(audioService.connectDualAudioElements).mockImplementation(async () => {
        processorMock.instance.isActive = true
        throw new Error('Preference reapplication failed')
      })
      renderHook(() => useAudioPlayback())
      const [a] = capturedAudioElements
      const pauses = vi.mocked(a.pause).mock.calls.length
      await act(async () => {
        a.src = 'https://baander.app/audio.mp3'
        a.dispatchEvent(new Event('loadstart'))
      })
      expect(usePlayerStore.getState().isPlaying).toBe(true)
      expect(a.pause).toHaveBeenCalledTimes(pauses)
      expect(a.volume).toBe(1)
    })

    it('ignores a failed connection from an unmounted playback lifetime', async () => {
      let rejectConnection!: (error: Error) => void
      vi.mocked(audioService.connectDualAudioElements).mockImplementationOnce(() => new Promise<void>((_, reject) => { rejectConnection = reject }))
      const first = renderHook(() => useAudioPlayback())
      const [a] = capturedAudioElements
      act(() => {
        a.src = 'https://baander.app/audio.mp3'
        a.dispatchEvent(new Event('loadstart'))
        first.unmount()
      })
      renderHook(() => useAudioPlayback())
      const replacement = capturedAudioElements[2]
      act(() => { usePlayerStore.setState({ isPlaying: true }) })
      const pauses = vi.mocked(replacement.pause).mock.calls.length
      await act(async () => { rejectConnection(new Error('Stale source capture failure')) })
      expect(usePlayerStore.getState().isPlaying).toBe(true)
      expect(replacement.pause).toHaveBeenCalledTimes(pauses)
      expect(audioService.connectDualAudioElements).toHaveBeenCalledTimes(1)
    })
  })

  describe('volume ownership', () => {
    it.each(['unconnected', 'absent', 'passive'])('keeps both elements at listening volume with %s processor', (mode) => {
      if (mode === 'absent') vi.mocked(audioService.getProcessor).mockReturnValue(null)
      if (mode === 'passive') {
        processorMock.instance.isActive = true
        processorMock.instance.passive = true
      }
      renderHook(() => useAudioPlayback())
      const [a, b] = capturedAudioElements
      act(() => {
        usePlayerStore.getState().runtimeActions.setMuted(true)
        usePlayerStore.getState().runtimeActions.setVolume(35)
      })
      expect([a.volume, b.volume]).toEqual([0.35, 0.35])
      expect([a.muted, b.muted]).toEqual([true, true])
      act(() => { usePlayerStore.getState().runtimeActions.toggleMute() })
      expect([a.volume, b.volume]).toEqual([0.35, 0.35])
      expect([a.muted, b.muted]).toEqual([false, false])
    })

    it('configures output before synchronous graph wiring and restores element controls after passive fallback', async () => {
      usePlayerStore.setState({ volume: 35, muted: true })
      let finishConnection!: () => void
      vi.mocked(audioService.connectDualAudioElements).mockImplementation(() => {
        expect(processorMock.instance.setVolume).toHaveBeenLastCalledWith(0.35)
        expect(processorMock.instance.setMuted).toHaveBeenLastCalledWith(true)
        processorMock.instance.isActive = true
        return new Promise<void>((resolve) => { finishConnection = resolve })
      })
      renderHook(() => useAudioPlayback())
      const [a, b] = capturedAudioElements
      act(() => {
        a.src = 'https://baander.app/audio.mp3'
        a.dispatchEvent(new Event('loadstart'))
      })
      // Pending EQ import must not leave attenuation ahead of the meter.
      expect([a.volume, b.volume]).toEqual([1, 1])
      expect([a.muted, b.muted]).toEqual([false, false])
      act(() => { usePlayerStore.getState().runtimeActions.setVolume(20) })
      expect(processorMock.instance.setVolume).toHaveBeenLastCalledWith(0.2)
      await act(async () => {
        processorMock.instance.passive = true
        finishConnection()
      })
      expect([a.volume, b.volume]).toEqual([0.2, 0.2])
      expect([a.muted, b.muted]).toEqual([true, true])
    })

    it('preserves listening volume during deferred connection, then transfers both inputs to unity', async () => {
      usePlayerStore.setState({ volume: 35, muted: true })
      let finishConnection!: () => void
      vi.mocked(audioService.connectDualAudioElements).mockImplementation(() => new Promise<void>((resolve) => {
        finishConnection = () => { processorMock.instance.isActive = true; resolve() }
      }))
      renderHook(() => useAudioPlayback())
      const [a, b] = capturedAudioElements
      act(() => {
        a.src = 'https://baander.app/audio.mp3'
        a.dispatchEvent(new Event('loadstart'))
        usePlayerStore.getState().runtimeActions.setVolume(20)
      })
      expect([a.volume, b.volume]).toEqual([0.2, 0.2])
      expect([a.muted, b.muted]).toEqual([true, true])
      await act(async () => { finishConnection() })
      expect([a.volume, b.volume]).toEqual([1, 1])
      expect([a.muted, b.muted]).toEqual([false, false])
      expect(processorMock.instance.setVolume).toHaveBeenLastCalledWith(0.2)
      expect(processorMock.instance.setMuted).toHaveBeenLastCalledWith(true)
    })
  })

  // --- store → DOM sync ----------------------------------------------------

  describe('owned media errors', () => {
    it('stops a later active MediaError after playback succeeded and permits manual selection', async () => {
      const queue = seedQueue(0, 2)
      renderHook(() => useAudioPlayback())
      const [a, b] = capturedAudioElements
      a.src = '/api/stream/track?id=t0'
      await act(async () => {
        await a.play()
        playMedia(a)
      })
      expect(usePlayerStore.getState().isPlaying).toBe(true)
      act(() => { errorMedia(a) })
      expect(usePlayerStore.getState().isPlaying).toBe(false)
      expect(audioService.setPlayingState).toHaveBeenLastCalledWith(false)
      expect(a.pause).toHaveBeenCalled()
      expect(b.pause).toHaveBeenCalled()
      expect(usePlayerStore.getState().currentTrack).toBe(queue[0])
      expect(toast.error).toHaveBeenCalledWith('This track could not be played.', expect.anything())
      vi.mocked(a.play).mockImplementation(() => {
        a.error = null
        a.paused = false
        return Promise.resolve()
      })
      await act(async () => { usePlayerStore.getState().runtimeActions.playTrack(queue[1]) })
      expect(usePlayerStore.getState().isPlaying).toBe(true)
      expect(usePlayerStore.getState().currentTrack).toBe(queue[1])
    })

    it('stops promoted media and the outgoing fade and cancels its deadline', async () => {
      vi.useFakeTimers()
      seedQueue(0, 2)
      usePlayerStore.setState({ isPlaying: true, crossfadeEnabled: true, crossfadeDuration: 4 })
      renderHook(() => useAudioPlayback())
      const [a, b] = capturedAudioElements
      a.paused = false
      await act(async () => {
        timeUpdate(a, 97, 100)
        b.dispatchEvent(new Event('canplaythrough'))
      })
      expect(usePlayerStore.getState().audioElement).toBe(b)
      expect(processorMock.instance.crossfadeToInactive).toHaveBeenCalledOnce()
      const cancellations = processorMock.instance.cancelCrossfade.mock.calls.length
      act(() => { errorMedia(b) })
      expect(usePlayerStore.getState().isPlaying).toBe(false)
      expect(audioService.setPlayingState).toHaveBeenLastCalledWith(false)
      expect(a.pause).toHaveBeenCalled()
      expect(b.pause).toHaveBeenCalled()
      expect(processorMock.instance.cancelCrossfade.mock.calls.length).toBeGreaterThan(cancellations)
      const pauses = vi.mocked(a.pause).mock.calls.length
      act(() => { vi.advanceTimersByTime(5000) })
      expect(a.pause).toHaveBeenCalledTimes(pauses)
    })

    it('invalidates pending incoming play when the active source errors', async () => {
      seedQueue(0, 2)
      usePlayerStore.setState({ isPlaying: true, crossfadeEnabled: true, crossfadeDuration: 4 })
      let finishPlay!: () => void
      renderHook(() => useAudioPlayback())
      const [a, b] = capturedAudioElements
      vi.mocked(b.play).mockReturnValueOnce(new Promise<void>((resolve) => { finishPlay = resolve }))
      await act(async () => {
        timeUpdate(a, 97, 100)
        b.dispatchEvent(new Event('canplaythrough'))
      })
      expect(b.play).toHaveBeenCalledOnce()
      act(() => { errorMedia(a) })
      await act(async () => { finishPlay() })
      expect(usePlayerStore.getState().audioElement).toBe(a)
      expect(usePlayerStore.getState().currentIndex).toBe(0)
      expect(usePlayerStore.getState().isPlaying).toBe(false)
      expect(processorMock.instance.crossfadeToInactive).not.toHaveBeenCalled()
      expect(processorMock.instance.instantSwap).not.toHaveBeenCalled()
      expect(a.pause).toHaveBeenCalled()
      expect(b.pause).toHaveBeenCalled()
    })

    it.each(['stale', 'inactive'] as const)('ignores an %s error notification', (source) => {
      seedQueue(0, 2)
      usePlayerStore.setState({ isPlaying: true })
      renderHook(() => useAudioPlayback())
      const [a, b] = capturedAudioElements
      a.paused = false
      const pauses = vi.mocked(a.pause).mock.calls.length
      act(() => {
        if (source === 'inactive') errorMedia(b)
        else a.dispatchEvent(new Event('error'))
      })
      expect(usePlayerStore.getState().isPlaying).toBe(true)
      expect(a.pause).toHaveBeenCalledTimes(pauses)
      expect(audioService.setPlayingState).not.toHaveBeenCalledWith(false)
    })

    it('ignores owned-source error notifications delivered after teardown', () => {
      seedQueue(0, 2)
      usePlayerStore.setState({ isPlaying: true })
      const { unmount } = renderHook(() => useAudioPlayback())
      const [a] = capturedAudioElements
      unmount()
      const pauses = vi.mocked(a.pause).mock.calls.length
      act(() => { errorMedia(a) })
      expect(usePlayerStore.getState().isPlaying).toBe(true)
      expect(a.pause).toHaveBeenCalledTimes(pauses)
      expect(audioService.setPlayingState).not.toHaveBeenCalledWith(false)
    })
  })

  describe('unsupported original formats', () => {
    const OPUS = 'audio/ogg; codecs=opus'

    /** Original sources stay pending until the test fails them, as the media element does. */
    function holdOriginalPlayback(audio: MockAudioElement) {
      const played: string[] = []
      const rejections: Array<(reason: Error) => void> = []
      vi.mocked(audio.play).mockImplementation(() => {
        played.push(audio.src)
        if (!audio.src.includes('format=')) {
          return new Promise<void>((_, reject) => { rejections.push(reject) })
        }
        audio.paused = false
        return Promise.resolve()
      })
      const failSource = () => {
        errorMedia(audio, 4)
        rejections.splice(0).forEach((reject) => reject(new DOMException('Unsupported source', 'NotSupportedError')))
      }
      return { played, failSource }
    }

    it('requests the original stream for a track whose format the browser plays', async () => {
      const queue = seedQueue(0, 2)
      renderHook(() => useAudioPlayback())
      const [a] = capturedAudioElements
      vi.mocked(a.play).mockImplementation(() => {
        a.paused = false
        return Promise.resolve()
      })
      await act(async () => { usePlayerStore.getState().runtimeActions.playTrack(queue[1]) })
      expect(a.src).toBe('/api/stream/track?id=t1')
      expect(a.canPlayType).not.toHaveBeenCalled()
      expect(usePlayerStore.getState().isPlaying).toBe(true)
      expect(activityService.recordPlay).toHaveBeenCalledOnce()
    })

    type CanPlay = (type: string) => CanPlayTypeResult
    it.each<[string, CanPlay]>([
      ['opus', (type) => type === OPUS ? 'probably' : 'maybe'],
      ['aac', (type) => type === 'audio/aac' ? 'maybe' : ''],
      ['mp3', () => ''],
    ])('requests a transcoded %s stream when the browser cannot play the original format', async (format, canPlay) => {
      const queue = seedQueue(0, 2)
      renderHook(() => useAudioPlayback())
      const [a] = capturedAudioElements
      vi.mocked(a.canPlayType).mockImplementation(canPlay)
      const playback = holdOriginalPlayback(a)
      await act(async () => { usePlayerStore.getState().runtimeActions.playTrack(queue[1]) })
      await act(async () => { playback.failSource() })

      const transcoded = `/api/stream/track?id=t1&format=${format}`
      expect(a.src).toBe(transcoded)
      expect(playback.played.at(-1)).toBe(transcoded)
      expect(usePlayerStore.getState().isPlaying).toBe(true)
      expect(usePlayerStore.getState().currentTrack).toBe(queue[1])
      expect(activityService.recordPlay).toHaveBeenCalledOnce()
      expect(toast.error).not.toHaveBeenCalled()
    })

    it('shows a playback error when the transcoded stream fails as well', async () => {
      const queue = seedQueue(0, 2)
      renderHook(() => useAudioPlayback())
      const [a] = capturedAudioElements
      vi.mocked(a.canPlayType).mockImplementation((type: string) => type === OPUS ? 'probably' : '')
      const playback = holdOriginalPlayback(a)
      await act(async () => { usePlayerStore.getState().runtimeActions.playTrack(queue[1]) })
      await act(async () => { playback.failSource() })
      // The server refuses the transcoded stream, for example with transcoding turned off.
      await act(async () => { errorMedia(a, 4) })

      expect(a.src).toBe('/api/stream/track?id=t1&format=opus')
      expect(usePlayerStore.getState().isPlaying).toBe(false)
      expect(audioService.setPlayingState).toHaveBeenLastCalledWith(false)
      expect(toast.error).toHaveBeenCalledOnce()
      expect(toast.error).toHaveBeenCalledWith('This track could not be played.', expect.objectContaining({
        description: 'The server could not convert it to a format this browser can play.',
      }))
    })

    it('switches a paused restored track to a transcoded stream without starting playback', async () => {
      const queue = [track('t0'), track('t1')]
      renderHook(() => useAudioPlayback())
      const [a] = capturedAudioElements
      vi.mocked(a.canPlayType).mockImplementation((type: string) => type === OPUS ? 'probably' : '')
      act(() => { usePlayerStore.getState().runtimeActions.restoreQueue(queue, 1, 0) })
      expect(a.src).toBe('/api/stream/track?id=t1')
      await act(async () => { errorMedia(a, 4) })

      expect(a.src).toBe('/api/stream/track?id=t1&format=opus')
      expect(a.play).not.toHaveBeenCalled()
      expect(usePlayerStore.getState().isPlaying).toBe(false)
      expect(toast.error).not.toHaveBeenCalled()
    })

    it('stops instead of transcoding when the failed source is not the current track', async () => {
      seedQueue(0, 2)
      renderHook(() => useAudioPlayback())
      const [a] = capturedAudioElements
      a.src = '/api/stream/track?id=unavailable'
      await act(async () => { usePlayerStore.setState({ isPlaying: true }) })
      act(() => { errorMedia(a, 4) })

      expect(a.src).toBe('/api/stream/track?id=unavailable')
      expect(usePlayerStore.getState().isPlaying).toBe(false)
      expect(toast.error).toHaveBeenCalledWith('This track could not be played.', expect.anything())
    })

    it('preloads a transcoded stream when the browser cannot play the next original format', async () => {
      seedQueue(0, 2)
      renderHook(() => useAudioPlayback())
      const [a, b] = capturedAudioElements
      vi.mocked(b.canPlayType).mockImplementation((type: string) => type === 'audio/aac' ? 'maybe' : '')
      await act(async () => { timeUpdate(a, 95, 100) })
      expect(b.src).toBe('/api/stream/track?id=t1')
      act(() => { errorMedia(b, 4) })
      expect(b.src).toBe('/api/stream/track?id=t1&format=aac')
      expect(b.load).toHaveBeenCalledTimes(2)

      b.error = null
      await act(async () => { b.dispatchEvent(new Event('canplaythrough')) })
      await act(async () => { endMedia(a) })
      expect(usePlayerStore.getState().audioElement).toBe(b)
      expect(usePlayerStore.getState().currentTrack?.publicId).toBe('t1')
      expect(processorMock.instance.instantSwap).toHaveBeenCalledOnce()
    })
  })

  describe('interrupted streams', () => {
    const POSITION = 42
    const ORIGINAL = '/api/stream/track?id=t0'
    const TRANSCODED = '/api/stream/track?id=t0&format=opus'

    /**
     * Each probe of the liveness endpoint gets the next answer: an HTTP status, a 200 from a
     * server that is draining, or no answer.
     */
    function answerLiveness(...answers: Array<number | 'draining' | 'unreachable'>) {
      for (const answer of answers) {
        if (answer === 'unreachable') liveness.get.mockRejectedValueOnce(new Error('Network Error'))
        else if (answer === 'draining') liveness.get.mockResolvedValueOnce({ status: 200, data: { status: 'alive', draining: true } })
        else liveness.get.mockResolvedValueOnce({ status: answer, data: { status: 'alive', draining: false } })
      }
    }

    async function elapse(ms: number) {
      await act(async () => { await vi.advanceTimersByTimeAsync(ms) })
    }

    /** The reloaded source reports its duration: finite for a complete file, infinite while progressive. */
    async function loadMetadata(audio: MockAudioElement, duration: number) {
      await act(async () => {
        audio.duration = duration
        audio.dispatchEvent(new Event('loadedmetadata'))
      })
    }

    /** The first queued track plays from `src` and has reached POSITION. */
    async function playAtPosition(src = ORIGINAL, duration = 200) {
      vi.useFakeTimers()
      const queue = seedQueue(0, 2)
      renderHook(() => useAudioPlayback())
      const [a, b] = capturedAudioElements
      vi.mocked(a.play).mockImplementation(() => {
        a.paused = false
        a.ended = false
        return Promise.resolve()
      })
      // A native load clears the error, rewinds, and announces the new load.
      vi.mocked(a.load).mockImplementation(() => {
        a.error = null
        a.paused = true
        a.currentTime = 0
        a.duration = NaN
        a.dispatchEvent(new Event('loadstart'))
      })
      await act(async () => { usePlayerStore.getState().runtimeActions.playTrack(queue[0]) })
      a.src = src
      act(() => {
        playMedia(a)
        a.duration = duration
        a.dispatchEvent(new Event('durationchange'))
        timeUpdate(a, POSITION, duration)
      })
      processorMock.instance.resetProgramme.mockClear()
      return { a, b, queue }
    }

    it('keeps retrying without a failure notice or a transcode while nginx answers 502', async () => {
      const { a } = await playAtPosition()
      answerLiveness(502)
      await act(async () => { errorMedia(a, 4) })

      expect(liveness.get).toHaveBeenCalledWith('/live', expect.anything())
      expect(a.src).toBe(ORIGINAL)
      expect(toast.error).not.toHaveBeenCalled()
      expect(usePlayerStore.getState().isPlaying).toBe(true)
      expect(usePlayerStore.getState().playbackRecovery).toBe('reconnecting')
      expect(getCurrentTime()).toBe(POSITION)
    })

    it('resumes an original stream at its position once the server answers, without a second play record', async () => {
      const { a } = await playAtPosition()
      answerLiveness('unreachable', 503, 200)
      await act(async () => { errorMedia(a, 2) })
      expect(a.load).not.toHaveBeenCalled()

      await elapse(1000)
      expect(liveness.get).toHaveBeenCalledTimes(2)
      expect(a.load).not.toHaveBeenCalled()
      await elapse(2000)
      expect(liveness.get).toHaveBeenCalledTimes(3)
      expect(a.load).toHaveBeenCalledOnce()
      expect(a.src).toBe(ORIGINAL)

      const plays = vi.mocked(a.play).mock.calls.length
      await loadMetadata(a, 200)
      expect(a.currentTime).toBe(POSITION)
      expect(a.play).toHaveBeenCalledTimes(plays + 1)
      expect(usePlayerStore.getState().playbackRecovery).toBe('none')
      expect(usePlayerStore.getState().isPlaying).toBe(true)
      expect(activityService.recordPlay).toHaveBeenCalledOnce()
      expect(processorMock.instance.resetProgramme).not.toHaveBeenCalled()
      expect(toast.error).not.toHaveBeenCalled()
    })

    it('keeps waiting while a draining server still answers, and resumes once it stops draining', async () => {
      const { a } = await playAtPosition()
      answerLiveness('draining', 200)
      await act(async () => { errorMedia(a, 2) })

      expect(liveness.get).toHaveBeenCalledOnce()
      expect(a.load).not.toHaveBeenCalled()
      expect(toast.error).not.toHaveBeenCalled()
      expect(usePlayerStore.getState().isPlaying).toBe(true)
      expect(usePlayerStore.getState().playbackRecovery).toBe('reconnecting')

      await elapse(1000)
      expect(liveness.get).toHaveBeenCalledTimes(2)
      expect(a.load).toHaveBeenCalledOnce()
      expect(a.src).toBe(ORIGINAL)

      await loadMetadata(a, 200)
      expect(a.currentTime).toBe(POSITION)
      expect(usePlayerStore.getState().playbackRecovery).toBe('none')
      expect(usePlayerStore.getState().isPlaying).toBe(true)
      expect(toast.error).not.toHaveBeenCalled()
    })

    it('stays paused while a transcode is progressive and seeks once the complete rendition arrives', async () => {
      const { a } = await playAtPosition(TRANSCODED, Infinity)
      answerLiveness('unreachable', 200)
      await act(async () => { errorMedia(a, 2) })
      await elapse(1000)
      expect(a.load).toHaveBeenCalledOnce()
      expect(a.src).toBe(TRANSCODED)

      const plays = vi.mocked(a.play).mock.calls.length
      await loadMetadata(a, Infinity)
      expect(a.play).toHaveBeenCalledTimes(plays)
      expect(a.currentTime).toBe(0)
      expect(usePlayerStore.getState().playbackRecovery).toBe('reconnecting')

      await elapse(1000)
      expect(a.load).toHaveBeenCalledTimes(2)
      await loadMetadata(a, 200)
      expect(a.currentTime).toBe(POSITION)
      expect(a.play).toHaveBeenCalledTimes(plays + 1)
      expect(usePlayerStore.getState().playbackRecovery).toBe('none')
      expect(toast.error).not.toHaveBeenCalled()
    })

    it('switches to a transcoded stream when the server answers an unsupported original', async () => {
      const { a } = await playAtPosition()
      vi.mocked(a.canPlayType).mockImplementation((type: string) => type === 'audio/ogg; codecs=opus' ? 'probably' : '')
      await act(async () => { errorMedia(a, 4) })

      expect(liveness.get).toHaveBeenCalledOnce()
      expect(a.src).toBe(TRANSCODED)
      expect(usePlayerStore.getState().playbackRecovery).toBe('none')
      expect(toast.error).not.toHaveBeenCalled()
    })

    it('enters the retry when playback stalls without an error for longer than the stall timeout', async () => {
      const { a } = await playAtPosition()
      act(() => { a.dispatchEvent(new Event('stalled')) })
      act(() => { timeUpdate(a, POSITION + 1, 200) })
      await elapse(20_000)
      expect(liveness.get).not.toHaveBeenCalled()

      act(() => { a.dispatchEvent(new Event('waiting')) })
      await elapse(9_000)
      expect(liveness.get).not.toHaveBeenCalled()
      await elapse(1_000)
      expect(liveness.get).toHaveBeenCalledOnce()
      expect(a.pause).toHaveBeenCalled()
      // The server answers, so the stalled stream is requested again at its position.
      expect(a.load).toHaveBeenCalledOnce()
      await loadMetadata(a, 200)
      expect(a.currentTime).toBe(POSITION + 1)
    })

    it('requests the source again when the resumed playback silently never starts', async () => {
      const { a } = await playAtPosition()
      answerLiveness('unreachable', 200, 200)
      await act(async () => { errorMedia(a, 2) })
      await elapse(1000)
      expect(a.load).toHaveBeenCalledOnce()
      vi.mocked(a.play).mockReturnValueOnce(new Promise<void>(() => {}))
      await loadMetadata(a, 200)
      await elapse(9_000)
      expect(a.load).toHaveBeenCalledOnce()

      await elapse(1_000)
      expect(liveness.get).toHaveBeenCalledTimes(3)
      expect(a.load).toHaveBeenCalledTimes(2)
      await loadMetadata(a, 200)
      expect(a.currentTime).toBe(POSITION)
      expect(usePlayerStore.getState().playbackRecovery).toBe('none')
    })

    it('waits at least the Retry-After of a busy encoder before the next attempt', async () => {
      const { a } = await playAtPosition(TRANSCODED, Infinity)
      answerLiveness('unreachable', 200, 200)
      await act(async () => { errorMedia(a, 2) })
      await elapse(1000)
      expect(a.load).toHaveBeenCalledOnce()

      // The service worker passes on the 503 transcode_busy answer the media element cannot read.
      noteStreamRetryAfter(TRANSCODED, 5)
      await act(async () => { errorMedia(a, 4) })
      await elapse(4_900)
      expect(a.load).toHaveBeenCalledOnce()
      await elapse(100)
      expect(a.load).toHaveBeenCalledTimes(2)
      expect(toast.error).not.toHaveBeenCalled()
    })

    it('moves the saved position when the listener seeks during the retry', async () => {
      const { a } = await playAtPosition()
      answerLiveness('unreachable', 200)
      await act(async () => { errorMedia(a, 2) })
      act(() => { usePlayerStore.getState().runtimeActions.seekTo(90) })
      expect(getCurrentTime()).toBe(90)
      expect(toast.info).not.toHaveBeenCalled()

      await elapse(1000)
      await loadMetadata(a, 200)
      expect(a.currentTime).toBe(90)
    })

    it('cancels the retry when the listener picks the next track', async () => {
      const { a, queue } = await playAtPosition()
      answerLiveness('unreachable')
      await act(async () => { errorMedia(a, 2) })
      await act(async () => { usePlayerStore.getState().runtimeActions.playNext() })
      expect(usePlayerStore.getState().currentTrack).toBe(queue[1])
      expect(usePlayerStore.getState().playbackRecovery).toBe('none')

      const probes = liveness.get.mock.calls.length
      const loads = vi.mocked(a.load).mock.calls.length
      await elapse(130_000)
      expect(liveness.get).toHaveBeenCalledTimes(probes)
      expect(a.load).toHaveBeenCalledTimes(loads)
      expect(a.src).toBe('/api/stream/track?id=t1')
      expect(toast.error).not.toHaveBeenCalled()
    })

    it('does not resume on its own after a pause, and pressing play tries at once from the saved position', async () => {
      const { a } = await playAtPosition()
      answerLiveness('unreachable')
      await act(async () => { errorMedia(a, 2) })
      act(() => { usePlayerStore.getState().runtimeActions.setIsPlaying(false) })
      expect(usePlayerStore.getState().playbackRecovery).toBe('none')

      await elapse(60_000)
      expect(liveness.get).toHaveBeenCalledOnce()
      expect(a.load).not.toHaveBeenCalled()
      expect(toast.error).not.toHaveBeenCalled()

      const plays = vi.mocked(a.play).mock.calls.length
      act(() => { usePlayerStore.getState().runtimeActions.setIsPlaying(true) })
      expect(a.load).toHaveBeenCalledOnce()
      expect(usePlayerStore.getState().playbackRecovery).toBe('reconnecting')
      await loadMetadata(a, 200)
      expect(a.currentTime).toBe(POSITION)
      expect(a.play).toHaveBeenCalledTimes(plays + 1)
      expect(activityService.recordPlay).toHaveBeenCalledOnce()
    })

    it('gives up after about two minutes, keeping the position so play tries again', async () => {
      const { a } = await playAtPosition()
      liveness.get.mockRejectedValue(new Error('Network Error'))
      await act(async () => { errorMedia(a, 2) })
      await elapse(119_000)
      expect(usePlayerStore.getState().isPlaying).toBe(true)
      expect(toast.error).not.toHaveBeenCalled()

      await elapse(1_000)
      expect(usePlayerStore.getState().isPlaying).toBe(false)
      expect(usePlayerStore.getState().playbackRecovery).toBe('none')
      expect(toast.error).toHaveBeenCalledExactlyOnceWith('This track could not be played.', expect.anything())
      expect(getCurrentTime()).toBe(POSITION)

      act(() => { usePlayerStore.getState().runtimeActions.setIsPlaying(true) })
      expect(a.load).toHaveBeenCalledOnce()
      await loadMetadata(a, 200)
      expect(a.currentTime).toBe(POSITION)
    })

    it('gives up the same way when the transcode is still progressive two minutes after the interruption', async () => {
      const { a } = await playAtPosition(TRANSCODED, Infinity)
      answerLiveness('unreachable')
      vi.mocked(a.load).mockImplementation(() => {
        a.error = null
        a.duration = Infinity
        a.dispatchEvent(new Event('loadedmetadata'))
      })
      await act(async () => { errorMedia(a, 2) })
      await elapse(119_000)
      expect(usePlayerStore.getState().isPlaying).toBe(true)
      expect(vi.mocked(a.load).mock.calls.length).toBeGreaterThan(1)

      await elapse(1_000)
      expect(usePlayerStore.getState().isPlaying).toBe(false)
      expect(toast.error).toHaveBeenCalledOnce()
      expect(getCurrentTime()).toBe(POSITION)
    })

    it('never shows or syncs position 0 while the stream recovers', async () => {
      const { a } = await playAtPosition()
      const seen: number[] = []
      const stop = subscribeToTime(() => seen.push(getCurrentTime()))
      answerLiveness('unreachable', 200)
      await act(async () => { errorMedia(a, 2) })
      // WebKit rewinds a failed element.
      act(() => { timeUpdate(a, 0, NaN) })
      await elapse(1000)
      act(() => { timeUpdate(a, 0, 200) })
      expect(getCurrentTime()).toBe(POSITION)

      await loadMetadata(a, 200)
      act(() => { timeUpdate(a, POSITION + 1, 200) })
      stop()
      expect(getCurrentTime()).toBe(POSITION + 1)
      expect(seen).not.toContain(0)
    })

    it('resumes repeat-one at its position when Firefox reports the cut and then the end of its buffer', async () => {
      const { a } = await playAtPosition()
      act(() => { usePlayerStore.getState().setRepeat('one') })
      answerLiveness('unreachable', 200)
      await act(async () => { errorMedia(a, 2) })
      await act(async () => { endMedia(a) })
      expect(a.currentTime).not.toBe(0)

      await elapse(1000)
      await loadMetadata(a, 200)
      expect(a.currentTime).toBe(POSITION)
      expect(activityService.recordPlay).toHaveBeenCalledOnce()
      expect(processorMock.instance.resetProgramme).not.toHaveBeenCalled()
    })

    it('ignores an ended notification from a load that already reported an error', async () => {
      const { a, queue } = await playAtPosition()
      await act(async () => { errorMedia(a, 3) })
      expect(toast.error).toHaveBeenCalledOnce()
      const plays = vi.mocked(a.play).mock.calls.length
      await act(async () => { endMedia(a) })

      expect(usePlayerStore.getState().currentTrack).toBe(queue[0])
      expect(a.src).toBe(ORIGINAL)
      expect(a.play).toHaveBeenCalledTimes(plays)
    })

    it('keeps the active track when the next-track preload fails during the outage', async () => {
      const { a, b, queue } = await playAtPosition()
      act(() => { timeUpdate(a, 195, 200) })
      expect(b.src).toBe('/api/stream/track?id=t1')
      await act(async () => { errorMedia(b, 4) })
      await act(async () => { errorMedia(b, 4) })

      expect(usePlayerStore.getState().audioElement).toBe(a)
      expect(usePlayerStore.getState().currentTrack).toBe(queue[0])
      expect(liveness.get).not.toHaveBeenCalled()
      expect(toast.error).not.toHaveBeenCalled()
    })

    it('asks for a tap when the browser refuses to resume without a gesture', async () => {
      const { a } = await playAtPosition()
      answerLiveness('unreachable', 200)
      await act(async () => { errorMedia(a, 2) })
      await elapse(1000)
      vi.mocked(a.play).mockRejectedValueOnce(new DOMException('play() needs a user gesture', 'NotAllowedError'))
      await loadMetadata(a, 200)
      expect(usePlayerStore.getState().isPlaying).toBe(false)
      expect(usePlayerStore.getState().playbackRecovery).toBe('tap-to-resume')
      expect(toast.error).not.toHaveBeenCalled()

      const plays = vi.mocked(a.play).mock.calls.length
      act(() => { usePlayerStore.getState().runtimeActions.setIsPlaying(true) })
      // Called synchronously, so the browser still sees the listener's gesture.
      expect(a.play).toHaveBeenCalledTimes(plays + 1)
      await elapse(0)
      expect(usePlayerStore.getState().isPlaying).toBe(true)
      expect(usePlayerStore.getState().playbackRecovery).toBe('none')
      expect(a.currentTime).toBe(POSITION)
    })

    it('records a play that the outage interrupted before it started', async () => {
      vi.useFakeTimers()
      const queue = seedQueue(0, 2)
      renderHook(() => useAudioPlayback())
      const [a] = capturedAudioElements
      let rejectPlay!: (error: Error) => void
      vi.mocked(a.play).mockReturnValueOnce(new Promise<void>((_, reject) => { rejectPlay = reject }))
      await act(async () => { usePlayerStore.getState().runtimeActions.playTrack(queue[1]) })
      answerLiveness('unreachable', 200)
      await act(async () => {
        errorMedia(a, 4)
        rejectPlay(new DOMException('Failed to load', 'NotSupportedError'))
      })
      expect(usePlayerStore.getState().isPlaying).toBe(true)
      expect(usePlayerStore.getState().playbackRecovery).toBe('reconnecting')

      vi.mocked(a.play).mockImplementation(() => {
        a.paused = false
        return Promise.resolve()
      })
      await elapse(1000)
      expect(a.load).toHaveBeenCalledOnce()
      await loadMetadata(a, 200)
      expect(activityService.recordPlay).toHaveBeenCalledExactlyOnceWith({ songId: 't1', albumId: undefined })
    })
  })

  describe('isPlaying sync', () => {
    it.each([['resolve', 'selection'], ['reject', 'selection'], ['resolve', 'replay'], ['reject', 'replay']] as const)('ignores deferred context %s after newer same-track %s', async (outcome, transition) => {
      const queue = seedQueue(0, 2)
      let finishResume!: () => void
      vi.mocked(audioService.resumeContextIfNeeded).mockReturnValueOnce(new Promise<void>((resolve, reject) => {
        finishResume = () => outcome === 'resolve' ? resolve() : reject(new Error('Obsolete context resume failed'))
      }))
      renderHook(() => useAudioPlayback())
      const [a] = capturedAudioElements
      a.src = '/api/stream/track?id=t0'
      act(() => { usePlayerStore.getState().runtimeActions.setIsPlaying(true) })
      expect(audioService.resumeContextIfNeeded).toHaveBeenCalledOnce()
      expect(a.play).not.toHaveBeenCalled()
      vi.mocked(a.play).mockImplementation(() => {
        a.paused = false
        a.ended = false
        return Promise.resolve()
      })
      await act(async () => {
        if (transition === 'selection') {
          usePlayerStore.getState().runtimeActions.playTrack(queue[1])
          usePlayerStore.getState().runtimeActions.playTrack(queue[0])
        } else usePlayerStore.getState().runtimeActions.replayCurrentTrack()
      })
      const plays = vi.mocked(a.play).mock.calls.length
      await act(async () => { finishResume() })
      expect(usePlayerStore.getState().currentTrack).toBe(queue[0])
      expect(a.src).toBe('/api/stream/track?id=t0')
      expect(usePlayerStore.getState().isPlaying).toBe(true)
      expect(a.paused).toBe(false)
      expect(a.play).toHaveBeenCalledTimes(plays)
    })

    it.each(['selection', 'replay'] as const)('ignores deferred native play rejection after newer %s returns to the same track', async (transition) => {
      const queue = seedQueue(0, 2)
      let rejectPlay!: (error: Error) => void
      renderHook(() => useAudioPlayback())
      const [a] = capturedAudioElements
      a.src = '/api/stream/track?id=t0'
      vi.mocked(a.play).mockReturnValueOnce(new Promise<void>((_, reject) => { rejectPlay = reject }))
      await act(async () => { usePlayerStore.getState().runtimeActions.setIsPlaying(true) })
      expect(a.play).toHaveBeenCalledOnce()
      vi.mocked(a.play).mockImplementation(() => {
        a.paused = false
        a.ended = false
        return Promise.resolve()
      })
      await act(async () => {
        if (transition === 'selection') {
          usePlayerStore.getState().runtimeActions.playTrack(queue[1])
          usePlayerStore.getState().runtimeActions.playTrack(queue[0])
        } else usePlayerStore.getState().runtimeActions.replayCurrentTrack()
      })
      await act(async () => { rejectPlay(new Error('Obsolete native resume failed')) })
      expect(usePlayerStore.getState().currentTrack).toBe(queue[0])
      expect(a.src).toBe('/api/stream/track?id=t0')
      expect(usePlayerStore.getState().isPlaying).toBe(true)
      expect(a.paused).toBe(false)
    })

    it.each(['context', 'play'] as const)('stops playback when the current owner %s resume rejects', async (failure) => {
      seedQueue(0, 2)
      renderHook(() => useAudioPlayback())
      const [a] = capturedAudioElements
      a.src = '/api/stream/track?id=t0'
      if (failure === 'context') vi.mocked(audioService.resumeContextIfNeeded).mockRejectedValueOnce(new Error('Current context resume failed'))
      else vi.mocked(a.play).mockRejectedValueOnce(new Error('Current native playback failed'))
      await act(async () => { usePlayerStore.getState().runtimeActions.setIsPlaying(true) })
      expect(usePlayerStore.getState().isPlaying).toBe(false)
      expect(a.pause).toHaveBeenCalled()
    })

    it('pauses the active element when isPlaying flips to false', async () => {
      seedQueue(0, 1)
      usePlayerStore.setState({ isPlaying: true })
      renderHook(() => useAudioPlayback())
      const [a] = capturedAudioElements
      ;a.paused = false // pretend it's playing
      ;a.src = 'https://baander.app/audio.mp3' // effect guards on !audio.src

      await act(async () => {
        usePlayerStore.setState({ isPlaying: false })
      })

      expect(a.pause).toHaveBeenCalled()
    })

    it('resumes the active element when isPlaying flips to true and it is paused', async () => {
      seedQueue(0, 1)
      usePlayerStore.setState({ isPlaying: false })
      renderHook(() => useAudioPlayback())
      const [a] = capturedAudioElements
      ;a.src = '/api/stream/track?id=t0'
      ;a.paused = true

      await act(async () => {
        usePlayerStore.setState({ isPlaying: true })
      })

      // play() called after async context resume.
      expect(a.play).toHaveBeenCalled()
    })
  })
})
