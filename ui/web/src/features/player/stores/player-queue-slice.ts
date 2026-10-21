import { hydrateQueueAvailability } from './player-availability'
import type { PlayerQueueState, PlayerQueueActions, PlayerSliceContext, Track } from './player-types'
import { activityService } from '../services/activity-service'
import { getCurrentTime, updateTime } from './player-time-tracker'
import { generateShuffleBag, resolveNextIndex } from './player-queue-navigation'
import { buildStreamUrl, invalidatePlaybackSelection, requestSelectedTrackPlayback, syncPlaybackVolume } from './player-playback-runtime'

export function createPlayerQueueSlice({ get, commit }: PlayerSliceContext): PlayerQueueState & PlayerQueueActions {
  const shuffleForQueue = (queue: Track[], index: number) => get().shuffle ? generateShuffleBag(queue.length, index) : []
  const selectTrack = (track: Track, index: number, queue = get().queue) => {
    if (track.available !== true) return
    const shuffleBag = queue === get().queue ? get().shuffleBag : shuffleForQueue(queue, index)
    commit({ queue, currentIndex: index, currentTrack: track, isPlaying: true, duration: 0, shuffleBag })
    updateTime(0)
    requestSelectedTrackPlayback(track, get, commit)
  }
  return {
    queue: [], currentIndex: -1, currentTrack: null, shuffleBag: [],
    playTrack: (track, queue) => {
      if (track.available !== true) {
        if (track.available === undefined && get().queue.some((item) => item.publicId === track.publicId)) {
          hydrateQueueAvailability({ get, commit }, () => {
            const hydrated = get().queue.find((item) => item.publicId === track.publicId)
            if (hydrated?.available === true) get().playTrack(hydrated)
          })
        }
        return
      }
      if (queue?.some((item) => item.available !== true)) {
        queue = queue.filter((item) => item.available === true)
      }
      const state = get()
      if (queue) {
        const found = queue.findIndex(item => item.publicId === track.publicId)
        // The requested track must belong to the committed queue.
        const selectedQueue = found >= 0 ? queue : [track, ...queue]
        selectTrack(track, found >= 0 ? found : 0, selectedQueue)
        return
      }
      const found = state.queue.findIndex(item => item.publicId === track.publicId)
      const selectedQueue = found < 0 ? [...state.queue, track]
        : state.queue[found] === track ? state.queue
        : state.queue.map((item, index) => index === found ? track : item)
      selectTrack(track, found >= 0 ? found : state.queue.length, selectedQueue)
    },
    playTrackFromList: (songs, position) => {
      if (!Number.isInteger(position) || !songs[position]) return
      if (songs[position].available !== true) return
      const tracks: Track[] = songs.map(song => ({
        publicId: song.publicId, available: song.available, title: song.title, artistName: song.artistName,
        albumName: song.albumName, albumPublicId: song.albumPublicId, duration: song.duration,
      }))
      get().playTrack(tracks[position], tracks)
    },
    addToQueue: (track) => {
      if (track.available !== true) return
      const state = get()
      if (state.queue.some(item => item.publicId === track.publicId)) return
      const queue = [...state.queue, track]
      commit({ queue, shuffleBag: shuffleForQueue(queue, state.currentIndex) })
    },
    insertAfterCurrent: (tracks) => {
      tracks = tracks.filter((track) => track.available === true)
      if (tracks.length === 0) return
      const state = get()
      const insertion = state.currentIndex < 0 ? state.queue.length : state.currentIndex + 1
      const queue = [...state.queue.slice(0, insertion), ...tracks, ...state.queue.slice(insertion)]
      commit({ queue, shuffleBag: shuffleForQueue(queue, state.currentIndex) })
    },
    reorderQueue: (from, to) => {
      const state = get()
      if (!Number.isInteger(from) || !Number.isInteger(to) || from === to || from < 0 || to < 0
        || from >= state.queue.length || to >= state.queue.length) return
      const queue = [...state.queue]
      const [moved] = queue.splice(from, 1)
      queue.splice(to, 0, moved)
      const currentIndex = from === state.currentIndex ? to
        : from < state.currentIndex && to >= state.currentIndex ? state.currentIndex - 1
        : from > state.currentIndex && to <= state.currentIndex ? state.currentIndex + 1 : state.currentIndex
      commit({ queue, currentIndex, shuffleBag: shuffleForQueue(queue, currentIndex) })
    },
    playNext: () => {
      const state = get()
      const index = resolveNextIndex(state.queue, state.currentIndex, state.shuffle, state.repeat, state.shuffleBag)
      if (index === null) { state.setIsPlaying(false); return }
      selectTrack(state.queue[index], index)
    },
    playPrevious: () => {
      const state = get()
      if (state.queue.length === 0) return
      if (getCurrentTime() > 3) {
        if (state.audioElement) state.audioElement.currentTime = 0
        updateTime(0)
        return
      }
      for (let offset = 1; offset <= state.queue.length; offset++) {
        const index = (state.currentIndex - offset + state.queue.length) % state.queue.length
        if (state.queue[index].available === true) {
          selectTrack(state.queue[index], index)
          return
        }
      }
    },
    adoptPreloadedNext: (element, expectedCurrentId, expectedNextId) => {
      const state = get()
      if (state.repeat === 'one' || state.currentTrack?.publicId !== expectedCurrentId) return false
      const index = resolveNextIndex(state.queue, state.currentIndex, state.shuffle, state.repeat, state.shuffleBag)
      const track = index === null ? undefined : state.queue[index]
      if (index === null || !track || track.publicId !== expectedNextId) return false
      invalidatePlaybackSelection()
      syncPlaybackVolume([element], state.volume, state.muted)
      commit({ audioElement: element, currentIndex: index, currentTrack: track, isPlaying: true,
        duration: Number.isFinite(element.duration) ? element.duration : 0 })
      updateTime(element.currentTime)
      activityService.recordPlay({ songId: track.publicId, albumId: track.albumPublicId })
      return true
    },
    removeFromQueue: (index) => {
      const state = get()
      if (!Number.isInteger(index) || index < 0 || index >= state.queue.length) return
      const queue = state.queue.filter((_, position) => position !== index)
      if (queue.length === 0) { state.clearQueue(); return }
      const currentIndex = index < state.currentIndex ? state.currentIndex - 1
        : index === state.currentIndex ? Math.min(index, queue.length - 1) : state.currentIndex
      const currentTrack = currentIndex >= 0 ? queue[currentIndex] : null
      if (index === state.currentIndex && currentTrack) {
        if (state.isPlaying) {
          const following = queue.findIndex((track, position) => position >= currentIndex && track.available === true)
          const availableIndex = following >= 0 ? following : queue.findIndex((track) => track.available === true)
          if (availableIndex >= 0) {
            selectTrack(queue[availableIndex], availableIndex, queue)
            return
          }
        }
        invalidatePlaybackSelection()
        if (state.audioElement) {
          state.audioElement.pause()
          state.audioElement.src = currentTrack.available === true ? buildStreamUrl(currentTrack.publicId) : ''
        }
        commit({ queue, currentIndex, currentTrack, isPlaying: false, duration: 0,
          shuffleBag: shuffleForQueue(queue, currentIndex) })
        updateTime(0)
        return
      }
      commit({ queue, currentIndex, currentTrack, shuffleBag: shuffleForQueue(queue, currentIndex) })
    },
    clearQueue: () => {
      const state = get()
      if (state.queue.length === 0 && !state.currentTrack && !state.isPlaying && state.duration === 0) return
      invalidatePlaybackSelection()
      state.audioElement?.pause()
      if (state.audioElement) state.audioElement.src = ''
      commit({ queue: [], currentIndex: -1, currentTrack: null, isPlaying: false, duration: 0, shuffleBag: [] })
      updateTime(0)
    },
    restoreQueue: (queue, index, position) => {
      const state = get()
      const currentIndex = queue.length === 0 ? -1 : Number.isInteger(index) ? Math.max(0, Math.min(queue.length - 1, index)) : 0
      invalidatePlaybackSelection()
      state.audioElement?.pause()
      const currentTrack = queue[currentIndex] ?? null
      const time = Number.isFinite(position) ? Math.max(0, position) : 0
      if (state.audioElement) {
        state.audioElement.src = currentTrack?.available === true ? buildStreamUrl(currentTrack.publicId) : ''
        if (currentTrack) state.audioElement.currentTime = time
      }
      commit({ queue, currentIndex, currentTrack, isPlaying: false,
        duration: 0, shuffleBag: shuffleForQueue(queue, currentIndex) })
      updateTime(time)
    },
  }
}
