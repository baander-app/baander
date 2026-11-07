# Session

The Session context coordinates an account's playback destination, media selection, music queue, and playback position, alongside registered user devices. Each browser tab registers its own playback endpoint incarnation. Browser adapters execute media commands; Session records command intent in PostgreSQL and accepts acknowledgements from the endpoint that owns each command.

## Domain Models

### Aggregate Roots

| Model | Purpose |
|-------|---------|
| `ListeningSession` | One aggregate per user (`user_id` unique): holds the song or video selection, music queue, track index, source-time position, desired and observed playback states, selected destination, active endpoint incarnation, and revision |
| `Device` | A registered browser device identified by its client-generated `device_id` and owner |

`ListeningSessionState` and `DeviceState` hold constructor/create/reconstitute in sync per the project's aggregate root convention.

### Playback Models & Operations

| Model | Kind | Purpose |
|-------|------|---------|
| `PlaybackTransfer` | Domain model | Tracks a handoff attempt between source and target incarnations through `preparing`, `quiescing`, and `starting` until it commits, fails, or becomes `uncertain` |
| `PlaybackMedia` | Value object | Selected media item (`song` or `video` kind and `PublicId`) |
| `PlaybackState` | Enum | Desired playback state: `Playing`, `Paused`, `Stopped` |
| `ObservedPlaybackState` | Enum | Endpoint-reported playback state: `Playing`, `Paused`, `Stopped`, `Unknown` |
| `PlaybackOperation` | Enum | Coordination operation dispatched to `PlaybackCoordinationInterface`: `Endpoint`, `Transfer`, `Acknowledge`, `Observe`, `Control`, `Reconcile`, `Snapshot`, `RecoverLocal` |

## Application Services & Ports

Session uses `PlaybackCoordinator` instead of Messenger command handlers for session coordination. `PlaybackCoordinator` validates media access through Media's `PlaybackMediaAccessInterface` and commits session state together with durable command intent through Shared's `TransactionPortInterface`.

| Interface | Implementation | Purpose |
|-----------|---------------|---------|
| `PlaybackCoordinationInterface` | `PlaybackCoordinator` | Apply controls (`load`, `play`, `pause`, `seek`, `stop`, `volume`, `queue`), endpoint registration, transfers, acknowledgements, observations, local recovery, snapshots, and reconciliation |
| `DevicePortInterface` | `DeviceAdapter` | Register, list, rename, and forget devices |
| `PlaybackStoreInterface` | `PlaybackStore` | Lock session state and persist destinations, incarnations, transfers, command intent, and request identity |
| `PlaybackConnectionsInterface` | `PlaybackCommandRelay` | Bind authenticated WebSocket connections and replay changed complete snapshots and pending commands |
| `ListeningSessionRepositoryInterface` | `ListeningSessionDoctrineRepository` | Persist `ListeningSession` aggregates |
| `DeviceRepositoryInterface` | `DeviceDoctrineRepository` | Persist `Device` registrations |

## Domain Events

| Event | Trigger |
|-------|---------|
| `DeviceRegistered` | Emitted by `Device::create()` when a device is first registered |

Playback commands and complete snapshots use the coordination ports and durable command ledger rather than domain events.

## API Endpoints

All endpoints are prefixed with `/api`.

### Sessions (`PlaybackController`)

Endpoint-authenticated operations take the endpoint capability token in the `X-Baander-Playback-Endpoint` header (required on `/api/session/ack` and `/api/session/observe`, and accepted on `/api/session/control` and `/api/session/snapshot` when `incarnationId` is supplied so the response includes that endpoint's pending commands).

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/api/session/endpoints` | Register a fresh playback endpoint incarnation for a tab and connection |
| POST | `/api/session/transfer` | Begin an acknowledged playback transfer between incarnations |
| POST | `/api/session/ack` | Acknowledge an owned endpoint command (`X-Baander-Playback-Endpoint` required) |
| POST | `/api/session/observe` | Renew endpoint presence and report observed playback state (`X-Baander-Playback-Endpoint` required) |
| POST | `/api/session/control` | Control the committed playback runner (`load`, `play`, `pause`, `seek`, `stop`, `volume`, `queue`) |
| POST | `/api/session/reconcile` | Stop an uncertain transfer target |
| POST | `/api/session/recover-local` | Recover a proven stopped local browser incarnation using its recovery credential |
| GET | `/api/session/snapshot` | Recover the complete playback snapshot |

### Devices (`DeviceController`)

Device mutation routes address a device by its 21-character `PublicId` (`{publicId}`, named `{deviceId}` in the route template).

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/devices` | List all devices registered for the current user |
| POST | `/api/devices` | Register or touch a device (upsert) |
| PUT / PATCH | `/api/devices/{publicId}` | Rename a device (`#[Route('/{deviceId}', methods: ['PUT'])]`) |
| DELETE | `/api/devices/{publicId}` | Forget a device (`#[Route('/{deviceId}', methods: ['DELETE'])]`) |

## Cross-Context Relationships

| Direction | Context | Details |
|-----------|---------|---------|
| Depends on | Auth | `AuthenticatedUserIdentityInterface` for authenticated user resolution |
| Depends on | Media | `PlaybackMediaAccessInterface` to authorize selected songs, videos, and queue entries |
| Depends on | Shared | `Uuid`, `PublicId`, `AbstractDomainEvent`, `ApiResponsesTrait`, `TransactionPortInterface`, and `ListeningSessionInteractionInterface` |
| Depended on by | Shared | `WebSocketController` delegates playback messages and connection lifecycle through `ListeningSessionInteractionInterface` (implemented by `ListeningSessionInteractionAdapter`) |

## Infrastructure

| Component | Type | Purpose |
|-----------|------|---------|
| `ListeningSessionEntity` | Doctrine entity | Persistence mapping for `ListeningSession` |
| `DeviceEntity` | Doctrine entity | Persistence mapping for `Device` |
| `ListeningSessionDoctrineRepository` | Doctrine repository | Implements `ListeningSessionRepositoryInterface` |
| `DeviceDoctrineRepository` | Doctrine repository | Implements `DeviceRepositoryInterface` |
| `DeviceAdapter` | Adapter | Implements `DevicePortInterface` |
| `PlaybackStore` | Doctrine store | Implements `PlaybackStoreInterface` with row locking for destinations, incarnations, transfers, and the command ledger |
| `PlaybackSessionSchemaListener` | Doctrine schema listener | Declares the playback coordination tables (`playback_session_states`, `playback_destinations`, `playback_endpoint_incarnations`, `playback_commands`, `playback_requests`, `playback_local_recoveries`) |
| `PlaybackCommandRelay` | Swoole relay | Implements `PlaybackConnectionsInterface`; polls bound WebSocket connections and delivers changed snapshots and pending commands |
| `ListeningSessionInteractionAdapter` | Messaging adapter | Implements Shared's `ListeningSessionInteractionInterface` to bridge WebSocket frames and connection binding to `PlaybackCoordinator` and `PlaybackCommandRelay` |

See [Live updates](../architecture/live-updates.md) for the WebSocket delivery model and its runtime ownership.
