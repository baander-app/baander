import type Hls from 'hls.js'
import { observeNativeSubtitleTiming } from './native-subtitle-timing'
import { observeSubtitleSelection, type SubtitlePreference } from './subtitle-selection'

export type { SubtitlePreference } from './subtitle-selection'

interface AttachmentOptions {
  video: HTMLVideoElement
  url: string
  position: number
  onError: (failure?: 'network') => void
  onReady: () => void
  getSubtitlePreference?: () => SubtitlePreference | undefined
  onSubtitlePreferenceChange?: (preference: SubtitlePreference) => void
}

export function attachHlsPlayback({ video, url, position, onError, onReady, getSubtitlePreference, onSubtitlePreferenceChange }: AttachmentOptions) {
  let disposed = false
  let player: Hls | null = null
  let disposeSubtitleTiming: (() => void) | null = null
  let subtitles: ReturnType<typeof observeSubtitleSelection> | null = null
  const active = () => !disposed
  const dispose = () => {
    if (disposed) return
    disposeSubtitleTiming?.()
    disposeSubtitleTiming = null
    subtitles?.dispose()
    subtitles = null
    disposed = true
    const instance = player
    player = null
    instance?.destroy()
  }
  const fail = (failure?: 'network') => {
    if (!active()) return
    disposeSubtitleTiming?.()
    disposeSubtitleTiming = null
    subtitles?.dispose()
    subtitles = null
    disposed = true
    const instance = player
    player = null
    try {
      onError(failure)
    } finally {
      // Destruction may reset the native clock. Let the owner retain its cursor
      // first, with callbacks already fenced against reentry.
      instance?.destroy()
    }
  }
  import('hls.js').then(({ default: HlsPlayer }) => {
    if (!active()) return
    if (getSubtitlePreference && onSubtitlePreferenceChange) {
      subtitles = observeSubtitleSelection({ video, getPreference: getSubtitlePreference, onChange: onSubtitlePreferenceChange })
    }
    if (!HlsPlayer.isSupported()) {
      if (video.canPlayType('application/vnd.apple.mpegurl')) {
        video.src = url
        onReady()
      }
      else fail()
      return
    }
    disposeSubtitleTiming = observeNativeSubtitleTiming(video)
    const instance = new HlsPlayer({
      autoStartLoad: false,
      startPosition: position,
      // Movie playlists grow during encoding; their published edge is not a live
      // playback target. Source seeks remain owned by the movie player.
      latencyController: undefined,
    })
    player = instance
    instance.on(HlsPlayer.Events.ERROR, (_event, data) => {
      if (active() && player === instance && data.fatal) fail(data.type === HlsPlayer.ErrorTypes.NETWORK_ERROR ? 'network' : undefined)
    })
    instance.on(HlsPlayer.Events.MEDIA_ATTACHED, () => {
      if (active() && player === instance) instance.loadSource(url)
    })
    instance.on(HlsPlayer.Events.MANIFEST_PARSED, () => {
      if (active() && player === instance) {
        instance.startLoad(position)
        subtitles?.ready()
        onReady()
      }
    })
    instance.attachMedia(video)
  }).catch(() => {
    fail()
  })
  return dispose
}
