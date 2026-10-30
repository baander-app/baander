import { requestWebSocketTicket, webSocketEndpoint } from '@/shared/auth/websocket-ticket'
import { LIVE_MAX_BYTES } from './protocol'

export interface LiveTransportConfig {
  wsEndpoint: string
  getConnectionTicket: (signal: AbortSignal) => Promise<string>
}

export interface LiveTransportListener {
  onOpen?: () => void
  onMessage?: (message: unknown) => void
  onError?: (error: Error) => void
  onClose?: () => void
}

/** Owns one ticket, socket and reconnect timer for all retained subscribers. */
export class SharedLiveTransport {
  private readonly listeners = new Set<LiveTransportListener>()
  private references = 0
  private generation = 0
  private socket: WebSocket | null = null
  private request: AbortController | null = null
  private retryTimer: ReturnType<typeof setTimeout> | null = null
  private retryDelay = 1_000
  private attempt = Promise.resolve()

  private readonly config: LiveTransportConfig

  constructor(config: LiveTransportConfig) {
    this.config = config
  }

  retain(): () => void {
    this.references++
    if (this.references === 1) this.attempt = this.connect()
    let released = false
    return () => {
      if (released) return
      released = true
      this.references--
      if (this.references === 0) this.stop()
    }
  }

  waitForAttempt(): Promise<void> {
    return this.attempt
  }

  subscribe(listener: LiveTransportListener): () => void {
    this.listeners.add(listener)
    return () => this.listeners.delete(listener)
  }

  send(message: object): void {
    if (this.isConnected()) this.socket?.send(JSON.stringify(message))
  }

  isConnected(): boolean {
    return this.socket !== null && this.socket.readyState === WebSocket.OPEN
  }

  reconnect(): void {
    if (!this.references) return
    this.stop()
    this.attempt = this.connect()
  }

  private async connect(): Promise<void> {
    const generation = ++this.generation
    const request = new AbortController()
    this.request = request
    const current = () => this.references > 0 && generation === this.generation && !request.signal.aborted
    try {
      const ticket = await this.config.getConnectionTicket(request.signal)
      if (!current()) return
      if (!/^[a-f0-9]{64}$/.test(ticket)) throw new Error('Invalid WebSocket ticket')
      const url = new URL(this.config.wsEndpoint)
      url.search = new URLSearchParams({ ticket }).toString()
      const socket = new WebSocket(url.href)
      this.socket = socket
      const ownsSocket = () => current() && this.socket === socket
      socket.onopen = () => {
        if (!ownsSocket()) return
        this.retryDelay = 1_000
        for (const listener of this.listeners) listener.onOpen?.()
      }
      socket.onmessage = event => {
        if (!ownsSocket() || typeof event.data !== 'string' || event.data.length > LIVE_MAX_BYTES) return
        let message: unknown
        try {
          message = JSON.parse(event.data)
        } catch {
          return
        }
        for (const listener of this.listeners) listener.onMessage?.(message)
      }
      socket.onerror = () => {
        if (ownsSocket()) this.reportError('WebSocket connection error')
      }
      socket.onclose = () => {
        if (!ownsSocket()) return
        this.socket = null
        for (const listener of this.listeners) listener.onClose?.()
        this.scheduleReconnect()
      }
    } catch {
      if (!current()) return
      this.reportError('Unable to connect the WebSocket')
      this.scheduleReconnect()
    } finally {
      if (this.request === request) this.request = null
    }
  }

  private scheduleReconnect(): void {
    if (!this.references || this.retryTimer !== null) return
    const delay = Math.min(30_000, Math.round(1_000 + Math.random() * (this.retryDelay * 3 - 1_000)))
    this.retryDelay = delay
    this.reportError(`WebSocket disconnected. Reconnecting in ${Math.round(delay / 1000)}s…`)
    this.retryTimer = setTimeout(() => {
      this.retryTimer = null
      if (this.references) this.attempt = this.connect()
    }, delay)
  }

  private reportError(message: string): void {
    for (const listener of this.listeners) listener.onError?.(new Error(message))
  }

  private stop(): void {
    this.generation++
    this.request?.abort()
    this.request = null
    if (this.retryTimer !== null) clearTimeout(this.retryTimer)
    this.retryTimer = null
    const socket = this.socket
    this.socket = null
    if (socket && (socket.readyState === WebSocket.CONNECTING || socket.readyState === WebSocket.OPEN)) socket.close()
    this.retryDelay = 1_000
  }
}

export const authenticatedTransport = new SharedLiveTransport({
  wsEndpoint: webSocketEndpoint(),
  getConnectionTicket: requestWebSocketTicket,
})
