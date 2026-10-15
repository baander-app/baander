/**
 * @module party/PartySyncBus
 * @description Real-time synchronization for co-watching sessions.
 *
 * Integrates with the backend's real-time events:
 *   - PlaybackPositionChanged: broadcast when any participant changes position
 *   - SeekSignalBroker: coordinates seek operations across participants
 *
 * The sync bus ensures all participants see the same frame at the same time,
 * with configurable tolerance for network jitter.
 *
 * Features:
 *   - Automatic host election (first participant)
 *   - Seek co-ordination with convergence window
 *   - Collaborative spatial annotations
 *   - Shared camera control in immersive modes
 */

import type { PartyState, PartyEvent, PartyParticipant, SpatialAnnotation } from '../types';

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

export interface PartySyncConfig {
  /** Maximum acceptable desync in milliseconds. */
  syncToleranceMs: number;
  /** How often to broadcast position updates (ms). */
  broadcastIntervalMs: number;
  /** WebSocket endpoint for party events. */
  wsEndpoint: string;
  /** Host-authenticated issuance of a fresh single-use connection ticket. */
  getConnectionTicket: (signal: AbortSignal) => Promise<string>;
  /** Callback to read current playback position from the player. */
  getPosition: () => number;
}


// ---------------------------------------------------------------------------
// PartySyncBus
// ---------------------------------------------------------------------------

export interface PartySyncEvents {
  onStateChange: (state: PartyState) => void;
  onEvent: (event: PartyEvent) => void;
  onSyncCorrection: (position: number, reason: string) => void;
  onError: (error: Error) => void;
}

/**
 * PartySyncBus — manages real-time co-watching synchronisation.
 *
 * Usage:
 * ```ts
 * const bus = new PartySyncBus(config, events);
 * await bus.join('session-id', 'user-id', 'MyName');
 *
 * // Broadcast local playback events
 * bus.broadcastPlay(12.5);
 * bus.broadcastPause(15.3);
 * bus.broadcastSeek(30.0);
 * ```
 */
export class PartySyncBus {
  /** Reconnection state. */
  private static readonly RECONNECT_INITIAL_MS = 1_000;
  private static readonly RECONNECT_MAX_MS = 30_000;
  private static readonly RECONNECT_MULTIPLIER = 2;

  private readonly config: PartySyncConfig;
  private readonly events: PartySyncEvents;

  private ws: WebSocket | null = null;
  private state: PartyState | null = null;
  private broadcastTimer: ReturnType<typeof setInterval> | null = null;
  private localParticipantId = '';
  private lastBroadcastPosition = 0;
  private joinArgs: { sessionId: string; userId: string; displayName: string } | null = null;
  private reconnectTimer: ReturnType<typeof setTimeout> | null = null;
  private reconnectDelayMs = PartySyncBus.RECONNECT_INITIAL_MS;
  private connectionGeneration = 0;
  private ticketRequest: AbortController | null = null;

  constructor(
    config: PartySyncConfig,
    events: PartySyncEvents,
  ) {
    this.config = config;
    this.events = events;
  }

  /** Join a co-watching session. */
  async join(sessionId: string, userId: string, displayName: string): Promise<void> {
    this.leave();
    this.localParticipantId = userId;

    // Store join args for reconnection
    this.joinArgs = { sessionId, userId, displayName };

    // Cancel any pending reconnect (e.g. reconnecting to a new session)
    this.cancelReconnect();

    // Initialize party state
    this.state = {
      sessionId,
      participants: [],
      hostId: '',
      syncOffsetMs: 0,
    };

    const generation = this.connectionGeneration;
    const request = new AbortController();
    this.ticketRequest = request;
    try {
      const ticket = await this.config.getConnectionTicket(request.signal);
      if (request.signal.aborted || generation !== this.connectionGeneration) return;
      if (ticket.length !== 64 || !/^[a-f0-9]{64}$/.test(ticket)) throw new Error('Invalid WebSocket ticket');
      const url = new URL(this.config.wsEndpoint);
      url.search = new URLSearchParams({ ticket }).toString();
      const ws = new WebSocket(url.href);
      this.ws = ws;

      ws.onopen = () => {
        if (this.ws !== ws) return;
        this.reconnectDelayMs = PartySyncBus.RECONNECT_INITIAL_MS;
        this.send({
          type: 'participant-joined',
          participant: { id: userId, displayName, position: 0, isHost: false },
        });
        this.broadcastTimer = setInterval(() => {
          this.broadcastCurrentPosition();
        }, this.config.broadcastIntervalMs);
      };

      ws.onmessage = (event) => {
        if (this.ws !== ws) return;
        try {
          const partyEvent = JSON.parse(event.data as string) as PartyEvent;
          this.handleIncomingEvent(partyEvent);
        } catch {
          // Ignore malformed messages.
        }
      };
      ws.onerror = () => {
        if (this.ws !== ws) return;
        this.events.onError(new Error('Party WebSocket error'));
      };
      ws.onclose = () => {
        if (this.ws !== ws) return;
        this.stopBroadcast();
        this.scheduleReconnect();
      };
    } catch {
      if (request.signal.aborted || generation !== this.connectionGeneration) return;
      this.events.onError(new Error('Unable to connect the party WebSocket'));
      this.scheduleReconnect();
    } finally {
      if (this.ticketRequest === request) this.ticketRequest = null;
    }
  }

  /** Leave the session. */
  leave(): void {
    this.connectionGeneration++;
    this.ticketRequest?.abort();
    this.ticketRequest = null;
    this.joinArgs = null;
    this.cancelReconnect();
    const ws = this.ws;
    if (ws?.readyState === WebSocket.OPEN) {
      this.send({ type: 'participant-left', participantId: this.localParticipantId });
    }
    this.ws = null;
    if (ws && (ws.readyState === WebSocket.CONNECTING || ws.readyState === WebSocket.OPEN)) {
      ws.close();
    }
    this.stopBroadcast();
    this.state = null;
  }

  /** Broadcast a play event. */
  broadcastPlay(position: number): void {
    this.send({ type: 'play', position });
  }

  /** Broadcast a pause event. */
  broadcastPause(position: number): void {
    this.send({ type: 'pause', position });
  }

  /** Broadcast a seek event. */
  broadcastSeek(position: number): void {
    this.send({ type: 'seek', position });
  }

  /** Broadcast a spatial annotation. */
  broadcastAnnotation(annotation: SpatialAnnotation): void {
    this.send({ type: 'annotation', annotation });
  }

  /** Get current party state. */
  getState(): PartyState | null {
    return this.state;
  }

  /** Check if this participant is the host. */
  isHost(): boolean {
    return this.state?.hostId === this.localParticipantId;
  }

  // -----------------------------------------------------------------------
  // Private
  // -----------------------------------------------------------------------

  private handleIncomingEvent(event: PartyEvent): void {
    switch (event.type) {
      case 'play':
      case 'pause':
      case 'seek':
        this.handlePlaybackEvent(event);
        break;
      case 'position-update':
        this.handlePositionUpdate(event);
        break;
      case 'participant-joined':
        this.updateParticipantList(event.participant);
        break;
      case 'participant-left':
        this.removeParticipant(event.participantId);
        break;
      case 'annotation':
        // Forward to immersive layer
        break;
    }

    this.events.onEvent(event);
  }

  private handlePlaybackEvent(
    event: { type: 'play' | 'pause' | 'seek'; position: number },
  ): void {
    if (!this.state) return;

    // Only the host drives playback for non-hosts
    // Or: democratic mode where anyone can control
    this.events.onSyncCorrection(
      event.position,
      `party-${event.type}`,
    );
  }

  /** Handle a position-update from another participant (not a seek). */
  private handlePositionUpdate(event: { position: number }): void {
    if (!this.state) return;

    const localPosition = this.config.getPosition();
    const deltaMs = Math.abs(event.position - localPosition) * 1000;

    // Only correct if desync exceeds tolerance
    if (deltaMs > this.config.syncToleranceMs) {
      this.events.onSyncCorrection(event.position, 'party-desync');
    }
  }

  private updateParticipantList(participant: PartyParticipant): void {
    if (!this.state) return;

    const existing = this.state.participants.find(p => p.id === participant.id);
    if (!existing) {
      this.state.participants.push(participant);
    }

    // First participant is host
    if (this.state.participants.length === 1) {
      this.state.hostId = participant.id;
      participant.isHost = true;
    }

    this.events.onStateChange(this.state);
  }

  private removeParticipant(participantId: string): void {
    if (!this.state) return;

    this.state.participants = this.state.participants.filter(p => p.id !== participantId);

    // If host left, elect new host
    if (this.state.hostId === participantId && this.state.participants.length > 0) {
      this.state.hostId = this.state.participants[0]!.id;
      this.state.participants[0]!.isHost = true;
    }

    this.events.onStateChange(this.state);
  }

  private broadcastCurrentPosition(): void {
    const position = this.config.getPosition();
    if (position !== this.lastBroadcastPosition) {
      this.lastBroadcastPosition = position;
      this.send({ type: 'position-update', position });
    }
  }

  /** Schedule a reconnection attempt with exponential backoff. */
  private scheduleReconnect(): void {
    // Don't reconnect if the user explicitly left or state was cleared
    if (!this.joinArgs || !this.state) return;

    const delay = this.reconnectDelayMs;
    this.reconnectDelayMs = Math.min(
      this.reconnectDelayMs * PartySyncBus.RECONNECT_MULTIPLIER,
      PartySyncBus.RECONNECT_MAX_MS,
    );

    this.events.onError(new Error(`Party WebSocket disconnected. Reconnecting in ${Math.round(delay / 1000)}s…`));

    this.reconnectTimer = setTimeout(async () => {
      this.reconnectTimer = null;
      if (!this.joinArgs) return; // leave() called during wait
      try {
        await this.join(this.joinArgs.sessionId, this.joinArgs.userId, this.joinArgs.displayName);
      } catch {
        this.scheduleReconnect();
      }
    }, delay);
  }

  /** Cancel any pending reconnect timer. */
  private cancelReconnect(): void {
    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer);
      this.reconnectTimer = null;
    }
  }

  private send(event: PartyEvent): void {
    if (this.ws && this.ws.readyState === WebSocket.OPEN) {
      this.ws.send(JSON.stringify(event));
    }
  }

  private stopBroadcast(): void {
    if (this.broadcastTimer) {
      clearInterval(this.broadcastTimer);
      this.broadcastTimer = null;
    }
  }
}
