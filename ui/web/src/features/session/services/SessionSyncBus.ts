/**
 * SessionSyncBus — personal session sync via WebSocket.
 * Adapted from PartySyncBus (no host/participant, unidirectional upstream).
 */

export interface SessionSyncConfig {
  wsEndpoint: string
  /** Issue a fresh single-use ticket for each connection attempt. */
  getConnectionTicket: (signal: AbortSignal) => Promise<string>
  deviceId: string
  getPosition: () => number
  getQueue: () => string[]
  getCurrentIndex: () => number
  getIsPlaying: () => boolean
}

export interface SessionSyncEvents {
  onStateUpdate: (state: {
    queue: string[]
    currentTrackIndex: number
    position: number
    playbackState: 'playing' | 'paused' | 'stopped'
    activeDeviceId: string | null
  }) => void
  onError: (error: Error) => void
}

export class SessionSyncBus {
  private static readonly RECONNECT_INITIAL_MS = 1_000
  private static readonly RECONNECT_MAX_MS = 30_000
  private static readonly RECONNECT_GROWTH = 3

  private readonly config: SessionSyncConfig
  private readonly events: SessionSyncEvents

  private ws: WebSocket | null = null
  private sessionArgs: { sessionId: string } | null = null
  private reconnectTimer: ReturnType<typeof setTimeout> | null = null
  private reconnectDelayMs = SessionSyncBus.RECONNECT_INITIAL_MS
  private connectionGeneration = 0
  private ticketRequest: AbortController | null = null

  constructor(config: SessionSyncConfig, events: SessionSyncEvents) {
    this.config = config
    this.events = events
  }

  async connect(sessionId: string): Promise<void> {
    this.disconnect()
    this.sessionArgs = { sessionId }
    const generation = this.connectionGeneration
    const request = new AbortController()
    this.ticketRequest = request

    try {
      const ticket = await this.config.getConnectionTicket(request.signal)
      if (request.signal.aborted || generation !== this.connectionGeneration) return
      if (ticket.length !== 64 || !/^[a-f0-9]{64}$/.test(ticket)) throw new Error('Invalid WebSocket ticket')
      const url = new URL(this.config.wsEndpoint)
      url.search = new URLSearchParams({ ticket }).toString()
      const ws = new WebSocket(url.href)
      this.ws = ws

      // A socket this bus has replaced or released may still report events; only the current one counts.
      ws.onopen = () => {
        if (this.ws !== ws) return
        this.send({
          type: 'session.join',
          deviceId: this.config.deviceId,
        })
        this.reconnectDelayMs = SessionSyncBus.RECONNECT_INITIAL_MS
      }

      ws.onmessage = (event) => {
        if (this.ws !== ws) return
        try {
          const msg = JSON.parse(event.data as string)
          if (msg.type === 'session.state' || msg.type === 'session.joined') {
            this.events.onStateUpdate(msg.data ?? msg)
          }
        } catch { /* ignore malformed */ }
      }

      ws.onerror = () => {
        if (this.ws !== ws) return
        this.events.onError(new Error('Session WebSocket error'))
      }

      ws.onclose = () => {
        if (this.ws !== ws) return
        this.scheduleReconnect()
      }
    } catch {
      if (request.signal.aborted || generation !== this.connectionGeneration) return
      this.events.onError(new Error('Unable to connect the session WebSocket'))
      this.scheduleReconnect()
    } finally {
      if (this.ticketRequest === request) this.ticketRequest = null
    }
  }

  sendSync(): void {
    const payload = {
      type: 'session.sync',
      deviceId: this.config.deviceId,
      queue: this.config.getQueue(),
      currentTrackIndex: this.config.getCurrentIndex(),
      position: this.config.getPosition(),
      playbackState: this.config.getIsPlaying() ? 'playing' : 'paused',
    }
    this.send(payload)
  }

  sendPlayback(action: string, position?: number): void {
    this.send({
      type: 'session.playback',
      deviceId: this.config.deviceId,
      action,
      position: position ?? this.config.getPosition(),
      queue: this.config.getQueue(),
      currentTrackIndex: this.config.getCurrentIndex(),
      playbackState: action === 'play' ? 'playing' : 'paused',
    })
  }

  disconnect(): void {
    this.connectionGeneration++
    this.ticketRequest?.abort()
    this.ticketRequest = null
    this.sessionArgs = null
    this.cancelReconnect()
    const ws = this.ws
    this.ws = null
    // A reconnect that is still connecting would otherwise open later and stay connected.
    if (ws && (ws.readyState === WebSocket.CONNECTING || ws.readyState === WebSocket.OPEN)) {
      ws.close()
    }
  }

  isConnected(): boolean {
    return this.ws !== null && this.ws.readyState === WebSocket.OPEN
  }

  private scheduleReconnect(): void {
    if (!this.sessionArgs) return
    const delay = this.nextReconnectDelay()
    this.events.onError(new Error(`Session WS disconnected. Reconnecting in ${Math.round(delay / 1000)}s…`))
    this.reconnectTimer = setTimeout(async () => {
      this.reconnectTimer = null
      if (!this.sessionArgs) return
      try {
        await this.connect(this.sessionArgs.sessionId)
      } catch {
        this.scheduleReconnect()
      }
    }, delay)
  }

  /**
   * Decorrelated jitter: a random delay between the minimum and three times the previous
   * delay, capped. Clients that lost the same server spread their reconnects out.
   */
  private nextReconnectDelay(): number {
    const min = SessionSyncBus.RECONNECT_INITIAL_MS
    const ceiling = this.reconnectDelayMs * SessionSyncBus.RECONNECT_GROWTH
    const delay = Math.min(
      SessionSyncBus.RECONNECT_MAX_MS,
      Math.round(min + Math.random() * (ceiling - min)),
    )
    this.reconnectDelayMs = delay
    return delay
  }

  private cancelReconnect(): void {
    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer)
      this.reconnectTimer = null
    }
  }

  private send(data: object): void {
    if (this.ws && this.ws.readyState === WebSocket.OPEN) {
      this.ws.send(JSON.stringify(data))
    }
  }
}
