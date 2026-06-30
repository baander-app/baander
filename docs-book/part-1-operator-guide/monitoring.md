# Monitoring and Observability

Baander provides several built-in endpoints and commands for monitoring system health, background jobs, and performance metrics. This page covers what is available and how to use it.

## Health Checks

Baander exposes three HTTP endpoints for health probing. None of these require authentication.

| Endpoint | Purpose | Healthy status | Unhealthy status |
|----------|---------|---------------|-----------------|
| `GET /health` | Full system health (PostgreSQL, Redis, Swoole, memory) | `200` with `"status": "healthy"` | `503` with `"status": "unhealthy"` |
| `GET /ready` | Dependency readiness (PostgreSQL, Redis, memory) | `200` with `"status": "ready"` | `503` with `"status": "not_ready"` |
| `GET /live` | Process liveness (Swoole worker alive) | `200` with `"status": "alive"` | N/A (always 200 if responding) |

These are designed for orchestration use. The `/health` endpoint is used by the Docker healthcheck defined in `docker-compose.yml`:

```yaml
healthcheck:
    test: ["CMD", "php", "bin/console", "app:health:check"]
    interval: 30s
    timeout: 5s
    retries: 3
    start_period: 15s
```

### CLI health check

You can also run the health check from the command line inside the container. It prints a table with per-component status and latency:

```bash
make exec cmd="php bin/console app:health:check"
```

This runs the same checks as the `/health` endpoint and exits with code 0 on success or 1 if any component is unhealthy. See the [full command reference](commands/app-health-check.md).

### What is checked

The health check verifies four components:

| Component | What it checks |
|-----------|---------------|
| PostgreSQL | Connection to the database via `SELECT 1` |
| Redis | `PING` command and database size |
| Swoole | Whether the Swoole extension is loaded and the worker process is running |
| Memory | Current usage, peak usage, and real memory allocation |

## Job Monitoring

Background jobs (library scans, transcoding, notifications) are tracked by a job monitoring system. All job endpoints require authentication with `ROLE_ADMIN`.

### Status overview

Get a quick summary of all jobs grouped by status, plus a list of currently running jobs:

```bash
curl -s -H "Authorization: Bearer $TOKEN" https://baander.test/api/monitor/status | jq .
```

The response includes `counts` (jobs per status) and `running` (active jobs with progress).

### Job list with filtering

Browse jobs with filtering, sorting, and cursor-based pagination:

```bash
# Failed jobs, sorted by creation date
curl -s -H "Authorization: Bearer $TOKEN" \
  "https://baander.test/api/monitor/jobs?status=failed&sort=createdAt&direction=desc&limit=20" | jq .

# Scan jobs
curl -s -H "Authorization: Bearer $TOKEN" \
  "https://baander.test/api/monitor/jobs?name=scan" | jq .
```

Available filters: `status` (`queued`, `running`, `finished`, `failed`, `cancelled`), `name` (partial match), `queue` (exact match).

### Job detail

Get the full record for a specific job, including exception details for failures:

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  "https://baander.test/api/monitor/jobs/<jobId>" | jq .
```

### Retry and cancel

You can retry failed jobs or cancel running/queued jobs through the API:

```bash
# Retry a failed job
curl -X POST -s -H "Authorization: Bearer $TOKEN" \
  "https://baander.test/api/monitor/jobs/<jobId>/retry" | jq .

# Cancel a running or queued job
curl -X POST -s -H "Authorization: Bearer $TOKEN" \
  "https://baander.test/api/monitor/jobs/<jobId>/cancel" | jq .
```

Cancellation is cooperative -- the job handler must check for the cancellation flag at its next checkpoint. Queued jobs are flagged before the worker picks them up.

## Job Analytics

The analytics endpoints provide aggregate insights into job performance over a time range. All require `ROLE_ADMIN`.

### Summary

Status counts, job type breakdown, success rate, and throughput:

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  "https://baander.test/api/monitor/analytics/summary?from=2026-04-20T00:00:00Z&to=2026-04-21T00:00:00Z" | jq .
```

Defaults to the last 24 hours if `from` and `to` are omitted. Maximum range is 90 days.

### Timing

Average, median, and P95 execution times per job type, plus average queue latency:

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  "https://baander.test/api/monitor/analytics/timing" | jq .
```

### Failures

Top failing job types, top exception classes, retry frequency, and recent failure details:

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  "https://baander.test/api/monitor/analytics/failures?limit=20" | jq .
```

| Endpoint | Description |
|----------|-------------|
| `GET /api/monitor/analytics/summary` | Status counts, success rate, throughput per hour |
| `GET /api/monitor/analytics/timing` | Execution time stats and queue latency per job type |
| `GET /api/monitor/analytics/failures` | Top failures, exception classes, retry stats |

## Server Stats

The server stats endpoint returns a snapshot of the current worker process state. Requires `ROLE_ADMIN`.

```bash
curl -s -H "Authorization: Bearer $TOKEN" https://baander.test/api/debug/stats | jq .
```

The response includes:

| Section | Contents |
|---------|----------|
| `memory` | Current usage, peak, real usage, real peak (all in MB) |
| `process` | PID, UID, GID, user, uptime |
| `swoole` | Swoole VM status (coroutine count, connections, request count) |
| `pools` | Swoole connection pool stats (active, free, limit per connection) |
| `doctrine` | Identity map size, scheduled inserts/updates/deletes, EM open status |
| `redis` | Connected status, ping result, DB size, client count, memory usage |
| `sse` | Active SSE connection count |

Use this for lightweight debugging of memory leaks, connection exhaustion, or hangs without setting up external monitoring.

## Prometheus Metrics

Baander exposes a Prometheus-compatible metrics endpoint at `GET /metrics`. No authentication is required.

```bash
curl -s https://baander.test/metrics
```

The endpoint returns metrics in Prometheus text exposition format (`text/plain; version=0.0.4`). Available metrics:

| Metric | Type | Description |
|--------|------|-------------|
| `swoole_up` | gauge | Whether the Swoole worker is running (1 = up) |
| `swoole_coroutine_num` | gauge | Current number of active coroutines |
| `swoole_request_count` | gauge | Total request count |
| `swoole_connection_num` | gauge | Current connection count |
| `swoole_worker_num` | gauge | Number of worker processes |
| `swoole_pool_active{connection="N"}` | gauge | Active connections in pool N |
| `swoole_pool_free{connection="N"}` | gauge | Free connections in pool N |
| `swoole_pool_limit{connection="N"}` | gauge | Connection limit for pool N |

### Prometheus scrape configuration

Point your Prometheus scrape config to the `/metrics` endpoint:

```yaml
scrape_configs:
  - job_name: 'baander'
    scrape_interval: 15s
    static_configs:
      - targets: ['baander-app:9501']
    metrics_path: '/metrics'
```

Grafana dashboard setup is out of scope for this guide.

## Rate Limiter Monitor

Inspect and manage configured rate limiters. Requires `ROLE_ADMIN`.

### List all rate limiters

```bash
curl -s -H "Authorization: Bearer $TOKEN" https://baander.test/api/monitor/rate-limiters | jq .
```

This returns the full catalog of configured rate limiters with their policy, limit, interval, and description. It reads from a static catalog that must be kept in sync with `config/packages/framework.yaml`.

### Clear rate limiter state

To unblock rate-limited users (for example, after a configuration change or during an incident), clear the shared rate limiter cache pool:

```bash
curl -X DELETE -s -H "Authorization: Bearer $TOKEN" \
  "https://baander.test/api/monitor/rate-limiters/auth_login_ip/clear?confirm=true" | jq .
```

Since all rate limiters share the same Redis-backed cache pool, this clears state for every limiter, not just the named one.

## Configuration Check

Validate your application configuration from the CLI or the API.

### CLI

```bash
make exec cmd="php bin/console app:config:validate"
```

This runs all configuration checks and prints a table of results. See the [full command reference](commands/app-config-validate.md).

### API

```bash
curl -s -H "Authorization: Bearer $TOKEN" https://baander.test/api/debug/config-check | jq .
```

The endpoint validates environment variables, key file existence, secret strength, and framework config. It returns a summary with `errors`, `warnings`, and `passed` counts. Rate-limited to prevent abuse.

### What is checked

| Check | Severity when failing |
|-------|----------------------|
| Required env vars (`DATABASE_URL`, `REDIS_URL`, `APP_SECRET`, `APP_URL`, `APP_DOMAIN`) | Error |
| Production-only vars (`REDIS_PASSWORD`, `OAUTH_ENCRYPTION_KEY`) | Error (prod only) |
| `DATABASE_URL` and `REDIS_URL` format | Error |
| `APP_URL` uses HTTPS | Error (prod only) |
| `APP_SECRET` is not the default placeholder | Error (prod only) |
| OAuth key file existence | Warning (dev) / Error (prod) |
| OAuth encryption key validity | Error (prod only) |
| VAPID key pair consistency | Warning |
| External API key lengths | Warning |

## Logs

### Container names

| Container | Service |
|-----------|---------|
| `baander-app` | PHP/Symfony application (Swoole) |
| `baander-nginx` | Nginx reverse proxy |
| `baander-postgres` | PostgreSQL database |
| `baander-redis` | Redis (cache, messenger, rate limiting) |

### Makefile commands

The Makefile provides several targets for viewing logs:

| Command | Description |
|---------|-------------|
| `make logs` | Follow app container logs (`docker logs -f baander-app`) |
| `make logs-nginx` | Follow nginx container logs |
| `make hl-logs` | Show last 1 hour of app logs, syntax-highlighted |
| `make hl-logs-f` | Follow app logs, syntax-highlighted |
| `make hl-logs-nginx` | Show last 1 hour of nginx logs, syntax-highlighted |
| `make hl-logs-nginx-f` | Follow nginx logs, syntax-highlighted |
| `make hl-logs-all` | Show last 1 hour of all container logs, syntax-highlighted |
| `make hl-logs-all-f` | Follow all container logs, syntax-highlighted |
| `make hl-logs-error` | Show error-level app logs only |
| `make hl-logs-warn` | Show warnings and above from app logs |

The `hl-*` commands pipe through `./hl` for colorized output. If `hl` is not installed, use the plain `docker logs` commands directly.

### Direct Docker commands

```bash
# Follow app logs
docker logs -f baander-app

# Last 100 lines of postgres logs
docker logs --tail 100 baander-postgres

# Redis logs since a specific time
docker logs --since 2026-04-21T10:00:00 baander-redis

# All containers at once
docker compose logs -f
```

### Log locations inside containers

| Container | Log path |
|-----------|----------|
| `baander-app` | stdout/stderr (captured by Docker), Swoole access logs to stdout |
| `baander-nginx` | stdout/stderr (captured by Docker), access log at `/var/log/nginx/access.log` |
| `baander-postgres` | stdout/stderr (captured by Docker), also at `/var/lib/postgresql/data/log/` |
| `baander-redis` | stdout/stderr (captured by Docker) |

To view nginx access logs inside the container:

```bash
make exec cmd="tail -100 /var/log/nginx/access.log"
```

## Quick Reference

| Command / Endpoint | Description |
|---------------------|-------------|
| `GET /health` | System health check (no auth) |
| `GET /ready` | Readiness probe (no auth) |
| `GET /live` | Liveness probe (no auth) |
| `GET /metrics` | Prometheus metrics (no auth) |
| `GET /api/debug/stats` | Server diagnostics (admin) |
| `GET /api/debug/config-check` | Configuration validation (admin) |
| `GET /api/monitor/status` | Job status summary (admin) |
| `GET /api/monitor/jobs` | Job list with filtering (admin) |
| `GET /api/monitor/jobs/{jobId}` | Job detail (admin) |
| `POST /api/monitor/jobs/{jobId}/retry` | Retry a failed job (admin) |
| `POST /api/monitor/jobs/{jobId}/cancel` | Cancel a job (admin) |
| `GET /api/monitor/analytics/summary` | Job analytics summary (admin) |
| `GET /api/monitor/analytics/timing` | Job timing analytics (admin) |
| `GET /api/monitor/analytics/failures` | Job failure analytics (admin) |
| `GET /api/monitor/rate-limiters` | Rate limiter catalog (admin) |
| `DELETE /api/monitor/rate-limiters/{name}/clear?confirm=true` | Clear rate limiter state (admin) |
| `make exec cmd="php bin/console app:health:check"` | CLI health check |
| `make exec cmd="php bin/console app:config:validate"` | CLI configuration validation |
| `make logs` | Follow app container logs |
| `make hl-logs` | Highlighted app logs (last hour) |
| `make info` | PHP, Symfony, and Composer version info |

## See Also

- [CLI Reference: app:health:check](commands/app-health-check.md)
- [CLI Reference: app:config:validate](commands/app-config-validate.md)
- [Troubleshooting](troubleshooting.md)
