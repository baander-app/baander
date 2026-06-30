# Shared Kernel

`src/Shared/` is not a bounded context. It provides cross-cutting infrastructure used by all 15 bounded contexts. It follows the same four-layer structure (Domain, Application, Infrastructure, Interface) but contains no business domain logic of its own.

When you need a primitive type, async utility, or shared controller, look here first before creating something new in your context.

## Domain Models

### Value Objects

| Model | Purpose |
|-------|---------|
| `Uuid` | UUID v7 — used as all primary keys throughout the system |
| `PublicId` | Public-facing opaque identifier, separate from internal UUID |
| `Email` | Typed email value object with validation |

### Pagination

| Model | Purpose |
|-------|---------|
| `Cursor` | Opaque cursor-based pagination token |
| `CursorDirection` | Pagination direction (`asc` or `desc`) |
| `CursorPage` | A page of results with cursor metadata (has next/previous cursors) |

### Search

| Model | Purpose |
|-------|---------|
| `SearchOptions` | Encapsulates search query parameters |
| `SearchResult` | A single search result with relevance data |

### Events

| Model | Purpose |
|-------|---------|
| `AbstractDomainEvent` | Base class for all domain events across contexts |
| `DomainEventInterface` | Contract that all domain events must implement |

### Jobs

| Model | Purpose |
|-------|---------|
| `JobStatus` | Enum representing async job statuses (pending, running, completed, failed) |

## Repository Interfaces

| Interface | Purpose |
|-----------|---------|
| `Searchable` | Contract for repositories that support full-text search. Implementations use `PgroongaSearchTrait` |

## Swoole Runtime

### Async Sleep

Use `App\Shared\Infrastructure\Swoole\Async::sleep()` for all sleeping. It auto-detects coroutine context and routes to `Swoole\Coroutine::sleep()` or `usleep()` accordingly. Never call `sleep()`, `usleep()`, or `Swoole\Coroutine::sleep()` directly.

### Process Pool

`Swoole\ProcessPool` provides isolated worker processes for CPU-bound work. Workers communicate via Unix sockets (`SWOOLE_IPC_UNIXSOCK`). The Transcode context uses this for FFmpeg encoding.

### WebSocket

| Component | Purpose |
|-----------|---------|
| `WebSocketConnectionRegistry` | Tracks active WebSocket connections by user |
| `WebSocketPusher` | Pushes messages to connected clients |

## Job Monitoring

Async jobs dispatched via Symfony Messenger are tracked through three components:

| Component | Purpose |
|-----------|---------|
| `JobIdStamp` | Middleware stamp that assigns a unique job ID to dispatched messages |
| `JobMonitoringMiddleware` | Records job lifecycle events (start, completion, failure) |
| `JobMonitorService` | Queries job status and history |

See the [CQRS and Messaging](../cqrs-and-messaging.md) page for dispatching patterns.

## Messenger

| Component | Purpose |
|-----------|---------|
| `SwooleTaskDispatcherInterface` | Contract for dispatching tasks to Swoole workers |
| `HttpServerTaskDispatcher` | Implementation that dispatches via Swoole HTTP server task workers |

## Doctrine

| Component | Purpose |
|-----------|---------|
| `UuidType` | Custom Doctrine column type for `Uuid` |
| `PublicIdType` | Custom Doctrine column type for `PublicId` |
| `GeneratePublicIdListener` | Doctrine event listener that auto-generates `PublicId` values on persist |

## Caching

Redis-backed tag-aware cache pools via `RedisTagAwareAdapter`. The `noeviction` policy is required for tag invalidation to work correctly.

## Redis

Connection management and configuration for all Redis-backed features: caching, Messenger transport, SSE Pub/Sub, and session storage.

## Server-Sent Events

SSE delivery uses Redis Pub/Sub channels. When a domain event is published, an event listener pushes it to a Redis channel. The SSE controller subscribes to that channel and streams events to connected clients.

See the [Real-Time Patterns](../real-time-patterns.md) page for the full SSE and WebSocket architecture.

## Security

| Component | Purpose |
|-----------|---------|
| `SseQueryTokenAuthenticator` | Authenticates SSE connections via query string token |
| `WsQueryTokenAuthenticator` | Authenticates WebSocket connections via query string token |

## Logging

| Component | Purpose |
|-----------|---------|
| `BoundedContextLogger` | Creates loggers scoped to a bounded context |
| `CorrelationIdProcessor` | Monolog processor that attaches a correlation ID to log entries |

## Controllers

| Controller | Path | Purpose |
|-----------|------|---------|
| `HealthCheckController` | `/health`, `/ready`, `/live` | Health and readiness probes |
| `ServerStatsController` | `/api/stats` | Server diagnostics and statistics |
| `JobMonitorController` | `/api/jobs` | Job status and management |
| `JobAnalyticsController` | `/api/jobs/analytics` | Job analytics and metrics |
| `PrometheusMetricsController` | `/api/metrics` | Prometheus metrics endpoint |
| `TransportController` | `/api/transport` | Messenger transport status |
| `ConfigCheckController` | `/api/config/check` | Configuration validation |
| `RateLimiterMonitorController` | `/api/rate-limiter` | Rate limiter status and statistics |
| `SpaController` | `/` | Single-page application entry point (catch-all) |
| `SseController` | `/api/sse` | Server-sent events endpoint |
| `NotificationSseController` | `/api/sse/notifications` | Notification-specific SSE stream |
| `WebSocketController` | `/ws` | WebSocket connection endpoint |

## DTOs

Shared response types used across contexts:

| DTO | Purpose |
|-----|---------|
| `ApiError` | Standard error response format |
| `OAuthError` | OAuth-specific error response |
| `ValidationError` | Validation failure details |
| `PaginatedResponse` | Offset-based pagination wrapper |
| `CursorPaginatedResponse` | Cursor-based pagination wrapper |

## Cross-Context Relationships

| Direction | Context | Details |
|-----------|---------|---------|
| Depends on | — | Nothing — Shared is the foundation |
| Depended on by | All 15 bounded contexts | Types, infrastructure, and shared controllers |

## See Also

- [Shared Kernel (detailed)](../shared-kernel.md) — UUID v7 usage, cursor pagination, and Redis configuration
- [Real-Time Patterns](../real-time-patterns.md) — SSE and WebSocket architecture
- [CQRS and Messaging](../cqrs-and-messaging.md) — Job monitoring and task dispatching
