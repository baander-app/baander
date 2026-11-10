import { useEffect } from 'react'
import type { PlaybackSnapshotResource } from '@/shared/api-client/gen/endpoints'
import { hasUnconfirmedPlayback, UNCONFIRMED_PLAYBACK_REFUSAL } from '@/features/session/services/playback-status'
import { useVideoPresentation } from '../stores/video-presentation-store'

/**
 * The video error to show. A Play refused over unconfirmed previous playback describes the
 * session rather than a failed request, so it stops applying once the session has no
 * unconfirmed playback left. Every other error stays until the next selection.
 */
export function useVideoPresentationError(snapshot: PlaybackSnapshotResource | null | undefined): string | null {
  const error = useVideoPresentation(state => state.error)
  const settled = error === UNCONFIRMED_PLAYBACK_REFUSAL && !!snapshot && !hasUnconfirmedPlayback(snapshot)

  useEffect(() => {
    // Without this the stale refusal would reappear, unasked, the next time a runner goes away.
    if (settled) useVideoPresentation.getState().dismissError(UNCONFIRMED_PLAYBACK_REFUSAL)
  }, [settled])

  return settled ? null : error
}
