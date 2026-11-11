import type { PlaybackSnapshotResource } from '@/shared/api-client/gen/endpoints'

export function hasUnconfirmedPlayback(snapshot: PlaybackSnapshotResource | null | undefined): boolean {
  return !!snapshot?.activeIncarnationId
    && !snapshot.destinations.some(destination => destination.incarnationId === snapshot.activeIncarnationId && destination.available)
}

/**
 * Whether "Play here anyway" applies: a hold can be overridden only once its destination is no
 * longer registered, which is the runner while no transfer is open, or the target of an uncertain
 * transfer, and only when that destination is a browser. A destination missing from the list is
 * offered, and the server decides.
 */
export function canOverrideHold(snapshot: PlaybackSnapshotResource | null | undefined): boolean {
  if (!snapshot) return false
  const listed = (incarnationId: string | null) => snapshot.destinations.find(destination => destination.incarnationId === incarnationId)
  const browser = (incarnationId: string | null) => (listed(incarnationId)?.kind ?? 'browser') === 'browser'
  if (snapshot.transfer) {
    const targetIncarnationId = snapshot.transfer.targetIncarnationId
    return snapshot.transfer.state === 'uncertain' && !listed(targetIncarnationId)?.available && browser(targetIncarnationId)
  }
  return hasUnconfirmedPlayback(snapshot) && browser(snapshot.activeIncarnationId)
}

/** What a refused Play or local transfer says while the active runner is unavailable and has not confirmed silence. */
export const UNCONFIRMED_PLAYBACK_REFUSAL = 'Previous playback is unconfirmed. Stop it in its original browser, or choose Play here anyway in the playback destination menu.'
