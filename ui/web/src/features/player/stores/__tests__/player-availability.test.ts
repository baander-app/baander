import { registerPlayerHandlers } from '../player-handlers'
import { mediator } from '@/shared/lib/mediator/bus'
import { RADIO_ACTIONS } from '@/features/radio/radio-actions'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { usePlayerStore, type Track } from '../player-store'
import { getCurrentTime, updateTime } from '../player-time-tracker'
import { getSongIndex } from '@/shared/api-client/gen/endpoints'

vi.mock('@/shared/api-client/gen/endpoints', () => ({ getSongIndex: vi.fn() }))
vi.mock('../../services/audio-service', () => ({ audioService: { getProcessor: () => null } }))

const lookup = vi.mocked(getSongIndex)
const meta = { next_cursor: null, prev_cursor: null, has_next_page: false, has_previous_page: false, total: 0, per_page: 100, stale_cursor: false }

function restore(available?: boolean) {
  const queue: Track[] = [
    { publicId: 'song-1', title: 'Remembered title', albumName: 'Remembered album', available },
    { publicId: 'song-2', title: 'Next song', available },
  ]
  usePlayerStore.getState().restoreQueue(queue, 0, 42)
  return queue
}

async function settle() {
  await Promise.resolve()
  await Promise.resolve()
}

registerPlayerHandlers()

describe('restored music availability', () => {
  beforeEach(() => {
    lookup.mockReset()
    usePlayerStore.getState().clearQueue()
    usePlayerStore.getState().setAudioElement(null)
    updateTime(0)
  })

  it('keeps restored metadata and position while unknown availability is checked before resume', async () => {
    let complete!: (value: Awaited<ReturnType<typeof getSongIndex>>) => void
    lookup.mockReturnValue(new Promise((resolve) => { complete = resolve }))
    const queue = restore()

    usePlayerStore.getState().setIsPlaying(true)
    expect(usePlayerStore.getState().queue).toBe(queue)
    expect(usePlayerStore.getState().currentTrack).toBe(queue[0])
    expect(usePlayerStore.getState().isPlaying).toBe(false)
    expect(getCurrentTime()).toBe(42)

    complete({ meta, data: [{ publicId: 'song-1', available: true }, { publicId: 'song-2', available: false }] })
    await settle()
    expect(usePlayerStore.getState().currentTrack).toEqual({ ...queue[0], available: true })
    expect(usePlayerStore.getState().queue[1]).toEqual({ ...queue[1], available: false })
    expect(usePlayerStore.getState().isPlaying).toBe(true)
    expect(getCurrentTime()).toBe(42)
  })

  it('loads the music stream after an unknown restore clears a real DOM audio source', async () => {
    const audio = document.createElement('audio')
    vi.spyOn(audio, 'pause').mockImplementation(() => {})
    usePlayerStore.getState().setAudioElement(audio)
    restore()
    expect(audio.getAttribute('src')).toBe('')
    expect(audio.src).not.toBe('')
    lookup.mockResolvedValueOnce({ meta, data: [{ publicId: 'song-1', available: true }] })

    usePlayerStore.getState().setIsPlaying(true)
    await settle()
    await settle()

    expect(audio.getAttribute('src')).toBe('/api/stream/track?id=song-1')
    expect(audio.currentTime).toBe(42)
    expect(usePlayerStore.getState().isPlaying).toBe(true)
  })

  it('confirms a different restored queue selection before playing it', async () => {
    const queue = restore()
    lookup.mockResolvedValueOnce({ meta, data: [{ publicId: 'song-1', available: false }, { publicId: 'song-2', available: true }] })
    usePlayerStore.getState().playTrack(queue[1])
    expect(usePlayerStore.getState().currentTrack).toBe(queue[0])
    expect(usePlayerStore.getState().isPlaying).toBe(false)
    await settle()
    await settle()
    expect(usePlayerStore.getState().currentTrack?.publicId).toBe('song-2')
    expect(usePlayerStore.getState().isPlaying).toBe(true)
  })

  it('checks long queues in API-sized batches and preserves every row', async () => {
    const queue = Array.from({ length: 205 }, (_, index) => ({ publicId: `song-${index}`, title: `Song ${index}` }))
    usePlayerStore.getState().restoreQueue(queue, 204, 42)
    lookup.mockImplementation(async (params) => ({
      meta,
      data: (params?.publicIds ?? '').split(',').map((publicId) => ({ publicId, available: true })),
    }))
    usePlayerStore.getState().setIsPlaying(true)
    await settle()
    await settle()
    expect(lookup.mock.calls.map(([params]) => params?.limit)).toEqual([100, 100, 5])
    expect(usePlayerStore.getState().queue).toHaveLength(205)
    expect(usePlayerStore.getState().currentTrack).toEqual({ ...queue[204], available: true })
    expect(usePlayerStore.getState().isPlaying).toBe(true)
  })

  it('preserves the restored selection when the song is unavailable or the lookup fails', async () => {
    const queue = restore()
    lookup.mockResolvedValueOnce({ meta, data: [{ publicId: 'song-1', available: false }] })
    usePlayerStore.getState().setIsPlaying(true)
    await settle()
    expect(usePlayerStore.getState().currentTrack).toEqual({ ...queue[0], available: false })
    expect(usePlayerStore.getState().isPlaying).toBe(false)
    expect(getCurrentTime()).toBe(42)

    const retryQueue = restore()
    lookup.mockRejectedValueOnce(new Error('Disconnected'))
    usePlayerStore.getState().setIsPlaying(true)
    await settle()
    expect(usePlayerStore.getState().queue).toBe(retryQueue)
    expect(usePlayerStore.getState().isPlaying).toBe(false)
    expect(getCurrentTime()).toBe(42)
  })

  it('discards persisted true observations until the API confirms current availability', async () => {
    const queue = restore(true)
    localStorage.setItem('baander-player', JSON.stringify({ state: { queue, currentIndex: 0 }, version: 1 }))
    await usePlayerStore.persist.rehydrate()
    expect(usePlayerStore.getState().currentTrack?.available).toBeUndefined()
    lookup.mockResolvedValueOnce({ meta, data: [{ publicId: 'song-1', available: false }] })
    usePlayerStore.getState().setIsPlaying(true)
    await settle()
    expect(usePlayerStore.getState().currentTrack?.title).toBe('Remembered title')
    expect(usePlayerStore.getState().currentTrack?.available).toBe(false)
    expect(usePlayerStore.getState().isPlaying).toBe(false)
  })

  it('cancels pending music resume when a radio station starts', async () => {
    let complete!: (value: Awaited<ReturnType<typeof getSongIndex>>) => void
    lookup.mockReturnValue(new Promise((resolve) => { complete = resolve }))
    const queue = restore()
    usePlayerStore.getState().setIsPlaying(true)
    mediator.dispatch(RADIO_ACTIONS.STARTED, {}, 'radio')
    complete({ meta, data: [{ publicId: 'song-1', available: true }] })
    await settle()
    await settle()
    expect(usePlayerStore.getState().queue).toBe(queue)
    expect(usePlayerStore.getState().isPlaying).toBe(false)
  })

  it('ignores a late lookup after a newer queue replaces the restored one', async () => {
    let complete!: (value: Awaited<ReturnType<typeof getSongIndex>>) => void
    lookup.mockReturnValue(new Promise((resolve) => { complete = resolve }))
    restore()
    usePlayerStore.getState().setIsPlaying(true)
    const selected = { publicId: 'new-song', title: 'New selection', available: true }
    usePlayerStore.getState().playTrack(selected, [selected])
    complete({ meta, data: [{ publicId: 'song-1', available: true }] })
    await settle()
    expect(usePlayerStore.getState().currentTrack).toBe(selected)
    expect(usePlayerStore.getState().queue).toEqual([selected])
  })
})
