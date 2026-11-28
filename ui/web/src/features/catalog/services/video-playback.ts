import { requestPlayback } from '@/features/session/services/playback-execution'
import type { PlaybackViewingContextResource } from '@/shared/api-client/gen/endpoints'

export interface VideoPlayIntent extends PlaybackViewingContextResource {
  videoId: string
  startSeconds: number
}

export function requestVideoPlayback(intent: VideoPlayIntent): Promise<void> {
  if (!Number.isFinite(intent.startSeconds) || intent.startSeconds < 0) {
    return Promise.reject(new Error('Video start position is invalid.'))
  }
  return requestPlayback({
    action: 'load',
    media: {
      kind: 'video',
      publicId: intent.videoId,
      viewing: { titleKind: intent.titleKind, titleId: intent.titleId }
    },
    position: intent.startSeconds,
    desiredState: 'playing'
  })
}
