# Shared Kernel

`src/Shared/` contains cross-cutting infrastructure used by all bounded contexts. It follows the same four-layer structure as other contexts but exists to provide shared primitives rather than model a business domain.

## Domain Models

| Model | Purpose | Location |
|-------|---------|----------|
| `Uuid` | UUID value object; generates v7 by default | `Domain/Model/Uuid.php` |
| `PublicId` | 21-character NanoID, distinct from UUID identity | `Domain/Model/PublicId.php` |
| `Email` | Typed email value object with validation | `Domain/Model/Email.php` |
| `Cursor` | Cursor-based pagination token | `Domain/Model/Cursor.php` |
| `CursorDirection` | Pagination direction (`asc` / `desc`) | `Domain/Model/CursorDirection.php` |
| `CursorPage` | A page of results with cursor metadata | `Domain/Model/CursorPage.php` |
| `SearchOptions` | Search query parameters | `Domain/Model/SearchOptions.php` |
| `SearchResult` | Search result with relevance data | `Domain/Model/SearchResult.php` |
| `JobStatus` | Async job status enum | `Domain/Model/JobStatus.php` |

### UUID v7

The `Uuid` class generates time-ordered UUID v7 values by default for aggregate identity and also accepts existing UUIDs of other versions. Database keys are not uniformly UUIDs: internal integer keys, text settings keys and protocol identifiers have separate contracts. The default generation and parsing methods are:

```php
$id = new Uuid();           // generates v7
$id = new Uuid($existing);  // from existing string
$id = Uuid::fromString($existing);
```

### PublicId

`PublicId` generates and validates 21-character NanoIDs matching `^[0-9a-zA-Z_-]{21}$`, for example `V1StGXR8_Z5jdHi6B-myT`. Many aggregates expose one alongside their UUID identity. Resources and routes may instead expose UUIDs, integer identifiers or protocol-specific identifiers; consult the endpoint contract. A parameter named `publicId` is not enough to establish its type: passkey deletion uses a UUID.

### Cursor Pagination

Baander supports cursor-based and offset-based pagination; the endpoint defines which it accepts. Cursors are opaque tokens that encode the position in the result set, providing consistent results even when data changes between requests.

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

`App\Shared\Infrastructure\Swoole\Async::sleep()` auto-detects coroutine context and routes to `Swoole\Coroutine::sleep()` or `usleep()` accordingly. Application and Interface code must not depend on Shared Infrastructure, so they inject `App\Shared\Application\Port\SleeperInterface`, which `AsyncSleeper` implements with `Async::sleep()`:

```php
use App\Shared\Application\Port\SleeperInterface;

public function __construct(private readonly SleeperInterface $sleeper) {}

$this->sleeper->sleep(1.0); // works in both coroutine and non-coroutine context
```

Infrastructure code may call `Async::sleep()` directly.

Never call `sleep()`, `usleep()`, or `Swoole\Coroutine::sleep()` directly.

### Process Pool

`Swoole\ProcessPool` provides isolated worker processes for CPU-bound work (like FFmpeg transcoding). Workers communicate via Unix sockets (`SWOOLE_IPC_UNIXSOCK`).

### WebSocket

- `WebSocketConnectionRegistry` — tracks active WebSocket connections
- `WebSocketPusher` — pushes messages to connected clients

## Job Monitoring

Async jobs dispatched via Symfony Messenger are tracked through:

- `JobIdStamp` — attached to messages to assign a unique job ID
- `JobMonitoringMiddleware` — assigns each dispatched message its job ID (`JobIdStamp`)
- `JobCancellationMiddleware` — runs each received job as the current job of its coroutine and marks a job that stopped at a cancellation checkpoint `cancelled`, without a retry
- `JobCancellationCheckpointInterface` — Application port that handlers call between items; `check()` throws `JobCancelledException` once the job was cancelled
- `JobMonitorService` — queries job status and history

See the [Real-Time Patterns](real-time-patterns.md) page for WebSocket details.

## Redis

- **Caching** — tag-aware cache pools via `RedisTagAwareAdapter`. The `noeviction` policy is required for tag invalidation to work correctly.
- **Session storage** — user sessions stored in Redis

## Controllers

The Shared kernel provides several shared controllers:

| Controller | Path | Purpose |
|-----------|------|---------|
| `HealthCheckController` | `/health`, `/ready`, `/live` | Health and readiness probes |
| `ServerStatsControllerWithCoroutines` | `/api/debug/stats` | Server diagnostics |
| `JobMonitorController` | `/api/monitor/*` | Job status and management |
| `PrometheusMetricsController` | `/metrics` | Prometheus metrics endpoint |
| `WebSocketController` | `/ws` | WebSocket endpoint |

The health probes, `/metrics` and `/ws` sit outside the `/api` routes covered by the default OpenAPI export. `/metrics` serves Prometheus text rather than an API resource envelope.

## DTOs

Shared response types:

- `ApiError` — standard error response
- `ValidationError` — validation failure details
- `PaginatedResponse` — offset-based pagination wrapper
- `CursorPaginatedResponse` — cursor-based pagination wrapper
