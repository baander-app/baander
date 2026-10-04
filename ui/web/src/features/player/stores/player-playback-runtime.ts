import { audioService } from '../services/audio-service'
import { activityService } from '../services/activity-service'
import { createLogger } from '@/shared/lib/logger'
import { updateTime } from './player-time-tracker'
import type { PlayerState, Track } from './player-types'

const logger = createLogger('PlayerStore')
let playbackSelectionGeneration = 0

/** Read the current attempt token without subscribing to playback state. */
export function getPlaybackSelectionGeneration(): number {
  return playbackSelectionGeneration
}

/** Keep programme input independent of listening volume once the graph owns output. */
export function syncPlaybackVolume(elements: HTMLAudioElement[], volume: number, muted: boolean) {
  const processor = audioService.getProcessor()
  // Configure output first so transferring ownership never exposes unity output.
  processor?.setVolume(volume / 100)
  processor?.setMuted(muted)
  const graphOwnsVolume = processor?.isActive && !processor.passive
  for (const element of elements) {
    element.volume = graphOwnsVolume ? 1 : volume / 100
    element.muted = graphOwnsVolume ? false : muted
  }
}

export function invalidatePlaybackSelection(): void {
  playbackSelectionGeneration++
}

export function buildStreamUrl(publicId: string): string {
  return `/api/stream/track?id=${encodeURIComponent(publicId)}`
}

/** A native play result belongs only to the selection and element that requested it. */
export function requestSelectedTrackPlayback(
  track: Track,
  get: () => PlayerState,
  set: (state: Partial<PlayerState>) => void,
  sourceMode: 'replace' | 'preserve' = 'replace',
) {
  const generation = ++playbackSelectionGeneration
  const el = get().audioElement
  set({ isPlaying: true })
  if (!el) return
  if (sourceMode === 'replace') {
    el.src = buildStreamUrl(track.publicId)
  } else {
    el.currentTime = 0
    updateTime(0)
  }
  const src = el.src
  const ownsSelection = () => generation === playbackSelectionGeneration
    && get().audioElement === el && el.src === src && get().currentTrack === track
  el.play().then(() => {
    if (!ownsSelection() || !get().isPlaying || el.paused || el.ended) return
    // ActivityService handles its own request errors; those are not playback errors.
    activityService.recordPlay({ songId: track.publicId, albumId: track.albumPublicId })
  }, (err) => {
    if (!ownsSelection()) return
    logger.warn('Autoplay blocked or failed:', err)
    set({ isPlaying: false })
  })
}
