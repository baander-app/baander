import type { PlaybackSnapshotResource } from '@/shared/api-client/gen/endpoints'

const states = new Set(['playing', 'paused', 'stopped'])
const observedStates = new Set([...states, 'unknown'])
const phases = new Set(['preparing', 'quiescing', 'starting', 'uncertain'])
const transferResults = new Set([...phases, 'committed', 'failed'])
const commands = new Set(['prepare', 'quiesce', 'start', 'stop', 'play', 'pause', 'seek', 'load', 'observe', 'volume', 'queue'])

function record(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function identity(value: unknown): value is string {
  return typeof value === 'string' && value.length > 0
}

function nullableIdentity(value: unknown): boolean {
  return value === null || identity(value)
}

function position(value: unknown): value is number {
  return typeof value === 'number' && Number.isFinite(value) && value >= 0
}

function integer(value: unknown, minimum = 0): value is number {
  return typeof value === 'number' && Number.isSafeInteger(value) && value >= minimum
}

function volume(value: unknown): value is number {
  return position(value) && value <= 1
}

function member(value: unknown, values: ReadonlySet<string>): boolean {
  return typeof value === 'string' && values.has(value)
}

function media(value: unknown): boolean {
  return value === null || (record(value) && (value.kind === 'song' || value.kind === 'video') && identity(value.publicId))
}

function playback(value: Record<string, unknown>): boolean {
  return media(value.media) && Array.isArray(value.queue) && value.queue.every(identity)
    && integer(value.currentTrackIndex) && value.currentTrackIndex < Math.max(1, value.queue.length)
    && position(value.position) && member(value.desiredState, states)
}

function destination(value: unknown): boolean {
  return record(value) && identity(value.destinationId) && identity(value.name) && value.kind === 'browser'
    && Array.isArray(value.capabilities) && value.capabilities.every(kind => kind === 'song' || kind === 'video' || kind === 'volume')
    && nullableIdentity(value.incarnationId) && (value.epoch === null || integer(value.epoch, 1))
    && (value.observedState === null || member(value.observedState, observedStates))
    && (value.position === null || position(value.position))
    && (value.observedVolume === null || volume(value.observedVolume)) && typeof value.available === 'boolean'
}

function transfer(value: unknown): boolean {
  return value === null || (record(value) && identity(value.attemptId) && member(value.state, phases)
    && nullableIdentity(value.sourceIncarnationId) && identity(value.targetIncarnationId)
    && position(value.position) && member(value.desiredState, states))
}

function command(value: unknown): boolean {
  return record(value) && identity(value.id) && identity(value.attemptId) && integer(value.revision)
    && identity(value.incarnationId) && integer(value.epoch, 1) && member(value.type, commands)
    && record(value.payload) && playback(value.payload)
    && (value.payload.volume == null || volume(value.payload.volume))
    && (value.type !== 'volume' || volume(value.payload.volume))
    && typeof value.expiresAt === 'string' && Number.isFinite(Date.parse(value.expiresAt))
}

function endpoint(value: unknown): boolean {
  return value == null || (record(value) && identity(value.destinationId) && identity(value.incarnationId)
    && integer(value.epoch, 1) && (value.capability == null || identity(value.capability))
    && (value.recoveryCredential == null || (identity(value.recoveryCredential) && identity(value.recoveryTabId)
      && typeof value.recoveryExpiresAt === 'string' && Number.isFinite(Date.parse(value.recoveryExpiresAt)))))
}

export function parsePlaybackSnapshot(value: unknown): PlaybackSnapshotResource | null {
  if (!record(value) || value.schemaVersion !== 1 || !identity(value.publicId) || !integer(value.revision)
    || !playback(value) || !member(value.playbackState, states) || !member(value.observedState, observedStates)
    || !nullableIdentity(value.selectedDestinationId) || !nullableIdentity(value.activeDestinationId)
    || !nullableIdentity(value.activeIncarnationId) || !transfer(value.transfer)
    || !Array.isArray(value.destinations) || !value.destinations.every(destination)
    || (value.requestResult != null && (!record(value.requestResult) || !identity(value.requestResult.requestId) || !identity(value.requestResult.commandId) || !(value.requestResult.outcome === null || typeof value.requestResult.outcome === 'string')
      || (value.requestResult.rejectionReason != null && !identity(value.requestResult.rejectionReason))))
    || (value.transferResult != null && (!record(value.transferResult) || !identity(value.transferResult.requestId)
      || !identity(value.transferResult.attemptId) || !member(value.transferResult.state, transferResults)
      || (value.transferResult.rejectionReason != null && !identity(value.transferResult.rejectionReason))))
    || !Array.isArray(value.commands) || !value.commands.every(command) || !endpoint(value.endpoint)) return null

  return value as unknown as PlaybackSnapshotResource
}
