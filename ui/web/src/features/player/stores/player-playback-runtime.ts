import { toast } from 'sonner'
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

export type TranscodeFormat = 'opus' | 'aac' | 'mp3'

const STREAM_PATH = '/api/stream/track'

/** Stable preference order; each MIME type matches the server's transcoded response. */
const TRANSCODE_FORMATS: ReadonlyArray<readonly [TranscodeFormat, string]> = [
  ['opus', 'audio/ogg; codecs=opus'],
  ['aac', 'audio/aac'],
  ['mp3', 'audio/mpeg'],
]

/** Without a bitrate the server applies its own transcoding cap. */
export function buildStreamUrl(publicId: string, format?: TranscodeFormat): string {
  const url = `${STREAM_PATH}?id=${encodeURIComponent(publicId)}`
  return format ? `${url}&format=${format}` : url
}

/** MP3 remains the last resort when the browser reports no transcoded format as playable. */
export function chooseTranscodeFormat(element: HTMLAudioElement): TranscodeFormat {
  return TRANSCODE_FORMATS.find(([, type]) => element.canPlayType(type) !== '')?.[0] ?? 'mp3'
}

function resolveSource(src: string): URL {
  return new URL(src, document.baseURI)
}

export function isTranscodedStream(src: string): boolean {
  if (!src) return false
  const url = resolveSource(src)
  return url.pathname === STREAM_PATH && url.searchParams.has('format')
}

/** The browser rejected this track's original stream, so a transcoded stream may still play. */
export function isUnsupportedOriginalStream(element: HTMLAudioElement, publicId: string): boolean {
  const error = element.error
  return error !== null && error.code === error.MEDIA_ERR_SRC_NOT_SUPPORTED && element.src !== ''
    && resolveSource(element.src).href === resolveSource(buildStreamUrl(publicId)).href
}

/**
 * A transcoded stream is sent progressively without a length or byte ranges
 * until the server has cached it; the browser cannot report its duration then.
 */
export function canSeekStream(element: HTMLAudioElement): boolean {
  return !isTranscodedStream(element.src) || Number.isFinite(element.duration)
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
  sourceMode: 'replace' | 'preserve' | 'reload' = 'replace',
  format?: TranscodeFormat,
) {
  const generation = ++playbackSelectionGeneration
  const el = get().audioElement
  set({ isPlaying: true })
  if (!el) return
  if (sourceMode === 'replace') {
    el.src = buildStreamUrl(track.publicId, format)
  } else {
    // Reloading restarts a stream that cannot seek back to its start.
    if (sourceMode === 'reload') el.load()
    else el.currentTime = 0
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
