import type { AttachmentPresentationOptions } from './hls-playback'
import {
  observeSubtitleSelection,
  type SubtitlePreference
} from './subtitle-selection'

interface NativeAttachmentOptions extends AttachmentPresentationOptions {
  video: HTMLVideoElement
  url: string
  onReady: () => void
  getSubtitlePreference?: () => SubtitlePreference | undefined
  onSubtitlePreferenceChange?: (preference: SubtitlePreference) => void
}

export function attachNativePlayback({
  video,
  url,
  onReady,
  getSubtitlePreference,
  onSubtitlePreferenceChange,
  onSubtitleChoices,
  onQualityChoices,
  onPresentationControls
}: NativeAttachmentOptions): () => void {
  const subtitles =
    getSubtitlePreference && onSubtitlePreferenceChange
      ? observeSubtitleSelection({
          video,
          getPreference: getSubtitlePreference,
          onChange: onSubtitlePreferenceChange,
          onChoices: onSubtitleChoices
        })
      : null
  onQualityChoices?.([], null)
  onPresentationControls?.({
    setQuality: () => {},
    refreshSubtitles: () => subtitles?.refresh()
  })
  const ready = () => subtitles?.ready()
  video.addEventListener('loadedmetadata', ready)
  video.src = url
  onReady()
  return () => {
    video.removeEventListener('loadedmetadata', ready)
    subtitles?.dispose()
  }
}
