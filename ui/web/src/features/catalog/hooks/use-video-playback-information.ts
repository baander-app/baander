import { useQuery } from '@tanstack/react-query'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { useImageBlob } from '@/shared/hooks/use-image-blob'

export interface VideoChapter {
  start: number
  end: number
  title: string
}
export interface VideoAudioTrack {
  index: number
  language: string | null
  title: string | null
  codec: string
  channels: number
  isDefault: boolean
}
export interface VideoQualityChoice {
  id: '360p' | '480p' | '720p' | '1080p' | '1440p' | '4K'
  label: string
  width: number
  height: number
}
export interface VideoPlaybackInformation {
  qualityChoices: VideoQualityChoice[]
  audioTracks: VideoAudioTrack[]
  subtitleTracks: {
    index: number
    language: string | null
    title: string | null
    codec: string
    isDefault: boolean
    isForced: boolean
    isText: boolean
  }[]
  chapters: VideoChapter[]
  preview: {
    state: 'pending' | 'ready' | 'failed' | 'unavailable'
    url: string | null
    interval: number
    width: number
    height: number
    columns: number
    count: number
  }
}

export function useVideoPlaybackInformation(videoId: string) {
  const query = useQuery({
    queryKey: ['catalog', 'video-playback-information', videoId],
    queryFn: async ({ signal }) =>
      (
        await AXIOS_INSTANCE.get<{ data: VideoPlaybackInformation }>(
          `/api/videos/${encodeURIComponent(videoId)}/playback-information`,
          { signal }
        )
      ).data.data
  })
  // Only load the authenticated first-party preview endpoint, never a metadata-supplied URL.
  const preview = useImageBlob(
    query.data?.preview.state === 'ready'
      ? `/api/videos/${encodeURIComponent(videoId)}/timeline-preview`
      : null
  )
  return { ...query, previewUrl: preview.src }
}
