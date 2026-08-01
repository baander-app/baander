# Discovery

The Discovery context handles local-network server discovery and device pairing. A self-hosted server registers itself, a pairing session is created with a short-lived code, and a client completes the pairing to obtain the server URL. All `/api/discovery/` endpoints are marked `PUBLIC_ACCESS` in `config/packages/security.yaml` — pairing happens before a user is authenticated.

## Domain Models

### Aggregate Roots

| Model | Purpose |
|-------|---------|
| `ServerInstance` | A registered self-hosted server instance (URL, name, version, API key, status) |
| `PairingSession` | A short-lived pairing session linking a client to a server via a pairing code |

### Value Objects

| Model | Purpose |
|-------|---------|
| `AuthenticationMethod` | Pairing method enum (`qr_code`, `email_url`, `server_code`) |
| `PairingCode` | Human-transcribable pairing code (consonant segments separated by dashes) |
| `ServerStatus` | Server lifecycle status enum (`online`, `offline`, `maintenance`) |

## Commands & Handlers

| Command | Handler | Purpose |
|---------|---------|---------|
| `RegisterServerCommand` | `RegisterServerHandler` | Register a self-hosted server and emit `ServerRegistered` |
| `CreatePairingCodeCommand` | `CreatePairingCodeHandler` | Open a pairing session for a server |
| `CompletePairingCommand` | `CompletePairingHandler` | Complete and validate a pairing session, emitting `PairingCompleted` |

## Ports

| Port | Purpose |
|------|---------|
| `ServerInstancePortInterface` | Server registration, lookup by public ID / URL, heartbeat, stale detection |
| `DiscoveryPortInterface` | Pairing session creation, completion, and lookup by pairing code |

## Domain Events

| Event | Trigger |
|-------|---------|
| `ServerRegistered` | A server completed self-registration |
| `PairingCompleted` | A pairing session was validated and completed |

## API Endpoints

All endpoints are prefixed with `/api` and are `PUBLIC_ACCESS` (no authentication).

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/api/discovery/register` | Register a self-hosted server |
| POST | `/api/discovery/pairing-code` | Create a pairing code for a server |
| GET | `/api/discovery/qr-payload/{serverPublicId}` | Get QR payload for a pending pairing session |
| POST | `/api/discovery/complete-pairing` | Complete a pairing session |

## Cross-Context Relationships

| Direction | Context | Details |
|-----------|---------|---------|
| Depends on | Shared | `Uuid`, `PublicId`, `AbstractDomainEvent` |

## Infrastructure

| Component | Type | Purpose |
|-----------|------|---------|
| `ServerInstanceEntity` / `PairingSessionEntity` | Doctrine entity | Persistence for both aggregates |
| `ServerInstanceRepository` / `PairingSessionRepository` | Doctrine repository | Repository implementations for both aggregates |
| `ServerInstanceService` | Port implementation | Backs `ServerInstancePortInterface` |
| `DiscoveryService` | Port implementation | Backs `DiscoveryPortInterface` |

See the [Architecture](../architecture.md#communication-between-contexts) page for details on inter-context event flow.
