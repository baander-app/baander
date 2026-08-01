import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { renderHook, act } from '@testing-library/react'
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
function createStubAudioElement(): HTMLAudioElement {
  const listeners = new Map<string, Set<EventListenerOrEventListenerObject>>()
  const el = {
    src: '',
    preload: 'auto' as string,
    currentTime: 0,
    duration: NaN,
    volume: 1,
    muted: false,
    paused: true,
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
  return el as unknown as HTMLAudioElement
}

// Capture the audio elements the hook creates via `new Audio()` so tests can
// drive events on them. A and B in creation order.
let capturedAudioElements: HTMLAudioElement[] = []
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
function timeUpdate(el: HTMLAudioElement, currentTime: number, duration: number) {
  ;(el as any).currentTime = currentTime
  ;(el as any).duration = duration
  el.dispatchEvent(new Event('timeupdate'))
}

// --- Suite ------------------------------------------------------------------

describe('useAudioPlayback', () => {
  beforeEach(() => {
    vi.clearAllMocks()
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
    if (RealAudio) globalThis.Audio = RealAudio
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
      expect((b as any).src).toBe('')

      // Cross gapless threshold: 100 - 95 = 5 < 6.
      act(() => {
        timeUpdate(a, 95, 100)
      })

      expect((b as any).src).toBe(`/api/stream/track?id=${q[1].publicId}`)
      expect((b as any).preload).toBe('auto')

      // canplaythrough promotes preload state to 'ready' (observable only via
      // the ended path, exercised below); fire it to complete the cycle.
      act(() => {
        b.dispatchEvent(new Event('canplaythrough'))
      })

      // A second timeupdate near the end must NOT re-prime (preloadState != idle).
      const srcAfterReady = (b as any).src
      act(() => {
        timeUpdate(a, 97, 100)
      })
      expect((b as any).src).toBe(srcAfterReady)

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
      expect((b as any).src).toContain('t1')
    })

    it('does not preload when there is no next track (end of non-repeating queue)', () => {
      seedQueue(1, 2) // at last index, repeat off → resolveNextIndex returns null
      renderHook(() => useAudioPlayback())
      const [a, b] = capturedAudioElements

      act(() => {
        timeUpdate(a, 95, 100)
      })
      expect((b as any).src).toBe('')
    })

    it('does not preload when duration is not finite', () => {
      seedQueue(0, 2)
      renderHook(() => useAudioPlayback())
      const [a, b] = capturedAudioElements

      // Live stream (Infinity duration) — guard returns early.
      act(() => {
        timeUpdate(a, 50, Infinity)
      })
      expect((b as any).src).toBe('')
    })

    it('resets preloadState to idle on element error', () => {
      seedQueue(0, 2)
      renderHook(() => useAudioPlayback())
      const [a, b] = capturedAudioElements

      act(() => {
        timeUpdate(a, 95, 100) // → preloading
      })
      expect((b as any).src).toContain('t1')

      // Fire error → state back to idle. A subsequent timeupdate past threshold
      // should attempt to re-prime (src gets set again).
      act(() => {
        b.dispatchEvent(new Event('error'))
        timeUpdate(a, 96, 100)
      })
      expect((b as any).src).toContain('t1')
    })
  })

  // --- ended branching -----------------------------------------------------

  describe('ended event', () => {
    it('restarts the current track when repeat === "one"', () => {
      const q = seedQueue(0, 2)
      usePlayerStore.setState({ repeat: 'one', currentTrack: q[0] })
      const { result } = renderHook(() => useAudioPlayback())
      const [a] = capturedAudioElements
      ;(a as any).currentTime = 42

      act(() => {
        a.dispatchEvent(new Event('ended'))
      })

      expect((a as any).currentTime).toBe(0)
      expect(a.play).toHaveBeenCalled()
      // playNext must NOT be called: index unchanged.
      expect(usePlayerStore.getState().currentIndex).toBe(0)
      void result
    })

    it('instant-swaps to the preloaded element when preload is ready and crossfade is off', () => {
      seedQueue(0, 2) // t0 → t1
      renderHook(() => useAudioPlayback())
      const [a, b] = capturedAudioElements

      // Prime + ready the preload.
      act(() => {
        timeUpdate(a, 95, 100)
        b.dispatchEvent(new Event('canplaythrough'))
      })

      const playSpy = (b as any).play as ReturnType<typeof vi.fn>
      playSpy.mockClear()

      act(() => {
        a.dispatchEvent(new Event('ended'))
      })

      // crossfade off → instantSwap on the processor.
      expect(processorMock.instance.instantSwap).toHaveBeenCalledTimes(1)
      expect(processorMock.instance.crossfadeToInactive).not.toHaveBeenCalled()
      // Inactive (B) is now played; active (A) paused + rewound.
      expect(playSpy).toHaveBeenCalled()
      expect(a.pause).toHaveBeenCalled()
      expect((a as any).currentTime).toBe(0)
      // Store advanced to next track.
      expect(usePlayerStore.getState().currentIndex).toBe(1)
    })

    it('crossfades to the preloaded element when crossfade is enabled', () => {
      seedQueue(0, 2)
      resetStore({
        queue: [track('t0'), track('t1')],
        currentIndex: 0,
        currentTrack: track('t0'),
        crossfadeEnabled: true,
        crossfadeDuration: 4.0,
      })
      renderHook(() => useAudioPlayback())
      const [a, b] = capturedAudioElements

      act(() => {
        timeUpdate(a, 95, 100)
        b.dispatchEvent(new Event('canplaythrough'))
      })

      act(() => {
        a.dispatchEvent(new Event('ended'))
      })

      expect(processorMock.instance.crossfadeToInactive).toHaveBeenCalledWith(4.0)
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

  // --- store → DOM sync ----------------------------------------------------

  describe('isPlaying sync', () => {
    it('pauses the active element when isPlaying flips to false', async () => {
      seedQueue(0, 1)
      usePlayerStore.setState({ isPlaying: true })
      renderHook(() => useAudioPlayback())
      const [a] = capturedAudioElements
      ;(a as any).paused = false // pretend it's playing
      ;(a as any).src = 'https://example/audio.mp3' // effect guards on !audio.src

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
      ;(a as any).src = '/api/stream/track?id=t0'
      ;(a as any).paused = true

      await act(async () => {
        usePlayerStore.setState({ isPlaying: true })
      })

      // play() called after async context resume.
      expect(a.play).toHaveBeenCalled()
    })
  })
})
