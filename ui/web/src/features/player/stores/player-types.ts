import type { SongEntry } from '@/features/catalog/types'

export interface Track {
  publicId: string
  title: string
  artistName?: string
  albumName?: string
  albumPublicId?: string
  duration?: number
}

export type RepeatMode = 'off' | 'all' | 'one'

export interface PlayerPreferences {
  shuffle: boolean
  repeat: RepeatMode
  crossfadeEnabled: boolean
  crossfadeDuration: number
  volume: number
  muted: boolean
}

export interface PlayerQueueState {
  queue: Track[]
  currentIndex: number
  currentTrack: Track | null
  shuffleBag: number[]
}

export interface PlayerPlaybackState {
  isPlaying: boolean
  duration: number
  audioElement: HTMLAudioElement | null
}

export interface PlayerQueueActions {
  playTrack: (track: Track, queue?: Track[]) => void
  playTrackFromList: (songs: SongEntry[], position: number) => void
  addToQueue: (track: Track) => void
  insertAfterCurrent: (tracks: Track[]) => void
  reorderQueue: (fromIndex: number, toIndex: number) => void
  playNext: () => void
  adoptPreloadedNext: (element: HTMLAudioElement, expectedCurrentId: string, expectedNextId: string) => boolean
  playPrevious: () => void
  removeFromQueue: (index: number) => void
  clearQueue: () => void
  restoreQueue: (queue: Track[], currentIndex: number, position: number) => void
}

export interface PlayerPlaybackActions {
  replayCurrentTrack: () => void
  /** Replace the current track's original stream after the browser rejected its format. */
  switchToTranscodedStream: () => void
  setIsPlaying: (playing: boolean) => void
  setDuration: (duration: number) => void
  seekTo: (time: number) => void
  setAudioElement: (element: HTMLAudioElement | null) => void
}

export interface PlayerPreferenceActions {
  applyPreferences: (preferences: Partial<PlayerPreferences>) => void
  setShuffle: (enabled: boolean) => void
  setRepeat: (mode: RepeatMode) => void
  toggleShuffle: () => void
  toggleRepeat: () => void
  setCrossfadeEnabled: (enabled: boolean) => void
  setCrossfadeDuration: (duration: number) => void
  setVolume: (volume: number) => void
  setMuted: (muted: boolean) => void
  toggleMute: () => void
}

export type PlayerState = PlayerQueueState & PlayerPlaybackState & PlayerPreferences
  & PlayerQueueActions & PlayerPlaybackActions & PlayerPreferenceActions
export type PersistedPlayerState = PlayerPreferences & Pick<PlayerQueueState, 'queue' | 'currentIndex'>

/** Slices share one commit so queue, selection, and preferences change atomically. */
export interface PlayerSliceContext {
  get: () => PlayerState
  commit: (patch: Partial<PlayerState>) => void
}
