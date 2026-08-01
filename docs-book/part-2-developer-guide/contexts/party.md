# Party

The Party context implements synchronized watch-party playback. Users create a party session, invite members, and watch media together with wall-clock synchronization, jitter compensation, and WebSocket-based real-time state broadcasting.

## Domain Models

| Model | Type | Purpose |
|-------|------|---------|
| `SyncedPartySession` | Aggregate Root | Party session with host, video, members, playback state, and jitter compensation |
| `PartyMember` | Model | A member within a party session |
| `MemberRole` | Value Object (enum) | Role within a party (`Host`, `Member`) |
| `PlaybackAction` | Value Object (enum) | Playback command (`Play`, `Pause`, `Seek`) |
| `PlaybackState` | Value Object (enum) | Current playback state (`Stopped`, `Playing`, `Paused`) |

State value objects `SyncedPartySessionState` and `PartyMemberState` keep constructor/create/reconstitute in sync per the project's aggregate root convention.

## Domain Events

| Event | Purpose |
|-------|---------|
| `PartySessionCreated` | Emitted when a new party is created |
| `MemberJoined` | Emitted when a user joins a party |
| `PartySessionEnded` | Emitted when a party is closed |

## Commands and Handlers

| Command | Handler | Purpose |
|---------|---------|---------|
| `CreatePartySessionCommand` | `CreatePartySessionHandler` | Create a new party session |
| `JoinPartySessionCommand` | `JoinPartySessionHandler` | Add a member to an existing party |
| `LeavePartySessionCommand` | `LeavePartySessionHandler` | Remove a member from a party |
| `StartPlaybackCommand` | `StartPlaybackHandler` | Begin playback for all members |
| `PausePlaybackCommand` | `PausePlaybackHandler` | Pause playback for all members |
| `SeekPlaybackCommand` | `SeekPlaybackHandler` | Seek to a specific position |
| `SyncPlaybackCommand` | `SyncPlaybackHandler` | Periodic playback state synchronization |
| `TransferHostCommand` | `TransferHostHandler` | Transfer the host role to another member |
| `EndPartySessionCommand` | `EndPartySessionHandler` | Close and end a party session |

## Ports

| Port | Purpose |
|------|---------|
| `PartySessionPortInterface` | Session lifecycle operations (create, end, query) |
| `PartyMemberPortInterface` | Member operations (join, leave, role transfer) |

## API Endpoints

All endpoints are prefixed with `/api`.

### Party Sessions

| Method | Path | Controller | Purpose |
|--------|------|------------|---------|
| GET | `/api/parties` | `PartySessionController` | List parties |
| POST | `/api/parties` | `PartySessionController` | Create a new party |
| GET | `/api/parties/{uuid}` | `PartySessionController` | Get a single party session |
| DELETE | `/api/parties/{uuid}` | `PartySessionController` | End a party session |

### Party Members

| Method | Path | Controller | Purpose |
|--------|------|------------|---------|
| POST | `/api/parties/{uuid}/join` | `PartyMemberController` | Join a party |
| POST | `/api/parties/{uuid}/leave` | `PartyMemberController` | Leave a party |

### Playback

| Method | Path | Controller | Purpose |
|--------|------|------------|---------|
| POST | `/api/parties/{uuid}/sync` | `PartyMemberController` | Sync playback state |

### WebSocket

| Path | Controller | Purpose |
|------|------------|---------|
| WebSocket endpoint | `PartyWebSocketController` | Real-time playback sync, state broadcasting |

## Cross-Context Dependencies

| Direction | Context | Relationship |
|-----------|---------|-------------|
| Depends on | Shared | Uses `Uuid` for identifiers |
| Depends on | Transcode | References video and transcode job data |
| Depends on | Auth | User identification and authentication |
| Depended on by | Notification | Party events trigger notifications |

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
| `PlaybackSynchronizer` | Wall-clock sync with jitter compensation |

The `Infrastructure/Playback/` directory contains the jitter compensation and synchronization logic.

### WebSocket

`PartyWebSocketController` handles real-time communication for playback state broadcasting between party members.
