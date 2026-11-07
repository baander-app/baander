# Real-Time Patterns

Baander has one real-time transport: a WebSocket connection served by Swoole at `/api/ws`. It carries `live.*` live-update subscriptions and change hints, watch-party sync and member events, playback-session coordination, transcode position reports, and server pushes to a user's open connections. Notifications and admin views do not hold dedicated SSE streams open; they refresh through WebSocket `live.changed` hints and coordinated HTTP reads, as [Delivery without a stream](#delivery-without-a-stream) and [Live Updates](live-updates.md) describe.

## WebSocket

WebSocket connections are managed through Swoole's process mode. The `WebSocketController` extends `AbstractWebSocketController` from SwooleBundle and handles the full connection lifecycle: open, message, close.

### Connection Registry

`WebSocketConnectionRegistry` uses Swoole's shared-memory `Table` to track connections across all worker processes. Three tables are maintained:

| Table | Purpose | Key format |
|-------|---------|------------|
| `connections` | Maps file descriptor to `user_id`, `access_token_id`, `worker_id`, and `connected_at` | `{fd}` |
| `roomMembers` | Maps room membership (for broadcasting) | `{room}\0{fd}` |
| `fdRooms` | Reverse index: which rooms a connection belongs to | `{fd}\0{room}` |

`access_token_id` binds the connection to its authorizing token chain so logout, token revocation, and `RevokedSocketReconciler` can close affected connections across workers. Each user is limited to 10 concurrent WebSocket connections. Orphaned entries (connections whose underlying TCP socket has closed) are cleaned up by `cleanupOrphans()`, which calls `Swoole\Server::isEstablished()` to verify the connection is still alive.

### Message Pushing

`WebSocketPusher` provides three sending strategies:

| Method | Target | Use case |
|--------|--------|----------|
| `pushToConnection(fd, payload)` | Single connection by file descriptor | Replies, error messages, `live.*` frames |
| `push(userId, payload)` | All connections for a user | User-scoped playback snapshots |
| `broadcast(room, payload)` | All members of a room | Party member events |

All payloads are JSON-encoded before sending. Failed pushes (disconnected clients) are logged and silently skipped.

### Rooms

A room's members receive everything broadcast to it, so a connection joins a room only through the message that checks it may. `WebSocketRoomPolicy` holds the join rules; the names come from `WebSocketRooms` in Shared Infrastructure, which `SwooleLivePartyRooms` also uses:

| Room | Joined by | Left by | Broadcasts |
|------|-----------|---------|------------|
| `party:{sessionUuid}` | `party.join` (client passes party `publicId` as `sessionId`, resolved to the party's internal UUID after `JoinPartySessionCommand` accepts the user) | Every socket of a member who leaves the party, with `party.leave` or `POST /api/party/sessions/{publicId}/leave`; every socket when the party ends; one socket with `room.leave` or by closing | `party.member_event` |

A party room follows the party's membership. `LeavePartySessionHandler` dispatches `MemberLeft`, and `EndPartySessionHandler` (or a leave that ends the party) dispatches `PartySessionEnded`. `LivePartyRoomListener` in Party Infrastructure hears both and calls `LivePartyRoomsPortInterface`. Its implementation, `SwooleLivePartyRooms`, takes every socket of the member out of the room and sends the sockets left in it one `party.member_event` leave event, or empties the room when the party ends. The registry's tables are shared by every worker, so the worker that runs the leave changes sockets on any worker. Outside an HTTP worker of the running server (a console command, a Messenger worker, a test kernel) the port does nothing.

No other room exists. Pushes to one user, such as `session.snapshot`, go through `push(userId, payload)` and need no room. `room.join` joins nothing: it answers a party room with `Party rooms are joined with party.join` and any other name with `Unknown room`, and logs a warning. No client sends it. A new room kind gets its join rule in `WebSocketRoomPolicy` and a row here.

A room name may be at most 52 bytes long. Swoole keeps only the first 63 bytes of a table key, and a membership key adds a NUL and the connection's fd (up to 10 digits) to the name.

### WebSocket Message Protocol

The controller dispatches messages by `type` field:

| Message type | Direction | Description |
|-------------|-----------|-------------|
| `connected` | Server to client | Sent on successful handshake |
| `ping` / `pong` | Both | Keep-alive |
| `live.subscribe` | Client to server | Subscribe the connection to live-update hints; answered with `live.ready` |
| `live.ready` / `live.changed` / `live.reset` / `live.health` | Server to client | Live-update subscription baseline, family change hints, authorization reset, and pipeline health (see [Live Updates](live-updates.md#message-contract)) |
| `room.join` | Client to server | Refused for every room; see [Rooms](#rooms) |
| `room.leave` | Client to server | Leave a room the connection is in; answered with `room.left` |
| `party.join` / `party.leave` | Client to server | Join or leave a watch-party session (`party.join` answers the sender with `party.joined`) |
| `party.playback` | Client to server | Play, pause, or seek (host only); answered with `party.playback_updated` |
| `party.sync` | Client to server | Report client position for drift correction |
| `party.sync_response` | Server to client | Server-adjusted position after sync |
| `party.member_event` | Server to room broadcast | Member joined (from `party.join`) or left (from `SwooleLivePartyRooms`, for a leave over the WebSocket or HTTP) |
| `session.endpoint` | Client to server | Register or refresh a playback endpoint; answered with `session.snapshot` |
| `session.transfer` | Client to server | Initiate a playback transfer to a destination; answered with `session.snapshot` |
| `session.ack` | Client to server | Acknowledge a playback transfer on the target endpoint; answered with `session.snapshot` |
| `session.control` | Client to server | Apply a playback control command (`play`, `pause`, `seek`, queue updates); answered with `session.snapshot` |
| `session.observe` | Client to server | Report observed playback state and position from the active endpoint; answered with `session.snapshot` |
| `session.reconcile` | Client to server | Reconcile an uncertain transfer or endpoint state; answered with `session.snapshot` |
| `session.snapshot` | Both | Request the current playback snapshot (client to server) or receive the updated playback snapshot (server to client reply and `PlaybackCommandRelay` push) |
| `session.error` | Server to client | Error reply for a `session.*` operation |
| `transcode.position` | Client to server | Report the playback position of a transcode session; answered with `transcode.position_ack` |
| `error` | Server to client | Error response with message |

Rate limiting is enforced per connection: 30 messages per second. Exceeding this returns an `error` message and the excess messages are dropped.

### Transcode position controls

`transcode.position` requires an owned `sessionId` and a finite, nonnegative `position` in seconds.
Its `action` is `seek` by default, or `pause`, `resume` or `progress`.
A native seek supplies the positive integer `generation` attached to the player so a delayed event cannot seek a replacement generation.
An explicit seek may omit it.
Progress requires both the current positive `generation` and `revision`; stale reports leave playback unchanged.

An optional UUID `requestId` identifies one control across retries, including reconnects.
Reusing it after another control has committed does not apply the earlier control again.
The WebSocket reply echoes a supplied identity and reports `status: "processed"`.
This means dispatch completed; a stale fence, replay or terminal session can still leave state unchanged.
It does not acknowledge worker execution.
Read the session's playback state separately for its current generation, revision, desired status and readiness.
The HTTP position endpoint accepts the same fields and echoes a supplied identity; its existing `status: "ok"` has the same processing meaning.

### Identity

A connection's user is fixed at its handshake, which redeems a ticket bound to the
user's access token (see [Authentication](#authentication)). No message changes it.
A client whose connection drops obtains a fresh ticket before reconnecting.

## Delivery without a stream

Server-sent event (SSE) endpoints for notifications and job monitoring were removed: their coroutines held pooled services for up to an hour each and exhausted the service pools. Each now refreshes through the WebSocket live-update protocol (`live.changed` hints) and coordinated HTTP reads (see [Live Updates](live-updates.md)):

- **Notifications** are stored, then read over HTTP when the view mounts or when a `live.changed` hint for the `notifications` family arrives. `CreateNotificationHandler` also dispatches a `SendPushCommand`, which delivers a Web Push message to each browser subscription the user registered, unless push is turned off for the server or the user turned off push for that category.
- **Admin pages** subscribe to their live-update families (`admin-jobs`, `admin-runtime`, `admin-users`, `admin-settings`, `admin-alerts`, `admin-security`, `admin-metadata`, `admin-media`, `deployment-definitions`) through `LiveQueryCoordinator`. Committed writes to `job_monitors`, `scheduled_jobs`, and `failed_messages` push immediate `live.changed` hints, while live telemetry readers use bounded `sampleIntervalMs` sampling (5,000ms for job status and server stats, 10,000ms for spans, and 30,000ms for analytics) while visible and online.

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

When the party session has an associated `transcodeJobId`, all three handlers dispatch a `PlaybackPositionChanged` (`App\Transcode\Domain\Event\PlaybackPositionChanged`) domain event via `EventDispatcherInterface` so the transcode subscriber can steer the media worker.

### Sync Protocol

Non-host participants periodically report their local playback position to the server. The sync flow:

1. Client sends `party.sync` with its current position and measured latency.
2. `SyncPlaybackHandler` delegates to `PlaybackSynchronizer::synchronize()` (`PlaybackSynchronizationPortInterface`).
3. `PlaybackSynchronizer` verifies party membership and calls `PartySessionPortInterface::syncPlayback()`, which computes the server's current position via `getCurrentPosition()` on `SyncedPartySession`.
4. If drift exceeds `clientLatency + 1.0` seconds, the server position is returned for correction; otherwise, the server position is returned as the authoritative position without requiring a seek.
5. `PlaybackSynchronizer` updates the member's smoothed EMA jitter (initializing to `jitter` when `currentJitter <= 0`) and saves the member.

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
4. When the party session has a `transcodeJobId`, a `PlaybackPositionChanged` event is dispatched.
5. `WebSocketController` replies `party.playback_updated` to the host, and participants converge to the new position through `party.sync` / `party.sync_response` and `live.changed` (`party` family) reads.

## Authentication

The browser WebSocket API cannot set an Authorization or DPoP header on an
upgrade. The client first calls `POST /api/ws/tickets` through its authenticated,
DPoP-aware HTTP client. The response contains `data.ticket` and `data.expiresAt`
and is marked `Cache-Control: no-store`. The ticket authorizes one connection for
at most thirty seconds, bounded by the authorizing token's expiry.

The client opens `/api/ws?ticket=<ticket>` on the API origin. Every reconnect needs
a fresh ticket. Logout, unmount and replacement cancel pending issuance so a late
response cannot open a socket. The embedded player takes a host-provided
`getWebSocketTicket` callback; its existing party message vocabulary still differs
from this server protocol and remains unqualified.

### WsTicketAuthenticator

Swoole's upgrade handler checks GET `/api/ws`, WebSocket framing, an exact allowed
Origin and a single canonical ticket query before calling `WsTicketAuthenticator`.
The adapter calls the Auth ticket-redemption port. Redis atomically checks the
Origin/path binding and consumes the ticket; a fresh database query checks token
ownership, DPoP binding, expiry, revocation and enabled-user state. Successful
redemption returns the user UUID and access-token ID to the connection registry.

The Origin allowlist defaults to the serialized origin of `APP_URL`; an explicit
empty list denies all upgrades. Ticket issuance uses the same policy. Missing or
foreign Origins, raw OAuth query tokens, duplicate parameters and reused tickets
are rejected. Unavailable ticket storage or authorization state returns 503 with
Retry-After. A stolen unused ticket can still race its intended client; tickets do
not provide proof possession for later messages.

---

*See [Shared Kernel](shared-kernel.md) for Redis connection management and Swoole async primitives. See [Architecture](overview.md) for the bounded context overview.*
