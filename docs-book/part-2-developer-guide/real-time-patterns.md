# Real-Time Patterns

Baander provides two real-time transport mechanisms: WebSocket for bidirectional low-latency communication (party sync, room messaging), and Server-Sent Events (SSE) for unidirectional server-to-client streams (notifications, job monitoring). Both run on top of Swoole and share a common authentication approach.

## WebSocket

WebSocket connections are managed through Swoole's process mode. The `WebSocketController` extends `AbstractWebSocketController` from SwooleBundle and handles the full connection lifecycle: open, message, close.

### Connection Registry

`WebSocketConnectionRegistry` uses Swoole's shared-memory `Table` to track connections across all worker processes. Three tables are maintained:

| Table | Purpose | Key format |
|-------|---------|------------|
| `connections` | Maps file descriptor to user identity and worker ID | `{fd}` |
| `roomMembers` | Maps room membership (for broadcasting) | `{room}\0{fd}` |
| `fdRooms` | Reverse index: which rooms a connection belongs to | `{fd}\0{room}` |

Each user is limited to 10 concurrent WebSocket connections. Orphaned entries (connections whose underlying TCP socket has closed) are cleaned up by `cleanupOrphans()`, which calls `Swoole\Server::isEstablished()` to verify the connection is still alive.

### Message Pushing

`WebSocketPusher` provides three sending strategies:

| Method | Target | Use case |
|--------|--------|----------|
| `pushToConnection(fd, payload)` | Single connection by file descriptor | Replies, error messages |
| `push(userId, payload)` | All connections for a user | User-scoped notifications |
| `broadcast(room, payload)` | All members of a room | Party events, room broadcasts |

All payloads are JSON-encoded before sending. Failed pushes (disconnected clients) are logged and silently skipped.

### WebSocket Message Protocol

The controller dispatches messages by `type` field:

| Message type | Direction | Description |
|-------------|-----------|-------------|
| `connected` | Server to client | Sent on successful handshake, includes `reconnectToken` |
| `auth.reconnect` | Client to server | Restore identity with a previously issued token |
| `ping` / `pong` | Both | Keep-alive |
| `room.join` / `room.leave` | Client to server | Join or leave a named room |
| `party.join` / `party.leave` | Client to server | Join or leave a watch-party session |
| `party.playback` | Client to server | Play, pause, or seek (host only) |
| `party.sync` | Client to server | Report client position for drift correction |
| `party.sync_response` | Server to client | Server-adjusted position after sync |
| `party.member_event` | Server to room broadcast | Member joined or left |
| `error` | Server to client | Error response with message |

Rate limiting is enforced per connection: 30 messages per second. Exceeding this returns an `error` message and the excess messages are dropped.

### Reconnection

On open, the server issues a `reconnectToken` (via `ReconnectionTokenService`). If the connection drops, the client can reconnect and send `auth.reconnect` with the token to restore its identity without re-authenticating through the full OAuth handshake. A new token is issued on each successful reconnection.

## SSE (Server-Sent Events)

SSE provides unidirectional server-to-client streaming over HTTP. Baander uses SSE for two endpoints: job monitoring and notifications.

### SSE Endpoints

| Endpoint | Authentication | Channel | Source |
|----------|---------------|---------|--------|
| `/api/sse/events` | Admin only, `?token=` | Polls `JobMonitorService` | Job status changes |
| `/api/notifications/sse` | Any user, `?token=` | Redis Pub/Sub `notification:{userId}` | `NotificationSseController` |

Both endpoints enforce a maximum of 5 concurrent connections per user (tracked via Redis key with a 120-second TTL). Exceeding the limit returns HTTP 429.

### Event Flow: Notifications

The notification SSE endpoint uses Redis Pub/Sub for true push delivery:

```
Notification publisher
    |
    v
Redis PUBLISH notification:{userId}
    |
    v
RedisPubSubConnection (blocking subscribe)
    |
    v
sendSseEvent() -> client
```

`RedisPubSubConnection` manages a dedicated Redis connection for subscribing (Redis does not allow publish and subscribe on the same connection). Messages are JSON-decoded and forwarded to the client as SSE events.

On connect, the client can pass a `Last-Event-ID` header. The server queries the database for any notifications with IDs greater than the provided value and replays them (up to 50) before subscribing to the live stream.

### Event Flow: Job Monitoring

The job monitoring endpoint uses a polling loop rather than Pub/Sub:

1. On connect, sends a `connected` event with current job counts by status.
2. Every 3 seconds, polls `JobMonitorService::getRecent()` for updated jobs.
3. Deduplicates by comparing `updatedAt` timestamps against the last emitted event.
4. Sends heartbeats every 30 seconds to keep the connection alive.

### SSE Event Format

All SSE events follow the standard format:

```
id: {event-id}
event: {event-type}
data: {json-payload}

```

Event types for job monitoring:

| Event | Trigger |
|-------|---------|
| `connected` | Initial connection with job counts |
| `job.queued` | Job moved to queued status |
| `job.started` | Job started running |
| `job.progress` | Job progress update |
| `job.completed` | Job finished successfully |
| `job.failed` | Job failed |
| `job.cancelled` | Job cancelled |
| `heartbeat` | Keep-alive (every 30s) |

Event types for notifications:

| Event | Trigger |
|-------|---------|
| `notification` | New or replayed notification |
| `heartbeat` | Keep-alive (every 30s) |

## Party Sync Protocol

The Party context implements synchronized watch-party playback over WebSocket. The `SyncedPartySession` aggregate root manages playback state, and `PlaybackSynchronizer` handles drift correction between participants.

### Playback State Model

| Field | Type | Description |
|-------|------|-------------|
| `playbackState` | `PlaybackState` enum | `playing`, `paused`, `stopped` |
| `wallClockPosition` | `float` | Position (seconds) when playback started |
| `playbackStartedAt` | `?DateTimeImmutable` | Timestamp when playback was last started or resumed |
| `pausedAtPosition` | `?float` | Captured position when paused |

The current playback position is computed dynamically by `getCurrentPosition()`:

```
currentPosition = wallClockPosition + (now - playbackStartedAt)
```

This wall-clock approach means the server does not need to store an incrementing counter. As long as the server clock is consistent, the position is always correct relative to the start time.

### Playback Actions

Host-initiated actions are dispatched as CQRS commands via Messenger:

| Command | Handler | Effect |
|---------|---------|--------|
| `StartPlaybackCommand` | `StartPlaybackHandler` | Sets state to `Playing`, resets `playbackStartedAt` |
| `PausePlaybackCommand` | `PausePlaybackHandler` | Captures current position into `pausedAtPosition`, sets `Paused` |
| `SeekPlaybackCommand` | `SeekPlaybackHandler` | Sets `wallClockPosition` to target, resets start timestamp |

All three handlers dispatch a `PlaybackPositionChanged` domain event via `EventDispatcherInterface`, which the broader system can react to.

### Sync Protocol

Non-host participants periodically report their local playback position to the server. The sync flow:

1. Client sends `party.sync` with its current position and measured latency.
2. `SyncPlaybackHandler` calls `SyncedPartySession::syncPlayback()` on the aggregate root.
3. The aggregate root computes the server's current position via `getCurrentPosition()`.
4. If drift exceeds `clientLatency + 1.0` seconds, the server position is returned for correction.
5. If drift is within tolerance, the server position is returned as the authoritative position.
6. `PlaybackSynchronizer` (called from infrastructure) additionally updates per-member jitter compensation.

### Jitter Compensation

Each `PartyMember` tracks a smoothed jitter value using exponential moving average (EMA):

```
EMA_ALPHA = 0.3
MAX_JITTER = 2.0

drift = |serverPosition - clientPosition|
jitter = min(drift, MAX_JITTER)
smoothedJitter = EMA_ALPHA * jitter + (1 - EMA_ALPHA) * previousJitter
```

The alpha of 0.3 gives more weight to recent measurements while smoothing out transient spikes. Jitter is capped at 2.0 seconds to prevent runaway values from a single large correction.

### Seek Handling

When the host seeks, the flow is:

1. Host sends `party.playback` with `action: "seek"` and a `position` value.
2. `SeekPlaybackHandler` calls `session.seekTo(position)` on the aggregate root.
3. If playing, `wallClockPosition` is set to the new position and `playbackStartedAt` is reset to `now()`. If paused, `pausedAtPosition` is updated instead.
4. A `PlaybackPositionChanged` event is dispatched.
5. All participants receive the update and seek their local player to the new position.

## Authentication

Both WebSocket and SSE use OAuth 2.0 token authentication via query parameter. This is necessary because neither the browser `EventSource` API (SSE) nor the WebSocket handshake (in some client configurations) supports custom headers.

### WsQueryTokenAuthenticator

Invoked during Swoole's `onHandshake` callback -- this is not a Symfony firewall authenticator. It builds a minimal Symfony `Request` from the Swoole request, injects the token as a `Bearer` header, and validates through the League OAuth2 `ResourceServer`. Returns the authenticated user's UUID string, or `null` on failure (which rejects the handshake).

### SseQueryTokenAuthenticator

A Symfony firewall authenticator (`AbstractAuthenticator`) that matches requests to `/api/sse/**` with a `token` query parameter. It uses the same OAuth2 `ResourceServer` validation path as the standard header-based authenticator, extracting the user identifier and loading the `SecurityUser` from the repository. Returns a `SelfValidatingPassport` on success, or a JSON 401 on failure.

Both authenticators share the same underlying OAuth 2.0 validation pipeline. The only difference is the entry point: WebSocket authenticates at the Swoole handshake level (before Symfony), while SSE authenticates through the standard Symfony security firewall.

---

*See [Shared Kernel](shared-kernel.md) for Redis connection management and Swoole async primitives. See [Architecture](architecture.md) for the bounded context overview.*
