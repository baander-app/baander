import { toast } from 'sonner'
import { requestPlayback, type PlaybackIntent } from '@/features/session/services/playback-execution'
import type { PlayerState, PlayerRuntimeActions, Track } from './player-types'
import { normalizePlayerPreferences } from './player-preferences-slice'

export function musicNavigation(state: PlayerState) {
  return { shuffle: state.shuffle, repeat: state.repeat, shuffleBag: state.shuffleBag,
    unavailablePublicIds: state.queue.filter(track => track.available === false).map(track => track.publicId) }
}

export function createPlayerIntentActions(get: () => PlayerState, runtime: PlayerRuntimeActions): Partial<PlayerRuntimeActions> {
  let mutedVolume: number | undefined
  const submit = (intent: PlaybackIntent) => {
    requestPlayback(intent).catch((error: unknown) => {
      toast.error('Playback request failed', {
        description: error instanceof Error ? error.message : 'The playback connection is unavailable.',
      })
    })
  }
  const load = (queue: Track[], index: number, position = 0, desiredState: 'playing' | 'paused' = 'playing') => {
    const track = queue[index]
    if (!track || track.available === false) return
    submit({ action: 'load', media: { kind: 'song', publicId: track.publicId },
      queue: queue.map(item => item.publicId), currentTrackIndex: index, position, desiredState })
  }
  const occurrenceAt = (queue: Track[], index: number) => queue.slice(0, index).filter(track => track.publicId === queue[index].publicId).length
  const playTrack = (track: Track, requestedQueue?: Track[]) => {
    if (track.available === false) return
    if (!requestedQueue) {
      submit({ action: 'load', media: { kind: 'song', publicId: track.publicId }, position: 0, desiredState: 'playing' })
      return
    }
    let queue = requestedQueue.filter(item => item.available !== false)
    let index = queue.findIndex(item => item.publicId === track.publicId)
    if (index < 0) { queue = [...queue, track]; index = queue.length - 1 }
    load(queue, index)
  }
  return {
    playTrack,
    playTrackFromList: (songs, position) => {
      const track = songs[position]
      if (Number.isInteger(position) && track) playTrack(track, songs)
    },
    setIsPlaying: playing => {
      submit({ action: playing ? 'play' : 'pause' })
    },
    seekTo: position => { if (Number.isFinite(position)) submit({ action: 'seek', position: Math.max(0, position) }) },
    playNext: () => submit({ action: 'next', navigation: musicNavigation(get()) }),
    playPrevious: () => submit({ action: 'previous', navigation: musicNavigation(get()) }),
    replayCurrentTrack: () => {
      const track = get().currentTrack
      if (track) submit({ action: 'load', media: { kind: 'song', publicId: track.publicId }, position: 0, desiredState: 'playing' })
    },
    restoreQueue: (queue, index, position) => {
      if (!queue.length) { submit({ action: 'queue', operation: { type: 'clear' } }); return }
      const selected = Number.isInteger(index) ? Math.max(0, Math.min(queue.length - 1, index)) : 0
      load(queue, selected, Number.isFinite(position) ? Math.max(0, position) : 0, 'paused')
    },
    clearQueue: () => submit({ action: 'queue', operation: { type: 'clear' } }),
    addToQueue: track => {
      if (track.available === false) return
      submit({ action: 'queue', operation: { type: 'append', tracks: [track.publicId] } })
    },
    insertAfterCurrent: tracks => {
      const state = get()
      const ids = tracks.filter(track => track.available !== false).map(track => track.publicId)
      if (!ids.length) return
      submit({ action: 'queue', operation: { type: 'insert', tracks: ids,
        afterPublicId: state.currentTrack?.publicId ?? null,
        afterOccurrence: state.currentIndex < 0 ? 0 : occurrenceAt(state.queue, state.currentIndex) } })
    },
    reorderQueue: (from, to) => {
      const state = get()
      if (!Number.isInteger(from) || !Number.isInteger(to) || from < 0 || to < 0 || from >= state.queue.length || to >= state.queue.length || from === to) return
      const remaining = state.queue.filter((_, index) => index !== from)
      const before = remaining[to]
      submit({ action: 'queue', operation: { type: 'reorder', publicId: state.queue[from].publicId,
        occurrence: occurrenceAt(state.queue, from), beforePublicId: before?.publicId ?? null,
        beforeOccurrence: before ? occurrenceAt(remaining, to) : 0 } })
    },
    removeFromQueue: index => {
      const state = get()
      if (!Number.isInteger(index) || index < 0 || index >= state.queue.length) return
      submit({ action: 'queue', operation: { type: 'remove', publicId: state.queue[index].publicId,
        occurrence: occurrenceAt(state.queue, index) } })
    },
    setVolume: volume => {
      if (!Number.isFinite(volume)) return
      const next = normalizePlayerPreferences({ volume }, get()).volume
      mutedVolume = undefined
      submit({ action: 'volume', volume: next / 100 })
    },
    setMuted: muted => {
      if (muted) mutedVolume = get().volume
      submit({ action: 'volume', volume: muted ? 0 : (mutedVolume ?? get().volume) / 100 })
    },
    toggleMute: () => {
      const muted = get().volume === 0
      if (!muted) mutedVolume = get().volume
      submit({ action: 'volume', volume: muted ? (mutedVolume ?? 75) / 100 : 0 })
    },
    applyPreferences: preferences => {
      const { volume, muted, ...local } = preferences
      runtime.applyPreferences(local)
      if (volume !== undefined || muted !== undefined) {
        const next = normalizePlayerPreferences(preferences, get())
        submit({ action: 'volume', volume: next.muted ? 0 : next.volume / 100 })
      }
    },
  }
}
