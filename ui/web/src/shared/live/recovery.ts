import { getLiveRecovery } from '@/shared/api-client/gen/endpoints'
import { advanceAuthorization, assertCurrentOwnership, captureOwnership, isCurrentOwnership, setAuthorityPending } from './ownership'
import { parseLiveMessage, type LiveFamily, type LiveHealth, type LiveMessage } from './protocol'
import type { SharedLiveTransport } from './transport'

export interface LiveAuthority {
  version: 1
  families: LiveFamily[]
  authorizationFingerprint: string
  health: LiveHealth
}

export interface LiveSnapshot {
  status: 'connecting' | 'recovering' | 'current' | 'degraded'
  families: readonly LiveFamily[]
  authorizationFingerprint: string | null
  health: LiveHealth
  /** Authority is established separately from completion of resource recovery reads. */
  authorityReady: boolean
}

export type LiveRecoveryEvent =
  | { type: 'baseline'; authority: LiveAuthority }
  | { type: 'dirty'; families: readonly LiveFamily[] }
  | { type: 'reset'; families: readonly LiveFamily[] }

const initialSnapshot = (): LiveSnapshot => ({
  status: 'connecting',
  families: [],
  authorizationFingerprint: null,
  health: { state: 'current', oldestPendingAgeMs: null },
  authorityReady: false,
})

function parseAuthority(value: unknown): LiveAuthority {
  if (typeof value !== 'object' || value === null || Object.keys(value).length !== 4) {
    throw new Error('Invalid live recovery authority')
  }
  const parsed = parseLiveMessage({
    ...value,
    type: 'live.ready',
    subscriptionId: '0'.repeat(32),
    authorizationGeneration: '1',
  })
  if (!parsed) throw new Error('Invalid live recovery authority')
  return {
    version: 1,
    families: parsed.families,
    authorizationFingerprint: parsed.authorizationFingerprint,
    health: parsed.health,
  }
}

/** Owns permission reconciliation; resource read scheduling belongs to its subscribers. */
export class LiveRecovery {
  private snapshot = initialSnapshot()
  private readonly listeners = new Set<() => void>()
  private readonly eventListeners = new Set<(event: LiveRecoveryEvent) => void>()
  private subscriptionId: string | null = null
  private authorizationGeneration: string | null = null
  private request: AbortController | null = null
  private sequence = 0
  private started = false
  private principal: string | null = null
  private expectedFingerprint: string | null = null

  private readonly transport: SharedLiveTransport
  private readonly purgeMirrors: () => void
  private readonly loadAuthority: (signal: AbortSignal) => Promise<LiveAuthority>

  constructor(
    transport: SharedLiveTransport,
    purgeMirrors: () => void,
    loadAuthority: (signal: AbortSignal) => Promise<LiveAuthority> = signal => getLiveRecovery({ signal }),
  ) {
    this.transport = transport
    this.purgeMirrors = purgeMirrors
    this.loadAuthority = loadAuthority
  }

  getSnapshot = (): LiveSnapshot => this.snapshot

  subscribe = (listener: () => void): (() => void) => {
    this.listeners.add(listener)
    return () => { this.listeners.delete(listener) }
  }

  subscribeEvents(listener: (event: LiveRecoveryEvent) => void): () => void {
    this.eventListeners.add(listener)
    return () => { this.eventListeners.delete(listener) }
  }

  start(): () => void {
    if (this.started) throw new Error('Live recovery already started')
    this.started = true
    this.principal = captureOwnership().principal
    const unsubscribe = this.transport.subscribe({
      onOpen: () => {
        if (!this.ownsPrincipal()) return
        this.subscriptionId = null
        this.authorizationGeneration = null
        this.recover()
      },
      onMessage: message => this.receive(message),
      onClose: () => this.disconnected(),
      onError: () => this.disconnected(),
    })
    const release = this.transport.retain()
    if (this.transport.isConnected()) this.recover()
    return () => {
      this.started = false
      this.sequence++
      this.request?.abort()
      this.request = null
      unsubscribe()
      release()
    }
  }

  /** Called after the baseline's required active resource reads complete. */
  completeRecovery(stamp = captureOwnership()): void {
    if (isCurrentOwnership(stamp) && this.snapshot.authorityReady) {
      this.update({ status: this.snapshot.health.state === 'degraded' ? 'degraded' : 'current' })
    }
  }

  private receive(value: unknown): void {
    if (!this.ownsPrincipal()) return
    const message = parseLiveMessage(value)
    if (!message) return
    if (message.type === 'live.ready') {
      // A ready frame establishes this socket's sole subscription identity.
      if (this.subscriptionId !== null) return
      this.subscriptionId = message.subscriptionId
      this.authorizationGeneration = message.authorizationGeneration
      this.expectedFingerprint = message.authorizationFingerprint
      this.request?.abort()
      this.sequence++
      this.acceptAuthority(message)
      return
    }
    if (message.subscriptionId !== this.subscriptionId || this.authorizationGeneration === null) return
    if (message.type === 'live.reset') {
      if (BigInt(message.authorizationGeneration) <= BigInt(this.authorizationGeneration)) return
      this.authorizationGeneration = message.authorizationGeneration
      this.expectedFingerprint = message.authorizationFingerprint
      this.fence(message.families)
      this.recover()
      return
    }
    if (message.authorizationGeneration !== this.authorizationGeneration) return
    if (message.authorizationFingerprint !== this.expectedFingerprint) return
    this.update({ health: message.health })
    if (message.type === 'live.changed') {
      this.update({ status: 'recovering' })
      this.emit({ type: 'dirty', families: message.families })
    } else if (message.health.state === 'degraded') {
      this.update({ status: 'degraded' })
    }
  }

  private disconnected(): void {
    if (!this.ownsPrincipal()) return
    this.subscriptionId = null
    this.authorizationGeneration = null
    this.recover('degraded')
  }

  private fence(families: readonly LiveFamily[]): void {
    setAuthorityPending(true)
    advanceAuthorization()
    this.purgeMirrors()
    this.update({ families: [], authorityReady: false, status: 'recovering' })
    this.emit({ type: 'reset', families })
  }

  private recover(status: 'recovering' | 'degraded' = 'recovering'): void {
    setAuthorityPending(true)
    this.request?.abort()
    const request = new AbortController()
    this.request = request
    const sequence = ++this.sequence
    const stamp = captureOwnership()
    this.update({ authorityReady: false, status })
    this.loadAuthority(request.signal).then((value) => {
      if (!this.started || request.signal.aborted || sequence !== this.sequence) return
      assertCurrentOwnership(stamp)
      this.acceptAuthority(parseAuthority(value))
    }).catch((error: unknown) => {
      if (!this.started || request.signal.aborted || sequence !== this.sequence || !isCurrentOwnership(stamp)) return
      const response = typeof error === 'object' && error !== null
        ? (error as { response?: { status?: unknown } }).response
        : undefined
      if (response?.status === 401 || response?.status === 403) this.fence(this.snapshot.families)
      this.update({ status: 'degraded' })
    }).finally(() => {
      if (this.request === request) this.request = null
    })
  }

  private acceptAuthority(authority: LiveAuthority | LiveMessage): void {
    if (this.snapshot.authorizationFingerprint !== null
      && authority.authorizationFingerprint !== this.snapshot.authorizationFingerprint) {
      this.fence(this.snapshot.families)
    }
    const normalized: LiveAuthority = {
      version: 1,
      families: authority.families,
      authorizationFingerprint: authority.authorizationFingerprint,
      health: authority.health,
    }
    this.expectedFingerprint = authority.authorizationFingerprint
    setAuthorityPending(false)
    this.update({
      ...normalized,
      authorityReady: true,
      status: authority.health.state === 'degraded' ? 'degraded' : 'recovering',
    })
    this.emit({ type: 'baseline', authority: normalized })
  }

  private ownsPrincipal(): boolean {
    return this.started && this.principal === captureOwnership().principal
  }

  private emit(event: LiveRecoveryEvent): void {
    this.eventListeners.forEach(listener => listener(event))
  }

  private update(patch: Partial<LiveSnapshot>): void {
    this.snapshot = { ...this.snapshot, ...patch }
    this.listeners.forEach(listener => listener())
  }
}
