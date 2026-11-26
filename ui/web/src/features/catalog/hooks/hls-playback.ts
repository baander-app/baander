import type Hls from 'hls.js'
import type { PresentationChoice } from './video-presentation'
import { observeNativeSubtitleTiming } from './native-subtitle-timing'
import {
  observeSubtitleSelection,
  type SubtitlePreference
} from './subtitle-selection'

export type { SubtitlePreference } from './subtitle-selection'

export interface AttachmentPresentationOptions {
  getQualityPreference?: () => string
  onQualityChoices?: (
    choices: PresentationChoice[],
    current: string | null
  ) => void
  onSubtitleChoices?: (choices: PresentationChoice[]) => void
  onPresentationControls?: (controls: {
    setQuality(id: string): void
    refreshSubtitles(): void
  }) => void
}

interface AttachmentOptions extends AttachmentPresentationOptions {
  video: HTMLVideoElement
  url: string
  position: number
  onError: (failure?: 'network') => void
  onReady: () => void
  getSubtitlePreference?: () => SubtitlePreference | undefined
  onSubtitlePreferenceChange?: (preference: SubtitlePreference) => void
}

export function attachHlsPlayback({
  video,
  url,
  position,
  onError,
  onReady,
  getSubtitlePreference,
  onSubtitlePreferenceChange,
  getQualityPreference,
  onQualityChoices,
  onSubtitleChoices,
  onPresentationControls
}: AttachmentOptions) {
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
  import('hls.js')
    .then(({ default: HlsPlayer }) => {
      if (!active()) return
      if (getSubtitlePreference && onSubtitlePreferenceChange) {
        subtitles = observeSubtitleSelection({
          video,
          getPreference: getSubtitlePreference,
          onChange: onSubtitlePreferenceChange,
          onChoices: onSubtitleChoices
        })
      }
      if (!HlsPlayer.isSupported()) {
        if (video.canPlayType('application/vnd.apple.mpegurl')) {
          onQualityChoices?.([], null)
          onPresentationControls?.({
            setQuality: () => {},
            refreshSubtitles: () => subtitles?.refresh()
          })
          video.src = url
          onReady()
        } else fail()
        return
      }
      disposeSubtitleTiming = observeNativeSubtitleTiming(video)
      const instance = new HlsPlayer({
        autoStartLoad: false,
        startPosition: position,
        // Movie playlists grow during encoding; their published edge is not a live
        // playback target. Source seeks remain owned by the movie player. hls.js merges
        // user configuration over its defaults with a plain spread, so an own key set to
        // undefined replaces the default latency controller class and no controller is
        // created; lowLatencyMode is disabled as well because it defaults on. The
        // constructor-configuration test pins both, so an hls.js upgrade that changes
        // either semantic fails the suite.
        latencyController: undefined,
        lowLatencyMode: false
      })
      player = instance
      const levelKey = (level: Hls['levels'][number]) =>
        `${level.width}x${level.height}:${level.bitrate}`
      const updateQuality = () => {
        if (!active() || player !== instance) return
        const levels = instance.levels ?? []
        const preference = getQualityPreference?.() ?? 'auto'
        const selected = levels.findIndex(
          (level) => levelKey(level) === preference
        )
        // An unavailable rendition falls back to Auto without losing the viewer's preference.
        const target = preference === 'auto' ? -1 : selected
        // hls.js answers every nextLevel assignment, even one naming the current choice,
        // with a level switch that flushes buffered media around the playing fragment; the
        // back-buffer part also removes the native subtitle cues that end before it.
        if (instance.manualLevel !== target) instance.nextLevel = target
        const choices = levels.map((level) => ({
          id: levelKey(level),
          label: level.height
            ? `${level.height}p`
            : `${Math.round(level.bitrate / 1000)} kbps`
        }))
        const currentHeight = levels[instance.currentLevel]?.height
        onQualityChoices?.(choices, currentHeight ? `${currentHeight}p` : null)
      }
      onPresentationControls?.({
        setQuality: updateQuality,
        refreshSubtitles: () => subtitles?.refresh()
      })
      if (HlsPlayer.Events.LEVELS_UPDATED)
        instance.on(HlsPlayer.Events.LEVELS_UPDATED, updateQuality)
      if (HlsPlayer.Events.LEVEL_SWITCHED)
        instance.on(HlsPlayer.Events.LEVEL_SWITCHED, updateQuality)
      instance.on(HlsPlayer.Events.ERROR, (_event, data) => {
        if (active() && player === instance && data.fatal)
          fail(
            data.type === HlsPlayer.ErrorTypes.NETWORK_ERROR
              ? 'network'
              : undefined
          )
      })
      instance.on(HlsPlayer.Events.MEDIA_ATTACHED, () => {
        if (active() && player === instance) instance.loadSource(url)
      })
      instance.on(HlsPlayer.Events.MANIFEST_PARSED, () => {
        if (active() && player === instance) {
          updateQuality()
          instance.startLoad(position)
          subtitles?.ready()
          onReady()
        }
      })
      instance.attachMedia(video)
    })
    .catch(() => {
      fail()
    })
  return dispose
}
