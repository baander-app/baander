import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { renderHook, act } from '@testing-library/react'
import { usePlayerStore } from '../player-store'
import { getCurrentTime, updateTime, useCurrentTime } from '../player-time-tracker'
import { activityService } from '../../services/activity-service'

vi.mock('../../services/audio-service', () => ({ audioService: { getProcessor: () => null } }))
vi.mock('../../services/activity-service', () => ({ activityService: { recordPlay: vi.fn(() => Promise.resolve()) } }))

beforeEach(() => {
  usePlayerStore.setState(usePlayerStore.getInitialState(), true)
  updateTime(0)
})
afterEach(() => { vi.restoreAllMocks() })

const tracks = [{ publicId: 'first', title: 'First' }, { publicId: 'second', title: 'Second' }]

it('clock ticks notify only clock consumers, without player notifications, serialization, or persistence writes', () => {
  usePlayerStore.getState().setVolume(60)
  const notifications = vi.fn()
  const unsubscribe = usePlayerStore.subscribe(notifications)
  const writes = vi.spyOn(Storage.prototype, 'setItem')
  const serialize = vi.spyOn(JSON, 'stringify')
  let playerRenders = 0, clockRenders = 0
  renderHook(() => { playerRenders++; return usePlayerStore(state => state.volume) })
  renderHook(() => { clockRenders++; return useCurrentTime() })
  for (let tick = 1; tick <= 20; tick++) act(() => { updateTime(tick / 4) })
  expect(playerRenders).toBe(1)
  expect(clockRenders).toBe(21)
  expect(getCurrentTime()).toBe(5)
  expect(notifications).not.toHaveBeenCalled()
  expect(writes).not.toHaveBeenCalled()
  expect(serialize).not.toHaveBeenCalled()
  unsubscribe()
})

it('identical values and invalid clock updates do not render or notify', () => {
  const notifications = vi.fn()
  const unsubscribe = usePlayerStore.subscribe(notifications)
  const state = usePlayerStore.getState()
  usePlayerStore.setState({ volume: state.volume, isPlaying: state.isPlaying })
  state.setIsPlaying(false)
  state.setDuration(0)
  state.setVolume(75)
  state.setMuted(false)
  state.setShuffle(false)
  state.setRepeat('off')
  state.setCrossfadeEnabled(false)
  state.setCrossfadeDuration(5)
  state.setAudioElement(null)
  state.applyPreferences({ volume: 75, muted: false })
  state.insertAfterCurrent([])
  state.reorderQueue(-1, 8)
  state.removeFromQueue(-1)
  state.clearQueue()
  let renders = 0
  renderHook(() => { renders++; return useCurrentTime() })
  act(() => { updateTime(0); updateTime(NaN); updateTime(Infinity); updateTime(-1) })
  expect(notifications).not.toHaveBeenCalled()
  expect(renders).toBe(1)
  unsubscribe()
})

it('publishes queue, selected track, and playback state together in one notification', () => {
  const snapshots: { index: number; id: string | undefined; playing: boolean; size: number }[] = []
  const unsubscribe = usePlayerStore.subscribe(state => snapshots.push({ index: state.currentIndex,
    id: state.currentTrack?.publicId, playing: state.isPlaying, size: state.queue.length }))
  usePlayerStore.getState().playTrack(tracks[1], tracks)
  expect(snapshots).toEqual([{ index: 1, id: 'second', playing: true, size: 2 }])
  unsubscribe()
})

it('persists only durable changes and restores a coherent selected track without transient resources', async () => {
  usePlayerStore.getState().playTrack(tracks[1], tracks)
  const writes = vi.spyOn(Storage.prototype, 'setItem')
  usePlayerStore.getState().setDuration(30)
  usePlayerStore.getState().setIsPlaying(false)
  updateTime(12)
  expect(writes).not.toHaveBeenCalled()
  usePlayerStore.getState().applyPreferences({ volume: 35, muted: true })
  expect(writes).toHaveBeenCalledTimes(1)
  const saved = JSON.parse(localStorage.getItem('baander-player')!)
  expect(saved.state).toEqual({ queue: tracks, currentIndex: 1, volume: 35, muted: true,
    shuffle: false, repeat: 'off', crossfadeEnabled: false, crossfadeDuration: 5 })
  usePlayerStore.setState({ currentTrack: null })
  await usePlayerStore.persist.rehydrate()
  expect(usePlayerStore.getState().currentTrack).toEqual(tracks[1])
  expect(usePlayerStore.getState().audioElement).toBeNull()
})

it('restoration invalidates pending native ownership and loads the restored source paused at its clock position', async () => {
  let rejectPlay!: (error: Error) => void
  const audio = { src: '', currentTime: 0, volume: 1, muted: false, paused: true,
    pause: vi.fn(), play: vi.fn(() => new Promise<void>((_, reject) => { rejectPlay = reject })) } as unknown as HTMLAudioElement
  usePlayerStore.getState().setAudioElement(audio)
  usePlayerStore.getState().playTrack(tracks[0], tracks)
  usePlayerStore.getState().restoreQueue(tracks, 1, 12)
  expect(usePlayerStore.getState().currentTrack).toBe(tracks[1])
  expect(usePlayerStore.getState().isPlaying).toBe(false)
  expect(audio.src).toContain('second')
  expect(audio.currentTime).toBe(12)
  expect(getCurrentTime()).toBe(12)
  usePlayerStore.getState().setIsPlaying(true)
  rejectPlay(new Error('Obsolete source failed'))
  await Promise.resolve()
  expect(usePlayerStore.getState().isPlaying).toBe(true)
  expect(activityService.recordPlay).not.toHaveBeenCalled()
})

describe('queue boundary validation', () => {
  it('invalid queue edits preserve the exact state reference', () => {
    usePlayerStore.getState().restoreQueue(tracks, 0, 0)
    const before = usePlayerStore.getState()
    before.reorderQueue(0, 99)
    before.removeFromQueue(99)
    before.reorderQueue(.5, 1)
    expect(usePlayerStore.getState()).toBe(before)
  })
})
