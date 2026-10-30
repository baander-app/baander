import type { SharedLiveTransport } from '@/shared/live/transport'

/**
 * SessionSyncBus — personal session sync via WebSocket.
 * Adapted from PartySyncBus (no host/participant, unidirectional upstream).
 */

export interface SessionSyncConfig {
  transport: SharedLiveTransport
  deviceId: string
  getPosition: () => number
  getQueue: () => string[]
  getCurrentIndex: () => number
  getIsPlaying: () => boolean
}

export interface SessionSyncState {
  queue: string[]
  currentTrackIndex: number
  position: number
  playbackState: 'playing' | 'paused' | 'stopped'
  activeDeviceId: string | null
}

export interface SessionSyncEvents {
  onStateUpdate: (state: SessionSyncState) => void
  onError: (error: Error) => void
}

function isSessionState(value: unknown): value is SessionSyncState {
  if (typeof value !== 'object' || value === null) return false
  const state = value as Record<string, unknown>
  return Array.isArray(state.queue) && state.queue.every(id => typeof id === 'string')
    && Number.isSafeInteger(state.currentTrackIndex) && (state.currentTrackIndex as number) >= 0
    && typeof state.position === 'number' && Number.isFinite(state.position) && state.position >= 0
    && (state.playbackState === 'playing' || state.playbackState === 'paused' || state.playbackState === 'stopped')
    && (state.activeDeviceId === null || typeof state.activeDeviceId === 'string')
}

export class SessionSyncBus {
  private readonly transport: SharedLiveTransport
  private release: (() => void) | null = null
  private unsubscribe: (() => void) | null = null

  private readonly config: SessionSyncConfig
  private readonly events: SessionSyncEvents

  constructor(config: SessionSyncConfig, events: SessionSyncEvents) {
    this.config = config
    this.events = events
    this.transport = config.transport
  }

  async connect(): Promise<void> {
    this.disconnect()
    const join = () => this.transport.send({ type: 'session.join', deviceId: this.config.deviceId })
    this.unsubscribe = this.transport.subscribe({
      onOpen: join,
      onError: error => this.events.onError(error),
      onMessage: message => {
        if (typeof message !== 'object' || message === null) return
        const msg = message as Record<string, unknown>
        const state = msg.data ?? msg
        if ((msg.type === 'session.state' || msg.type === 'session.joined') && isSessionState(state)) {
          this.events.onStateUpdate(state)
        }
      },
    })
    this.release = this.transport.retain()
    if (this.transport.isConnected()) join()
    await this.transport.waitForAttempt()
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
    this.unsubscribe?.()
    this.unsubscribe = null
    this.release?.()
    this.release = null
  }

  isConnected(): boolean {
    return this.release !== null && this.transport.isConnected()
  }

  private send(data: object): void {
    if (this.release !== null) this.transport.send(data)
  }
}
