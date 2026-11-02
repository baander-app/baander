import { observeSubtitleSelection, type SubtitlePreference } from './subtitle-selection'

interface NativeAttachmentOptions {
  video: HTMLVideoElement
  url: string
  onReady: () => void
  getSubtitlePreference?: () => SubtitlePreference | undefined
  onSubtitlePreferenceChange?: (preference: SubtitlePreference) => void
}

export function attachNativePlayback({ video, url, onReady, getSubtitlePreference, onSubtitlePreferenceChange }: NativeAttachmentOptions): () => void {
  const subtitles = getSubtitlePreference && onSubtitlePreferenceChange
    ? observeSubtitleSelection({ video, getPreference: getSubtitlePreference, onChange: onSubtitlePreferenceChange })
    : null
  const ready = () => subtitles?.ready()
  video.addEventListener('loadedmetadata', ready)
  video.src = url
  onReady()
  return () => {
    video.removeEventListener('loadedmetadata', ready)
    subtitles?.dispose()
  }
}
