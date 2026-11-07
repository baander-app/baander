# Monitoring and Troubleshooting

When the web interface responds but a library stops changing, check the background
path as well as the web server. A successful web probe does not establish that a
scan, outbox relay or consumer is making progress.

Run the commands below on the installed Docker host, from the installation checkout,
with the `dc` shell function and private environment files from
[Getting Started](../getting-started/installation.md#installation). `dc exec -T app` runs a command
inside that installation's web container; it does not enter the worker deployment.
For HTTP probes, set `BAANDER_URL` to the HTTPS origin clients use, with no trailing
slash. The client must trust its certificate.

| Guide | Scope |
|-------|-------|
| [Health Checks, Metrics, and Logs](health-and-metrics.md) | HTTP `/health`, `/ready`, and `/live` probes, `app:health:check`, Swoole server stats, Prometheus metrics, rate limiter inspection, configuration validation, and container logs |
| [Job and Transport Monitoring](job-monitoring.md) | Job monitor status, filtering, cooperative cancellation, retry vs failure-transport redelivery, Redis transport depth, failed messages, and job analytics |
| [Troubleshooting](troubleshooting.md) | Symptom-by-symptom diagnostics for scans, transcoding, database/Redis connectivity, permissions, and authentication |

| Component or stage | First signal | What it establishes |
|--------------------|--------------|---------------------|
| Reverse proxy and web server | `/live`, `dc logs nginx app` | The request reaches a responding process; read `draining` too |
| PostgreSQL and Redis | `/ready`, `app:health:check` | Dependency connectivity at the time of the check |
| Web worker memory and pools | `app:server:stats`, health memory row | Current web process pressure; the CLI health process measures its own memory |
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

- [CLI Reference: app:health:check](../commands/app-health-check.md)
- [CLI Reference: app:config:validate](../commands/app-config-validate.md)
- [CLI Reference: app:monitor:transport](../commands/app-monitor-transport.md)
- [CLI Reference: app:failed-message:list](../commands/app-failed-message-list.md)
- [CLI Reference: app:failed-message:flush](../commands/app-failed-message-flush.md)
- [Background Work](../workers/background-work.md)
- [Troubleshooting](troubleshooting.md)
