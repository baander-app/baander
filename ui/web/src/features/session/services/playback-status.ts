import type { PlaybackSnapshotResource } from '@/shared/api-client/gen/endpoints'

export function hasUnconfirmedPlayback(snapshot: PlaybackSnapshotResource | null | undefined): boolean {
  return !!snapshot?.activeIncarnationId
    && !snapshot.destinations.some(destination => destination.incarnationId === snapshot.activeIncarnationId && destination.available)
}

/**
 * Whether "Play here anyway" applies: a hold can be overridden only once its destination is no
 * longer registered, which is the runner while no transfer is open, or the target of an uncertain
 * transfer.
 */
export function canOverrideHold(snapshot: PlaybackSnapshotResource | null | undefined): boolean {
  if (!snapshot) return false
  if (snapshot.transfer) {
    const targetIncarnationId = snapshot.transfer.targetIncarnationId
    const target = snapshot.destinations.find(destination => destination.incarnationId === targetIncarnationId)
    return snapshot.transfer.state === 'uncertain' && !target?.available
  }
  return hasUnconfirmedPlayback(snapshot)
}

/** What a refused Play or local transfer says while the active runner is unavailable and has not confirmed silence. */
export const UNCONFIRMED_PLAYBACK_REFUSAL = 'Previous playback is unconfirmed. Stop it in its original browser, or choose Play here anyway in the playback destination menu.'
