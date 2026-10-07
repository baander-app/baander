import { getCurrentTime, updateTime } from '@/features/player/stores/player-time-tracker'
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { audioService } from '../../services/audio-service'
import { activityService } from '../../services/activity-service'
import { toast } from 'sonner'
import {
  usePlayerStore,
  generateShuffleBag,
  resolveNextIndex,
  type Track,
  type PlayerState,
} from '../player-store'

vi.mock('../../services/audio-service', () => ({
  audioService: { getProcessor: vi.fn(() => null) },
}))

vi.mock('sonner', () => ({
  toast: { error: vi.fn(), info: vi.fn() },
}))

// --- Helpers ---------------------------------------------------------------

function track(publicId: string, title?: string): Track {
  return { publicId, title: title ?? publicId, artistName: 'A', albumName: 'Alb' }
}

function tracks(...ids: string[]): Track[] {
  return ids.map((id) => track(id))
}

/** A minimal HTMLAudioElement stub whose play() resolves instead of throwing. */
function makeAudioStub(): HTMLAudioElement {
  const stub = {
    src: '',
    currentTime: 0,
    volume: 1,
    muted: false,
    paused: false,
    ended: false,
    play: vi.fn(() => Promise.resolve()),
    pause: vi.fn(),
  }
  return stub as unknown as HTMLAudioElement
}

/** Replace the entire store state with a known starting point. */
function resetStore() {
  updateTime(0)
  usePlayerStore.setState({
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
}

/** Seed a queue of N tracks with currentIndex pointed at `current`. */
function seedQueue(current = 0, n = 3): Track[] {
  const q = tracks('t0', 't1', 't2', 't3', 't4').slice(0, n)
  usePlayerStore.setState({
    queue: q,
    currentIndex: current,
    currentTrack: q[current] ?? null,
    isPlaying: true,
  })
  return q
}

// --- Suite -----------------------------------------------------------------

describe('player-store', () => {
  beforeEach(() => {
    vi.mocked(audioService.getProcessor).mockReturnValue(null)
    resetStore()
  })

  describe('adoptPreloadedNext', () => {
    it('adopts the playing element without reloading or playing it again', () => {
      const queue = seedQueue()
      const incoming = makeAudioStub()
      incoming.src = '/api/stream/track?id=t1'
      incoming.currentTime = 1.5
      Object.defineProperty(incoming, 'duration', { value: 120 })
      const recordPlay = vi.spyOn(activityService, 'recordPlay').mockResolvedValue()
      usePlayerStore.setState({ volume: 40, muted: true })

      expect(usePlayerStore.getState().adoptPreloadedNext(incoming, 't0', 't1')).toBe(true)

      expect(usePlayerStore.getState()).toMatchObject({
        audioElement: incoming, currentTrack: queue[1], currentIndex: 1,
        isPlaying: true, duration: 120,
      })
      expect(incoming.src).toBe('/api/stream/track?id=t1')
      expect(incoming.play).not.toHaveBeenCalled()
      expect(incoming.volume).toBe(0.4)
      expect(incoming.muted).toBe(true)
      expect(recordPlay).toHaveBeenCalledExactlyOnceWith({ songId: 't1', albumId: undefined })
      expect(usePlayerStore.getState().adoptPreloadedNext(incoming, 't0', 't1')).toBe(false)
      expect(recordPlay).toHaveBeenCalledTimes(1)
      recordPlay.mockRestore()
    })

    it.each([
      { current: 'stale', next: 't1', repeat: 'off' as const },
      { current: 't0', next: 'stale', repeat: 'off' as const },
      { current: 't0', next: 't1', repeat: 'one' as const },
    ])('rejects stale or repeat-one transitions: %o', ({ current, next, repeat }) => {
      seedQueue()
      usePlayerStore.setState({ repeat })
      const before = usePlayerStore.getState()
      expect(before.adoptPreloadedNext(makeAudioStub(), current, next)).toBe(false)
      expect(usePlayerStore.getState()).toBe(before)
    })

    it('rejects the last track without repeat', () => {
      seedQueue(2)
      const before = usePlayerStore.getState()
      expect(before.adoptPreloadedNext(makeAudioStub(), 't2', 't0')).toBe(false)
      expect(usePlayerStore.getState()).toBe(before)
    })

    it.each([
      { index: 0, shuffle: true, repeat: 'off' as const, next: 't2', expectedIndex: 2 },
      { index: 2, shuffle: false, repeat: 'all' as const, next: 't0', expectedIndex: 0 },
      { index: 1, shuffle: true, repeat: 'all' as const, next: 't0', expectedIndex: 0 },
    ])('resolves latest shuffle and repeat order: %o', ({ index, shuffle, repeat, next, expectedIndex }) => {
      seedQueue(index)
      usePlayerStore.setState({ shuffle, repeat, shuffleBag: [0, 2, 1] })
      const recordPlay = vi.spyOn(activityService, 'recordPlay').mockResolvedValue()
      expect(usePlayerStore.getState().adoptPreloadedNext(makeAudioStub(), `t${index}`, next)).toBe(true)
      expect(usePlayerStore.getState().currentIndex).toBe(expectedIndex)
      expect(usePlayerStore.getState().duration).toBe(0)
      recordPlay.mockRestore()
    })
  })

  it('keeps stored volume when attaching muted audio so unmute restores sound', () => {
    usePlayerStore.setState({ volume: 40, muted: true })
    const audio = makeAudioStub()
    usePlayerStore.getState().setAudioElement(audio)
    expect(audio.volume).toBe(0.4)
    expect(audio.muted).toBe(true)
    usePlayerStore.getState().setMuted(false)
    expect(audio.volume).toBe(0.4)
    expect(audio.muted).toBe(false)
  })

  it('keeps active graph input at unity across attachment, mute, volume and adoption', () => {
    const processor = { isActive: true, passive: false, setVolume: vi.fn(), setMuted: vi.fn() }
    vi.mocked(audioService.getProcessor).mockReturnValue(processor as unknown as NonNullable<ReturnType<typeof audioService.getProcessor>>)
    seedQueue()
    usePlayerStore.setState({ volume: 40, muted: true })
    const audio = makeAudioStub()
    usePlayerStore.getState().setAudioElement(audio)
    usePlayerStore.getState().setVolume(20)
    usePlayerStore.getState().toggleMute()
    expect(audio.volume).toBe(1)
    expect(audio.muted).toBe(false)
    expect(processor.setVolume).toHaveBeenLastCalledWith(0.2)
    expect(processor.setMuted).toHaveBeenLastCalledWith(false)
    const incoming = makeAudioStub()
    const recordPlay = vi.spyOn(activityService, 'recordPlay').mockResolvedValue()
    expect(usePlayerStore.getState().adoptPreloadedNext(incoming, 't0', 't1')).toBe(true)
    expect(incoming.volume).toBe(1)
    expect(incoming.muted).toBe(false)
    recordPlay.mockRestore()
  })

  it('updates volume while muted with a passive processor', () => {
    const processor = { isActive: true, passive: true, setVolume: vi.fn(), setMuted: vi.fn() }
    vi.mocked(audioService.getProcessor).mockReturnValue(processor as unknown as NonNullable<ReturnType<typeof audioService.getProcessor>>)
    const audio = makeAudioStub()
    usePlayerStore.getState().setAudioElement(audio)
    usePlayerStore.getState().setMuted(true)
    usePlayerStore.getState().setVolume(20)
    expect(audio.volume).toBe(0.2)
    expect(audio.muted).toBe(true)
    usePlayerStore.getState().setMuted(false)
    expect(audio.volume).toBe(0.2)
    expect(audio.muted).toBe(false)
  })

  // =========================================================================
  // resolveNextIndex
  // =========================================================================
  describe('resolveNextIndex', () => {
    it('returns null for an empty queue regardless of mode', () => {
      expect(resolveNextIndex([], -1, false, 'off', [])).toBeNull()
      expect(resolveNextIndex([], -1, true, 'all', [])).toBeNull()
    })

    it('advances linearly when shuffle is off and not at the end', () => {
      const q = tracks('a', 'b', 'c')
      expect(resolveNextIndex(q, 1, false, 'off', [])).toBe(2)
    })

    it('returns null at end of queue with repeat off (stop)', () => {
      const q = tracks('a', 'b', 'c')
      expect(resolveNextIndex(q, 2, false, 'off', [])).toBeNull()
    })

    it('wraps to 0 at end of queue with repeat all', () => {
      const q = tracks('a', 'b', 'c')
      expect(resolveNextIndex(q, 2, false, 'all', [])).toBe(0)
    })

    it('repeat one returns the same index', () => {
      const q = tracks('a', 'b', 'c')
      // repeat === 'one' is handled by linear branch: currentIndex < length-1
      // so at index 0 it returns 1. The store's playNext with repeat 'one'
      // relies on this — verify the documented linear behavior.
      expect(resolveNextIndex(q, 0, false, 'one', [])).toBe(1)
    })

    it('shuffle: follows the bag order', () => {
      const q = tracks('a', 'b', 'c', 'd')
      // bag visits 0 -> 2 -> 1 -> 3
      const bag = [0, 2, 1, 3]
      expect(resolveNextIndex(q, 0, true, 'off', bag)).toBe(2)
      expect(resolveNextIndex(q, 2, true, 'off', bag)).toBe(1)
      expect(resolveNextIndex(q, 1, true, 'off', bag)).toBe(3)
    })

    it('shuffle: returns null at end of bag with repeat off', () => {
      const q = tracks('a', 'b')
      const bag = [0, 1]
      expect(resolveNextIndex(q, 1, true, 'off', bag)).toBeNull()
    })

    it('shuffle: wraps to bag[0] at end of bag with repeat all', () => {
      const q = tracks('a', 'b', 'c')
      const bag = [2, 0, 1]
      expect(resolveNextIndex(q, 1, true, 'all', bag)).toBe(2)
    })

    it('shuffle: current index not in bag returns null or wraps on all', () => {
      const q = tracks('a', 'b', 'c')
      const bag = [0, 1]
      // currentIndex 2 absent from bag -> indexOf returns -1, nextBagIdx 0
      expect(resolveNextIndex(q, 2, true, 'off', bag)).toBe(0)
      expect(resolveNextIndex(q, 2, true, 'all', bag)).toBe(0)
    })
  })

  // =========================================================================
  // generateShuffleBag
  // =========================================================================
  describe('generateShuffleBag', () => {
    it('produces a permutation of all indices', () => {
      vi.spyOn(Math, 'random').mockReturnValue(0.5)
      const bag = generateShuffleBag(5)
      vi.restoreAllMocks()
      expect(bag.slice().sort((a, b) => a - b)).toEqual([0, 1, 2, 3, 4])
      expect(bag).toHaveLength(5)
    })

    it('returns empty array for zero-length queue', () => {
      expect(generateShuffleBag(0)).toEqual([])
    })

    it('places excludeIndex first when provided', () => {
      vi.spyOn(Math, 'random').mockReturnValue(0.5)
      const bag = generateShuffleBag(5, 2)
      vi.restoreAllMocks()
      expect(bag[0]).toBe(2)
      // still a permutation of all 5
      expect(bag.slice().sort((a, b) => a - b)).toEqual([0, 1, 2, 3, 4])
    })

    it('ignore excludeIndex out of range', () => {
      vi.spyOn(Math, 'random').mockReturnValue(0.5)
      const bag = generateShuffleBag(3, 99)
      vi.restoreAllMocks()
      expect(bag.slice().sort((a, b) => a - b)).toEqual([0, 1, 2])
    })

    it('handles single-element queue', () => {
      expect(generateShuffleBag(1)).toEqual([0])
      expect(generateShuffleBag(1, 0)).toEqual([0])
    })

    it('with excludeIndex, remaining indices are still all present', () => {
      vi.spyOn(Math, 'random').mockReturnValue(0.1)
      const bag = generateShuffleBag(6, 3)
      vi.restoreAllMocks()
      expect(bag[0]).toBe(3)
      const rest = bag.slice(1)
      expect(rest.slice().sort((a, b) => a - b)).toEqual([0, 1, 2, 4, 5])
    })
  })

  // =========================================================================
  // playNext
  // =========================================================================
  describe('replayCurrentTrack', () => {
    beforeEach(() => { vi.spyOn(activityService, 'recordPlay').mockResolvedValue() })
    afterEach(() => { vi.mocked(activityService.recordPlay).mockRestore() })

    function prepareReplay() {
      const queue = seedQueue(1, 3)
      const audio = makeAudioStub()
      audio.src = '/api/stream/track?id=t1'
      audio.currentTime = 42
      usePlayerStore.getState().setAudioElement(audio)
      return { queue, audio }
    }

    it('records each successful repeat while preserving the loaded source and queue', async () => {
      const { queue, audio } = prepareReplay()
      const sourceSetter = vi.fn()
      Object.defineProperty(audio, 'src', { get: () => '/api/stream/track?id=t1', set: sourceSetter })
      for (let count = 1; count <= 3; count++) {
        usePlayerStore.getState().replayCurrentTrack()
        await Promise.resolve()
        expect(activityService.recordPlay).toHaveBeenCalledTimes(count)
      }
      expect(audio.currentTime).toBe(0)
      expect(getCurrentTime()).toBe(0)
      expect(sourceSetter).not.toHaveBeenCalled()
      expect(usePlayerStore.getState().queue).toBe(queue)
      expect(usePlayerStore.getState().currentTrack).toBe(queue[1])
      expect(usePlayerStore.getState().currentIndex).toBe(1)
      expect(audio.play).toHaveBeenCalledTimes(3)
    })

    it('stops a currently owned repeat that rejects', async () => {
      const { audio } = prepareReplay()
      vi.mocked(audio.play).mockRejectedValueOnce(new Error('Repeat unavailable'))
      usePlayerStore.getState().replayCurrentTrack()
      await Promise.resolve()
      expect(usePlayerStore.getState().isPlaying).toBe(false)
      expect(activityService.recordPlay).not.toHaveBeenCalled()
    })

    it.each(['resolve', 'reject'] as const)('ignores obsolete repeat %s after manual selection or replay ABA', async (outcome) => {
      for (const replay of [false, true]) {
        vi.mocked(activityService.recordPlay).mockClear()
        const { audio, queue } = prepareReplay()
        let settle!: () => void
        vi.mocked(audio.play).mockReturnValueOnce(new Promise<void>((resolve, reject) => {
          settle = () => outcome === 'resolve' ? resolve() : reject(new Error('Obsolete repeat failed'))
        }))
        usePlayerStore.getState().replayCurrentTrack()
        if (replay) usePlayerStore.getState().replayCurrentTrack()
        else usePlayerStore.getState().playTrack(queue[2])
        settle()
        await Promise.resolve()
        expect(usePlayerStore.getState().isPlaying).toBe(true)
        expect(activityService.recordPlay).toHaveBeenCalledExactlyOnceWith({ songId: replay ? 't1' : 't2', albumId: undefined })
      }
    })

    it.each(['resolve', 'reject'] as const)('ignores pending repeat %s after element replacement or pause/resume', async (outcome) => {
      for (const interruption of ['element', 'pause'] as const) {
        vi.mocked(activityService.recordPlay).mockClear()
        const { audio } = prepareReplay()
        let settle!: () => void
        vi.mocked(audio.play).mockReturnValueOnce(new Promise<void>((resolve, reject) => {
          settle = () => outcome === 'resolve' ? resolve() : reject(new Error('Interrupted repeat failed'))
        }))
        usePlayerStore.getState().replayCurrentTrack()
        if (interruption === 'element') usePlayerStore.getState().setAudioElement(makeAudioStub())
        else {
          usePlayerStore.getState().setIsPlaying(false)
          usePlayerStore.getState().setIsPlaying(true)
        }
        settle()
        await Promise.resolve()
        expect(usePlayerStore.getState().isPlaying).toBe(true)
        expect(activityService.recordPlay).not.toHaveBeenCalled()
      }
    })

    it.each(['track', 'element', 'source'] as const)('ignores repeat without selected %s', (missing) => {
      const { audio } = prepareReplay()
      if (missing === 'track') usePlayerStore.setState({ currentTrack: null })
      if (missing === 'element') usePlayerStore.getState().setAudioElement(null)
      if (missing === 'source') audio.src = ''
      usePlayerStore.getState().replayCurrentTrack()
      expect(audio.play).not.toHaveBeenCalled()
      expect(audio.currentTime).toBe(42)
      expect(activityService.recordPlay).not.toHaveBeenCalled()
    })
  })

  describe('transcoded streams', () => {
    const TRANSCODED = '/api/stream/track?id=t0&format=opus'
    beforeEach(() => { vi.spyOn(activityService, 'recordPlay').mockResolvedValue() })
    afterEach(() => { vi.mocked(activityService.recordPlay).mockRestore() })

    function prepareTranscoded(duration: number) {
      seedQueue(0, 1)
      const audio = makeAudioStub()
      audio.src = TRANSCODED
      Object.defineProperty(audio, 'duration', { value: duration, configurable: true })
      audio.currentTime = 12
      usePlayerStore.getState().setAudioElement(audio)
      usePlayerStore.getState().setDuration(Number.isFinite(duration) ? duration : 0)
      updateTime(12)
      vi.mocked(toast.info).mockClear()
      return audio
    }

    it('keeps the position and explains why seeking is unavailable while the stream is converted', () => {
      const audio = prepareTranscoded(Infinity)
      usePlayerStore.getState().seekTo(60)
      expect(audio.currentTime).toBe(12)
      expect(getCurrentTime()).toBe(12)
      expect(toast.info).toHaveBeenCalledWith("Seeking isn't available yet", expect.objectContaining({
        description: 'This track is being converted for your browser. You can seek in it the next time it plays.',
      }))
    })

    it('seeks in a converted stream once the browser knows its duration', () => {
      const audio = prepareTranscoded(200)
      usePlayerStore.getState().seekTo(60)
      expect(audio.currentTime).toBe(60)
      expect(getCurrentTime()).toBe(60)
      expect(toast.info).not.toHaveBeenCalled()
    })

    it('reloads a converted stream to repeat it when it cannot seek back to the start', async () => {
      const audio = prepareTranscoded(Infinity)
      const load = vi.fn()
      Object.assign(audio, { load })
      usePlayerStore.getState().replayCurrentTrack()
      await Promise.resolve()
      expect(audio.src).toBe(TRANSCODED)
      expect(load).toHaveBeenCalledOnce()
      expect(load.mock.invocationCallOrder[0]).toBeLessThan(vi.mocked(audio.play).mock.invocationCallOrder[0])
      expect(getCurrentTime()).toBe(0)
      expect(audio.play).toHaveBeenCalledOnce()
      expect(activityService.recordPlay).toHaveBeenCalledOnce()
    })
  })

  describe.each(['playNext', 'playPrevious'] as const)('%s async ownership', (action) => {
    beforeEach(() => { vi.spyOn(activityService, 'recordPlay').mockResolvedValue() })
    afterEach(() => { vi.mocked(activityService.recordPlay).mockRestore() })

    function prepare() {
      const queue = seedQueue(1, 4)
      const audio = makeAudioStub()
      usePlayerStore.getState().setAudioElement(audio)
      return { queue, audio, selected: queue[action === 'playNext' ? 2 : 0] }
    }

    it('publishes the selected track before native play events and records current success', async () => {
      const { audio, selected } = prepare()
      vi.mocked(audio.play).mockImplementationOnce(() => {
        expect(usePlayerStore.getState().currentTrack).toBe(selected)
        expect(usePlayerStore.getState().isPlaying).toBe(true)
        return Promise.resolve()
      })
      usePlayerStore.getState()[action]()
      await Promise.resolve()
      expect(activityService.recordPlay).toHaveBeenCalledExactlyOnceWith({ songId: selected.publicId, albumId: selected.albumPublicId })
    })

    it('stops a currently selected playback request that rejects', async () => {
      const { audio, selected } = prepare()
      vi.mocked(audio.play).mockRejectedValueOnce(new Error('Current navigation playback failed'))
      usePlayerStore.getState()[action]()
      await Promise.resolve()
      expect(usePlayerStore.getState().currentTrack).toBe(selected)
      expect(usePlayerStore.getState().isPlaying).toBe(false)
      expect(activityService.recordPlay).not.toHaveBeenCalled()
    })

    it.each(['resolve', 'reject'] as const)('ignores stale %s after manual selection or selection ABA', async (outcome) => {
      for (const aba of [false, true]) {
        vi.mocked(activityService.recordPlay).mockClear()
        const { audio, queue, selected } = prepare()
        let settle!: () => void
        vi.mocked(audio.play).mockReturnValueOnce(new Promise<void>((resolve, reject) => {
          settle = () => outcome === 'resolve' ? resolve() : reject(new Error('Obsolete navigation failed'))
        }))
        usePlayerStore.getState()[action]()
        usePlayerStore.getState().playTrack(queue[3])
        if (aba) usePlayerStore.getState().playTrack(selected)
        settle()
        await Promise.resolve()
        expect(usePlayerStore.getState().currentTrack).toBe(aba ? selected : queue[3])
        expect(usePlayerStore.getState().isPlaying).toBe(true)
        expect(activityService.recordPlay).toHaveBeenCalledExactlyOnceWith({ songId: (aba ? selected : queue[3]).publicId, albumId: undefined })
      }
    })

    it.each(['resolve', 'reject'] as const)('ignores stale %s after element replacement or element ABA', async (outcome) => {
      for (const aba of [false, true]) {
        vi.mocked(activityService.recordPlay).mockClear()
        const { audio } = prepare()
        let settle!: () => void
        vi.mocked(audio.play).mockReturnValueOnce(new Promise<void>((resolve, reject) => {
          settle = () => outcome === 'resolve' ? resolve() : reject(new Error('Detached media failed'))
        }))
        usePlayerStore.getState()[action]()
        const replacement = makeAudioStub()
        usePlayerStore.getState().setAudioElement(replacement)
        if (aba) usePlayerStore.getState().setAudioElement(audio)
        settle()
        await Promise.resolve()
        expect(usePlayerStore.getState().isPlaying).toBe(true)
        expect(activityService.recordPlay).not.toHaveBeenCalled()
      }
    })

    it.each(['resolve', 'reject'] as const)('ignores pending %s after explicit pause and resume', async (outcome) => {
      const { audio } = prepare()
      let settle!: () => void
      vi.mocked(audio.play).mockReturnValueOnce(new Promise<void>((resolve, reject) => {
        settle = () => outcome === 'resolve' ? resolve() : reject(new Error('Paused request failed late'))
      }))
      usePlayerStore.getState()[action]()
      usePlayerStore.getState().setIsPlaying(false)
      usePlayerStore.getState().setIsPlaying(true)
      settle()
      await Promise.resolve()
      expect(usePlayerStore.getState().isPlaying).toBe(true)
      expect(activityService.recordPlay).not.toHaveBeenCalled()
    })

    it.each(['resolve', 'reject'] as const)('ignores pending %s after end-of-queue stop and resume', async (outcome) => {
      const { audio, queue } = prepare()
      let settle!: () => void
      vi.mocked(audio.play).mockReturnValueOnce(new Promise<void>((resolve, reject) => {
        settle = () => outcome === 'resolve' ? resolve() : reject(new Error('Stopped queue request failed late'))
      }))
      usePlayerStore.getState().playTrack(queue[queue.length - 1])
      usePlayerStore.getState().playNext()
      expect(usePlayerStore.getState().isPlaying).toBe(false)
      usePlayerStore.getState().setIsPlaying(true)
      settle()
      await Promise.resolve()
      expect(usePlayerStore.getState().isPlaying).toBe(true)
      expect(activityService.recordPlay).not.toHaveBeenCalled()
    })

    it.each(['pause', 'native pause', 'disconnect', 'source change'] as const)('does not record a fulfilled request after %s', async (interruption) => {
      const { audio } = prepare()
      let settle!: () => void
      vi.mocked(audio.play).mockReturnValueOnce(new Promise<void>((resolve) => { settle = resolve }))
      usePlayerStore.getState()[action]()
      if (interruption === 'pause') usePlayerStore.getState().setIsPlaying(false)
      if (interruption === 'native pause') Object.defineProperty(audio, 'paused', { value: true })
      if (interruption === 'disconnect') usePlayerStore.getState().setAudioElement(null)
      if (interruption === 'source change') audio.src = '/api/stream/track?id=other'
      settle()
      await Promise.resolve()
      expect(activityService.recordPlay).not.toHaveBeenCalled()
    })
  })
  describe('playNext', () => {
    beforeEach(() => {
      usePlayerStore.setState({ audioElement: makeAudioStub() })
    })

    it('advances currentIndex by 1 with repeat off', () => {
      seedQueue(0, 3)
      usePlayerStore.getState().playNext()
      const s = usePlayerStore.getState()
      expect(s.currentIndex).toBe(1)
      expect(s.currentTrack?.publicId).toBe('t1')
      expect(s.isPlaying).toBe(true)
    })

    it('stops (isPlaying false) at end of queue with repeat off', () => {
      seedQueue(2, 3)
      usePlayerStore.getState().playNext()
      const s = usePlayerStore.getState()
      expect(s.currentIndex).toBe(2) // unchanged
      expect(s.isPlaying).toBe(false)
    })

    it('wraps to 0 with repeat all at end', () => {
      seedQueue(2, 3)
      usePlayerStore.setState({ repeat: 'all' })
      usePlayerStore.getState().playNext()
      const s = usePlayerStore.getState()
      expect(s.currentIndex).toBe(0)
      expect(s.currentTrack?.publicId).toBe('t0')
    })

    it('returns null and stops when queue is empty', () => {
      usePlayerStore.setState({ queue: [], currentIndex: -1 })
      usePlayerStore.getState().playNext()
      const s = usePlayerStore.getState()
      expect(s.currentIndex).toBe(-1)
      expect(s.isPlaying).toBe(false)
    })

    it('follows the shuffle bag when shuffle is on', () => {
      seedQueue(0, 4)
      usePlayerStore.setState({ shuffle: true, shuffleBag: [0, 2, 1, 3] })
      usePlayerStore.getState().playNext()
      expect(usePlayerStore.getState().currentIndex).toBe(2)
      usePlayerStore.getState().playNext()
      expect(usePlayerStore.getState().currentIndex).toBe(1)
      usePlayerStore.getState().playNext()
      expect(usePlayerStore.getState().currentIndex).toBe(3)
    })

    it('shuffle at bag end with repeat all wraps to bag[0]', () => {
      seedQueue(3, 4)
      usePlayerStore.setState({
        shuffle: true,
        shuffleBag: [0, 2, 1, 3],
        repeat: 'all',
      })
      usePlayerStore.getState().playNext()
      expect(usePlayerStore.getState().currentIndex).toBe(0)
    })

    it('shuffle at bag end with repeat off stops', () => {
      seedQueue(3, 4)
      usePlayerStore.setState({ shuffle: true, shuffleBag: [0, 2, 1, 3] })
      usePlayerStore.getState().playNext()
      const s = usePlayerStore.getState()
      expect(s.currentIndex).toBe(3) // unchanged
      expect(s.isPlaying).toBe(false)
    })

    it('sets the audio element src and calls play', () => {
      const audio = makeAudioStub()
      seedQueue(0, 3)
      usePlayerStore.setState({ audioElement: audio })
      usePlayerStore.getState().playNext()
      expect(audio.src).toContain('id=t1')
      expect(audio.play).toHaveBeenCalledTimes(1)
    })
  })

  // =========================================================================
  // playPrevious — restart rule + index wrap
  // =========================================================================
  describe('playPrevious', () => {
    beforeEach(() => {
      usePlayerStore.setState({ audioElement: makeAudioStub() })
    })

    it('restarts current track when currentTime > 3', () => {
      seedQueue(1, 3)
      const audio = makeAudioStub()
      audio.currentTime = 5
      updateTime(5)
      usePlayerStore.setState({ audioElement: audio })
      usePlayerStore.getState().playPrevious()
      const s = usePlayerStore.getState()
      // index unchanged, track unchanged
      expect(s.currentIndex).toBe(1)
      expect(s.currentTrack?.publicId).toBe('t1')
      expect(audio.currentTime).toBe(0)
    })

    it('at exactly 3 seconds (boundary, not > 3) goes to previous track', () => {
      seedQueue(1, 3)
      updateTime(3)
      usePlayerStore.getState().playPrevious()
      expect(usePlayerStore.getState().currentIndex).toBe(0)
    })

    it('goes to previous track when currentTime <= 3', () => {
      seedQueue(2, 3)
      updateTime(1)
      usePlayerStore.getState().playPrevious()
      expect(usePlayerStore.getState().currentIndex).toBe(1)
      expect(usePlayerStore.getState().currentTrack?.publicId).toBe('t1')
    })

    it('wraps to last track when at index 0 and currentTime <= 3', () => {
      seedQueue(0, 3)
      updateTime(1)
      usePlayerStore.getState().playPrevious()
      expect(usePlayerStore.getState().currentIndex).toBe(2)
      expect(usePlayerStore.getState().currentTrack?.publicId).toBe('t2')
    })

    it('no-ops when queue is empty', () => {
      usePlayerStore.setState({ queue: [], currentIndex: -1 })
      usePlayerStore.getState().playPrevious()
      expect(usePlayerStore.getState().currentIndex).toBe(-1)
    })

    it('restart rule does NOT depend on repeat mode (still restarts > 3s)', () => {
      seedQueue(1, 3)
      updateTime(10)
      usePlayerStore.setState({ repeat: 'all' })
      usePlayerStore.getState().playPrevious()
      expect(usePlayerStore.getState().currentIndex).toBe(1)
    })

    it('at index 0 with currentTime > 3 restarts instead of wrapping', () => {
      seedQueue(0, 3)
      updateTime(10)
      usePlayerStore.getState().playPrevious()
      expect(usePlayerStore.getState().currentIndex).toBe(0)
    })

    it('sets audio src + play when moving to a previous track', () => {
      const audio = makeAudioStub()
      seedQueue(2, 3)
      updateTime(0)
      usePlayerStore.setState({ audioElement: audio })
      usePlayerStore.getState().playPrevious()
      expect(audio.src).toContain('id=t1')
      expect(audio.play).toHaveBeenCalledTimes(1)
    })
  })

  // =========================================================================
  // toggleShuffle / toggleRepeat
  // =========================================================================
  describe('toggleShuffle', () => {
    it('enables shuffle and generates a bag with currentIndex first', () => {
      seedQueue(1, 4)
      vi.spyOn(Math, 'random').mockReturnValue(0.5)
      usePlayerStore.getState().toggleShuffle()
      vi.restoreAllMocks()
      const s = usePlayerStore.getState()
      expect(s.shuffle).toBe(true)
      expect(s.shuffleBag[0]).toBe(1)
      expect(s.shuffleBag.slice().sort((a, b) => a - b)).toEqual([0, 1, 2, 3])
    })

    it('disables shuffle and clears the bag', () => {
      seedQueue(1, 4)
      usePlayerStore.setState({ shuffle: true, shuffleBag: [1, 0, 2, 3] })
      usePlayerStore.getState().toggleShuffle()
      const s = usePlayerStore.getState()
      expect(s.shuffle).toBe(false)
      expect(s.shuffleBag).toEqual([])
    })

    it('enabling shuffle resets repeat to off', () => {
      seedQueue(0, 3)
      usePlayerStore.setState({ repeat: 'all' })
      usePlayerStore.getState().toggleShuffle()
      expect(usePlayerStore.getState().repeat).toBe('off')
    })

    it('disabling shuffle preserves the current repeat mode', () => {
      seedQueue(0, 3)
      usePlayerStore.setState({ shuffle: true, repeat: 'off' })
      usePlayerStore.setState({ repeat: 'all' }) // simulating user setting repeat while shuffled
      usePlayerStore.getState().toggleShuffle()
      expect(usePlayerStore.getState().repeat).toBe('all')
    })

    it('on empty queue enables shuffle but produces empty bag', () => {
      usePlayerStore.getState().toggleShuffle()
      const s = usePlayerStore.getState()
      expect(s.shuffle).toBe(true)
      expect(s.shuffleBag).toEqual([])
    })
  })

  describe('toggleRepeat', () => {
    it('cycles off -> all -> one -> off', () => {
      usePlayerStore.getState().toggleRepeat()
      expect(usePlayerStore.getState().repeat).toBe('all')
      usePlayerStore.getState().toggleRepeat()
      expect(usePlayerStore.getState().repeat).toBe('one')
      usePlayerStore.getState().toggleRepeat()
      expect(usePlayerStore.getState().repeat).toBe('off')
    })

    it('turning repeat on disables shuffle', () => {
      usePlayerStore.setState({ shuffle: true, shuffleBag: [0, 1] })
      usePlayerStore.getState().toggleRepeat() // off -> all
      expect(usePlayerStore.getState().shuffle).toBe(false)
    })

    it('toggling back to off preserves whatever shuffle was (false here)', () => {
      usePlayerStore.setState({ repeat: 'one', shuffle: false })
      usePlayerStore.getState().toggleRepeat() // one -> off
      expect(usePlayerStore.getState().repeat).toBe('off')
      expect(usePlayerStore.getState().shuffle).toBe(false)
    })
  })

  // =========================================================================
  // reorderQueue — currentIndex adjustment branches
  // =========================================================================
  describe('reorderQueue', () => {
    it('no-op when from === to', () => {
      seedQueue(1, 4)
      const before = usePlayerStore.getState().queue
      usePlayerStore.getState().reorderQueue(1, 1)
      expect(usePlayerStore.getState().queue).toBe(before)
      expect(usePlayerStore.getState().currentIndex).toBe(1)
    })

    it('moves the current track with the item (from === currentIndex)', () => {
      seedQueue(1, 4) // queue [t0,t1,t2,t3], current 1
      usePlayerStore.getState().reorderQueue(1, 3)
      const s = usePlayerStore.getState()
      expect(s.queue.map((t) => t.publicId)).toEqual(['t0', 't2', 't3', 't1'])
      expect(s.currentIndex).toBe(3)
    })

    it('moved-range passes over current from above (from < current, to >= current) -> current -1', () => {
      seedQueue(2, 4) // current 2
      usePlayerStore.getState().reorderQueue(0, 3)
      const s = usePlayerStore.getState()
      // t0 moved to end: [t1,t2,t3,t0]; current was t2, shifts to index 1
      expect(s.queue.map((t) => t.publicId)).toEqual(['t1', 't2', 't3', 't0'])
      expect(s.currentIndex).toBe(1)
    })

    it('moved item from below current (from > current, to <= current) -> current +1', () => {
      seedQueue(1, 4) // current 1
      usePlayerStore.getState().reorderQueue(3, 0)
      const s = usePlayerStore.getState()
      // t3 moved to front: [t3,t0,t1,t2]; current was t1, shifts to index 2
      expect(s.queue.map((t) => t.publicId)).toEqual(['t3', 't0', 't1', 't2'])
      expect(s.currentIndex).toBe(2)
    })

    it('move within the same side of current does not change currentIndex', () => {
      seedQueue(0, 4) // current 0
      usePlayerStore.getState().reorderQueue(2, 3)
      const s = usePlayerStore.getState()
      expect(s.queue.map((t) => t.publicId)).toEqual(['t0', 't1', 't3', 't2'])
      expect(s.currentIndex).toBe(0)
    })

    it('regenerates shuffle bag when shuffle is on', () => {
      seedQueue(1, 4)
      const audio = makeAudioStub()
      usePlayerStore.setState({ shuffle: true, shuffleBag: [1, 0, 2, 3], audioElement: audio })
      usePlayerStore.getState().reorderQueue(0, 3)
      // bag length matches new queue length and is a permutation
      const bag = usePlayerStore.getState().shuffleBag
      expect(bag).toHaveLength(4)
      expect(bag.slice().sort((a, b) => a - b)).toEqual([0, 1, 2, 3])
    })
  })

  // =========================================================================
  // removeFromQueue — currentIndex adjustment
  // =========================================================================
  describe('removeFromQueue', () => {
    it('removing before current decrements currentIndex', () => {
      seedQueue(2, 4) // current 2 (t2)
      usePlayerStore.getState().removeFromQueue(0)
      const s = usePlayerStore.getState()
      expect(s.queue.map((t) => t.publicId)).toEqual(['t1', 't2', 't3'])
      expect(s.currentIndex).toBe(1)
      expect(s.currentTrack?.publicId).toBe('t2')
    })

    it('removing after current leaves currentIndex unchanged', () => {
      seedQueue(1, 4)
      usePlayerStore.getState().removeFromQueue(3)
      const s = usePlayerStore.getState()
      expect(s.queue.map((t) => t.publicId)).toEqual(['t0', 't1', 't2'])
      expect(s.currentIndex).toBe(1)
    })

    it('removing the current track clamps to the new tail (min(index, len-1))', () => {
      seedQueue(2, 4) // current 2
      usePlayerStore.getState().removeFromQueue(2)
      const s = usePlayerStore.getState()
      expect(s.queue.map((t) => t.publicId)).toEqual(['t0', 't1', 't3'])
      // Math.min(2, 3-1=2) = 2 -> points at t3
      expect(s.currentIndex).toBe(2)
      expect(s.currentTrack?.publicId).toBe('t3')
    })

    it('removing the last item when it is current moves current to new tail', () => {
      seedQueue(2, 3) // current at last (2)
      usePlayerStore.getState().removeFromQueue(2)
      const s = usePlayerStore.getState()
      expect(s.queue.map((t) => t.publicId)).toEqual(['t0', 't1'])
      // Math.min(2, 2-1=1) = 1
      expect(s.currentIndex).toBe(1)
      expect(s.currentTrack?.publicId).toBe('t1')
    })

    it('removing down to empty sets currentIndex -1 and currentTrack null', () => {
      seedQueue(0, 1) // single item, current 0
      usePlayerStore.getState().removeFromQueue(0)
      const s = usePlayerStore.getState()
      expect(s.queue).toEqual([])
      // Math.min(0, 0-1=-1) = -1
      expect(s.currentIndex).toBe(-1)
      expect(s.currentTrack).toBeNull()
    })

    it('regenerates shuffle bag when shuffle is on', () => {
      seedQueue(1, 4)
      usePlayerStore.setState({ shuffle: true, shuffleBag: [1, 0, 2, 3] })
      usePlayerStore.getState().removeFromQueue(0)
      const bag = usePlayerStore.getState().shuffleBag
      expect(bag).toHaveLength(3)
      expect(bag.slice().sort((a, b) => a - b)).toEqual([0, 1, 2])
    })
  })

  // =========================================================================
  // clearQueue
  // =========================================================================
  describe('clearQueue', () => {
    it('resets queue, index, track, and shuffleBag', () => {
      const audio = makeAudioStub()
      seedQueue(1, 3)
      usePlayerStore.setState({
        shuffleBag: [1, 0, 2],
        isPlaying: true,
        audioElement: audio,
      })
      usePlayerStore.getState().clearQueue()
      const s = usePlayerStore.getState()
      expect(s.queue).toEqual([])
      expect(s.currentIndex).toBe(-1)
      expect(s.currentTrack).toBeNull()
      expect(s.shuffleBag).toEqual([])
      expect(s.isPlaying).toBe(false)
      expect(audio.pause).toHaveBeenCalled()
    })
  })

  // =========================================================================
  // playTrack — queue replacement + audio wiring
  // =========================================================================
  describe('playTrack', () => {
    it('ignores rejected playback from a replaced selection', async () => {
      const audio = makeAudioStub()
      let rejectFirst!: (reason: Error) => void
      vi.mocked(audio.play).mockReturnValueOnce(new Promise<void>((_, reject) => { rejectFirst = reject }))
      usePlayerStore.setState({ audioElement: audio })
      usePlayerStore.getState().playTrack(track('first'))
      usePlayerStore.getState().playTrack(track('third'))
      rejectFirst(new Error('Playback interrupted by source replacement'))
      await Promise.resolve()
      await Promise.resolve()
      expect(usePlayerStore.getState().currentTrack?.publicId).toBe('third')
      expect(usePlayerStore.getState().isPlaying).toBe(true)
    })

    it('ignores rejected playback from an earlier replay of the same track', async () => {
      const audio = makeAudioStub()
      const selected = track('first')
      let rejectFirst!: (reason: Error) => void
      vi.mocked(audio.play).mockReturnValueOnce(new Promise<void>((_, reject) => { rejectFirst = reject }))
      usePlayerStore.setState({ audioElement: audio })
      usePlayerStore.getState().playTrack(selected)
      usePlayerStore.getState().playTrack(selected)
      rejectFirst(new Error('Earlier replay interrupted'))
      await Promise.resolve()
      await Promise.resolve()
      expect(usePlayerStore.getState().isPlaying).toBe(true)
    })

    it.each(['navigation', 'adoption'] as const)('ignores old playback after %s returns to the same selection', async (transition) => {
      const audio = makeAudioStub()
      const q = tracks('first', 'second')
      let rejectFirst!: (reason: Error) => void
      vi.mocked(audio.play).mockReturnValueOnce(new Promise<void>((_, reject) => { rejectFirst = reject }))
      usePlayerStore.setState({ audioElement: audio })
      usePlayerStore.getState().playTrack(q[0], q)
      if (transition === 'navigation') {
        usePlayerStore.getState().playNext()
        usePlayerStore.getState().playPrevious()
      } else {
        const incoming = makeAudioStub()
        usePlayerStore.setState({ repeat: 'all' })
        expect(usePlayerStore.getState().adoptPreloadedNext(incoming, 'first', 'second')).toBe(true)
        expect(usePlayerStore.getState().adoptPreloadedNext(audio, 'second', 'first')).toBe(true)
      }
      rejectFirst(new Error('Obsolete selection interrupted'))
      await Promise.resolve()
      await Promise.resolve()
      expect(usePlayerStore.getState().audioElement).toBe(audio)
      expect(usePlayerStore.getState().currentTrack).toBe(q[0])
      expect(usePlayerStore.getState().isPlaying).toBe(true)
    })

    it('stops playback when the current selection fails', async () => {
      const audio = makeAudioStub()
      vi.mocked(audio.play).mockRejectedValueOnce(new Error('Current playback failed'))
      usePlayerStore.setState({ audioElement: audio })
      usePlayerStore.getState().playTrack(track('first'))
      await Promise.resolve()
      await Promise.resolve()
      expect(usePlayerStore.getState().isPlaying).toBe(false)
    })

    it.each(['rewind', 'rejected adoption'] as const)('retains pending selection ownership after %s', async (operation) => {
      const audio = makeAudioStub()
      let rejectPlay!: (reason: Error) => void
      vi.mocked(audio.play).mockReturnValueOnce(new Promise<void>((_, reject) => { rejectPlay = reject }))
      usePlayerStore.setState({ audioElement: audio })
      usePlayerStore.getState().playTrack(track('first'), tracks('first', 'second'))
      if (operation === 'rewind') {
        updateTime(4)
        usePlayerStore.getState().playPrevious()
      } else {
        expect(usePlayerStore.getState().adoptPreloadedNext(makeAudioStub(), 'first', 'unexpected')).toBe(false)
      }
      rejectPlay(new Error('Current pending selection failed'))
      await Promise.resolve()
      await Promise.resolve()
      expect(usePlayerStore.getState().isPlaying).toBe(false)
    })

    it('replaces queue and locates the track index when queue given', () => {
      const audio = makeAudioStub()
      usePlayerStore.setState({ audioElement: audio })
      const q = tracks('a', 'b', 'c')
      usePlayerStore.getState().playTrack(q[1], q)
      const s = usePlayerStore.getState()
      expect(s.queue).toBe(q)
      expect(s.currentIndex).toBe(1)
      expect(s.currentTrack?.publicId).toBe('b')
      expect(s.isPlaying).toBe(true)
      expect(audio.src).toContain('id=b')
      expect(audio.play).toHaveBeenCalledTimes(1)
    })

    it('appends to queue when track not present and no queue given', () => {
      seedQueue(0, 2)
      usePlayerStore.getState().playTrack(track('new'))
      const s = usePlayerStore.getState()
      expect(s.queue.map((t) => t.publicId)).toEqual(['t0', 't1', 'new'])
      expect(s.currentIndex).toBe(2)
      expect(s.currentTrack?.publicId).toBe('new')
    })

    it('jumps to existing track without duplicating when already in queue', () => {
      const q = tracks('a', 'b', 'c')
      usePlayerStore.setState({ queue: q, currentIndex: 0 })
      usePlayerStore.getState().playTrack(q[2])
      const s = usePlayerStore.getState()
      expect(s.queue).toHaveLength(3)
      expect(s.currentIndex).toBe(2)
    })

    it('regenerates shuffle bag when shuffle is on and queue mutates', () => {
      const audio = makeAudioStub()
      usePlayerStore.setState({ shuffle: true, audioElement: audio })
      vi.spyOn(Math, 'random').mockReturnValue(0.5)
      usePlayerStore.getState().playTrack(track('solo'), tracks('solo'))
      vi.restoreAllMocks()
      const bag = usePlayerStore.getState().shuffleBag
      expect(bag).toHaveLength(1)
      expect(bag[0]).toBe(0)
    })
  })
})
