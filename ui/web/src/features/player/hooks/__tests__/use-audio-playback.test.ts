import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { renderHook, act, cleanup } from '@testing-library/react'
import { reapplyAllEqState } from '@/features/equalizer/stores/eq-reapply'
import { audioService } from '@/features/player/services/audio-service'

// --- Mocks must be hoisted before the hook imports them ---------------------

// Fake AudioProcessor: tracks active source and records swap/crossfade calls.
// getActiveSource() starts at 'A' (matches real AudioProcessor), and the swap
// methods flip it so subsequent preloads target the newly-inactive element.
const processorMock = vi.hoisted(() => {
  let active: 'A' | 'B' = 'A'
  return {
    instance: {
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
    getProcessor: vi.fn(() => processorMock.instance),
    destroy: vi.fn(),
  },
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
    preload: 'auto' as string,
    currentTime: 0,
    duration: NaN,
    volume: 1,
    muted: false,
    paused: true,
    ended: false,
    seeking: false,
    crossOrigin: '',
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
  return el as unknown as MockAudioElement
}

// Capture the audio elements the hook creates via `new Audio()` so tests can
// drive events on them. A and B in creation order.
let capturedAudioElements: MockAudioElement[] = []
let RealAudio: typeof Audio | undefined

import { useAudioPlayback } from '../use-audio-playback'
import { usePlayerStore, type Track, type PlayerState } from '@/features/player/stores/player-store'

// --- Helpers ----------------------------------------------------------------

function track(publicId: string): Track {
  return { publicId, title: publicId, artistName: 'A' }
}

function resetStore(overrides: Partial<PlayerState> = {}) {
  usePlayerStore.setState({
    queue: [],
    currentIndex: -1,
    currentTrack: null,
    isPlaying: false,
    currentTime: 0,
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

// --- Suite ------------------------------------------------------------------

describe('useAudioPlayback', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(audioService.resumeContextIfNeeded).mockResolvedValue(undefined)
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
    vi.useRealTimers()
    if (RealAudio) globalThis.Audio = RealAudio
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
    active.src = '/api/stream/track?id=t0'
    act(() => { active.dispatchEvent(new Event('loadstart')) })
    expect(audioService.connectDualAudioElements).toHaveBeenCalledWith(active, capturedAudioElements[3])
    act(() => { active.dispatchEvent(new Event('play')) })
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
        a.dispatchEvent(new Event('play'))
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
        a.dispatchEvent(new Event('ended'))
      })
      expect(processorMock.instance.resetProgramme).not.toHaveBeenCalled()
      expect(processorMock.instance.instantSwap).not.toHaveBeenCalled()
    })
  })

  describe('ended event', () => {
    it('restarts the current track when repeat === "one"', () => {
      const q = seedQueue(0, 2)
      usePlayerStore.setState({ repeat: 'one', currentTrack: q[0] })
      const { result } = renderHook(() => useAudioPlayback())
      const [a] = capturedAudioElements
      ;a.currentTime = 42

      act(() => {
        a.dispatchEvent(new Event('ended'))
      })

      expect(a.currentTime).toBe(0)
      expect(a.play).toHaveBeenCalled()
      expect(processorMock.instance.resetProgramme).toHaveBeenCalledOnce()
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
        a.dispatchEvent(new Event('ended'))
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
      expect(b.volume).toBe(0.75)
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

    it('falls back to plain playNext when preload never completed (idle)', () => {
      seedQueue(0, 2)
      renderHook(() => useAudioPlayback())
      const [a] = capturedAudioElements

      // No timeupdate fired → preloadState still 'idle'.
      act(() => {
        a.dispatchEvent(new Event('ended'))
      })

      expect(processorMock.instance.instantSwap).not.toHaveBeenCalled()
      expect(processorMock.instance.crossfadeToInactive).not.toHaveBeenCalled()
      // playNext still advances the queue.
      expect(usePlayerStore.getState().currentIndex).toBe(1)
    })

    it('falls back to playNext when preloadState is preloading but not yet ready', () => {
      seedQueue(0, 2)
      renderHook(() => useAudioPlayback())
      const [a] = capturedAudioElements

      // Start preload (→ preloading) but do NOT fire canplaythrough.
      act(() => {
        timeUpdate(a, 95, 100)
      })
      // ended before ready → fallback path (processor swap skipped).
      act(() => {
        a.dispatchEvent(new Event('ended'))
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
        a.dispatchEvent(new Event('ended'))
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
        b.dispatchEvent(new Event('ended'))
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
        a.dispatchEvent(new Event('ended'))
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
        a.dispatchEvent(new Event('ended'))
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
        a.dispatchEvent(new Event('ended'))
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

    it('keeps both elements volume and mute synchronized after handoff', async () => {
      seedQueue(0, 2)
      renderHook(() => useAudioPlayback())
      const [a, b] = capturedAudioElements
      await act(async () => {
        timeUpdate(a, 95, 100)
        b.dispatchEvent(new Event('canplaythrough'))
        a.dispatchEvent(new Event('ended'))
      })
      act(() => {
        usePlayerStore.getState().setMuted(true)
        usePlayerStore.getState().setVolume(40)
        usePlayerStore.getState().setMuted(false)
      })
      expect([a.volume, b.volume]).toEqual([0.4, 0.4])
      expect([a.muted, b.muted]).toEqual([false, false])
    })
  })

  // --- store → DOM sync ----------------------------------------------------

  describe('isPlaying sync', () => {
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
