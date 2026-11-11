import type { QueryClient } from '@tanstack/react-query'
import type { OverridePlaybackRequest, PlaybackEndpointResource, PlaybackSnapshotResource } from '@/shared/api-client/gen/endpoints'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { authenticatedTransport, type SharedLiveTransport } from '@/shared/live/transport'
import { captureOwnership, isCurrentOwnership, subscribeOwnership } from '@/shared/live/ownership'
import { getDeviceId, getDeviceName } from '../utils/device-id'
import { parsePlaybackSnapshot } from './playback-protocol'
import { getPlaybackAdapter, getPlaybackAdapters, registerPlaybackIntentHandler, type PlaybackIntent, type PlaybackQueueOperation } from './playback-execution'
import type { PlaybackCommandResource } from '@/shared/api-client/gen/endpoints'
import { PlaybackLocalRecovery, type LocalRecoveryState, type RecoveryEndpoint } from './playback-local-recovery'
import type { PlaybackRecoveryCheckpoint, PlaybackRecoveryRecord } from './playback-recovery-store'
import { PlaybackCommandRunner, type PlaybackAcknowledgement } from './playback-command-runner'
import { hasUnconfirmedPlayback, UNCONFIRMED_PLAYBACK_REFUSAL } from './playback-status'
import { playbackPageId } from './playback-page-identity'
import { CLOSE_REPORT_PATH, PlaybackCloseReporter } from './playback-close-reporter'
import { MediaCompatibilityError } from '@/features/player/services/media-delivery'

type RequestResult = NonNullable<PlaybackSnapshotResource['requestResult']>
type TransferResult = NonNullable<PlaybackSnapshotResource['transferResult']>
type ControlSnapshot = PlaybackSnapshotResource
interface PendingRequest {
  payload: Record<string, unknown>
  resolve: () => void
  reject: (error: Error) => void
  lastRevision: number
  commandId: string | null
  reading: boolean
}

interface PendingTransfer {
  payload: { requestId: string; expectedRevision: number; targetIncarnationId: string }
  attemptId: string | null
  resolve: () => void
  reject: (error: Error) => void
  reading: boolean
  lastRevision: number
}

/** An incarnation this page stopped playing for and has not yet reported closed. */
interface ClosedIncarnation {
  endpoint: PlaybackEndpointResource
  checkpoint: PlaybackRecoveryCheckpoint
}
let retirement: { owner: ReturnType<typeof captureOwnership>; closing: Promise<ClosedIncarnation | null> } | null = null

const PRESENCE_INTERVAL_MS = 10_000
const REQUEST_TIMEOUT_MS = 15_000
/** How long the server keeps a command deliverable. A page that cannot be registered for that long cannot be commanded. */
const COMMAND_LIFETIME_MS = 20_000
const REGISTRATION_RETRY_MS = 1_000
const OVERRIDE_PATH = '/api/session/override'
/** How long a refused request still says what the listener wants once the hold on the session is overridden. */
const REFUSED_REQUEST_LIFETIME_MS = 120_000

/** A request this tab was refused for unconfirmed playback, which an override may start again. */
class RefusedRequest {
  private readonly intent: PlaybackIntent
  private readonly at: number

  constructor(intent: PlaybackIntent, at: number) {
    this.intent = intent
    this.at = at
  }

  /**
   * The refused intent while it is still what the listener wants at `now`. The wall clock keeps
   * counting while the machine sleeps: a refusal older than the limit, or one the clock puts in
   * the future, starts nothing.
   */
  freshIntent(now: number): PlaybackIntent | null {
    const age = now - this.at
    return age >= 0 && age <= REFUSED_REQUEST_LIFETIME_MS ? this.intent : null
  }
}

export const PLAYBACK_SESSION_KEY = ['session', 'current'] as const
export const PLAYBACK_CONNECTION_KEY = ['session', 'connection'] as const
export interface PlaybackConnection {
  endpoint: Omit<PlaybackEndpointResource, 'capability'> | null
  connected: boolean
  error: string | null
  localRecovery?: LocalRecoveryState
}

export class PlaybackSessionCoordinator {
  private readonly ownership = captureOwnership()
  private readonly endpointId = crypto.randomUUID()
  private clientRequestId: string | null = null
  private readonly identityWaiters = new Set<{ resolve: (id: string) => void; reject: (error: unknown) => void }>()
  private readonly runner = new PlaybackCommandRunner(ack => this.acknowledge(ack), (command, signal) => this.checkpointCommand(command, signal))
  private endpoint: PlaybackEndpointResource | null = null
  private snapshot: PlaybackSnapshotResource | null = null
  private readonly transfers = new Set<PendingTransfer>()
  private readonly httpRequests = new Set<AbortController>()
  private readonly pendingRequests = new Map<string, PendingRequest>()
  private readonly registrationReady = new Set<() => void>()
  private intentTail: Promise<void> = Promise.resolve()
  private queuedSeek: { intent: { action: 'seek'; position: number }; result: Promise<void> } | null = null
  private disposed = false
  private connection = 0
  private localQualified = false
  private suspended = false
  private executionBlocked = false
  private presenceConfirmedAt: number | null = null
  private registrationStartedAt: number | null = null
  private ready = false
  private registrationPending = false
  /** Whether the socket now open carries an answered registration: the server reaches the page only through such a socket. */
  private socketRegistered = false
  private registrationRetry: ReturnType<typeof setTimeout> | undefined
  private registrationDeadline: ReturnType<typeof setTimeout> | undefined
  private cleanup: (() => void)[] = []
  private interval: ReturnType<typeof setInterval> | undefined
  private recovery: Promise<void> = Promise.resolve()
  private closed: ClosedIncarnation | null = null
  /** Whether the page has disconnected, and stopped its players, since its last answered registration. */
  private returning = false
  /** Every incarnation this page has been assigned. A snapshot that names any of them names this page. */
  private readonly incarnations = new Set<string>()
  /** Set from the moment a page that never disconnected sends its registration until the answer is bound. */
  private rebinding = false
  /** The last request this tab was refused for unconfirmed playback, when starting it again is what an override is for. */
  private refused: RefusedRequest | null = null
  private readonly closeReport = new PlaybackCloseReporter({
    current: () => this.current(),
    endpoint: () => this.endpoint,
    snapshot: () => this.snapshot,
    cursor: snapshot => this.cursor(snapshot),
    checkpoint: (snapshot, position) => this.checkpointAt(snapshot, position),
  })

  private readonly client: QueryClient
  private readonly transport: SharedLiveTransport
  private readonly localRecovery?: PlaybackLocalRecovery

  constructor(client: QueryClient, transport: SharedLiveTransport = authenticatedTransport, localRecovery?: PlaybackLocalRecovery) {
    this.client = client
    this.transport = transport
    this.localRecovery = localRecovery
  }

  start(): () => void {
    this.publish(false, null)
    this.cleanup.push(registerPlaybackIntentHandler(intent => this.request(intent)))
    this.cleanup.push(subscribeOwnership(() => {
      if (!this.current()) this.dispose()
    }))
    this.cleanup.push(this.transport.subscribe({
      onOpen: () => { this.opened() },
      onClose: () => { this.disconnect() },
      onMessage: message => this.message(message),
      onError: error => this.fail(error),
    }))
    this.cleanup.push(this.transport.retain())
    this.cleanup.push(this.closeReport.start())
    if (this.transport.isConnected()) this.register()
    // Only an unloading page reports its close. A tab that is merely hidden keeps playing, and
    // nothing tells the two apart but this event.
    const hide = () => {
      this.closeReport.send()
      this.suspended = true
      this.disconnect()
      this.localRecovery?.dispose()
    }
    const show = () => {
      if (!this.suspended || !this.current()) return
      this.suspended = false
      this.retry().catch(error => this.fail(error))
    }
    window.addEventListener('pagehide', hide)
    window.addEventListener('pageshow', show)
    this.cleanup.push(() => { window.removeEventListener('pagehide', hide); window.removeEventListener('pageshow', show) })
    this.interval = setInterval(() => { this.observe().catch(error => this.fail(error)) }, PRESENCE_INTERVAL_MS)
    return () => this.dispose()
  }

  async getClientRequestId(signal: AbortSignal): Promise<string> {
    const check = () => {
      signal.throwIfAborted()
      if (!this.current()) throw new Error('Playback account or runtime changed.')
    }
    check()
    const id = this.clientRequestId ?? await new Promise<string>((resolve, reject) => {
      const waiter = {
        resolve: (value: string) => { cleanup(); resolve(value) },
        reject: (error: unknown) => { cleanup(); reject(error) },
      }
      const abort = () => waiter.reject(signal.reason)
      const cleanup = () => {
        this.identityWaiters.delete(waiter)
        signal.removeEventListener('abort', abort)
      }
      this.identityWaiters.add(waiter)
      signal.addEventListener('abort', abort, { once: true })
    })
    check()
    return id
  }

  private current(): boolean {
    return !this.disposed && isCurrentOwnership(this.ownership)
  }

  private publish(connected: boolean, error: string | null): void {
    if (!this.current()) return
    const endpoint = this.endpoint
    this.client.setQueryData<PlaybackConnection>(PLAYBACK_CONNECTION_KEY, {
      endpoint: endpoint ? { destinationId: endpoint.destinationId, incarnationId: endpoint.incarnationId, epoch: endpoint.epoch } : null,
      connected, error,
      ...(this.localRecovery ? { localRecovery: this.localRecovery.getState() } : {}),
    })
  }

  private fail(error: unknown): void {
    if (this.clientRequestId === null) {
      for (const waiter of this.identityWaiters) waiter.reject(error)
    }
    this.presenceConfirmedAt = null
    this.publish(this.ready, error instanceof Error ? error.message : 'Playback connection failed.')
  }

  private apply(value: unknown): ControlSnapshot {
    const snapshot = parsePlaybackSnapshot(value) as ControlSnapshot | null
    if (!snapshot) throw new Error('Invalid playback state received.')
    if (!this.current()) throw new Error('Playback account changed.')
    if (this.snapshot && snapshot.revision < this.snapshot.revision) return snapshot
    this.snapshot = snapshot
    const published = { ...snapshot, commands: [] }
    delete published.endpoint
    delete published.requestResult
    delete published.transferResult
    delete published.recoveryResult
    this.client.setQueryData(PLAYBACK_SESSION_KEY, published)
    // The refusal describes the session, not a failed request: once the session has no unconfirmed
    // playback left, the alert would tell the viewer to recover a browser that holds nothing.
    const connection = this.client.getQueryData<PlaybackConnection>(PLAYBACK_CONNECTION_KEY)
    if (!hasUnconfirmedPlayback(snapshot)) {
      // Whatever released the session, the refused request was an answer to a hold that is gone.
      this.refused = null
      if (connection?.error === UNCONFIRMED_PLAYBACK_REFUSAL) this.publish(connection.connected, null)
    }
    this.runner.fence(snapshot.revision)
    // While the page registers again as itself its place moves to a new incarnation without a new
    // revision, so nothing is judged until the answer is bound; binding the answer judges it then.
    if (this.endpoint && !this.rebinding) this.letGoUnlessNamed(snapshot)
    this.refreshRequests()
    this.refreshTransfers()
    return snapshot
  }

  /**
   * A session that no longer names this tab sends it nothing further. A transfer that failed or
   * expired leaves its target a preparation nobody will start or stop, and a session that was
   * taken over elsewhere leaves a tab that still plays: the tab lets go of the one and silences
   * the other.
   */
  private letGoUnlessNamed(snapshot: PlaybackSnapshotResource): void {
    if (this.names(snapshot)) return
    this.runner.dropPreparation().catch(error => this.fail(error))
    this.runner.silenceLostPlayback().catch(error => this.fail(error))
  }

  /** Whether the session names this page: as its runner, or as a participant of its open transfer. */
  private names(snapshot: PlaybackSnapshotResource): boolean {
    return [snapshot.activeIncarnationId, snapshot.transfer?.sourceIncarnationId, snapshot.transfer?.targetIncarnationId]
      .some(incarnation => incarnation != null && this.incarnations.has(incarnation))
  }

  private async withRequest<T>(operation: (signal: AbortSignal) => Promise<T>): Promise<T> {
    if (!this.current()) throw new Error('Playback account changed.')
    const request = new AbortController()
    this.httpRequests.add(request)
    const timeout = setTimeout(() => request.abort(new Error('Playback request timed out; its result is unconfirmed.')), REQUEST_TIMEOUT_MS)
    let onAbort = () => {}
    const aborted = new Promise<never>((_, reject) => {
      onAbort = () => reject(request.signal.reason instanceof Error ? request.signal.reason : new Error('Playback request aborted.'))
      request.signal.addEventListener('abort', onAbort, { once: true })
    })
    try { return await Promise.race([operation(request.signal), aborted]) }
    finally {
      clearTimeout(timeout)
      request.signal.removeEventListener('abort', onAbort)
      this.httpRequests.delete(request)
    }
  }

  private abortRequests(): void {
    for (const request of this.httpRequests) request.abort(new Error('Playback connection or account changed.'))
  }

  private async post(path: string, body: object, endpoint?: PlaybackEndpointResource): Promise<ControlSnapshot> {
    const response = await this.withRequest(signal => AXIOS_INSTANCE.post(path, body, {
      signal,
      ...(endpoint?.capability ? { headers: { 'X-Baander-Playback-Endpoint': endpoint.capability } } : {}),
    }))
    return this.apply(response.data?.data ?? response.data)
  }

  private message(message: unknown): void {
    if (!this.current() || typeof message !== 'object' || message === null || !('type' in message)) return
    if (message.type === 'session.error') {
      this.registrationPending = false
      this.rebinding = false
      this.fail(new Error('message' in message && typeof message.message === 'string' ? message.message : 'Playback request failed.'))
      this.registerAgainWhileBound()
      return
    }
    if (message.type !== 'session.snapshot' || !('data' in message)) return
    try {
      const snapshot = this.apply(message.data)
      // A registration answer binds the page whatever its revision: a control this page sent
      // meanwhile may already have shown it a later one.
      if (snapshot.endpoint?.capability && this.transport.isConnected()) {
        this.acceptEndpoint(snapshot).catch(error => {
          this.registrationPending = false
          this.fail(error)
          this.registerAgainWhileBound()
        })
        return
      }
      if (this.snapshot && snapshot.revision < this.snapshot.revision) return
      this.dispatchCommands(snapshot)
    } catch (error) { this.fail(error) }
  }

  private dispatchCommands(snapshot: PlaybackSnapshotResource): void {
    if (!this.current() || !this.ready || !this.snapshot || snapshot.revision < this.snapshot.revision) return
    for (const command of snapshot.commands) {
      this.runner.accept(command, snapshot.revision).catch(error => this.fail(error))
    }
  }

  private disconnect(): void {
    this.clientRequestId = null
    this.presenceConfirmedAt = null
    this.registrationStartedAt = null
    this.clearRegistrationTimers()
    this.socketRegistered = false
    this.rebinding = false
    this.refused = null
    this.closeReport.discard()
    const cursor = this.runner.observe()?.position
    const fenced = this.runner.fenceLocalExecution()
    this.localRecovery?.noteFence(fenced)
    this.abortRequests()
    this.ready = false
    this.registrationPending = false
    this.connection++
    const endpoint = this.endpoint
    const snapshot = this.snapshot
    this.endpoint = null
    this.publish(false, 'Playback connection lost. Remote playback may still be active.')
    const previous = this.recovery
    const stopping = this.runner.disconnect().catch(error => { this.fail(error); return null })
    const closing = Promise.all([previous, stopping]).then(([, observation]) => {
      if (endpoint && snapshot) {
        const position = observation?.position ?? cursor ?? snapshot.position
        this.closed = { endpoint, checkpoint: this.checkpointAt(snapshot, position) }
      }
      return this.closed
    })
    retirement = { owner: this.ownership, closing }
    this.recovery = closing.then(() => {})
  }

  private clearRegistrationTimers(): void {
    clearTimeout(this.registrationRetry)
    clearTimeout(this.registrationDeadline)
    this.registrationRetry = undefined
    this.registrationDeadline = undefined
  }

  /** Whether any player of this page is audible, whoever started it. */
  private playing(): boolean {
    return getPlaybackAdapters().some(adapter => adapter.observe().observedState === 'playing')
  }

  private opened(): void {
    if (!this.current()) return
    // The socket this one replaced will never answer a registration that was sent on it.
    this.registrationPending = false
    this.socketRegistered = false
    this.clearRegistrationTimers()
    // An endpoint that is still bound means the socket was replaced without a close, and the page
    // keeps playing. The server reaches a page only through a socket that carries its
    // registration, so a page that stays without one for a command's lifetime cannot be
    // commanded any more: it then stops as it does after a close.
    if (this.endpoint) this.registrationDeadline = setTimeout(() => this.registrationOverdue(), COMMAND_LIFETIME_MS)
    this.register()
  }

  private register(): void {
    this.reconnect().catch(error => {
      this.fail(error)
      this.registerAgainWhileBound()
    })
  }

  /**
   * A page that plays through a replaced socket has nobody to press Retry for it: a registration
   * that failed, was refused or stayed unanswered is sent again for as long as the socket is open.
   */
  private registerAgainWhileBound(delay = REGISTRATION_RETRY_MS): void {
    const due = () => this.current() && this.endpoint !== null && !this.socketRegistered && !this.suspended && this.transport.isConnected()
    if (!due()) return
    clearTimeout(this.registrationRetry)
    this.registrationRetry = setTimeout(() => {
      this.registrationRetry = undefined
      if (!due()) return
      this.registrationPending = false
      this.register()
    }, delay)
  }

  private registrationOverdue(): void {
    this.registrationDeadline = undefined
    if (!this.current() || this.socketRegistered || !this.endpoint) return
    this.disconnect()
    if (this.transport.isConnected()) this.register()
  }

  private async reconnect(): Promise<void> {
    if (this.registrationPending || this.suspended) return
    this.registrationPending = true
    this.registrationStartedAt = Date.now()
    const connection = ++this.connection
    try {
      await this.sendRegistration(connection)
    } catch (error) {
      // A later attempt or a disconnect took over: this failure describes nothing that is current.
      if (connection !== this.connection) return
      this.registrationPending = false
      throw error
    }
  }

  private async sendRegistration(connection: number): Promise<void> {
    const current = () => this.current() && connection === this.connection && this.transport.isConnected()
    // An endpoint that is still bound means the socket was replaced without a close. The page
    // never stopped and still answers for its own players, so it fences nothing, recovers nothing
    // of its own and registers again as the same page; the server then keeps its place.
    const bound = this.endpoint !== null
    await this.recovery
    if (!bound && retirement && this.ownership.principal !== null && retirement.owner.principal === this.ownership.principal) this.closed = await retirement.closing
    if (!current()) return
    const closed = bound ? null : this.closed
    if (closed) this.returning = true
    // A player that survived the stop still speaks for itself: reporting its incarnation closed,
    // or recovering its record, would tell the server that a playing page had stopped.
    const silent = closed === null || !this.playing()
    if (closed) {
      this.closed = null
      retirement = null
      if (silent) await this.reportClosed(closed)
      if (!current()) return
    }
    if (this.localRecovery) {
      const initializing = this.localRecovery.initialize(record => this.recoverLocal(record), bound || !silent ? 'keep' : 'recover')
      this.publishRecovery(bound)
      await initializing
      if (!current()) return
      this.publishRecovery(bound)
    }
    this.clientRequestId = this.localRecovery?.tabId ?? this.endpointId
    for (const waiter of this.identityWaiters) waiter.resolve(this.clientRequestId)
    await this.withRequest(signal => AXIOS_INSTANCE.post('/api/devices', { deviceId: getDeviceId(), name: getDeviceName() }, { signal }))
    if (!current()) return
    const adapters = (['song', 'video'] as const).map(kind => getPlaybackAdapter(kind)).filter(adapter => adapter !== undefined)
    const capabilities: string[] = adapters.map(adapter => adapter.kind)
    if (adapters.length > 0 && adapters.every(adapter => adapter.supportsVolume)) capabilities.push('volume')
    this.localQualified = !!this.localRecovery?.isEligible() && adapters.length > 0 && adapters.every(adapter => adapter.localRecovery?.eligible)
    this.rebinding = bound
    this.transport.send({ type: 'session.endpoint', deviceId: getDeviceId(), endpointId: this.localRecovery?.tabId ?? this.endpointId, name: getDeviceName(), capabilities,
      pageId: playbackPageId,
      ...(this.localRecovery ? { tabId: this.localRecovery.tabId, localRecoveryEligible: this.localQualified } : {}),
    })
    if (bound) this.registerAgainWhileBound(REQUEST_TIMEOUT_MS)
  }

  /** Shows the recovery progress without calling a page that never disconnected disconnected. */
  private publishRecovery(bound: boolean): void {
    if (!bound) this.publish(false, null)
    else this.publish(this.ready, this.client.getQueryData<PlaybackConnection>(PLAYBACK_CONNECTION_KEY)?.error ?? null)
  }

  /**
   * Tells the server that an incarnation of this page has gone and its players are silent, so the
   * session is released from it at once. The registration that follows does not depend on the
   * answer: a page that registers again keeps its place, and a runner that holds no player then
   * reports itself stopped.
   */
  private async reportClosed(closed: ClosedIncarnation): Promise<void> {
    try {
      await this.post(CLOSE_REPORT_PATH, { incarnationId: closed.endpoint.incarnationId, checkpoint: closed.checkpoint }, closed.endpoint)
    } catch (error) {
      console.warn('[PlaybackSession] The close report for the previous registration was not accepted:', this.refusal(error, 'The request failed.').message)
    }
  }

  /** Where this page's player stands, or the snapshot's cursor while it holds none. */
  private cursor(snapshot: PlaybackSnapshotResource): number {
    return this.runner.observe()?.position ?? snapshot.position
  }

  private checkpointAt(snapshot: Pick<PlaybackSnapshotResource, 'revision' | 'media' | 'currentTrackIndex'>, position: number): PlaybackRecoveryCheckpoint {
    return { revision: snapshot.revision, media: snapshot.media, currentTrackIndex: snapshot.currentTrackIndex, position }
  }

  private async recoverLocal(record: PlaybackRecoveryRecord): Promise<void> {
    const snapshot = await this.post('/api/session/recover-local', { incarnationId: record.incarnationId, tabId: record.tabId,
      recoveryCredential: record.recoveryCredential, checkpoint: record.checkpoint })
    const result = snapshot.recoveryResult
    if (result?.incarnationId !== record.incarnationId || result.outcome !== 'recovered') throw new Error('The server did not confirm local playback recovery.')
  }

  private async acceptEndpoint(snapshot: PlaybackSnapshotResource): Promise<void> {
    const generation = this.connection
    const endpoint = snapshot.endpoint as RecoveryEndpoint
    if (!endpoint?.capability) return
    if (this.localRecovery) await this.localRecovery.registered(endpoint, this.checkpointAt(snapshot, snapshot.position), this.localQualified)
    if (!this.current() || this.suspended || generation !== this.connection) return
    // A page that registered again through a replaced socket was never fenced. Rearming its
    // adapters would cancel what they are executing, so only a fenced page is rearmed.
    const fenced = this.endpoint === null || this.executionBlocked
    const returning = this.returning
    this.returning = false
    this.registrationPending = false
    this.clearRegistrationTimers()
    this.socketRegistered = true
    this.rebinding = false
    if (this.endpoint?.incarnationId !== endpoint.incarnationId) this.refused = null
    this.endpoint = endpoint
    this.incarnations.add(endpoint.incarnationId)
    this.runner.bind(endpoint)
    // The answer is the first session this incarnation is shown. A page that kept playing through
    // a replaced socket, and was taken over while it could not be reached, learns here that the
    // server kept no place for it, as any other snapshot would tell it.
    if (this.snapshot) this.letGoUnlessNamed(this.snapshot)
    this.executionBlocked = false
    if (fenced) for (const adapter of getPlaybackAdapters()) adapter.localRecovery?.rearmAfterRegistration()
    this.ready = true
    this.presenceConfirmedAt = this.registrationStartedAt
    this.publish(true, null)
    this.closeReport.prepare()
    this.refreshRequests(true)
    this.refreshTransfers(true)
    this.dispatchCommands(snapshot)
    // A command for the new incarnation may have been relayed while the answer was being bound.
    if (this.snapshot && this.snapshot !== snapshot) this.dispatchCommands(this.snapshot)
    for (const ready of this.registrationReady) ready()
    // The session may still name this page as its runner although the page stopped its players
    // when it disconnected: its close report was lost, and the registration kept its place. It
    // says so at once instead of leaving the session to show playback that is not there.
    if (returning && this.snapshot?.activeIncarnationId === endpoint.incarnationId && this.runner.observe() === null) {
      this.observe().catch(error => this.fail(error))
    }
  }

  private async ensureLocalEndpoint(): Promise<PlaybackEndpointResource> {
    if (!this.current() || !this.ready || !this.endpoint) throw new Error('Playback connection is unavailable.')
    const available = () => this.snapshot?.destinations.some(destination =>
      destination.incarnationId === this.endpoint?.incarnationId && destination.available)
    const presenceAge = this.presenceConfirmedAt === null ? null : Date.now() - this.presenceConfirmedAt
    if (this.snapshot?.activeIncarnationId === this.endpoint.incarnationId && available()
      && presenceAge !== null && presenceAge >= 0 && presenceAge < PRESENCE_INTERVAL_MS) return this.endpoint
    performance.mark('baander:ensure-local-observe', { detail: { available: available(), presenceAge, active: this.snapshot?.activeIncarnationId ?? null, destinations: this.snapshot?.destinations.map(destination => `${destination.incarnationId}:${destination.available}`) ?? null } })
    await this.observe()
    const observedAvailable = available()
    performance.mark('baander:ensure-local-observed', { detail: { available: observedAvailable, current: this.current(), ready: this.ready, endpoint: this.endpoint?.incarnationId ?? null } })
    if (!this.current() || !this.ready || !this.endpoint) throw new Error('Playback connection is unavailable.')
    if (observedAvailable) return this.endpoint

    this.disconnect()
    let finish: (() => void) | undefined
    const readiness = this.withRequest(() => new Promise<void>(resolve => {
      finish = resolve
      this.registrationReady.add(resolve)
    }))
    try {
      await Promise.all([this.reconnect(), readiness])
    } catch (error) {
      // The registration was sent and stayed unanswered: a later retry may send another.
      this.registrationPending = false
      throw error
    } finally {
      if (finish) {
        this.registrationReady.delete(finish)
        finish()
      }
    }
    if (!this.current() || !this.ready || !this.endpoint || !available()) throw new Error('Local playback registration is unavailable. Retry the playback connection.')
    return this.endpoint
  }

  private async checkpointCommand(command: PlaybackCommandResource, signal: AbortSignal): Promise<void> {
    if (command.type === 'stop' || command.type === 'quiesce') return
    if (this.executionBlocked) throw new Error('Local playback requires a fresh registration after recovery storage failed.')
    if (!this.localQualified || !this.localRecovery) return
    try {
      await this.localRecovery.checkpoint({ revision: command.revision, media: command.payload.media,
        currentTrackIndex: command.payload.currentTrackIndex, position: command.payload.position })
      signal.throwIfAborted()
    } catch (error) {
      if (signal.aborted || !this.current()) throw error
      this.executionBlocked = true
      this.localRecovery.noteFence(this.runner.fenceLocalExecution())
      this.fail(error)
      throw error
    }
  }

  async pauseForRadio(): Promise<void> {
    if (!this.snapshot) throw new Error('Playback state is unavailable.')
    if (this.snapshot.transfer) throw new Error('Wait for playback transfer or recovery before starting radio.')
    if (!this.snapshot.media || (this.snapshot.desiredState === 'stopped' && this.snapshot.observedState === 'stopped')) return
    await this.request({ action: 'pause' })
  }

  async retry(): Promise<void> {
    if (!this.current()) throw new Error('Playback account changed.')
    if (this.ready && !this.executionBlocked) {
      this.refreshRequests(true)
      this.refreshTransfers(true)
      // A page that kept playing through a replaced socket is ready and still has to register on it.
      if (this.socketRegistered || this.registrationPending || !this.transport.isConnected()) return
      clearTimeout(this.registrationRetry)
      this.registrationRetry = undefined
      try { await this.reconnect() }
      catch (error) { this.fail(error); this.registerAgainWhileBound(); throw error }
      return
    }
    if (this.executionBlocked) this.disconnect()
    if (this.registrationPending) throw new Error('Playback registration is still pending.')
    this.publish(false, null)
    try {
      if (this.transport.isConnected()) await this.reconnect()
      else this.transport.reconnect()
    } catch (error) { this.fail(error); throw error }
  }

  private async acknowledge(ack: PlaybackAcknowledgement): Promise<void> {
    const endpoint = this.endpoint
    if (!endpoint || !this.ready) return
    try {
      await this.post('/api/session/ack', ack, endpoint)
    } catch (error) {
      // The page registered again while this answer was on its way, so the answer named an
      // incarnation that has been succeeded. The command comes again for the new one.
      if (this.endpoint !== endpoint) return
      throw error
    }
    if (ack.outcome === 'rejected') this.fail(ack.rejectionReason
      ? new MediaCompatibilityError(ack.rejectionReason)
      : new Error('Playback could not start or apply the control. Try again on the destination; it may require a user gesture.'))
    else await this.observe()
  }

  private async observe(): Promise<void> {
    const generation = this.connection
    const endpoint = this.endpoint
    const snapshot = this.snapshot
    if (!this.current() || !this.ready || !endpoint || !snapshot) return
    // A runner that holds no player and hears none plays nothing: it disconnected, stopped, and
    // was kept as the runner when it registered again. Saying so releases the session from it.
    const held = this.runner.observe()
    const silentRunner = held === null && snapshot.activeIncarnationId === endpoint.incarnationId && !this.playing()
    const observation = held ?? { observedState: silentRunner ? 'stopped' as const : 'unknown' as const, position: snapshot.position }
    if (this.localQualified && this.localRecovery) {
      try { await this.localRecovery.checkpoint(this.checkpointAt(snapshot, observation.position)) }
      catch (error) {
        if (generation !== this.connection || !this.current()) return
        this.executionBlocked = true; this.localRecovery.noteFence(this.runner.fenceLocalExecution()); throw error
      }
    }
    // Transfer activation can replace the snapshot without increasing its command revision.
    if (generation !== this.connection || endpoint !== this.endpoint || snapshot !== this.snapshot || !this.ready || !this.current()) return
    const startedAt = Date.now()
    try {
      await this.post('/api/session/observe', { incarnationId: endpoint.incarnationId, expectedRevision: snapshot.revision, ...observation }, endpoint)
      if (generation === this.connection && endpoint === this.endpoint && this.ready && this.current()) {
        this.presenceConfirmedAt = startedAt
        // The proof inside a prepared close report ages; each presence report replaces it.
        this.closeReport.prepare()
      }
    } catch (error) {
      this.presenceConfirmedAt = null
      throw error
    }
  }

  request(intent: PlaybackIntent): Promise<void> {
    const captured = structuredClone(intent)
    performance.mark('baander:intent-request', { detail: { action: captured.action, ready: this.ready, endpoint: this.endpoint?.incarnationId ?? null, active: this.snapshot?.activeIncarnationId ?? null, pending: this.pendingRequests.size } })
    // Scrubbing commits several positions while an earlier intent is still executing;
    // only the last one matters, so consecutive queued seeks collapse into one. A seek
    // never jumps ahead of another queued intent: a later non-seek intent closes the window.
    if (captured.action === 'seek' && this.queuedSeek) {
      this.queuedSeek.intent.position = captured.position
      return this.queuedSeek.result
    }
    const queued = captured.action === 'seek' ? { intent: captured, result: Promise.resolve() } : null
    const result = this.intentTail.then(() => {
      if (queued && this.queuedSeek === queued) this.queuedSeek = null
      return this.executeIntent(captured)
    })
    if (queued) queued.result = result
    this.queuedSeek = queued
    this.intentTail = result.catch(() => {})
    return result
  }

  private async executeIntent(intent: PlaybackIntent): Promise<void> {
    performance.mark('baander:intent-execute', { detail: { action: intent.action, current: this.current(), ready: this.ready, snapshot: !!this.snapshot, endpoint: this.endpoint?.incarnationId ?? null, active: this.snapshot?.activeIncarnationId ?? null, pending: this.pendingRequests.size, transfer: !!this.snapshot?.transfer } })
    if (!this.current() || !this.ready || !this.snapshot || !this.endpoint) throw new Error('Playback connection is unavailable.')
    try {
      if (intent.action === 'stop-local-video') {
        // A local video close only stops a session this endpoint actively plays. Another
        // runner, another video or a live handoff owns stopping, so the close resolves
        // without a control and records why. A transfer whose destination never proved it
        // stopped keeps the session fenced for every tab, and the server refuses every
        // control until it settles; silencing this tab's own video can start nothing, so
        // the view still closes.
        if (this.endpoint.incarnationId !== intent.incarnationId || this.snapshot.activeIncarnationId !== intent.incarnationId
          || this.snapshot.media?.kind !== 'video' || this.snapshot.media.publicId !== intent.videoId) {
          performance.mark('baander:intent-refused', { detail: { action: intent.action, reason: 'not the active local video' } })
          return
        }
        if (this.snapshot.transfer) {
          const uncertain = this.snapshot.transfer.state === 'uncertain'
          performance.mark('baander:intent-refused', { detail: { action: intent.action, reason: uncertain ? 'destination uncertain' : 'transfer in progress' } })
          if (uncertain) await this.runner.silence().catch(error => this.fail(error))
          return
        }
        intent = { action: 'stop' }
      }
      if (this.pendingRequests.size > 0 && intent.action !== 'stop') throw new Error('A previous playback result is unconfirmed. Retry its result before sending another control.')
      if (intent.action === 'next' || intent.action === 'previous') intent = this.navigate(intent)
      if ((intent.action === 'load' || intent.action === 'play')
        && (!this.snapshot.activeIncarnationId || this.snapshot.activeIncarnationId === this.endpoint.incarnationId)) {
        await this.ensureLocalEndpoint()
      }
      if ((intent.action === 'load' || intent.action === 'play') && hasUnconfirmedPlayback(this.snapshot)) {
        // Only a Play or a song selection that wanted to play is worth starting after an override:
        // a queue restore wants paused, and a movie opens its view from its own Play button.
        const wantsPlaying = intent.action === 'play'
          || (intent.action === 'load' && intent.media.kind === 'song' && intent.desiredState === 'playing')
        this.refused = wantsPlaying ? new RefusedRequest(intent, Date.now()) : null
        throw new Error(UNCONFIRMED_PLAYBACK_REFUSAL)
      }
      if (intent.action === 'queue' && 'operation' in intent) {
        intent = this.editQueue(intent.operation)
        if (intent.action === 'queue' && 'queue' in intent && intent.queue.length === 0) await this.executeIntent({ action: 'stop' })
      }
      if (intent.action === 'load' && intent.media.kind === 'song' && intent.queue === undefined) {
        const queue = [...this.snapshot.queue]
        let index = queue[this.snapshot.currentTrackIndex] === intent.media.publicId ? this.snapshot.currentTrackIndex : queue.indexOf(intent.media.publicId)
        if (index < 0) { index = queue.length; queue.push(intent.media.publicId) }
        intent = { ...intent, queue, currentTrackIndex: index }
      }
      const payload = { requestId: crypto.randomUUID(), expectedRevision: this.snapshot.revision, ...intent }
      await this.sendControl(payload)
      if ((intent.action === 'load' || intent.action === 'play') && !this.snapshot.activeIncarnationId && !this.snapshot.transfer) {
        await this.performTransfer(this.endpoint.incarnationId)
      }
      // A request of this tab went through: an earlier refusal no longer says what the listener wants.
      this.refused = null
      performance.mark('baander:intent-done', { detail: { action: intent.action, active: this.snapshot?.activeIncarnationId ?? null } })
    } catch (error) {
      performance.mark('baander:intent-error', { detail: { action: intent.action, message: error instanceof Error ? error.message : String(error) } })
      this.fail(error); throw error
    }
  }

  private async postControl(payload: Record<string, unknown>): Promise<ControlSnapshot> {
    const endpoint = this.endpoint
    const response = await this.post('/api/session/control', {
      ...payload,
      ...(endpoint ? { incarnationId: endpoint.incarnationId } : {}),
    }, endpoint ?? undefined)
    this.dispatchCommands(response)
    return response
  }

  private sendControl(payload: Record<string, unknown>): Promise<void> {
    return new Promise((resolve, reject) => {
      const request: PendingRequest = { payload, resolve, reject, lastRevision: this.snapshot!.revision, reading: true, commandId: null }
      this.pendingRequests.set(String(payload.requestId), request)
      this.postControl(payload).then(response => {
        request.lastRevision = response.revision
        this.completeRequest(request, response.requestResult)
      }).catch(error => {
        const status = (error as { response?: { status?: number } }).response?.status
        if (status && status >= 400 && status < 500) this.pendingRequests.delete(String(payload.requestId))
        this.rejectUnconfirmed(request, error)
      }).finally(() => {
        request.reading = false
        this.refreshRequests()
      })
    })
  }

  /** The server explains playback refusals in the response body; prefer its reason over the transport status text. */
  private refusal(error: unknown, fallback: string): Error {
    if (error instanceof Error) {
      const reason = (error as { response?: { data?: { error?: { message?: unknown } } } }).response?.data?.error?.message
      if (typeof reason === 'string' && reason !== '') return new Error(reason)
      return error
    }
    return new Error(fallback)
  }

  private rejectUnconfirmed(request: PendingRequest, error: unknown): void {
    const refusal = this.refusal(error, 'The playback result could not be confirmed.')
    request.reject(refusal)
    this.fail(refusal)
  }

  private completeRequest(request: PendingRequest, result: RequestResult | null | undefined): void {
    if (!result || result.requestId !== request.payload.requestId) {
      request.reject(new Error('The server did not return this playback request’s result.'))
      return
    }
    if (request.commandId && request.commandId !== result.commandId) {
      request.reject(new Error('The server changed the playback command identity.'))
      return
    }
    request.commandId = result.commandId
    if (result.outcome === null) return
    this.pendingRequests.delete(result.requestId)
    if (['retained', 'applied', 'playing', 'paused', 'stopped'].includes(result.outcome)) {
      request.resolve()
      this.publish(this.ready, null)
    } else {
      const error = result.outcome === 'rejected' && result.rejectionReason
        ? new MediaCompatibilityError(result.rejectionReason)
        : new Error(result.outcome === 'superseded' ? 'The playback request was superseded.'
          : result.outcome === 'never_effective' ? 'The destination did not answer the playback request. Nothing changed there; try again.'
            : 'The destination rejected the playback request.')
      request.reject(error)
      this.fail(error)
    }
  }

  private refreshRequests(force = false): void {
    if (!this.current() || !this.ready || !this.snapshot) return
    for (const request of this.pendingRequests.values()) {
      if (request.reading || (!force && request.lastRevision >= this.snapshot.revision)) continue
      request.reading = true
      request.lastRevision = this.snapshot.revision
      this.postControl(request.payload).then(response => {
        this.completeRequest(request, response.requestResult)
      }).catch(error => {
        this.rejectUnconfirmed(request, error)
      }).finally(() => {
        request.reading = false
        this.refreshRequests()
      })
    }
  }

  private editQueue(operation: PlaybackQueueOperation): PlaybackIntent {
    const snapshot = this.snapshot!
    const queue = [...snapshot.queue]
    if (operation.type === 'clear') return { action: 'queue', queue: [], currentTrackIndex: 0 }
    const find = (id: string | null, occurrence = 0) => {
      if (id === null) return -1
      return queue.findIndex(value => value === id && occurrence-- === 0)
    }
    let current = snapshot.currentTrackIndex
    if (operation.type === 'append') queue.push(...operation.tracks.filter(id => !queue.includes(id)))
    if (operation.type === 'insert') {
      const previous = find(operation.afterPublicId, operation.afterOccurrence)
      if (operation.afterPublicId !== null && previous < 0) throw new Error('The insertion point is no longer in the queue.')
      const at = operation.afterPublicId === null ? queue.length : previous + 1
      queue.splice(at, 0, ...operation.tracks)
      if (at <= current) current += operation.tracks.length
    }
    if (operation.type === 'remove' || operation.type === 'reorder') {
      const from = find(operation.publicId, operation.occurrence)
      if (from < 0) throw new Error('The selected queue item is no longer available.')
      const currentWasMoved = from === current
      const [item] = queue.splice(from, 1)
      if (from < current) current--
      if (operation.type === 'reorder') {
        const target = find(operation.beforePublicId, operation.beforeOccurrence)
        const to = target < 0 ? queue.length : target
        queue.splice(to, 0, item)
        current = currentWasMoved ? to : current + (to <= current ? 1 : 0)
      } else if (currentWasMoved && queue.length > 0) {
        current = Math.min(from, queue.length - 1)
        return { action: 'load', media: { kind: 'song', publicId: queue[current] }, queue, currentTrackIndex: current, position: 0, desiredState: snapshot.desiredState }
      }
    }
    return { action: 'queue', queue, currentTrackIndex: queue.length ? Math.max(0, current) : 0 }
  }

  private navigate(intent: Extract<PlaybackIntent, { action: 'next' | 'previous' }>): PlaybackIntent {
    const snapshot = this.snapshot!
    if (intent.action === 'previous' && snapshot.position > 3) return { action: 'seek', position: 0 }
    const unavailable = new Set(intent.navigation?.unavailablePublicIds)
    const playable = (index: number) => snapshot.queue[index] !== undefined && !unavailable.has(snapshot.queue[index])
    let index: number | undefined
    if (intent.action === 'previous') {
      for (let offset = 1; offset <= snapshot.queue.length; offset++) {
        const previous = (snapshot.currentTrackIndex - offset + snapshot.queue.length) % snapshot.queue.length
        if (playable(previous)) { index = previous; break }
      }
    } else {
      const order = intent.navigation?.shuffle ? intent.navigation.shuffleBag : snapshot.queue.map((_, position) => position)
      const position = order.indexOf(snapshot.currentTrackIndex)
      const following = order.slice(position + 1)
      const candidates = intent.navigation?.repeat === 'all' ? [...following, ...order.slice(0, position + 1)] : following
      index = candidates.find(playable)
    }
    if (index === undefined) return { action: 'stop' }
    return { action: 'load', media: { kind: 'song', publicId: snapshot.queue[index] }, queue: snapshot.queue,
      currentTrackIndex: index, position: 0, desiredState: 'playing' }
  }

  transfer(incarnationId: string): Promise<void> {
    const local = incarnationId === this.endpoint?.incarnationId
    const result = this.intentTail.then(async () => {
      if (local) {
        if (hasUnconfirmedPlayback(this.snapshot) && this.snapshot?.activeIncarnationId !== this.endpoint?.incarnationId) {
          this.refused = null
          throw new Error(UNCONFIRMED_PLAYBACK_REFUSAL)
        }
        incarnationId = (await this.ensureLocalEndpoint()).incarnationId
      }
      await this.performTransfer(incarnationId)
    })
    this.intentTail = result.catch(() => {})
    return result
  }

  private async performTransfer(incarnationId: string): Promise<void> {
    if (!this.current() || !this.ready || !this.snapshot) throw new Error('Playback connection is unavailable.')
    try {
      const payload = { requestId: crypto.randomUUID(), expectedRevision: this.snapshot.revision, targetIncarnationId: incarnationId }
      await new Promise<void>((resolve, reject) => {
        this.transfers.add({ payload, attemptId: null, resolve, reject, reading: false, lastRevision: -1 })
        this.refreshTransfers(true)
      })
    } catch (error) { this.fail(error); throw error }
  }

  private completeTransfer(transfer: PendingTransfer, result: TransferResult | null | undefined): void {
    if (!result || result.requestId !== transfer.payload.requestId
      || (transfer.attemptId && result.attemptId !== transfer.attemptId)) {
      transfer.reject(new Error('The server did not return this playback transfer’s result.'))
      this.transfers.delete(transfer)
      return
    }
    transfer.attemptId = result.attemptId
    if (!['committed', 'failed', 'uncertain'].includes(result.state)) return
    this.transfers.delete(transfer)
    if (result.state === 'committed') transfer.resolve()
    else transfer.reject(result.state === 'failed' && result.rejectionReason
      ? new MediaCompatibilityError(result.rejectionReason)
      : new Error(result.state === 'uncertain'
        ? 'Playback at the destination is uncertain. Confirm it stopped before resuming here.'
        : 'The playback transfer failed or was superseded.'))
  }

  private refreshTransfers(force = false): void {
    if (!this.current() || !this.ready || !this.snapshot) return
    for (const transfer of this.transfers) {
      if (transfer.reading || (!force && transfer.lastRevision >= this.snapshot.revision)) continue
      transfer.reading = true
      transfer.lastRevision = this.snapshot.revision
      this.post('/api/session/transfer', transfer.payload).then(response => {
        transfer.lastRevision = Math.max(transfer.lastRevision, response.revision)
        this.completeTransfer(transfer, response.transferResult)
      }).catch(error => {
        const refusal = this.refusal(error, 'The playback transfer result could not be confirmed.')
        transfer.reject(refusal)
        const status = (error as { response?: { status?: number } })?.response?.status
        if (status && status >= 400 && status < 500) this.transfers.delete(transfer)
        this.fail(refusal)
      }).finally(() => {
        transfer.reading = false
        this.refreshTransfers()
      })
    }
  }

  /**
   * Ends the hold of a destination that is no longer registered and never confirmed that it
   * stopped, because the listener chose to play here anyway. The server decides whether that
   * destination is really gone. The tab then starts what it was last refused, when that was a
   * Play or a song selection and recent enough to still be what the listener wants.
   */
  override(): Promise<void> {
    const result = this.intentTail.then(() => this.performOverride())
    this.intentTail = result.catch(() => {})
    return result
  }

  private async performOverride(): Promise<void> {
    try {
      // The server takes an override only from a tab whose own registration holds.
      const endpoint = await this.ensureLocalEndpoint()
      if (!this.snapshot) throw new Error('Playback connection is unavailable.')
      const refused = this.refused
      const payload: OverridePlaybackRequest = { requestId: crypto.randomUUID(), expectedRevision: this.snapshot.revision, incarnationId: endpoint.incarnationId }
      let answer: ControlSnapshot
      try { answer = await this.post(OVERRIDE_PATH, payload, endpoint) }
      catch (error) { throw this.refusal(error, 'The override could not be confirmed.') }
      if (answer.requestResult?.requestId !== payload.requestId || answer.requestResult.outcome !== 'overridden') {
        throw new Error('The server did not confirm the override.')
      }
      this.refused = null
      this.publish(this.ready, null)
      // Without a fresh refusal, the override only takes over and starts nothing.
      const intent = refused?.freshIntent(Date.now())
      if (intent && !answer.activeIncarnationId && !answer.transfer) {
        await this.executeIntent(intent)
      }
    } catch (error) { this.fail(error); throw error }
  }

  async reconcile(): Promise<void> {
    if (!this.snapshot) return
    try { await this.post('/api/session/reconcile', { expectedRevision: this.snapshot.revision }) }
    catch (error) { this.fail(error); throw error }
  }

  dispose(): void {
    if (this.disposed) return
    this.disconnect()
    if (this.ownership.principal === null || captureOwnership().principal !== this.ownership.principal) retirement = null
    this.disposed = true
    for (const waiter of this.identityWaiters) waiter.reject(new Error('Playback account or runtime changed.'))
    for (const request of this.pendingRequests.values()) request.reject(new Error('Playback account or runtime changed.'))
    this.pendingRequests.clear()
    for (const transfer of this.transfers) transfer.reject(new Error('Playback account or runtime changed.'))
    this.transfers.clear()
    clearInterval(this.interval)
    this.cleanup.splice(0).forEach(stop => stop())
  }
}

let activeCoordinator: PlaybackSessionCoordinator | undefined

export function registerPlaybackCoordinator(coordinator: PlaybackSessionCoordinator): () => void {
  activeCoordinator = coordinator
  return () => { if (activeCoordinator === coordinator) activeCoordinator = undefined }
}

export function getPlaybackClientRequestId(signal: AbortSignal): Promise<string> {
  return activeCoordinator ? activeCoordinator.getClientRequestId(signal) : Promise.reject(new Error('Playback connection is unavailable.'))
}

export function transferPlayback(incarnationId: string): Promise<void> {
  return activeCoordinator ? activeCoordinator.transfer(incarnationId) : Promise.reject(new Error('Playback connection is unavailable.'))
}

export function overridePlayback(): Promise<void> {
  return activeCoordinator ? activeCoordinator.override() : Promise.reject(new Error('Playback connection is unavailable.'))
}

export function reconcilePlayback(): Promise<void> {
  return activeCoordinator ? activeCoordinator.reconcile() : Promise.reject(new Error('Playback connection is unavailable.'))
}

export function retryPlaybackConnection(): Promise<void> {
  return activeCoordinator ? activeCoordinator.retry() : Promise.reject(new Error('Playback connection is unavailable.'))
}

export function pausePlaybackForRadio(): Promise<void> {
  return activeCoordinator ? activeCoordinator.pauseForRadio() : Promise.resolve()
}
