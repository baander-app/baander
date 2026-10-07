# Architecture Overview

Baander follows strict Domain-Driven Design (DDD). The codebase is organized into bounded contexts — each representing a distinct business capability with its own domain model, rules, and language.

## Why DDD

Bounded contexts isolate business logic. The Playlist context knows nothing about Transcoding; Auth doesn't depend on Catalog. This keeps the codebase navigable, testable, and safe to change — modifications in one context rarely ripple into others.

## Bounded Context Map

Baander has 21 bounded contexts plus a Shared kernel. Most contexts follow the full four-layer structure. A few are utility contexts that omit layers they don't need.

| Context | Responsibility | Layers |
|---------|---------------|--------|
| Activity | Listen history tracking | Full |
| Auth | Login, OAuth 2.0 authorization server, DPoP-bound tokens, passkeys, TOTP | Full |
| Catalog | Artists, albums, songs, movies, videos, genres | Full |
| Command | CLI commands (OpenAPI export) | Interface only — no domain logic, just thin wrappers around other contexts |
| Discovery | LAN server discovery and device pairing | Full |
| Favorites | User favorites (songs, artists, albums) | Full |
| Filesystem | File operations, MIME detection, inotify watching | Domain + Infrastructure — no Application or Interface layers |
| Library | Media library management and file scanning | Full |
| Lyrics | Lyrics handling | Domain + Infrastructure only — no Application or Interface layers |
| Media | File handling, streaming, image storage | Full |
| Metadata | External API enrichment (Discogs, Last.fm, MusicBrainz) | Full |
| Notification | Notifications, webhooks, preferences | Full |
| Party | Synchronized watch-party playback | Full |
| Playlist | Playlist CRUD | Full |
| QoL | Stream governance and quality-of-life allocation | Service-based (no aggregate roots) |
| Radio | Internet radio, country subscriptions, station starring | Full |
| Recommendation | Music recommendations | Full |
| Scheduler | Cron-like scheduled job execution | Full |
| Session | Listening sessions and registered devices | Full |
| Transcode | CMAF video transcoding via FFmpeg | Full |
| UserPreference | User preferences (accent color, sidebar) | Full |

The **Shared kernel** (`src/Shared/`) is not a bounded context — it provides cross-cutting infrastructure used by all contexts. It follows the full four-layer structure.

## Project Layout

```
src/
├── Activity/            # Bounded context
├── Auth/                # Bounded context
├── Catalog/             # Bounded context
├── Command/             # Utility context (CLI wrappers)
├── Discovery/           # Bounded context (LAN discovery + device pairing)
├── Favorites/           # Bounded context
├── Filesystem/          # Utility context (file ops, inotify)
├── Kernel.php           # HTTP kernel
├── Library/             # Bounded context
├── Lyrics/              # Utility context (domain + infra only)
├── Media/               # Bounded context
├── Metadata/            # Bounded context
├── Notification/        # Bounded context
├── Party/               # Bounded context
├── Playlist/            # Bounded context
├── QoL/                 # Bounded context (service-based, stream governance)
├── Radio/               # Bounded context (internet radio)
├── Recommendation/      # Bounded context
├── Scheduler/           # Bounded context (scheduled jobs)
├── Session/             # Bounded context (listening sessions + devices)
├── Shared/              # Cross-cutting kernel (not a bounded context)
├── Transcode/           # Bounded context
└── UserPreference/      # Bounded context
```

Each bounded context follows the same four-layer architecture:

```
src/<Context>/
├── Domain/              # Business rules — entities, value objects, repository interfaces
│   ├── Event/           # Domain events (if any)
│   ├── Model/           # Entities (aggregate roots) and value objects
│   ├── Repository/      # Repository interfaces
│   └── Service/         # Domain services (if any)
├── Application/         # Use cases — command/query handlers, DTOs, ports
│   ├── Command/         # Command DTOs (CQRS)
│   ├── CommandHandler/  # Command handlers (CQRS)
│   ├── Port/            # Inbound interfaces (used by controllers)
│   └── DTO/             # Application-level DTOs
├── Infrastructure/      # Technical details — Doctrine repositories, external adapters
│   ├── Doctrine/
│   │   ├── Entity/      # ORM entities (separate from domain models)
│   │   └── Repository/  # Repository implementations
│   └── Adapter/         # External service adapters
└── Interface/           # API layer — controllers, requests, resources
    ├── Controller/      # HTTP controllers
    ├── DTO/             # Request/response DTOs
    └── Resource/        # API response transformers
```

**Dependency flow:** Interface → Application → Domain. Infrastructure implements Domain interfaces but Domain never depends on Infrastructure. This inversion keeps business logic framework-free and testable.

### Domain

Entities, value objects, repository interfaces, and domain services. Pure PHP with no framework dependencies. This is where business rules live.

### Application

Use-case orchestration. Command/query handlers (via Symfony Messenger), DTOs, and port interfaces. Depends only on Domain.

### Infrastructure

Technical implementations. Doctrine repository implementations, external API adapters, storage drivers. Implements Domain interfaces.

### Interface

API entry points. Controllers, request DTOs, response resources. Coordinates between Application ports and the HTTP layer.

## Shared Kernel

`src/Shared/` contains cross-cutting infrastructure used by all contexts:

- **Domain models** — `Uuid` (v7), `PublicId`, `Email`, cursor pagination types
- **Swoole runtime** — async primitives, process pool, WebSocket support
- **Messenger** — job monitoring middleware, task dispatching
- **Caching** — Redis tag-aware cache pools
- **Search** — PGroonga full-text search trait
- **SSE** — server-sent events via Redis Pub/Sub
- **Security** — authenticators for SSE and WebSocket connections

See the [Shared Kernel](shared-kernel.md) page for detailed documentation.

## Anti-Corruption Layer

Baander uses League OAuth2 Server only for its `ResourceServer`, which validates access tokens. Auth issues tokens itself, through the login and refresh endpoints and through its own authorization server at `/api/oauth/authorize`, `/api/oauth/token`, and `/api/oauth/device/authorize`; it runs no League authorization server. League defines its own repository interfaces (e.g., `League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface`) that the resource server calls internally. Without an anti-corruption layer, the Auth domain would depend directly on League interfaces, coupling business logic to a third-party library.

Instead, `config/services.yaml` aliases League's interfaces to internal adapter implementations:

```yaml
# OAuth League Interface Aliases (Anti-Corruption Layer)
League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface:
    alias: App\Auth\Infrastructure\Adapter\OAuth\AccessTokenRepository
League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface:
    alias: App\Auth\Infrastructure\Adapter\OAuth\RefreshTokenRepository
```

`ResourceServerFactory` builds the `ResourceServer` with `DpopAwareBearerTokenValidator`, which also checks the token's audience. `OAuth2Authenticator` (API requests) and `WsQueryTokenAuthenticator` (WebSocket query tokens) call it. The revocation endpoint uses the two aliased repositories. The domain layer only knows about `App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface` — never the League class.

## Communication Between Contexts

Contexts communicate through:
- **Symfony Messenger** — asynchronous commands between contexts (e.g., Library → Metadata for enrichment)
- **Domain events** — for side effects that don't need immediate handling
- **Shared kernel** — common types like `Uuid` and `PublicId` provide a shared vocabulary without coupling
