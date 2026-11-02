import { skipToken, useQuery } from '@tanstack/react-query'
import type { PlaybackSnapshotResource } from '@/shared/api-client/gen/endpoints'
import { PLAYBACK_CONNECTION_KEY, PLAYBACK_SESSION_KEY, reconcilePlayback, retryPlaybackConnection, transferPlayback, type PlaybackConnection } from '../services/playback-session-coordinator'

export function usePlaybackSession() {
  const { data: snapshot } = useQuery<PlaybackSnapshotResource>({ queryKey: PLAYBACK_SESSION_KEY, queryFn: skipToken })
  const { data: connection } = useQuery<PlaybackConnection>({ queryKey: PLAYBACK_CONNECTION_KEY, queryFn: skipToken })
  return {
    snapshot,
    endpoint: connection?.endpoint ?? null,
    connected: connection?.connected ?? false,
    error: connection?.error ?? null,
    localRecovery: connection?.localRecovery ?? null,
    transfer: transferPlayback,
    reconcile: reconcilePlayback,
    retry: retryPlaybackConnection,
  }
}
