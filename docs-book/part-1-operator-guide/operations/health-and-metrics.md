# Health Checks, Metrics, and Logs

Inspect component readiness, Swoole worker memory and connection pools, Prometheus metrics, rate-limiter namespaces, configuration validity, and service logs across the web stack and worker pair.

## Health Checks

Baander exposes three HTTP endpoints for health probing. None of these require authentication.

| Endpoint | Purpose | Healthy status | Unhealthy status |
|----------|---------|---------------|-----------------|
| `GET /health` | Component results (PostgreSQL, Redis, consumer, Swoole, memory); worker and memory do not decide HTTP status | `200` with `"status": "healthy"` | `503` with `"status": "unhealthy"` |
| `GET /ready` | Dependency readiness (PostgreSQL, Redis); not ready while the web server drains | `200` with `"status": "ready"` | `503` with `"status": "not_ready"` |
| `GET /live` | Process liveness (Swoole worker alive) | `200` with `"status": "alive"` and `"draining": false` | N/A (always 200 if responding; `"draining": true` while the web server drains) |

`/health` lists the background worker and memory, but neither decides the response code: a worker outage or a web server worker near its memory limit does not turn `/health` into `503`, and `/ready` checks neither. The web server [alerts administrators](../administration/notifications.md#health-alerts) about both instead.

While the web server [drains before it stops](../maintenance/upgrading.md#stopping-and-restarting-the-web-server), `/ready` answers `503` without checking any dependency. Its `checks` list holds a single `server` entry whose `details` are `{"draining": true}`. The server accepts no new connections once the drain starts, so only a client that holds a kept-alive connection from before the stop sees this answer, and the answer carries `Connection: close`. `/live` still answers `200` during the drain, so liveness probes do not restart the container, and its body says `"draining": true`. The web player reads that answer as "server not back yet".

These are designed for orchestration use. The Docker healthcheck does not call an endpoint; it runs the `app:health:check` command. The web image defines it in `Dockerfile`, and the development stack in `docker-compose.yml`:

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
dc exec -T app php bin/console app:health:check
```

This runs the same checks as `/health` and exits with code 1 if a component other
than the background worker or memory is unhealthy; otherwise it exits with code 0.
A `not_available` row does not fail the command. Outside a web worker, Swoole can
therefore read `N/A` even when the command succeeds. The memory row measures the
CLI process, not the web server's workers. FFmpeg, provider access and successful
media processing are not checked. See the
[full command reference](../commands/app-health-check.md).

### What is checked

The health check verifies five components:

| Component | What it checks |
|-----------|---------------|
| PostgreSQL | Connection to the database via `SELECT 1` |
| Redis | `PING` command and database size |
| Messenger (background worker) | The age of the heartbeat the worker writes to Redis. Unhealthy after 45 seconds without a renewal while idle or 90 seconds while handling a message, and when the heartbeat disappears. A heartbeat that says the worker stopped reads not available for 45 seconds and unhealthy after that; the 45 seconds give a restarted worker time to report in. Not available until a heartbeat has been seen, and while Redis is unreachable. |
| Swoole | Swoole VM statistics when running inside a Swoole worker; not available outside one or without the extension |
| Memory | The real memory use of each HTTP worker of the web server against PHP's `memory_limit`, which the production image sets to [512M](../configuration/deployment-profiles.md#php-memory-limit). Each worker reports every 5 seconds, and the check reads the worker using the most. Unhealthy at 90 percent of the limit, about 461 MiB at 512M; not available when the limit is `-1`. |

The admitted worker deployment writes consumer heartbeats to Redis, where the
web container reads them. An idle consumer renews about every 10 seconds; while
handling a job, it renews every 30 seconds through the keepalive alarm. Messenger
health details include `lastDeliveryQueueAgeSeconds` and `lastDeliveryAt`: these
describe the last dequeued message, not the oldest message still waiting.

These health checks cover the Redis consumer, but do not check the outbox
relay. A fresh heartbeat can coexist with undelivered outbox work. Use the host
controller's separate `readiness` action and inspect progress and logs as described
in [Observe background work](../workers/background-work.md#observe-background-work).
Controller `status` returns `readiness: "not_checked"`; it is an ownership and state
observation, not a readiness test. See [app:outbox:consume](../commands/app-outbox-consume.md) for relay behavior
and [Worker deployment](../workers/worker-deployment.md) for ownership,
drain and explicit recovery procedures. Restarting web leaves background work
with the worker deployment.

The web server runs these checks every 60 seconds and alerts administrators once per outage; see [Health alerts](../administration/notifications.md#health-alerts). A planned stop or restart of the web server sends no alert: once the server drains, its checks stop. An outage of a checked component while the server is not draining still alerts.

While Redis is unreachable, requests that require rate-limit or DPoP state answer
`503` instead of treating the failure as an empty bucket or an invalid credential.
Rate-limit failures carry `Retry-After: 1`; DPoP store failures carry
`Retry-After: 5`. Both carry `Cache-Control: no-store`. The first check that rejects
the request determines its response. Each Redis connection has one-second connect
and read timeouts. A request can make several Redis calls, so those timeouts do
not promise a one-second response for the whole request.

Clients keep their sessions and retry after the delay. Logout and token revocation
retain a narrow availability exception: they can continue when the replay store or
generic API budget is unavailable, but still require the other authentication and
proof checks. A confirmed exhausted budget still applies. See
[Sessions, logout and a Redis outage](../maintenance/security.md#sessions-logout-and-a-redis-outage).

The Redis health check alerts once for the outage. The alert is delivered at once
because it is a row in PostgreSQL; the background worker check reads not available
meanwhile. The server logs the store failure, not every `503` it answers.


## Server Stats

The server stats endpoint returns a snapshot of every HTTP worker of the web server. Requires `ROLE_ADMIN`. The [`app:server:stats`](../commands/app-server-stats.md) command prints the same data from the web container.

```bash
dc exec -T app php bin/console app:server:stats
```

The `data` object holds:

| Field | Contents |
|-------|----------|
| `workers` | One entry per worker that answered, each with `worker_id` and the groups below |
| `workers[].memory` | Current usage, peak, real usage, real peak (all in MB) |
| `workers[].process` | PID, UID, GID, user, uptime |
| `workers[].swoole` | Swoole VM status, or `null` |
| `workers[].coroutines` | Running coroutines and the peak count |
| `workers[].pools` | Swoole connection pool stats (active, free, limit per connection) |
| `missing_workers` | IDs of workers that did not answer in time |
| `worker_errors` | Workers whose figures could not be read, with the error |
| `redis` | Connected status, ping result, DB size, client count, memory usage; read once for the server |

Use this for lightweight debugging of memory leaks, connection exhaustion, or hangs without setting up external monitoring.

## Prometheus Metrics

Baander exposes a Prometheus-compatible metrics endpoint at `GET /metrics`. No authentication is required.

```bash
curl --fail --show-error --silent "$BAANDER_URL/metrics"
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

Use a scrape target reachable from the Prometheus server. The example below assumes
that Prometheus shares the application network and resolves its `app` service.
The web port is not a public metrics listener to expose separately:

```yaml
scrape_configs:
  - job_name: 'baander'
    scrape_interval: 15s
    static_configs:
      - targets: ['app:9501']
    metrics_path: '/metrics'
```

Only metrics present in the serving worker's snapshot are emitted. These web
metrics do not measure outbox delivery or establish consumer readiness. Restrict
network access to diagnostics as described in [Security](../maintenance/security.md#network-security).
Grafana dashboard setup is out of scope for this guide.

## Rate Limiter Monitor

Inspect and manage configured rate limiters. Requires `ROLE_ADMIN`.

### List all rate limiters

```bash
dc exec -T app php bin/console app:rate-limiter:list
```

This lists every limiter configured under `framework.rate_limiter` in
`config/packages/framework.yaml`, with its effective policy, limit, interval,
description and Redis storage namespace. The API field for the namespace is
`storageNamespace`.

### Clear rate limiter state

After diagnosing a rate-limit incident, clear one limiter only if resetting every
subject's budget for that limiter is the intended action:

```bash
dc exec -T app php bin/console app:rate-limiter:clear auth_login_ip
```

Each limiter has its own Redis namespace. A clear advances its generation and
resets every subject's budget without scanning keys. Old subject states expire or
are replaced on the next read; an old writer must retry against the new generation.
Other limiters keep their state. An unknown name returns `404` through the API
and fails the console command.

To clear every limiter, call `DELETE /api/monitor/rate-limiters/clear?confirm=true`,
or use the **Clear All** button on the admin Rate Limits tab. Clear-all tries each
namespace sequentially and reports failures; successful clears remain in effect.
An unavailable or unacknowledged clear reports failure instead of claiming that
clients were unblocked. A successful generation change does not repair every kind
of corrupt subject record; see the
[clear command's recovery limits](../commands/app-rate-limiter-clear.md#details).

The CLI calls the same application service. Clearing all limiters resets every
subject's budgets across them; use it only when that is the intended scope:

```bash
dc exec -T app php bin/console app:rate-limiter:clear --all
```

### Rate-limit storage outages

All 19 configured limiters use atomic Redis state while retaining their configured
budgets and fixed/sliding-window policies. A protected request whose limiter state
cannot be established receives a generic `503`, `Retry-After: 1` and
`Cache-Control: no-store`; it does not receive a fresh budget from a cache miss.
Ordinary exhaustion keeps the limiter's existing response and retry delay.

The generic API limiter alone permits logout and token revocation to continue
when its store is unavailable. It still enforces confirmed exhaustion, and the
remaining authentication and DPoP checks still run. Media exemptions and the
development bypass are unchanged. The list command reads configuration only;
a successful listing does not prove that Redis accepts consumption or clear operations.

## Configuration Check

Validate your application configuration from the CLI or the API.

### CLI

```bash
dc exec -T app php bin/console app:config:validate
```

This runs all configuration checks and prints a table of results. See the [full command reference](../commands/app-config-validate.md).

### API

`GET /api/debug/config-check` is the admin API counterpart. Use the authenticated
admin interface or a DPoP-capable client.

The endpoint validates environment variables, key file existence, secret strength, and framework config. It returns a summary with `errors`, `warnings`, and `passed` counts. Rate-limited to prevent abuse.

### What is checked

| Check | Severity when failing |
|-------|----------------------|
| Required env vars (`DATABASE_URL`, `REDIS_URL`, `APP_SECRET`, `APP_URL`, `APP_DOMAIN`) | Error |
| `REDIS_PASSWORD` is set | Error (prod only) |
| `DATABASE_URL` and `REDIS_URL` format | Error |
| `APP_URL` uses HTTPS | Error (prod only) |
| `APP_SECRET` is not the default placeholder | Error (prod only) |
| OAuth key file existence | Warning (dev) / Error (prod) |
| VAPID key pair consistency | Warning |
| External API key lengths | Warning |

## Logs

Use Compose service names in the installed `dc` context; fixed Docker container
names can point at a different checkout. These commands read logs without restarting
anything:

```bash
dc ps
dc logs --tail=100 app nginx database redis
dc logs --since=1h --tail=200 app
dc logs -f app nginx
```

Stop following with Ctrl+C. PostgreSQL in the supplied Compose stack also writes
its collected server logs to `/var/log/postgresql` inside `database`; inspect that
location if `dc logs database` shows only the collector startup:

```bash
dc exec -T database ls -lt /var/log/postgresql
```

Read the file matching the incident time after identifying it. Nginx's access log
belongs to `nginx`, not `app`:

```bash
dc exec -T nginx tail -n 100 /var/log/nginx/access.log
```

The separate worker deployment has its own control and consumer containers. Use
its exact manifest and the inspection steps in
[Background Work](../workers/background-work.md#observe-background-work) to locate their logs;
web logs alone cannot explain an outbox relay failure. For Swoole failures, also
check the configured application log directory: `config/packages/swoole.yaml`
selects `swoole_<environment>.log` under the application's `var/log` directory.

Before sharing logs or diagnostic output, redact tokens, credentials, private
paths and personal data. Preserve timestamps, job IDs and the relevant error chain.

