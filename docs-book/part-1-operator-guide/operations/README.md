# Monitoring and Troubleshooting

When the web interface responds but a library stops changing, check the background
path as well as the web server. A successful web probe does not establish that a
scan, outbox relay or consumer is making progress.

Run the commands below on the installation host, from its checkout, with the `dc`
function from [Installation](../getting-started/installation.md#command-context).
`dc exec -T web` runs a command inside the deployment's web container; it does not
enter the worker pair. For HTTP probes, set `BAANDER_URL` to the origin clients use,
the configuration's `origin`, with no trailing slash. For an HTTPS origin, the client
must trust the certificate of the TLS proxy in front of the gateway.

| Guide | Scope |
|-------|-------|
| [Health Checks, Metrics, and Logs](health-and-metrics.md) | HTTP `/health`, `/ready`, and `/live` probes, `app:health:check`, Swoole server stats, Prometheus metrics, rate limiter inspection, configuration validation, and container logs |
| [Job and Transport Monitoring](job-monitoring.md) | Job monitor status, filtering, cooperative cancellation, retry vs failure-transport redelivery, Redis transport depth, failed messages, and job analytics |
| [Investigating Logs](log-viewer.md) | Source discovery, literal search, archived files, live follow and read-only SQL in the admin dashboard and terminal |
| [Troubleshooting](troubleshooting.md) | Symptom-by-symptom diagnostics for scans, transcoding, database/Redis connectivity, permissions, and authentication |

| Component or stage | First signal | What it establishes |
|--------------------|--------------|---------------------|
| Gateway and web server | `/live`, `dc logs gateway web` | The request reaches a responding process; read `draining` too |
| PostgreSQL and Redis | `/ready`, `app:health:check` | Dependency connectivity at the time of the check |
| Web worker memory and pools | `app:server:stats` (pools with `--json`), health memory row | Current web process pressure; the CLI health process measures its own memory |
| Admitted worker deployment | Host controller `readiness` action | Current readiness observation of the exact admitted deployment |
| Redis consumer | Messenger heartbeat and job progress | Recent consumer activity; neither proves outbox progress |
| Outbox relay | Worker readiness observation and relay logs | Relay state; follow an actual queued operation to establish delivery |
| Queued and failed work | Job monitor and transport status | Job records and transport counts, with the limits below |
| Registry and relay service | [Registry operations](../administration/registry.md) | Separate service health and recovery evidence |

See [Background Work](../workers/background-work.md) for the path from a request through the
outbox and Redis to a consumer, and [Troubleshooting](troubleshooting.md) for
symptom-led checks.

## Quick Reference

| Command / Endpoint | Description |
|---------------------|-------------|
| `GET /health` | System health check, each component's status only (no auth) |
| `GET /ready` | Readiness probe (no auth) |
| `GET /live` | Liveness probe (no auth) |
| `GET /metrics` | Prometheus metrics on the web server's port `9501` (no auth); the shipped gateways answer `404` |
| `GET /api/debug/health` | Full health report with latency and details (admin) |
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
| `dc exec -T web php bin/console app:monitor:transport` | CLI transport queue depths and consumer state |
| `dc exec -T web php bin/console app:failed-message:list` | CLI failed message list |
| `dc exec web php bin/console app:failed-message:flush` | CLI removal of all failed messages, after a terminal confirmation |
| `GET /api/monitor/analytics/summary` | Job analytics summary (admin) |
| `GET /api/monitor/analytics/timing` | Job timing analytics (admin) |
| `GET /api/monitor/analytics/failures` | Job failure analytics (admin) |
| `GET /api/monitor/rate-limiters` | Rate limiter catalog (admin) |
| `DELETE /api/monitor/rate-limiters/clear?confirm=true` | Clear every rate limiter (super admin) |
| `DELETE /api/monitor/rate-limiters/{name}/clear?confirm=true` | Clear rate limiter state (super admin) |
| `dc exec -T web php bin/console app:health:check` | CLI health check |
| `dc exec -T web php bin/console app:config:validate` | CLI configuration validation |
| `dc logs -f web` | Follow this installation's web logs |
| `dc logs --since=1h web` | Recent web logs |
| `dc ps` | This installation's Compose service status; the worker pair is not a Compose service |

## See Also

- [CLI Reference: app:health:check](../commands/app-health-check.md)
- [CLI Reference: app:config:validate](../commands/app-config-validate.md)
- [CLI Reference: app:monitor:transport](../commands/app-monitor-transport.md)
- [CLI Reference: app:failed-message:list](../commands/app-failed-message-list.md)
- [CLI Reference: app:failed-message:flush](../commands/app-failed-message-flush.md)
- [Background Work](../workers/background-work.md)
- [Troubleshooting](troubleshooting.md)
