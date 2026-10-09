# Discovery

The Discovery context handles local-network server discovery and device pairing. A self-hosted server registers itself, a pairing session is created with a short-lived code, and a client completes the pairing to obtain the server URL. In `config/packages/security.yaml`, registration requires `ROLE_ADMIN` and the other `/api/discovery/` endpoints require a fully authenticated user; the controller repeats both checks with `#[IsGranted]`.

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
| `RegisterServerCommand` | `RegisterServerHandler` | Register a self-hosted server and emit `ServerRegistered` (the register route and `app:discovery:register`) |
| `CreatePairingCodeCommand` | `CreatePairingCodeHandler` | Open a pairing session for a server |
| `CompletePairingCommand` | `CompletePairingHandler` | Complete and validate a pairing session, emitting `PairingCompleted` |

`RegisterServerCommand` carries the URL, name and version; `RegisterServerHandler` generates the API key (64 hex characters from `random_bytes(32)`). The handler rejects a URL that is not a valid `http` or `https` URL, and a blank name or version, with `InvalidInputException` (HTTP 422, console exit `INVALID`). `ServerInstanceResource` does not include the API key, so neither the route nor the command returns it.

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

All endpoints are prefixed with `/api`.

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/api/discovery/register` | Register a self-hosted server (`ROLE_ADMIN`; console: `app:discovery:register`) |
| POST | `/api/discovery/pairing-code` | Create a pairing code for a server (authenticated) |
| GET | `/api/discovery/qr-payload/{serverPublicId}` | Get QR payload for a pending pairing session (authenticated) |
| POST | `/api/discovery/complete-pairing` | Complete a pairing session (authenticated) |

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

### Console Commands

| Command | Purpose |
|---------|---------|
| `app:discovery:register` (`DiscoveryRegisterCommand`) | Register a server from its URL, name and version; dispatches `RegisterServerCommand` |

See the [Architecture](../architecture.md#communication-between-contexts) page for details on inter-context event flow.
