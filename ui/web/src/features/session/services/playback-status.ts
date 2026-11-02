import type { PlaybackSnapshotResource } from '@/shared/api-client/gen/endpoints'

export function hasUnconfirmedPlayback(snapshot: PlaybackSnapshotResource | null | undefined): boolean {
  return !!snapshot?.activeIncarnationId
    && !snapshot.destinations.some(destination => destination.incarnationId === snapshot.activeIncarnationId && destination.available)
}
