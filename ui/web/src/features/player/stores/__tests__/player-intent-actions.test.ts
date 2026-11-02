import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { toast } from 'sonner'
import { registerPlaybackIntentHandler } from '@/features/session/services/playback-execution'
import { usePlayerStore, type Track } from '../player-store'

vi.mock('sonner', () => ({ toast: { error: vi.fn(), info: vi.fn() } }))

const tracks: Track[] = [
  { publicId: 'first', title: 'First', available: true },
  { publicId: 'second', title: 'Second', available: true },
]
let unregister: (() => void) | undefined

beforeEach(() => {
  vi.clearAllMocks()
  usePlayerStore.setState({ queue: tracks, currentIndex: 0, currentTrack: tracks[0], isPlaying: false, volume: 75, muted: false, shuffle: false, repeat: 'off', shuffleBag: [] })
})
afterEach(() => unregister?.())

describe('player user intent', () => {
  it('submits playback without changing the local execution state', () => {
    const handle = vi.fn(async () => {})
    unregister = registerPlaybackIntentHandler(handle)
    usePlayerStore.getState().playTrack(tracks[1], tracks)
    expect(handle).toHaveBeenCalledExactlyOnceWith({ action: 'load', media: { kind: 'song', publicId: 'second' },
      queue: ['first', 'second'], currentTrackIndex: 1, position: 0, desiredState: 'playing' })
    expect(usePlayerStore.getState().currentTrack).toBe(tracks[0])
    expect(usePlayerStore.getState().isPlaying).toBe(false)
  })

  it('reports an unavailable coordinator without starting playback', async () => {
    usePlayerStore.getState().setIsPlaying(true)
    await Promise.resolve()
    expect(toast.error).toHaveBeenCalledWith('Playback request failed', expect.objectContaining({ description: 'Playback connection is unavailable.' }))
    expect(usePlayerStore.getState().isPlaying).toBe(false)
  })

  it('submits queue edits without seeking or reloading the current selection', () => {
    const handle = vi.fn(async () => {})
    unregister = registerPlaybackIntentHandler(handle)
    usePlayerStore.getState().reorderQueue(0, 1)
    expect(handle).toHaveBeenCalledExactlyOnceWith({ action: 'queue', operation: { type: 'reorder', publicId: 'first', occurrence: 0, beforePublicId: null, beforeOccurrence: 0 } })
    expect(usePlayerStore.getState().queue).toBe(tracks)
  })

  it('lets the coordinator remove the current item against the latest queue', () => {
    const handle = vi.fn(async () => {})
    unregister = registerPlaybackIntentHandler(handle)
    usePlayerStore.getState().removeFromQueue(0)
    expect(handle).toHaveBeenCalledWith({ action: 'queue', operation: { type: 'remove', publicId: 'first', occurrence: 0 } })
  })

  it('submits clearing as one ordered operation', () => {
    const handle = vi.fn(async () => {})
    unregister = registerPlaybackIntentHandler(handle)
    usePlayerStore.getState().clearQueue()
    expect(handle).toHaveBeenCalledExactlyOnceWith({ action: 'queue', operation: { type: 'clear' } })
  })

  it('keeps rapid additions as separate operations instead of stale queue replacements', () => {
    const handle = vi.fn(async () => {})
    unregister = registerPlaybackIntentHandler(handle)
    usePlayerStore.getState().addToQueue({ publicId: 'third', title: 'Third', available: true })
    usePlayerStore.getState().addToQueue({ publicId: 'fourth', title: 'Fourth', available: true })
    expect(handle.mock.calls).toEqual([
      [{ action: 'queue', operation: { type: 'append', tracks: ['third'] } }],
      [{ action: 'queue', operation: { type: 'append', tracks: ['fourth'] } }],
    ])
  })

  it('identifies duplicate queue occurrences for removal and insertion', () => {
    const handle = vi.fn(async () => {})
    unregister = registerPlaybackIntentHandler(handle)
    usePlayerStore.setState({ queue: [tracks[0], tracks[1], tracks[0]], currentIndex: 2, currentTrack: tracks[0] })
    usePlayerStore.getState().removeFromQueue(2)
    usePlayerStore.getState().insertAfterCurrent([tracks[1]])
    expect(handle.mock.calls).toEqual([
      [{ action: 'queue', operation: { type: 'remove', publicId: 'first', occurrence: 1 } }],
      [{ action: 'queue', operation: { type: 'insert', tracks: ['second'], afterPublicId: 'first', afterOccurrence: 1 } }],
    ])
  })

  it('does not discard a pause or clear while earlier intents await acknowledgement', () => {
    const handle = vi.fn(() => new Promise<void>(() => {}))
    unregister = registerPlaybackIntentHandler(handle)
    usePlayerStore.setState({ queue: [], currentIndex: -1, currentTrack: null })
    usePlayerStore.getState().setIsPlaying(true)
    usePlayerStore.getState().setIsPlaying(false)
    usePlayerStore.getState().addToQueue(tracks[0])
    usePlayerStore.getState().clearQueue()
    expect(handle.mock.calls).toEqual([
      [{ action: 'play' }], [{ action: 'pause' }],
      [{ action: 'queue', operation: { type: 'append', tracks: ['first'] } }], [{ action: 'queue', operation: { type: 'clear' } }],
    ])
  })

  it('selects a track against the latest queue when no replacement list was requested', () => {
    const handle = vi.fn(async () => {})
    unregister = registerPlaybackIntentHandler(handle)
    usePlayerStore.getState().addToQueue({ publicId: 'third', title: 'Third', available: true })
    usePlayerStore.getState().playTrack(tracks[1])
    expect(handle).toHaveBeenLastCalledWith({ action: 'load', media: { kind: 'song', publicId: 'second' }, position: 0, desiredState: 'playing' })
  })

  it('bounds restored queue selection and position before submitting a paused load', () => {
    const handle = vi.fn(async () => {})
    unregister = registerPlaybackIntentHandler(handle)
    usePlayerStore.getState().restoreQueue(tracks, 50, -4)
    expect(handle).toHaveBeenLastCalledWith({ action: 'load', media: { kind: 'song', publicId: 'second' },
      queue: ['first', 'second'], currentTrackIndex: 1, position: 0, desiredState: 'paused' })
  })

  it('submits normalized volume and delegates navigation to the coordinator', () => {
    const handle = vi.fn(async () => {})
    unregister = registerPlaybackIntentHandler(handle)
    const state = usePlayerStore.getState()
    state.setVolume(40)
    state.playNext()
    state.playPrevious()
    expect(handle.mock.calls).toEqual([[{ action: 'volume', volume: 0.4 }],
      [{ action: 'next', navigation: { shuffle: false, repeat: 'off', shuffleBag: [], unavailablePublicIds: [] } }],
      [{ action: 'previous', navigation: { shuffle: false, repeat: 'off', shuffleBag: [], unavailablePublicIds: [] } }]])
    expect(usePlayerStore.getState().volume).toBe(75)
  })
})
