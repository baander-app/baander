import { assignMusicSource, parseTranscodeFormat, resolveMusicSource, type TranscodeFormat } from '../services/music-delivery'
import { MediaCompatibilityError } from '../services/media-delivery'
import { toast } from 'sonner'
import { audioService } from '../services/audio-service'
import { activityService } from '../services/activity-service'
import { createLogger } from '@/shared/lib/logger'
import { updateTime } from './player-time-tracker'
import { cancelPlaybackRecovery } from './player-playback-recovery'
import type { PlayerState, Track } from './player-types'

export { buildStreamUrl, chooseTranscodeFormat, type TranscodeFormat } from '../services/music-delivery'

const logger = createLogger('PlayerStore')
let playbackSelectionGeneration = 0
let selectionController: AbortController | undefined
/** The selection whose native play is still pending, and with it the play record. */
let pendingPlayRecord: number | null = null

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
  selectionController?.abort()
  cancelPlaybackRecovery()
}

/** Whether the current selection has not yet recorded its play because native play is pending. */
export function hasPendingPlayRecord(): boolean {
  return pendingPlayRecord === playbackSelectionGeneration
}

const STREAM_PATH = '/api/stream/track'

function resolveSource(src: string): URL {
  return new URL(src, document.baseURI)
}

/** Whether `src` streams this track, as the original file or a transcoded rendition. */
export function isTrackStream(src: string, publicId: string): boolean {
  if (!src) return false
  const url = resolveSource(src)
  return (url.pathname === STREAM_PATH || url.pathname === '/api/stream/original/track')
    && url.searchParams.get('id') === publicId
}

export function isTranscodedStream(src: string): boolean {
  if (!src) return false
  const url = resolveSource(src)
  return url.pathname === STREAM_PATH && url.searchParams.has('format') && url.searchParams.get('mode') !== 'copy'
}

/** The browser rejected this track's original stream, so a transcoded stream may still play. */
export function isUnsupportedOriginalStream(element: HTMLAudioElement, publicId: string): boolean {
  const error = element.error
  return error !== null && error.code === error.MEDIA_ERR_SRC_NOT_SUPPORTED && element.src !== ''
    && isTrackStream(element.src, publicId) && !isTranscodedStream(element.src)
}

/**
 * A transcoded stream is sent progressively without a length or byte ranges
 * until the server has cached it; the browser cannot report its duration then.
 */
export function canSeekStream(element: HTMLAudioElement): boolean {
  const progressive = isTranscodedStream(element.src)
    || (element.src !== '' && resolveSource(element.src).searchParams.get('mode') === 'copy')
  return !progressive || Number.isFinite(element.duration)
}

export function notifySeekUnavailable(): void {
  toast.info("Seeking isn't available yet", {
    id: 'player-seek-unavailable',
    description: 'This track is being converted for your browser. You can seek in it the next time it plays.',
  })
}

/** A media element error carries no response body, so the message names only what failed. */
export function notifyPlaybackFailure(transcoded: boolean): void {
  toast.error('This track could not be played.', {
    id: 'player-playback-failure',
    description: transcoded ? 'The server could not convert it to a format this browser can play.' : undefined,
  })
}

/** A native play result belongs only to the selection and element that requested it. */
export function requestSelectedTrackPlayback(
  track: Track,
  get: () => PlayerState,
  set: (state: Partial<PlayerState>) => void,
  { sourceMode = 'replace', format, autoplay = true, position = 0 }: {
    sourceMode?: 'replace' | 'preserve' | 'reload'
    format?: TranscodeFormat
    autoplay?: boolean
    position?: number
  } = {},
) {
  if (track.available !== true) return
  const generation = ++playbackSelectionGeneration
  selectionController?.abort()
  const controller = new AbortController()
  selectionController = controller
  cancelPlaybackRecovery()
  const el = get().audioElement
  if (sourceMode === 'replace') el?.pause()
  set({ isPlaying: autoplay })
  if (!el) return
  pendingPlayRecord = generation
  const settle = () => {
    if (pendingPlayRecord === generation) pendingPlayRecord = null
  }
  const prepare = async () => {
    if (sourceMode !== 'preserve') {
      const previousFormat = sourceMode === 'reload' ? resolveSource(el.src).searchParams.get('format') : null
      const preferredFormat = format ?? parseTranscodeFormat(previousFormat)
      const url = await resolveMusicSource(el, track.publicId, controller.signal, preferredFormat, format ? el.src : undefined)
      if (generation !== playbackSelectionGeneration || get().audioElement !== el || get().currentTrack !== track) return false
      assignMusicSource(el, track.publicId, url)
      if (sourceMode === 'reload') {
        el.load()
        updateTime(0)
      }
    } else {
      el.currentTime = 0
      updateTime(0)
    }
    return true
  }
  prepare().then(ready => {
    if (!ready || controller.signal.aborted || generation !== playbackSelectionGeneration
      || get().audioElement !== el || get().currentTrack !== track) return
    if (position > 0) { el.currentTime = position; updateTime(position) }
    if (!autoplay) { settle(); return }
    const src = el.src
    const ownsSelection = () => generation === playbackSelectionGeneration
      && get().audioElement === el && el.src === src && get().currentTrack === track
    el.play().then(() => {
      settle()
      if (!ownsSelection()) {
        if (el.src === src && !get().playbackExecutionActive) el.pause()
        return
      }
      if (!get().isPlaying || el.paused || el.ended) return
      activityService.recordPlay({ songId: track.publicId, albumId: track.albumPublicId })
    }, (err) => {
      settle()
      if (!ownsSelection()) return
      logger.warn('Autoplay blocked or failed:', err)
      set({ isPlaying: false })
    })
  }).catch((error: unknown) => {
    settle()
    if (controller.signal.aborted || generation !== playbackSelectionGeneration) return
    set({ isPlaying: false })
    toast.error(error instanceof MediaCompatibilityError ? error.message : 'This track could not be played.', { id: 'player-playback-failure' })
  })
}
