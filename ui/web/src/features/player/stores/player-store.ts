import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import { activityService } from '../services/activity-service'
import { createLogger } from '@/shared/lib/logger'
import { updateTime, registerTimeBridge } from './player-time-tracker'
import type { SongEntry } from '@/features/catalog/types'

const logger = createLogger('PlayerStore')

export interface Track {
  publicId: string
  title: string
  artistName?: string
  albumName?: string
  albumPublicId?: string
  duration?: number
}

export type RepeatMode = 'off' | 'all' | 'one'

export interface PlayerState {
  // Queue
  queue: Track[]
  currentIndex: number

  // Playback
  currentTrack: Track | null
  isPlaying: boolean
  currentTime: number
  duration: number

  // Modes
  shuffle: boolean
  repeat: RepeatMode

  // Shuffle bag — Fisher-Yates index sequence
  shuffleBag: number[]

  // Crossfade
  crossfadeEnabled: boolean
  crossfadeDuration: number // 0-12 seconds

  // Volume
  volume: number // 0-100
  muted: boolean

  // Audio element reference (not persisted)
  audioElement: HTMLAudioElement | null

  // Actions — queue
  playTrack: (track: Track, queue?: Track[]) => void
  playTrackFromList: (songs: SongEntry[], position: number) => void
  addToQueue: (track: Track) => void
  insertAfterCurrent: (tracks: Track[]) => void
  reorderQueue: (fromIndex: number, toIndex: number) => void
  playNext: () => void
  playPrevious: () => void
  removeFromQueue: (index: number) => void
  clearQueue: () => void

  // Actions — playback
  setIsPlaying: (playing: boolean) => void
  setDuration: (duration: number) => void
  seekTo: (time: number) => void

  // Actions — modes
  setShuffle: (enabled: boolean) => void
  setRepeat: (mode: RepeatMode) => void
  toggleShuffle: () => void
  toggleRepeat: () => void

  // Actions — crossfade
  setCrossfadeEnabled: (enabled: boolean) => void
  setCrossfadeDuration: (duration: number) => void

  // Actions — volume
  setVolume: (volume: number) => void
  setMuted: (muted: boolean) => void
  toggleMute: () => void

  // Actions — audio element
  setAudioElement: (el: HTMLAudioElement | null) => void
}

export function buildStreamUrl(publicId: string): string {
  return `/api/stream/track?id=${encodeURIComponent(publicId)}`
}

/**
 * Generate a Fisher-Yates shuffle bag — a random permutation of indices.
 * If `excludeIndex` is given, it is placed first (current track plays first on enable).
 */
export function generateShuffleBag(queueLength: number, excludeIndex?: number): number[] {
  const indices = Array.from({ length: queueLength }, (_, i) => i)
  if (excludeIndex !== undefined && excludeIndex >= 0 && excludeIndex < queueLength) {
    indices.splice(indices.indexOf(excludeIndex), 1)
  }
  for (let i = indices.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1))
    ;[indices[i], indices[j]] = [indices[j], indices[i]]
  }
  if (excludeIndex !== undefined && excludeIndex >= 0 && excludeIndex < queueLength) {
    indices.unshift(excludeIndex)
  }
  return indices
}

/**
 * Resolve the next track index considering shuffle bag and repeat mode.
 * Returns null when the queue should stop (end of non-repeating queue).
 */
export function resolveNextIndex(
  queue: Track[],
  currentIndex: number,
  shuffle: boolean,
  repeat: RepeatMode,
  shuffleBag: number[],
): number | null {
  if (queue.length === 0) return null
  if (shuffle) {
    const bagIdx = shuffleBag.indexOf(currentIndex)
    const nextBagIdx = bagIdx + 1
    if (nextBagIdx < shuffleBag.length) return shuffleBag[nextBagIdx]
    if (repeat === 'all') return shuffleBag[0]
    return null
  }
  if (currentIndex < queue.length - 1) return currentIndex + 1
  if (repeat === 'all') return 0
  return null
}

export const usePlayerStore = create<PlayerState>()(
  persist(
    (set, get) => ({
      // Initial state
      queue: [],
      currentIndex: -1,
      currentTrack: null,
      isPlaying: false,
      currentTime: 0,
      duration: 0,
      shuffle: false,
      repeat: 'off',
      shuffleBag: [] as number[],
      crossfadeEnabled: false,
      crossfadeDuration: 5.0,
      volume: 75,
      muted: false,
      audioElement: null,

      // Queue actions
      playTrack: (track, queue) => {
        const state = get()
        const el = state.audioElement

        if (queue) {
          // Replace entire queue, start playing from this track
          const index = queue.findIndex((t) => t.publicId === track.publicId)
          set({ queue, currentIndex: index >= 0 ? index : 0, currentTrack: track })
        } else {
          // Add to queue if not present, play it
          const existingIndex = state.queue.findIndex((t) => t.publicId === track.publicId)
          if (existingIndex >= 0) {
            set({ currentIndex: existingIndex, currentTrack: track })
          } else {
            set({
              queue: [...state.queue, track],
              currentIndex: state.queue.length,
              currentTrack: track,
            })
          }
        }

        // Regenerate shuffle bag on queue mutation
        if (get().shuffle) {
          set({ shuffleBag: generateShuffleBag(get().queue.length, get().currentIndex) })
        }

        // Start playback on audio element
        set({ isPlaying: true })
        if (el) {
          el.src = buildStreamUrl(track.publicId)
          el.play().then(() => {
            // Record activity only when playback actually starts
            activityService.recordPlay({
              songId: track.publicId,
              albumId: track.albumPublicId,
            })
          }).catch((err) => {
            logger.warn('Autoplay blocked or failed:', err)
            set({ isPlaying: false })
          })
        }
      },

      playTrackFromList: (songs, position) => {
        const song = songs[position]
        if (!song) return

        const tracks: Track[] = songs.map((s) => ({
          publicId: s.publicId,
          title: s.title,
          artistName: s.artistName,
          albumName: s.albumName,
          albumPublicId: s.albumPublicId,
          duration: s.duration,
        }))

        get().playTrack(tracks[position], tracks)
      },

      addToQueue: (track) => {
        const { queue } = get()
        if (queue.some((t) => t.publicId === track.publicId)) return
        const newQueue = [...queue, track]
        set({ queue: newQueue })
        if (get().shuffle) set({ shuffleBag: generateShuffleBag(newQueue.length) })
      },

      insertAfterCurrent: (tracks) => {
        const { queue, currentIndex } = get()
        let newQueue: Track[]
        if (currentIndex < 0) {
          newQueue = [...queue, ...tracks]
        } else {
          const insertAt = currentIndex + 1
          newQueue = [...queue]
          newQueue.splice(insertAt, 0, ...tracks)
        }
        set({ queue: newQueue })
        if (get().shuffle) set({ shuffleBag: generateShuffleBag(newQueue.length) })
      },

      reorderQueue: (fromIndex, toIndex) => {
        const { queue, currentIndex } = get()
        if (fromIndex === toIndex) return
        const newQueue = [...queue]
        const [moved] = newQueue.splice(fromIndex, 1)
        newQueue.splice(toIndex, 0, moved)
        // Adjust currentIndex to follow the moved track
        let newIndex = currentIndex
        if (fromIndex === currentIndex) {
          newIndex = toIndex
        } else if (fromIndex < currentIndex && toIndex >= currentIndex) {
          newIndex = currentIndex - 1
        } else if (fromIndex > currentIndex && toIndex <= currentIndex) {
          newIndex = currentIndex + 1
        }
        set({ queue: newQueue, currentIndex: newIndex })
        if (get().shuffle) set({ shuffleBag: generateShuffleBag(newQueue.length) })
      },

      playNext: () => {
        const { queue, currentIndex, shuffle, repeat, audioElement, shuffleBag } = get()
        const nextIndex = resolveNextIndex(queue, currentIndex, shuffle, repeat, shuffleBag)
        if (nextIndex === null) {
          set({ isPlaying: false })
          return
        }

        const track = queue[nextIndex]
        const el = audioElement
        if (el) {
          el.src = buildStreamUrl(track.publicId)
          el.play().then(() => {
            activityService.recordPlay({
              songId: track.publicId,
              albumId: track.albumPublicId,
            })
          }).catch((err) => { logger.warn('Failed to record play activity:', err) })
        }
        set({ currentIndex: nextIndex, currentTrack: track, isPlaying: true })
        updateTime(0)
      },

      playPrevious: () => {
        const { queue, currentIndex, audioElement, currentTime } = get()
        if (queue.length === 0) return

        // If more than 3 seconds in, restart current track
        if (currentTime > 3) {
          const el = audioElement
          if (el) el.currentTime = 0
          updateTime(0)
          return
        }

        let prevIndex: number
        if (currentIndex > 0) {
          prevIndex = currentIndex - 1
        } else {
          prevIndex = queue.length - 1
        }

        const track = queue[prevIndex]
        const el = audioElement
        if (el) {
          el.src = buildStreamUrl(track.publicId)
          el.play().then(() => {
            activityService.recordPlay({
              songId: track.publicId,
              albumId: track.albumPublicId,
            })
          }).catch((err) => { logger.warn('Failed to record play activity:', err) })
        }
        set({ currentIndex: prevIndex, currentTrack: track, isPlaying: true })
        updateTime(0)
      },

      removeFromQueue: (index) => {
        const { queue, currentIndex } = get()
        const newQueue = [...queue]
        newQueue.splice(index, 1)

        let newIndex = currentIndex
        if (index < currentIndex) {
          newIndex = currentIndex - 1
        } else if (index === currentIndex) {
          newIndex = Math.min(index, newQueue.length - 1)
        }

        set({
          queue: newQueue,
          currentIndex: newIndex,
          currentTrack: newIndex >= 0 && newIndex < newQueue.length ? newQueue[newIndex] : null,
        })
        if (get().shuffle) set({ shuffleBag: generateShuffleBag(newQueue.length) })
      },

      clearQueue: () => {
        const { audioElement } = get()
        if (audioElement) {
          audioElement.pause()
          audioElement.src = ''
        }
        activityService.reset()
        set({
          queue: [],
          currentIndex: -1,
          currentTrack: null,
          isPlaying: false,
          duration: 0,
          shuffleBag: [],
        })
        updateTime(0)
      },

      // Playback actions
      setIsPlaying: (playing) => set({ isPlaying: playing }),
      setDuration: (duration) => set({ duration }),

      seekTo: (time) => {
        const { audioElement, duration } = get()
        const clamped = Math.max(0, Math.min(duration || 0, time))
        if (audioElement) audioElement.currentTime = clamped
        updateTime(clamped)
      },

      // Mode actions
      setShuffle: (enabled) => set({ shuffle: enabled }),
      setRepeat: (mode) => set({ repeat: mode }),

      toggleShuffle: () => {
        const { shuffle, repeat, queue, currentIndex } = get()
        const newShuffle = !shuffle
        const bag = newShuffle ? generateShuffleBag(queue.length, currentIndex) : []
        set({ shuffle: newShuffle, repeat: newShuffle ? 'off' : repeat, shuffleBag: bag })
      },

      toggleRepeat: () => {
        const { repeat, shuffle } = get()
        const next = repeat === 'off' ? 'all' : repeat === 'all' ? 'one' : 'off'
        set({ repeat: next, shuffle: next !== 'off' ? false : shuffle })
      },

      // Crossfade actions
      setCrossfadeEnabled: (enabled) => set({ crossfadeEnabled: enabled }),
      setCrossfadeDuration: (duration) => set({ crossfadeDuration: duration }),

      // Volume actions
      setVolume: (volume) => {
        const clamped = Math.max(0, Math.min(100, Math.round(volume)))
        set({ volume: clamped })
        const { audioElement, muted } = get()
        if (audioElement && !muted) audioElement.volume = clamped / 100
      },

      setMuted: (muted) => {
        set({ muted })
        const { audioElement } = get()
        if (audioElement) audioElement.muted = muted
      },

      toggleMute: () => {
        const { muted, audioElement } = get()
        const next = !muted
        set({ muted: next })
        if (audioElement) audioElement.muted = next
      },

      // Audio element
      setAudioElement: (el) => {
        const { volume, muted } = get()
        if (el) {
          el.volume = muted ? 0 : volume / 100
        }
        set({ audioElement: el })
      },
    }),
    {
      name: 'baander-player',
      version: 1,
      onRehydrateStorage: () => (state) => {
        if (state?.shuffle && state.queue.length > 0) {
          state.shuffleBag = generateShuffleBag(state.queue.length, state.currentIndex)
        }
      },
      partialize: (state) => ({
        queue: state.queue,
        currentIndex: state.currentIndex,
        shuffle: state.shuffle,
        repeat: state.repeat,
        volume: state.volume,
        muted: state.muted,
        crossfadeEnabled: state.crossfadeEnabled,
        crossfadeDuration: state.crossfadeDuration,
      }),
    },
  ),
)

/** Read player state outside React — used by service worker and audio callbacks. */
export function getPlayerSnapshot() {
  return usePlayerStore.getState()
}

// Register dual-write bridge: updateTime() writes to both external store and Zustand
registerTimeBridge((time) => usePlayerStore.setState({ currentTime: time }))
