import type { PlayerPlaybackState, PlayerPlaybackActions, PlayerSliceContext } from './player-types'
import { getCurrentTime, updateTime } from './player-time-tracker'
import {
  buildStreamUrl,
  canSeekStream,
  chooseTranscodeFormat,
  invalidatePlaybackSelection,
  notifySeekUnavailable,
  requestSelectedTrackPlayback,
  syncPlaybackVolume,
} from './player-playback-runtime'

export function createPlayerPlaybackSlice({ get, commit }: PlayerSliceContext): PlayerPlaybackState & PlayerPlaybackActions {
  return {
    isPlaying: false,
    duration: 0,
    audioElement: null,
    replayCurrentTrack: () => {
      const { currentTrack, audioElement } = get()
      if (!currentTrack || !audioElement?.src) return
      requestSelectedTrackPlayback(currentTrack, get, commit, canSeekStream(audioElement) ? 'preserve' : 'reload')
    },
    switchToTranscodedStream: () => {
      const { currentTrack, audioElement, isPlaying } = get()
      if (!currentTrack || !audioElement) return
      const format = chooseTranscodeFormat(audioElement)
      if (isPlaying) {
        requestSelectedTrackPlayback(currentTrack, get, commit, 'replace', format)
        return
      }
      invalidatePlaybackSelection()
      audioElement.src = buildStreamUrl(currentTrack.publicId, format)
    },
    setIsPlaying: (playing) => {
      if (playing === get().isPlaying) return
      if (!playing) invalidatePlaybackSelection()
      const { audioElement, currentTrack } = get()
      if (playing && audioElement && !audioElement.src && currentTrack) {
        audioElement.src = buildStreamUrl(currentTrack.publicId)
        audioElement.currentTime = getCurrentTime()
      }
      commit({ isPlaying: playing })
    },
    setDuration: (duration) => {
      if (!Number.isFinite(duration) || duration < 0) return
      commit({ duration })
    },
    seekTo: (time) => {
      if (!Number.isFinite(time)) return
      const { audioElement, duration } = get()
      const clamped = Math.max(0, Math.min(duration || 0, time))
      if (audioElement && audioElement.currentTime !== clamped) {
        if (!canSeekStream(audioElement)) {
          notifySeekUnavailable()
          return
        }
        audioElement.currentTime = clamped
      }
      if (getCurrentTime() !== clamped) updateTime(clamped)
    },
    setAudioElement: (element) => {
      const { volume, muted, audioElement } = get()
      if (audioElement === element) return
      invalidatePlaybackSelection()
      syncPlaybackVolume(element ? [element] : [], volume, muted)
      commit({ audioElement: element })
    },
  }
}
