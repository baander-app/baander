# Requirements: Pooled Redis Connection Manager

## Problem

`RedisClientFactory::create()` returns a bare `Redis` instance with no lifecycle enforcement. Callers must manually call `close()`. In long-running Swoole workers (SSE controllers), connections are created in loops and error paths that skip `close()` lead to stale connections accumulating. There are ~15 call sites with inconsistent cleanup discipline.

## Goals

- Eliminate manual `->close()` calls from all application code.
- Provide a coroutine-aware connection pool that reuses connections across Swoole coroutines on the same worker.
- Offer two complementary APIs: `borrow()` for short-lived ops and `checkout()`/`release()` for long-lived ops.
- Guarantee cleanup even when callers forget to release (orphan reclamation).
- Maintain pool health via ping checks, idle eviction, and worker shutdown hooks.

## Non-goals

- Cross-worker pooling (each worker gets its own pool instance).
- Redis Sentinel or Redis Cluster support (current factory is single-node).
- Async/non-blocking Redis client replacement (continues using the PHP `Redis` extension).
- Retry logic or circuit breaking on Redis errors.

## Approach options

### A) Callback-based pool only (`borrow`)

Only expose `borrow(callable)`. No `checkout()` API. Long-lived callers nest a single `borrow()` around their entire lifecycle.

**Pros:** Simpler — one API, zero leak surface.  
**Cons:** Forces SSE loops and pub/sub into awkward long-running callbacks. Deep nesting. No way to hold a connection across multiple coroutine yields.

### B) Full pool with both APIs (recommended)

`borrow(callable)` for short ops + `checkout()`/`release()` for long-lived. Same underlying pool. Orphan reclamation for `checkout()` safety.

**Pros:** Fits both usage patterns naturally. Leak-proof for short ops, safe fallback for long ops.  
**Cons:** Two APIs to learn. Slightly more implementation complexity.

### C) Destruct-based auto-close proxy

Return a wrapper whose `__destruct()` calls `close()`. No pooling.

**Pros:** Minimal change.  
**Cons:** Swoole GC is unpredictable — destructors don't run promptly. No connection reuse. Doesn't solve the stale connection problem in practice.

## Recommended direction

**Option B — full pool with both APIs.** It covers every call site in the codebase naturally. Short-lived cache/nonce operations get the impossible-to-leak `borrow()` API. SSE controllers and pub/sub get `checkout()`/`release()` with orphan reclamation as a safety net.

### Architecture

**`RedisClientFactory` becomes a connection pool** backed by two collections: idle connections and checked-out connections (keyed by coroutine ID).

#### `borrow(callable $fn): mixed`
- Checks out a connection (or creates one if pool empty and under max).
- Executes `$fn($redis)`.
- Returns connection to idle pool in `finally`.
- All current single-op call sites migrate here.

#### `checkout(): ManagedRedisConnection`
- Returns a `ManagedRedisConnection` wrapping `Redis`.
- `ManagedRedisConnection` delegates all Redis methods via `__call()`.
- Tracks the coroutine ID that checked it out.
- `release()` returns the underlying `Redis` to the idle pool.

#### Pool internals
- **Coroutine-aware:** Track checked-out connections by `Swoole\Coroutine::getCid()`.
- **Max size:** Configurable cap (default 10 per worker). Throws `RedisPoolExhaustedException` when exhausted.
- **Idle eviction:** Connections idle > N seconds are closed and removed.
- **Health check:** `ping()` before handing out an idle connection; discard and create new if stale.
- **Orphan reclamation:** On every `checkout()`/`borrow()`, scan for checked-out connections whose coroutine ID no longer exists and reclaim them.
- **Worker shutdown:** `dispose()` closes all connections (idle + checked-out).

### Failure modes

| Scenario | Behavior |
|---|---|
| Pool exhausted (max reached, none idle) | Throw `RedisPoolExhaustedException` |
| Connection dies while checked out | Discard on `release()`, pool creates fresh next time |
| Coroutine exits without `release()` | Orphan reclaimed on next pool interaction |
| Worker shutdown with active checkouts | `dispose()` force-closes all connections |
| `ping()` fails on idle connection | Discard, try next idle or create new |

### Migration plan

1. Rewrite `RedisClientFactory` with pool internals; make `create()` private.
2. Create `ManagedRedisConnection` value object.
3. Migrate short-lived callers to `borrow()`: `DpopNonceManager`, `RedisDpopJtiCache`, `HealthCheckService`, `JobMonitorController`, `TransportController`.
4. Migrate long-lived callers to `checkout()`: `SseController`, `NotificationSseController`, `ServerStatsControllerWithCoroutines`, `RedisPubSubConnection`.
5. Deprecate `create()`.

## Success criteria

- Zero manual `->close()` calls remain in application code.
- All existing tests pass without assertion changes.
- Pool metrics (active/idle/total) are observable for debugging.
- No stale Redis connections accumulate under load in SSE workers.
