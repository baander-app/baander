import { hydrateQueueAvailability } from './player-availability'
import type { PlayerPlaybackState, PlayerPlaybackActions, PlayerSliceContext } from './player-types'
import { getCurrentTime, updateTime } from './player-time-tracker'
import {
  buildStreamUrl,
  canSeekStream,
  chooseTranscodeFormat,
  hasPendingPlayRecord,
  invalidatePlaybackSelection,
  isTranscodedStream,
  notifyPlaybackFailure,
  notifySeekUnavailable,
  requestSelectedTrackPlayback,
  syncPlaybackVolume,
} from './player-playback-runtime'
import {
  beginPlaybackRecovery,
  continuePlaybackRecovery,
  isRecoveringPlayback,
  pausePlaybackRecovery,
  resumePlaybackRecovery,
  seekPlaybackRecovery,
  type PlaybackRecoveryHost,
} from './player-playback-recovery'

/** Where a recovering seek ends: the reported duration, else the track's length, else nowhere. */
function recoverySeekEnd(duration: number, trackLength: number | undefined): number {
  if (duration > 0) return duration
  if (trackLength !== undefined && Number.isFinite(trackLength) && trackLength > 0) return trackLength
  return Infinity
}

export function createPlayerPlaybackSlice({ get, commit }: PlayerSliceContext): PlayerPlaybackState & PlayerPlaybackActions {
  const recoveryHost: PlaybackRecoveryHost = {
    get,
    commit,
    notifyFailure: (src) => notifyPlaybackFailure(isTranscodedStream(src)),
  }
  return {
    isPlaying: false,
    duration: 0,
    audioElement: null,
    playbackRecovery: 'none',
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
    interruptPlayback: (element, cause, onServerUp) => {
      const { audioElement, currentTrack } = get()
      if (!currentTrack || audioElement !== element) return
      if (isRecoveringPlayback(element)) {
        continuePlaybackRecovery(element, cause)
        return
      }
      const recordPlay = hasPendingPlayRecord()
      // The recovery now owns this selection's playback, so a pending native play must not settle it.
      invalidatePlaybackSelection()
      beginPlaybackRecovery(recoveryHost, element, currentTrack, cause, onServerUp, recordPlay)
    },
    setIsPlaying: (playing) => {
      if (playing === get().isPlaying) {
        if (!playing) invalidatePlaybackSelection()
        return
      }
      if (playing && get().currentTrack?.available !== true) {
        if (get().currentTrack?.available === undefined && get().currentTrack) {
          hydrateQueueAvailability({ get, commit }, () => get().setIsPlaying(true))
        }
        return
      }
      if (playing ? resumePlaybackRecovery() : pausePlaybackRecovery()) return
      if (!playing) invalidatePlaybackSelection()
      const { audioElement, currentTrack } = get()
      if (playing && audioElement && (!audioElement.src || audioElement.getAttribute?.('src') === '') && currentTrack) {
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
      const { audioElement, duration, currentTrack } = get()
      if (isRecoveringPlayback(audioElement)) {
        // A progressive transcode never reported a duration; the catalogue's length still bounds the seek.
        const end = recoverySeekEnd(duration, currentTrack?.duration)
        if (seekPlaybackRecovery(Math.max(0, Math.min(end, time)))) return
      }
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
