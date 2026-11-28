import { MoviePlayerOverlay } from './MoviePlayerOverlay'
import type { DetailPlaybackOptions } from './use-video-detail-playback'

interface ActiveVideoOverlayProps {
  active: DetailPlaybackOptions | null
  onClose: () => void
}

export function ActiveVideoOverlay({ active, onClose }: ActiveVideoOverlayProps) {
  if (!active) return null
  return (
    <MoviePlayerOverlay
      key={active.videoId}
      title={active.title}
      videoId={active.videoId}
      sourceDuration={active.duration ?? undefined}
      startSeconds={active.startSeconds}
      onClose={onClose}
    />
  )
}
