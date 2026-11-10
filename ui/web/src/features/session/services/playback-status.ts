import type { PlaybackSnapshotResource } from '@/shared/api-client/gen/endpoints'

export function hasUnconfirmedPlayback(snapshot: PlaybackSnapshotResource | null | undefined): boolean {
  return !!snapshot?.activeIncarnationId
    && !snapshot.destinations.some(destination => destination.incarnationId === snapshot.activeIncarnationId && destination.available)
}

/** What a refused Play or local transfer says while the active runner is unavailable and has not confirmed silence. */
export const UNCONFIRMED_PLAYBACK_REFUSAL = 'Previous playback is unconfirmed. Stop playback in its original browser or recover that browser before resuming here.'
