# Session

The Session context implements listening sessions and registered devices. Users register devices, claim or create a listening session, join a session from a device, and synchronize playback state. Session events are broadcast to connected clients over WebSocket.

## Domain Models

### Aggregate Roots

| Model | Purpose |
|-------|---------|
| `ListeningSession` | A listening session with playback state, owner, and joined devices |
| `Device` | A registered device belonging to a user |

`ListeningSessionState` and `DeviceState` hold constructor/create/reconstitute in sync per the project's aggregate root convention.

## Commands & Handlers

| Command | Handler | Purpose |
|---------|---------|---------|
| `CreateSessionCommand` | `CreateSessionCommandHandler` | Create a new listening session |
| `ClaimSessionCommand` | `ClaimSessionCommandHandler` | Claim a session for a user |
| `SessionJoinCommand` | `SessionJoinCommandHandler` | Join a device into a session |
| `SessionPlaybackCommand` | `SessionPlaybackCommandHandler` | Drive playback on a session |
| `SyncSessionCommand` | `SyncSessionCommandHandler` | Synchronize session playback state |

## Ports

| Port | Purpose |
|------|---------|
| `SessionPortInterface` | Session and device operations (create, claim, join, sync, playback, register/rename/forget devices) |

## Domain Events

| Event | Trigger |
|-------|---------|
| `SessionCreated` | A new listening session was created |
| `SessionClaimed` | A session was claimed by a user |
| `SessionUpdated` | A session's playback state was updated |
| `DeviceRegistered` | A device was registered |

## API Endpoints

All endpoints are prefixed with `/api`.

### Sessions

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/session` | Get the current session |
| PUT | `/api/session` | Sync session playback state |
| POST | `/api/session/claim` | Claim a session |
| POST | `/api/session/new` | Create a new session |

### Devices

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/api/devices` | Register a device |
| GET | `/api/devices` | List the user's devices |
| PUT | `/api/devices/{deviceId}` | Rename a device |
| DELETE | `/api/devices/{deviceId}` | Forget a device |

## Cross-Context Relationships

| Direction | Context | Details |
|-----------|---------|---------|
| Depends on | Shared | `Uuid`, `AbstractDomainEvent`, `WebSocketPusher`, `ApiResponsesTrait` |
| Depends on | Auth | `SecurityUser` for authenticated user resolution |

## Infrastructure

| Component | Type | Purpose |
|-----------|------|---------|
| `ListeningSessionEntity` | Doctrine entity | Persistence for `ListeningSession` |
| `DeviceEntity` | Doctrine entity | Persistence for `Device` |
| `ListeningSessionRepository` | Doctrine repository | Repository implementation for sessions |
| `DeviceRepository` | Doctrine repository | Repository implementation for devices |
| `SessionAdapter` | Adapter | Session port infrastructure adapter |
| `SessionEventSubscriber` | Event subscriber | Broadcasts `SessionClaimed` / `SessionUpdated` over WebSocket via `WebSocketPusher` |

See the [Architecture](../architecture.md#async-runtime) page for details on WebSocket broadcasting and the async runtime.
