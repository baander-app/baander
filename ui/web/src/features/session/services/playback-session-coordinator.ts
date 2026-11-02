import type { QueryClient } from '@tanstack/react-query'
import type { PlaybackEndpointResource, PlaybackSnapshotResource } from '@/shared/api-client/gen/endpoints'
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
import { hasUnconfirmedPlayback } from './playback-status'

type RequestResult = NonNullable<PlaybackSnapshotResource['requestResult']>
type ControlSnapshot = PlaybackSnapshotResource
interface PendingRequest {
  payload: Record<string, unknown>
  resolve: () => void
  reject: (error: Error) => void
  lastRevision: number
  commandId: string | null
  reading: boolean
}

type StopProof = { endpoint: PlaybackEndpointResource; position: number }
let retirement: { owner: ReturnType<typeof captureOwnership>; recovery: Promise<StopProof | null> } | null = null

const PRESENCE_INTERVAL_MS = 10_000

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
  private readonly runner = new PlaybackCommandRunner(ack => this.acknowledge(ack), (command, signal) => this.checkpointCommand(command, signal))
  private endpoint: PlaybackEndpointResource | null = null
  private snapshot: PlaybackSnapshotResource | null = null
  private readonly transfers = new Set<{ attemptId: string | null; target: string; resolve: () => void; reject: (error: Error) => void }>()
  private readonly httpRequests = new Set<AbortController>()
  private readonly pendingRequests = new Map<string, PendingRequest>()
  private readonly registrationReady = new Set<() => void>()
  private intentTail: Promise<void> = Promise.resolve()
  private disposed = false
  private connection = 0
  private localQualified = false
  private suspended = false
  private executionBlocked = false
  private presenceConfirmedAt: number | null = null
  private registrationStartedAt: number | null = null
  private ready = false
  private registrationPending = false
  private cleanup: (() => void)[] = []
  private interval: ReturnType<typeof setInterval> | undefined
  private recovery: Promise<void> = Promise.resolve()
  private stopProof: StopProof | null = null

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
      onOpen: () => { this.reconnect().catch(error => { this.registrationPending = false; this.fail(error) }) },
      onClose: () => { this.disconnect() },
      onMessage: message => this.message(message),
      onError: error => this.fail(error),
    }))
    this.cleanup.push(this.transport.retain())
    if (this.transport.isConnected()) this.reconnect().catch(error => { this.registrationPending = false; this.fail(error) })
    const hide = () => {
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
    delete published.recoveryResult
    this.client.setQueryData(PLAYBACK_SESSION_KEY, published)
    this.runner.fence(snapshot.revision)
    this.refreshRequests()
    this.completeTransfers(snapshot)
    return snapshot
  }

  private async withRequest<T>(operation: (signal: AbortSignal) => Promise<T>): Promise<T> {
    if (!this.current()) throw new Error('Playback account changed.')
    const request = new AbortController()
    this.httpRequests.add(request)
    const timeout = setTimeout(() => request.abort(new Error('Playback request timed out; its result is unconfirmed.')), 15_000)
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
      this.fail(new Error('message' in message && typeof message.message === 'string' ? message.message : 'Playback request failed.'))
      return
    }
    if (message.type !== 'session.snapshot' || !('data' in message)) return
    try {
      const snapshot = this.apply(message.data)
      if (this.snapshot && snapshot.revision < this.snapshot.revision) return
      if (snapshot.endpoint?.capability && this.transport.isConnected()) {
        this.acceptEndpoint(snapshot).catch(error => { this.registrationPending = false; this.fail(error) })
        return
      }
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
    this.presenceConfirmedAt = null
    this.registrationStartedAt = null
    const fenced = this.runner.fenceLocalExecution()
    this.localRecovery?.noteFence(fenced)
    this.abortRequests()
    this.ready = false
    this.registrationPending = false
    this.connection++
    const endpoint = this.endpoint
    this.endpoint = null
    this.publish(false, 'Playback connection lost. Remote playback may still be active.')
    const previous = this.recovery
    const stopping = this.runner.disconnect()
    const stopped = Promise.all([previous, stopping]).then(([, observation]) => {
      const proof = endpoint && observation?.observedState === 'stopped' ? { endpoint, position: observation.position } : this.stopProof
      this.stopProof = proof
      return proof
    }).catch(error => { this.fail(error); return null })
    retirement = { owner: this.ownership, recovery: stopped }
    this.recovery = stopped.then(() => {})
  }

  private async reconnect(): Promise<void> {
    if (this.registrationPending || this.suspended) return
    this.registrationPending = true
    this.registrationStartedAt = Date.now()
    const connection = ++this.connection
    const current = () => this.current() && connection === this.connection && this.transport.isConnected()
    await this.recovery
    if (retirement && this.ownership.principal !== null && retirement.owner.principal === this.ownership.principal) this.stopProof = await retirement.recovery
    if (!current()) return
    if (this.localRecovery) {
      const initializing = this.localRecovery.initialize(record => this.recoverLocal(record))
      this.publish(false, null)
      await initializing
      if (!current()) return
      this.publish(false, null)
    }
    if (this.stopProof && this.localRecovery?.wasRecovered(this.stopProof.endpoint.incarnationId)) { this.stopProof = null; retirement = null }
    const proof = this.stopProof
    if (proof) {
      const response = await this.withRequest(signal => AXIOS_INSTANCE.get('/api/session/snapshot', {
        signal,
        params: { incarnationId: proof.endpoint.incarnationId },
        headers: { 'X-Baander-Playback-Endpoint': proof.endpoint.capability },
      }))
      if (!current()) return
      const old = this.apply(response.data?.data ?? response.data)
      await this.post('/api/session/observe', {
        incarnationId: proof.endpoint.incarnationId, expectedRevision: old.revision,
        observedState: 'stopped', position: proof.position,
      }, proof.endpoint)
      if (!current()) return
      for (const command of old.commands) {
        if (command.type !== 'stop' || command.incarnationId !== proof.endpoint.incarnationId) continue
        await this.post('/api/session/ack', {
          commandId: command.id, attemptId: command.attemptId, revision: command.revision,
          incarnationId: command.incarnationId, epoch: command.epoch, outcome: 'stopped', position: proof.position,
        }, proof.endpoint)
        if (!current()) return
      }
      this.stopProof = null
      retirement = null
    }
    await this.withRequest(signal => AXIOS_INSTANCE.post('/api/devices', { deviceId: getDeviceId(), name: getDeviceName() }, { signal }))
    if (!current()) return
    const adapters = (['song', 'video'] as const).map(kind => getPlaybackAdapter(kind)).filter(adapter => adapter !== undefined)
    const capabilities: string[] = adapters.map(adapter => adapter.kind)
    if (adapters.length > 0 && adapters.every(adapter => adapter.supportsVolume)) capabilities.push('volume')
    this.localQualified = !!this.localRecovery?.isEligible() && adapters.length > 0 && adapters.every(adapter => adapter.localRecovery?.eligible)
    this.transport.send({ type: 'session.endpoint', deviceId: getDeviceId(), endpointId: this.localRecovery?.tabId ?? this.endpointId, name: getDeviceName(), capabilities,
      ...(this.localRecovery ? { tabId: this.localRecovery.tabId, localRecoveryEligible: this.localQualified } : {}),
    })
  }

  private checkpoint(snapshot: Pick<PlaybackSnapshotResource, 'revision' | 'media' | 'currentTrackIndex' | 'position'>): PlaybackRecoveryCheckpoint {
    return { revision: snapshot.revision, media: snapshot.media, currentTrackIndex: snapshot.currentTrackIndex, position: snapshot.position }
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
    if (this.localRecovery) await this.localRecovery.registered(endpoint, this.checkpoint(snapshot), this.localQualified)
    if (!this.current() || this.suspended || generation !== this.connection) return
    this.registrationPending = false
    this.endpoint = endpoint
    this.runner.bind(endpoint)
    this.executionBlocked = false
    for (const adapter of getPlaybackAdapters()) adapter.localRecovery?.rearmAfterRegistration()
    this.ready = true
    this.presenceConfirmedAt = this.registrationStartedAt
    this.publish(true, null)
    this.refreshRequests(true)
    this.dispatchCommands(snapshot)
    for (const ready of this.registrationReady) ready()
  }

  private async ensureLocalEndpoint(): Promise<PlaybackEndpointResource> {
    if (!this.current() || !this.ready || !this.endpoint) throw new Error('Playback connection is unavailable.')
    const available = () => this.snapshot?.destinations.some(destination =>
      destination.incarnationId === this.endpoint?.incarnationId && destination.available)
    const presenceAge = this.presenceConfirmedAt === null ? null : Date.now() - this.presenceConfirmedAt
    if (this.snapshot?.activeIncarnationId === this.endpoint.incarnationId && available()
      && presenceAge !== null && presenceAge >= 0 && presenceAge < PRESENCE_INTERVAL_MS) return this.endpoint
    await this.observe()
    if (!this.current() || !this.ready || !this.endpoint) throw new Error('Playback connection is unavailable.')
    if (available()) return this.endpoint

    this.disconnect()
    let finish: (() => void) | undefined
    const readiness = this.withRequest(() => new Promise<void>(resolve => {
      finish = resolve
      this.registrationReady.add(resolve)
    }))
    try {
      await Promise.all([this.reconnect(), readiness])
    } catch (error) {
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
    if (this.ready && !this.executionBlocked) { this.refreshRequests(true); return }
    if (this.executionBlocked) this.disconnect()
    if (this.registrationPending) throw new Error('Playback registration is still pending.')
    this.publish(false, null)
    try {
      if (this.transport.isConnected()) await this.reconnect()
      else this.transport.reconnect()
    } catch (error) { this.registrationPending = false; this.fail(error); throw error }
  }

  private async acknowledge(ack: PlaybackAcknowledgement): Promise<void> {
    const endpoint = this.endpoint
    if (!endpoint || !this.ready) return
    await this.post('/api/session/ack', ack, endpoint)
    if (ack.outcome === 'rejected') this.fail(new Error('Playback could not start or apply the control. Try again on the destination; it may require a user gesture.'))
    else await this.observe()
  }

  private async observe(): Promise<void> {
    const generation = this.connection
    const endpoint = this.endpoint
    const snapshot = this.snapshot
    if (!this.current() || !this.ready || !endpoint || !snapshot) return
    const observation = this.runner.observe() ?? { observedState: 'unknown' as const, position: snapshot.position }
    if (this.localQualified && this.localRecovery) {
      try { await this.localRecovery.checkpoint(this.checkpoint({ ...snapshot, position: observation.position })) }
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
      if (generation === this.connection && endpoint === this.endpoint && this.ready && this.current()) this.presenceConfirmedAt = startedAt
    } catch (error) {
      this.presenceConfirmedAt = null
      throw error
    }
  }

  request(intent: PlaybackIntent): Promise<void> {
    const captured = structuredClone(intent)
    const result = this.intentTail.then(() => this.executeIntent(captured))
    this.intentTail = result.catch(() => {})
    return result
  }

  private async executeIntent(intent: PlaybackIntent): Promise<void> {
    if (!this.current() || !this.ready || !this.snapshot || !this.endpoint) throw new Error('Playback connection is unavailable.')
    try {
      if (intent.action === 'stop-local-video') {
        if (this.endpoint.incarnationId !== intent.incarnationId || this.snapshot.activeIncarnationId !== intent.incarnationId
          || this.snapshot.transfer || this.snapshot.media?.kind !== 'video' || this.snapshot.media.publicId !== intent.videoId) return
        intent = { action: 'stop' }
      }
      if (this.pendingRequests.size > 0 && intent.action !== 'stop') throw new Error('A previous playback result is unconfirmed. Retry its result before sending another control.')
      if (intent.action === 'next' || intent.action === 'previous') intent = this.navigate(intent)
      if ((intent.action === 'load' || intent.action === 'play')
        && (!this.snapshot.activeIncarnationId || this.snapshot.activeIncarnationId === this.endpoint.incarnationId)) {
        await this.ensureLocalEndpoint()
      }
      if ((intent.action === 'load' || intent.action === 'play') && hasUnconfirmedPlayback(this.snapshot)) {
        throw new Error('Previous playback is unconfirmed. Stop playback in its original browser or recover that browser before resuming here.')
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
    } catch (error) { this.fail(error); throw error }
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

  private rejectUnconfirmed(request: PendingRequest, error: unknown): void {
    request.reject(error instanceof Error ? error : new Error('The playback result could not be confirmed.'))
    this.fail(error)
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
      const error = new Error(result.outcome === 'superseded' ? 'The playback request was superseded.' : 'The destination rejected the playback request.')
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
          throw new Error('Previous playback is unconfirmed. Stop playback in its original browser or recover that browser before resuming here.')
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
      const snapshot = await this.post('/api/session/transfer', { requestId: crypto.randomUUID(), expectedRevision: this.snapshot.revision, targetIncarnationId: incarnationId })
      await new Promise<void>((resolve, reject) => {
        this.transfers.add({ attemptId: snapshot.transfer?.attemptId ?? null, target: incarnationId, resolve, reject })
        this.completeTransfers(this.snapshot!)
      })
    } catch (error) { this.fail(error); throw error }
  }

  private completeTransfers(snapshot: PlaybackSnapshotResource): void {
    for (const transfer of this.transfers) {
      if (snapshot.transfer?.attemptId === transfer.attemptId && snapshot.transfer.state !== 'uncertain') continue
      this.transfers.delete(transfer)
      if (!snapshot.transfer && snapshot.activeIncarnationId === transfer.target) transfer.resolve()
      else transfer.reject(new Error(snapshot.transfer?.state === 'uncertain'
        ? 'Playback at the destination is uncertain. Confirm it stopped before resuming here.'
        : 'The playback transfer failed or was superseded.'))
    }
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

export function transferPlayback(incarnationId: string): Promise<void> {
  return activeCoordinator ? activeCoordinator.transfer(incarnationId) : Promise.reject(new Error('Playback connection is unavailable.'))
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
