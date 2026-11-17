# Party

The Party context implements synchronized watch-party playback. Users create a party session, invite members, and watch media together with wall-clock synchronization, jitter compensation, and WebSocket-based real-time state broadcasting.

## Domain Models

| Model | Type | Purpose |
|-------|------|---------|
| `SyncedPartySession` | Aggregate Root | Party session with host, video, members, playback state, and jitter compensation |
| `PartyMember` | Model | A member within a party session |
| `MemberRole` | Value Object (enum) | Role within a party (`Host`, `Member`) |
| `PlaybackAction` | Value Object (enum) | Playback command (`Play`, `Pause`, `Seek`, `Join`, `Leave`) |
| `PlaybackState` | Value Object (enum) | Current playback state (`Stopped`, `Playing`, `Paused`) |

`SyncedPartySession` and `PartyMember` keep their fields in the state objects `SyncedPartySessionState` and `PartyMemberState`, which each model's private constructor, `create()` and `reconstitute()` share, as the project's aggregate root convention requires.

## Domain Events

| Event | Purpose |
|-------|---------|
| `PartySessionCreated` | Emitted when a new party is created |
| `MemberJoined` | Emitted when a user joins a party |
| `MemberLeft` | Emitted when a user leaves a party |
| `PartySessionEnded` | Emitted when a party is closed |
| `PlaybackStateChanged` | Emitted when the host plays, pauses or seeks; carries the session's playback state right after the change |

## Commands and Handlers

| Command | Handler | Purpose |
|---------|---------|---------|
| `CreatePartySessionCommand` | `CreatePartySessionHandler` | Create a new party session |
| `JoinPartySessionCommand` | `JoinPartySessionHandler` | Add a member to an existing party, or reconnect a returning one, which a full party still admits |
| `LeavePartySessionCommand` | `LeavePartySessionHandler` | Remove a member from a party; a leaving host hands the session's host role to the earliest-joined remaining member |
| `StartPlaybackCommand` | `StartPlaybackHandler` | Begin playback for all members |
| `PausePlaybackCommand` | `PausePlaybackHandler` | Pause playback for all members |
| `SeekPlaybackCommand` | `SeekPlaybackHandler` | Seek to a specific position |
| `SyncPlaybackCommand` | `SyncPlaybackHandler` | Periodic playback state synchronization |
| `TransferHostCommand` | `TransferHostHandler` | Transfer the host role to another member |
| `EndPartySessionCommand` | `EndPartySessionHandler` | Close and end a party session |

## Ports

| Port | Purpose | Implemented By |
|------|---------|----------------|
| `SyncedPartySessionRepositoryInterface` | Domain repository contract for `SyncedPartySession` persistence and queries | `SyncedPartySessionRepository` |
| `PartyMemberRepositoryInterface` | Domain repository contract for `PartyMember` persistence and queries | `PartyMemberRepository` |
| `PartySessionPortInterface` | Session lifecycle operations (create, end, query) | `PartySessionService` |
| `PartyMemberPortInterface` | Member operations (join, leave, role transfer) | `PartyMemberService` |
| `PartyMediaAccessPortInterface` | Verifies that the host may play the session's video and that any named transcode job encodes it | `PartyMediaAccess` |
| `PartyPublicIdentityPortInterface` | Resolves public and internal identifiers for party sessions, members, users, videos, and transcode jobs | `PartyPublicIdentity` |
| `PlaybackSynchronizationPortInterface` | Wall-clock position calculation and jitter compensation | `PlaybackSynchronizer` |

## API Endpoints

REST endpoints use `/api/party/sessions`. Every session route parameter is `{publicId}` (a 21-character `PublicId`), and `PartySessionResource` exposes `publicId` rather than internal UUIDs.

Parties are invite-only. The session list returns only the active parties the caller hosts or has joined. A session and its member list answer `404` unless the caller has a member row in the session, which the host holds from creation; an unauthenticated request gets `401`. Joining with the public ID stays open to any signed-in user.

### Party Sessions

| Method | Path | Controller | Purpose |
|--------|------|------------|---------|
| GET | `/api/party/sessions/` | `PartySessionController` | List the caller's active parties |
| POST | `/api/party/sessions/` | `PartySessionController` | Create a new party |
| GET | `/api/party/sessions/{publicId}` | `PartySessionController` | Get a party session and its members (host and members only) |
| DELETE | `/api/party/sessions/{publicId}` | `PartySessionController` | End a party session |

### Party Members

| Method | Path | Controller | Purpose |
|--------|------|------------|---------|
| POST | `/api/party/sessions/{publicId}/join` | `PartySessionController` | Join a party |
| POST | `/api/party/sessions/{publicId}/leave` | `PartySessionController` | Leave a party |
| GET | `/api/party/sessions/{publicId}/members/` | `PartyMemberController` | List party members (host and members only) |
| PATCH | `/api/party/sessions/{publicId}/members/me` | `PartyMemberController` | Update the current member's playback preferences |
| POST | `/api/party/sessions/{publicId}/members/transfer-host` | `PartyMemberController` | Transfer the host role |

### Playback

| Method | Path | Controller | Purpose |
|--------|------|------------|---------|
| POST | `/api/party/sessions/{publicId}/sync` | `PartySessionController` | Sync playback state |

### WebSocket

| Path | Controller | Purpose |
|------|------------|---------|
| `GET /api/ws` | `WebSocketController` (Shared) | The application WebSocket, which carries the `party.*` messages |

A member requests a single-use ticket from `POST /api/ws/tickets`, upgrades at `/api/ws`, then sends `party.join` with the session's public ID as `sessionId` to enter the party's room. The room receives `party.member_event` when a member joins with `party.join` or leaves, and `party.playback_state` after each play, pause or seek by the host, with `sessionId` (the public ID), `playbackState`, `currentPosition`, `wallClockPosition` and `updatedAt`. A play, pause or seek from anyone else broadcasts nothing. See [Real-Time Patterns](../architecture/real-time-patterns.md#party-sync-protocol) for the protocol.

## Cross-Context Dependencies

| Direction | Context | Relationship |
|-----------|---------|-------------|
| Depends on | Shared | Uses `Uuid`, `PublicId`, and `LivePartyRoomsPortInterface` |
| Depends on | Catalog | `PartyPublicIdentity` translates video IDs by reading Catalog's `videos` table with SQL; no Catalog class is imported |
| Depends on | Transcode | References video playback access (`PlaybackAccessInterface`) and transcode job data; the start, pause and seek handlers raise Transcode's `PlaybackPositionChanged` |
| Depends on | Auth | User identification and authentication |

## Infrastructure

### Doctrine Entities

| Entity | Purpose |
|--------|---------|
| `SyncedPartySessionEntity` | Party session persistence |
| `PartyMemberEntity` | Party member persistence |
| `PartyEventEntity` | Audit log of party events |

### Services

| Service | Purpose |
|---------|---------|
| `PartySessionService` | Session lifecycle management |
| `PartyMemberService` | Member management |
| `PartyMediaAccess` | Checks video playback access and transcode job ownership via `PlaybackAccessInterface` |
| `PartyPublicIdentity` | Translates between internal `Uuid` values and 21-character `PublicId` values |
| `PlaybackSynchronizer` | Wall-clock sync with jitter compensation (`src/Party/Infrastructure/PlaybackSynchronizer.php`) |
| `LivePartyRoomListener` | Removes departed members from live WebSocket party rooms on `MemberLeft`, closes rooms on `PartySessionEnded`, and sends `party.playback_state` to the room on `PlaybackStateChanged` |

### WebSocket

The shared `WebSocketController` dispatches the party commands for `party.*` messages and broadcasts member joins to the party's room. `SwooleLivePartyRooms`, called by `LivePartyRoomListener`, broadcasts leaves and the host's playback state.
