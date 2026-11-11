import type { PlaybackSnapshotResource } from '@/shared/api-client/gen/endpoints'

type Destination = PlaybackSnapshotResource['destinations'][number]

export function hasUnconfirmedPlayback(snapshot: PlaybackSnapshotResource | null | undefined): boolean {
  return !!snapshot?.activeIncarnationId
    && !snapshot.destinations.some(destination => destination.incarnationId === snapshot.activeIncarnationId && destination.available)
}

/**
 * The destination whose hold on the session "Play here anyway" would end, or null while the
 * action does not apply. A hold can be overridden only once its destination is no longer
 * registered: the runner while no transfer is open, or the target of an uncertain transfer. The
 * destination is undefined when the session names one the destination list no longer has.
 */
export function overridableHold(snapshot: PlaybackSnapshotResource | null | undefined): { by: 'runner' | 'transfer-target'; destination: Destination | undefined } | null {
  if (!snapshot) return null
  const named = (incarnationId: string) => snapshot.destinations.find(destination => destination.incarnationId === incarnationId)
  if (snapshot.transfer) {
    const target = named(snapshot.transfer.targetIncarnationId)
    return snapshot.transfer.state === 'uncertain' && !target?.available ? { by: 'transfer-target', destination: target } : null
  }
  return snapshot.activeIncarnationId && hasUnconfirmedPlayback(snapshot)
    ? { by: 'runner', destination: named(snapshot.activeIncarnationId) } : null
}

/** What a refused Play or local transfer says while the active runner is unavailable and has not confirmed silence. */
export const UNCONFIRMED_PLAYBACK_REFUSAL = 'Previous playback is unconfirmed. Stop it in its original browser, or choose Play here anyway in the playback destination menu.'
