/**
 * SessionSyncBus — personal session sync via WebSocket.
 * Adapted from PartySyncBus (no host/participant, unidirectional upstream).
 */

export interface SessionSyncConfig {
  wsEndpoint: string
  authToken?: string
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
  onReconnect: () => void
  onError: (error: Error) => void
}

export class SessionSyncBus {
  private static readonly RECONNECT_INITIAL_MS = 1_000
  private static readonly RECONNECT_MAX_MS = 30_000
  private static readonly RECONNECT_MULTIPLIER = 2

  private readonly config: SessionSyncConfig
  private readonly events: SessionSyncEvents

  private ws: WebSocket | null = null
  private sessionArgs: { sessionId: string } | null = null
  private reconnectTimer: ReturnType<typeof setTimeout> | null = null
  private reconnectDelayMs = SessionSyncBus.RECONNECT_INITIAL_MS

  constructor(config: SessionSyncConfig, events: SessionSyncEvents) {
    this.config = config
    this.events = events
  }

  async connect(sessionId: string): Promise<void> {
    this.sessionArgs = { sessionId }
    this.cancelReconnect()

    const url = this.buildWsUrl(sessionId)
    this.ws = new WebSocket(url)

    this.ws.onopen = () => {
      this.send({
        type: 'session.join',
        deviceId: this.config.deviceId,
      })
      this.reconnectDelayMs = SessionSyncBus.RECONNECT_INITIAL_MS
    }

    this.ws.onmessage = (event) => {
      try {
        const msg = JSON.parse(event.data as string)
        if (msg.type === 'session.state' || msg.type === 'session.joined') {
          this.events.onStateUpdate(msg.data ?? msg)
        } else if (msg.type === 'connected' && msg.reconnected) {
          this.events.onReconnect()
        }
      } catch { /* ignore malformed */ }
    }

    this.ws.onerror = () => {
      this.events.onError(new Error('Session WebSocket error'))
    }

    this.ws.onclose = () => {
      this.scheduleReconnect()
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
    this.sessionArgs = null
    this.cancelReconnect()
    if (this.ws && this.ws.readyState === WebSocket.OPEN) {
      this.ws.close()
    }
    this.ws = null
  }

  isConnected(): boolean {
    return this.ws !== null && this.ws.readyState === WebSocket.OPEN
  }

  private buildWsUrl(_sessionId: string): string {
    const base = this.config.wsEndpoint
    const sep = base.includes('?') ? '&' : '?'
    const auth = this.config.authToken ? `${sep}token=${encodeURIComponent(this.config.authToken)}` : ''
    return `${base}${auth}`
  }

  private scheduleReconnect(): void {
    if (!this.sessionArgs) return
    const delay = this.reconnectDelayMs
    this.reconnectDelayMs = Math.min(
      this.reconnectDelayMs * SessionSyncBus.RECONNECT_MULTIPLIER,
      SessionSyncBus.RECONNECT_MAX_MS,
    )
    this.events.onError(new Error(`Session WS disconnected. Reconnecting in ${Math.round(delay / 1000)}s…`))
    this.reconnectTimer = setTimeout(async () => {
      this.reconnectTimer = null
      if (!this.sessionArgs) return
      try {
        await this.connect(this.sessionArgs.sessionId)
      } catch { /* scheduleReconnect called by onclose */ }
    }, delay)
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
