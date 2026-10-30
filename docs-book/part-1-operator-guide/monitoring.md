# Monitoring and Observability

When the web interface responds but a library stops changing, check the background
path as well as the web server. A successful web probe does not establish that a
scan, outbox relay or consumer is making progress.

Run the commands below on the installed Docker host, from the installation checkout,
with the `dc` shell function and private environment files from
[Getting Started](getting-started.md#installation). `dc exec -T app` runs a command
inside that installation's web container; it does not enter the worker deployment.
For HTTP probes, set `BAANDER_URL` to the HTTPS origin clients use, with no trailing
slash. The client must trust its certificate.

| Component or stage | First signal | What it establishes |
|--------------------|--------------|---------------------|
| Reverse proxy and web server | `/live`, `dc logs nginx app` | The request reaches a responding process; read `draining` too |
| PostgreSQL and Redis | `/ready`, `app:health:check` | Dependency connectivity at the time of the check |
| Web worker memory and pools | `app:server:stats`, health memory row | Current web process pressure; the CLI health process measures its own memory |
| Admitted worker deployment | Host controller `readiness` action | Current readiness observation of the exact admitted deployment |
| Redis consumer | Messenger heartbeat and job progress | Recent consumer activity; neither proves outbox progress |
| Outbox relay | Worker readiness observation and relay logs | Relay state; follow an actual queued operation to establish delivery |
| Queued and failed work | Job monitor and transport status | Job records and transport counts, with the limits below |
| Registry and relay service | [Registry operations](registry.md) | Separate service health and recovery evidence |

See [Background Work](background-work.md) for the path from a request through the
outbox and Redis to a consumer, and [Troubleshooting](troubleshooting.md) for
symptom-led checks.

## Health Checks

Baander exposes three HTTP endpoints for health probing. None of these require authentication.

| Endpoint | Purpose | Healthy status | Unhealthy status |
|----------|---------|---------------|-----------------|
| `GET /health` | Component results (PostgreSQL, Redis, consumer, Swoole, memory); worker and memory do not decide HTTP status | `200` with `"status": "healthy"` | `503` with `"status": "unhealthy"` |
| `GET /ready` | Dependency readiness (PostgreSQL, Redis); not ready while the web server drains | `200` with `"status": "ready"` | `503` with `"status": "not_ready"` |
| `GET /live` | Process liveness (Swoole worker alive) | `200` with `"status": "alive"` and `"draining": false` | N/A (always 200 if responding; `"draining": true` while the web server drains) |

`/health` lists the background worker and memory, but neither decides the response code: a worker outage or a web server worker near its memory limit does not turn `/health` into `503`, and `/ready` checks neither. The web server [alerts administrators](notifications.md#health-alerts) about both instead.

While the web server [drains before it stops](upgrading.md#stopping-and-restarting-the-web-server), `/ready` answers `503` without checking any dependency. Its `checks` list holds a single `server` entry whose `details` are `{"draining": true}`. The server accepts no new connections once the drain starts, so only a client that holds a kept-alive connection from before the stop sees this answer, and the answer carries `Connection: close`. `/live` still answers `200` during the drain, so liveness probes do not restart the container, and its body says `"draining": true`. The web player reads that answer as "server not back yet".

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
[full command reference](commands/app-health-check.md).

### What is checked

The health check verifies five components:

| Component | What it checks |
|-----------|---------------|
| PostgreSQL | Connection to the database via `SELECT 1` |
| Redis | `PING` command and database size |
| Messenger (background worker) | The age of the heartbeat the worker writes to Redis. Unhealthy after 45 seconds without a renewal while idle or 90 seconds while handling a message, and when the heartbeat disappears. A heartbeat that says the worker stopped reads not available for 45 seconds and unhealthy after that; the 45 seconds give a restarted worker time to report in. Not available until a heartbeat has been seen, and while Redis is unreachable. |
| Swoole | Swoole VM statistics when running inside a Swoole worker; not available outside one or without the extension |
| Memory | The real memory use of each HTTP worker of the web server against PHP's `memory_limit`, which the production image sets to [512M](configuration.md#php-memory-limit). Each worker reports every 5 seconds, and the check reads the worker using the most. Unhealthy at 90 percent of the limit, about 461 MiB at 512M; not available when the limit is `-1`. |

The admitted worker deployment writes consumer heartbeats to Redis, where the
web container reads them. An idle consumer renews about every 10 seconds; while
handling a job, it renews every 30 seconds through the keepalive alarm. Messenger
health details include `lastDeliveryQueueAgeSeconds` and `lastDeliveryAt`: these
describe the last dequeued message, not the oldest message still waiting.

These health checks cover the Redis consumer, but do not check the outbox
relay. A fresh heartbeat can coexist with undelivered outbox work. Use the host
controller's separate `readiness` action and inspect progress and logs as described
in [Observe background work](background-work.md#observe-background-work).
Controller `status` returns `readiness: "not_checked"`; it is an ownership and state
observation, not a readiness test. See [app:outbox:consume](commands/app-outbox-consume.md) for relay behavior
and [Worker deployment](commands/README.md#worker-deployment) for ownership,
drain and explicit recovery procedures. Restarting web leaves background work
with the worker deployment.

The web server runs these checks every 60 seconds and alerts administrators once per outage; see [Health alerts](notifications.md#health-alerts). A planned stop or restart of the web server sends no alert: once the server drains, its checks stop. An outage of a checked component while the server is not draining still alerts.

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
[Sessions, logout and a Redis outage](security.md#sessions-logout-and-a-redis-outage).

The Redis health check alerts once for the outage. The alert is delivered at once
because it is a row in PostgreSQL; the background worker check reads not available
meanwhile. The server logs the store failure, not every `503` it answers.

## Job Monitoring

The job monitor records background work such as library scans, transcoding and
notifications. All job endpoints require authentication with `ROLE_ADMIN`.
API clients must send both the access token with the `DPoP` scheme and a fresh
`DPoP` proof signed by the key bound to that token. A token alone is insufficient;
the `Bearer` scheme is refused for a bound token. Use the authenticated admin
interface or a client implementing that protocol for the API routes listed here.
The shell examples use console counterparts and require host access to the
installation.

### Status overview

Get a quick summary of all jobs grouped by status, plus a list of currently running jobs:

```bash
dc exec -T app php bin/console app:monitor:status
```

The console prints status counts and running jobs with progress. The API response
uses `counts` and `running`; add `--json` to the console command for structured
output.

### Job list with filtering

Browse jobs with filtering, sorting, and cursor-based pagination:

```bash
# Failed jobs, newest first
dc exec -T app php bin/console app:monitor:jobs --status=failed --sort=createdAt --direction=desc --limit=20

# Jobs whose type name contains scan
dc exec -T app php bin/console app:monitor:jobs --type=scan
```

The API filters are `status` (`queued`, `running`, `finished`, `failed`, `cancelled`),
`name` (partial match) and `queue` (exact match). The console uses `--type` for
the API's `name` filter; `--cursor` continues a previous page.

### Job detail

Get the full record, including exception details for a failed job. Replace
`JOB_ID` with the job ID from the list:

```bash
dc exec -T app php bin/console app:monitor:job:show JOB_ID
```

A job keeps one record and one job ID across its deliveries. An automatic retry, a retry from the failure transport, and a redelivery after a worker stopped mid-job all run the same job again: the record returns to `running` with a new start time, loses the previous attempt's finish time, progress and error, and its `attempt` number increases. The record keeps its creation time and the queue it was first received from. `duration` covers the current attempt only.

### Retry and cancel

You can retry failed jobs or cancel running jobs through the API. Retrying a job here dispatches a copy of its stored payload as a new job, returned as `newJobId`, and sets the original record's `retried` flag while leaving its status failed.
After fixing the cause, choose the failed job deliberately before retrying:

```bash
# Dispatch the failed job again under a new job ID
dc exec -T app php bin/console app:monitor:job:retry JOB_ID

# Request cancellation of the selected job
dc exec -T app php bin/console app:monitor:job:cancel JOB_ID
```

Cancellation is cooperative. The job stops at its next checkpoint, between two items of its work, and its status becomes `cancelled`; it is not retried and does not go to the failure transport. The metadata sync jobs, the bulk lyrics fetch and the cover extraction have checkpoints; other jobs run to the end. [app:monitor:job:cancel](commands/app-monitor-job-cancel.md) lists the jobs and their checkpoints.

A finished bulk lyrics fetch can still be cancelled while the fetches it queued are waiting: they are skipped when they come due, and the job's status changes from `finished` to `cancelled`. The job detail's `cancellable` field says whether cancelling a job can still change anything. Cancelling a failed job, or a finished job with no queued work waiting, answers `409`.

### Transport status

The admin **Transport Health** card shows the number of entries in the async Redis stream, the number of messages in the failure transport, and whether the consumer this container is configured with is registered on the stream. The same figures come from the API and the console:

```bash
dc exec -T app php bin/console app:monitor:transport
```

The async count is the Redis stream length. `consumerRunning` means that the
consumer name configured in the probing container is registered on the stream; a
stale registration can remain after a process stops, and the web container's name
can differ from the admitted consumer's. Compare heartbeat, controller readiness
and changing job progress before concluding that work is processing. This check
also excludes the outbox. See
[app:monitor:transport](commands/app-monitor-transport.md).

### Failed messages

A message that exhausts its retries moves to the failure transport, the `failed_messages` PostgreSQL table. The endpoints below list and manage it. Each one has a console counterpart that reads the same table, including messages waiting out a retry delay.

```bash
dc exec -T app php bin/console app:failed-message:list --page=1 --limit=50
```

Use the ID from that list to inspect the stored message before deciding on a retry:

```bash
dc exec -T app php bin/console messenger:failed:show MESSAGE_ID
```

A job-monitor retry dispatches a new job; a failure-transport retry handles the
stored message again. Choose one recovery path after diagnosing the cause so you
do not deliberately submit both. A failure-transport retry executes the work and
can repeat external effects:

```bash
dc exec -T app php bin/console messenger:failed:retry MESSAGE_ID --force
```

Removal and flush discard the stored work. Use them only when that loss is the
intended action; neither repairs its cause. The table keeps their references for
that deliberate decision.

| Endpoint | Console counterpart |
|----------|---------------------|
| `GET /api/monitor/transport/failed` | [`app:failed-message:list`](commands/app-failed-message-list.md) |
| `GET /api/monitor/transport/failed/{id}` | `messenger:failed:show <id>` |
| `POST /api/monitor/transport/failed/{id}/retry` | `messenger:failed:retry <id> --force` |
| `DELETE /api/monitor/transport/failed/{id}` | `messenger:failed:remove <id> --force` |
| `POST /api/monitor/transport/failed/flush?confirm=true` | [`app:failed-message:flush`](commands/app-failed-message-flush.md) |

Retrying runs `messenger:failed:retry` in a child process, which handles the message before the request returns. A message that fails again goes back to the failure transport under a new ID with its retry count increased, including after an unrecoverable exception. It stays listed until a retry succeeds or you remove it. `GET /api/monitor/transport/status` and `app:monitor:transport` report the number of failed messages as `failedQueueDepth`.

## Job Analytics

The analytics endpoints provide aggregate insights into job performance over a time range. All require `ROLE_ADMIN`.

### Summary

Status counts, job type breakdown, success rate, and throughput:

```bash
dc exec -T app php bin/console app:monitor:analytics --section=summary --from=2026-04-24T00:00:00Z --to=2026-04-25T00:00:00Z
```

Each endpoint counts the jobs created from `from` up to but not including `to`, so the example covers exactly 24 April. Both bounds take an RFC 3339 timestamp with a timezone; `to` must be after `from`. They default to the last 24 hours, and a range longer than 90 days ends 90 days after `from`.

### Timing

Average, median, and P95 execution times per job type, plus average queue latency:

```bash
dc exec -T app php bin/console app:monitor:analytics --section=timing
```

### Failures

Top failing job types, top exception classes, retry frequency, and recent failure details:

```bash
dc exec -T app php bin/console app:monitor:analytics --section=failures --limit=20
```

| Endpoint | Description |
|----------|-------------|
| `GET /api/monitor/analytics/summary` | Status counts, success rate, throughput per hour |
| `GET /api/monitor/analytics/timing` | Execution time stats and queue latency per job type |
| `GET /api/monitor/analytics/failures` | Top failures, exception classes, retry stats |

## Server Stats

The server stats endpoint returns a snapshot of every HTTP worker of the web server. Requires `ROLE_ADMIN`. The [`app:server:stats`](commands/app-server-stats.md) command prints the same data from the web container.

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
network access to diagnostics as described in [Security](security.md#network-security).
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
[clear command's recovery limits](commands/app-rate-limiter-clear.md#details).

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

This runs all configuration checks and prints a table of results. See the [full command reference](commands/app-config-validate.md).

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
[Background Work](background-work.md#observe-background-work) to locate their logs;
web logs alone cannot explain an outbox relay failure. For Swoole failures, also
check the configured application log directory: `config/packages/swoole.yaml`
selects `swoole_<environment>.log` under the application's `var/log` directory.

Before sharing logs or diagnostic output, redact tokens, credentials, private
paths and personal data. Preserve timestamps, job IDs and the relevant error chain.

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
| `GET /api/monitor/transport/status` | Transport queue depths and consumer state (admin) |
| `GET /api/monitor/transport/failed` | Failed message list (admin) |
| `GET /api/monitor/transport/failed/{id}` | Failed message detail (admin) |
| `POST /api/monitor/transport/failed/{id}/retry` | Retry a failed message (admin) |
| `DELETE /api/monitor/transport/failed/{id}` | Remove a failed message (admin) |
| `POST /api/monitor/transport/failed/flush?confirm=true` | Remove all failed messages (admin) |
| `dc exec -T app php bin/console app:monitor:transport` | CLI transport queue depths and consumer state |
| `dc exec -T app php bin/console app:failed-message:list` | CLI failed message list |
| `dc exec -T app php bin/console app:failed-message:flush` | CLI removal of all failed messages |
| `GET /api/monitor/analytics/summary` | Job analytics summary (admin) |
| `GET /api/monitor/analytics/timing` | Job timing analytics (admin) |
| `GET /api/monitor/analytics/failures` | Job failure analytics (admin) |
| `GET /api/monitor/rate-limiters` | Rate limiter catalog (admin) |
| `DELETE /api/monitor/rate-limiters/clear?confirm=true` | Clear every rate limiter (admin) |
| `DELETE /api/monitor/rate-limiters/{name}/clear?confirm=true` | Clear rate limiter state (admin) |
| `dc exec -T app php bin/console app:health:check` | CLI health check |
| `dc exec -T app php bin/console app:config:validate` | CLI configuration validation |
| `dc logs -f app` | Follow this installation's app logs |
| `dc logs --since=1h app` | Recent app logs |
| `dc ps` | This installation's Compose service status |

## See Also

- [CLI Reference: app:health:check](commands/app-health-check.md)
- [CLI Reference: app:config:validate](commands/app-config-validate.md)
- [CLI Reference: app:monitor:transport](commands/app-monitor-transport.md)
- [CLI Reference: app:failed-message:list](commands/app-failed-message-list.md)
- [CLI Reference: app:failed-message:flush](commands/app-failed-message-flush.md)
- [Background Work](background-work.md)
- [Troubleshooting](troubleshooting.md)
