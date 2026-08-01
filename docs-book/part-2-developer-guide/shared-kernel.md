# Shared Kernel

`src/Shared/` contains cross-cutting infrastructure used by all bounded contexts. It follows the same four-layer structure as other contexts but exists to provide shared primitives rather than model a business domain.

## Domain Models

| Model | Purpose | Location |
|-------|---------|----------|
| `Uuid` | UUID v7 — used as all primary keys | `Domain/Model/Uuid.php` |
| `PublicId` | Public-facing identifier, separate from internal UUID | `Domain/Model/PublicId.php` |
| `Email` | Typed email value object with validation | `Domain/Model/Email.php` |
| `Cursor` | Cursor-based pagination token | `Domain/Model/Cursor.php` |
| `CursorDirection` | Pagination direction (`asc` / `desc`) | `Domain/Model/CursorDirection.php` |
| `CursorPage` | A page of results with cursor metadata | `Domain/Model/CursorPage.php` |
| `SearchOptions` | Search query parameters | `Domain/Model/SearchOptions.php` |
| `SearchResult` | Search result with relevance data | `Domain/Model/SearchResult.php` |
| `JobStatus` | Async job status enum | `Domain/Model/JobStatus.php` |

### UUID v7

All primary keys use UUID v7 (time-ordered). Never use auto-incrementing integers. The `Uuid` class generates v7 UUIDs by default:

```php
$id = new Uuid();           // generates v7
$id = new Uuid($existing);  // from existing string
$id = Uuid::fromString($existing);
```

### PublicId

Each entity has both an internal `Uuid` and a public-facing `PublicId`. The public ID is what appears in API responses and URLs. This separation allows internal IDs to change without breaking external references.

### Cursor Pagination

Baander uses cursor-based pagination instead of offset-based. Cursors are opaque tokens that encode the position in the result set, providing consistent results even when data changes between requests.

## Search

PGroonga powers full-text search across the codebase. The `PgroongaSearchTrait` provides a reusable implementation for Doctrine repositories:

```php
final class AlbumRepository implements AlbumRepositoryInterface
{
    use PgroongaSearchTrait;
}
```

Repositories that support search implement the `Searchable` domain interface from `Domain/Repository/Searchable.php`.

## Swoole Async

### Async Sleep

Use `App\Shared\Infrastructure\Swoole\Async::sleep()` for all sleeping. It auto-detects coroutine context and routes to `Swoole\Coroutine::sleep()` or `usleep()` accordingly:

```php
use App\Shared\Infrastructure\Swoole\Async;

Async::sleep(1.0); // works in both coroutine and non-coroutine context
```

Never call `sleep()`, `usleep()`, or `Swoole\Coroutine::sleep()` directly.

### Process Pool

`Swoole\ProcessPool` provides isolated worker processes for CPU-bound work (like FFmpeg transcoding). Workers communicate via Unix sockets (`SWOOLE_IPC_UNIXSOCK`).

### WebSocket

- `WebSocketConnectionRegistry` — tracks active WebSocket connections
- `WebSocketPusher` — pushes messages to connected clients

## Job Monitoring

Async jobs dispatched via Symfony Messenger are tracked through:

- `JobIdStamp` — attached to messages to assign a unique job ID
- `JobMonitoringMiddleware` — records job start, completion, and failure
- `JobMonitorService` — queries job status and history

See the [Real-Time Patterns](real-time-patterns.md) page for SSE and WebSocket details.

## Redis

- **Caching** — tag-aware cache pools via `RedisTagAwareAdapter`. The `noeviction` policy is required for tag invalidation to work correctly.
- **SSE Pub/Sub** — server-sent events are delivered via Redis Pub/Sub channels
- **Session storage** — user sessions stored in Redis

## Controllers

The Shared kernel provides several shared controllers:

| Controller | Path | Purpose |
|-----------|------|---------|
| `HealthCheckController` | `/health`, `/ready`, `/live` | Health and readiness probes |
| `ServerStatsController` | `/api/debug/stats` | Server diagnostics |
| `JobMonitorController` | `/api/monitor/*` | Job status and management |
| `PrometheusMetricsController` | `/metrics` | Prometheus metrics endpoint |
| `SseController` | `/sse` | Server-sent events endpoint |
| `WebSocketController` | `/ws` | WebSocket endpoint |

## DTOs

Shared response types:

- `ApiError` — standard error response
- `ValidationError` — validation failure details
- `PaginatedResponse` — offset-based pagination wrapper
- `CursorPaginatedResponse` — cursor-based pagination wrapper
