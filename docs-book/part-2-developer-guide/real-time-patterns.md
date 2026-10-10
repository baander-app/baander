# Real-Time Patterns

Baander has one real-time transport: a WebSocket connection served by Swoole. It carries watch-party sync and member events, listening-session sync, transcode position reports, and server pushes to a user's open connections. Notifications and the admin pages do not hold a stream open; they read over HTTP, as [Delivery without a stream](#delivery-without-a-stream) describes.

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
| `broadcast(room, payload)` | All members of a room | Party member events |

All payloads are JSON-encoded before sending. Failed pushes (disconnected clients) are logged and silently skipped.

### Rooms

A room's members receive everything broadcast to it, so a connection joins a room only through the message that checks it may. `WebSocketRoomPolicy` holds the join rules; the names come from `WebSocketRooms` in Shared Infrastructure, which `SwooleLivePartyRooms` also uses:

| Room | Joined by | Left by | Broadcasts |
|------|-----------|---------|------------|
| `party:{sessionId}` | `party.join`, after `JoinPartySessionCommand` accepts the user as a member of the party | Every socket of a member who leaves the party, with `party.leave` or `POST /api/party/sessions/{uuid}/leave`; every socket when the party ends; one socket with `room.leave` or by closing | `party.member_event` |

A party room follows the party's membership. `LeavePartySessionHandler` dispatches `MemberLeft`, and `EndPartySessionHandler` (or a leave that ends the party) dispatches `PartySessionEnded`. `LivePartyRoomListener` in Party Infrastructure hears both and calls `LivePartyRoomsPortInterface`. Its implementation, `SwooleLivePartyRooms`, takes every socket of the member out of the room and sends the sockets left in it one `party.member_event` leave event, or empties the room when the party ends. The registry's tables are shared by every worker, so the worker that runs the leave changes sockets on any worker. Outside an HTTP worker of the running server (a console command, a Messenger worker, a test kernel) the port does nothing.

No other room exists. Pushes to one user, such as `session.claimed` and `session.state`, go through `push(userId, payload)` and need no room. `room.join` joins nothing: it answers a party room with `Party rooms are joined with party.join` and any other name with `Unknown room`, and logs a warning. No client sends it. A new room kind gets its join rule in `WebSocketRoomPolicy` and a row here.

A room name may be at most 52 bytes long. Swoole keeps only the first 63 bytes of a table key, and a membership key adds a NUL and the connection's fd (up to 10 digits) to the name.

### WebSocket Message Protocol

The controller dispatches messages by `type` field:

| Message type | Direction | Description |
|-------------|-----------|-------------|
| `connected` | Server to client | Sent on successful handshake |
| `ping` / `pong` | Both | Keep-alive |
| `room.join` | Client to server | Refused for every room; see [Rooms](#rooms) |
| `room.leave` | Client to server | Leave a room the connection is in; answered with `room.left` |
| `party.join` / `party.leave` | Client to server | Join or leave a watch-party session |
| `party.playback` | Client to server | Play, pause, or seek (host only) |
| `party.sync` | Client to server | Report client position for drift correction |
| `party.sync_response` | Server to client | Server-adjusted position after sync |
| `party.member_event` | Server to room broadcast | Member joined (from `party.join`) or left (from `SwooleLivePartyRooms`, for a leave over the WebSocket or HTTP) |
| `session.join` | Client to server | Join the user's listening session from a device; answered with `session.joined` |
| `session.playback` | Client to server | Play, pause or seek the listening session; answered with `session.playback_result` |
| `session.sync` | Client to server | Report a device's position; answered with `session.sync_result` |
| `session.claimed` / `session.state` | Server to the user's connections | A device took over the listening session, or its queue changed (`SessionEventSubscriber`) |
| `transcode.position` | Client to server | Report the playback position of a transcode session; answered with `transcode.position_ack` |
| `error` | Server to client | Error response with message |

Rate limiting is enforced per connection: 30 messages per second. Exceeding this returns an `error` message and the excess messages are dropped.

### Identity

A connection's user is fixed at its handshake, which authenticates the access token (see [Authentication](#authentication)). No message changes it. A client whose connection drops opens a new one with a current access token.

## Delivery without a stream

Server-sent event (SSE) endpoints for notifications and job monitoring were removed: their coroutines held pooled services for up to an hour each and exhausted the service pools. Each now works without a stream:

- **Notifications** are stored, then read over HTTP. The web client fetches the list and the unread count when the notification views load and refetches them once they are older than 30 seconds. `CreateNotificationHandler` also dispatches a `SendPushCommand`, which delivers a Web Push message to each browser subscription the user registered, unless push is turned off for the server or the user turned off push for that category.
- **Admin pages** poll their endpoints with React Query: server diagnostics every 5 seconds, the dashboard every 10 seconds, the job monitor every 15 to 30 seconds.

The WebSocket channel does not carry admin job events yet.

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

A WebSocket connection authenticates with an OAuth 2.0 access token in the `token` query parameter, because the browser WebSocket API cannot set an `Authorization` header on the handshake.

### WsQueryTokenAuthenticator

Invoked during Swoole's `onHandshake` callback -- this is not a Symfony firewall authenticator. It builds a minimal Symfony `Request` from the Swoole request, injects the token as a `Bearer` header, and validates through the League OAuth2 `ResourceServer`. Returns the authenticated user's UUID string, or `null` on failure (which rejects the handshake).

---

*See [Shared Kernel](shared-kernel.md) for Redis connection management and Swoole async primitives. See [Architecture](architecture.md) for the bounded context overview.*
