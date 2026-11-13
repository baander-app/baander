# Job and Transport Monitoring

Track asynchronous library scans, metadata enrichment, transcoding, and notification jobs through the job monitor, inspect Redis stream and failure-transport depths, and query historical job analytics.

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

Cancellation is cooperative. The job stops at its next checkpoint, between two items of its work, and its status becomes `cancelled`; it is not retried and does not go to the failure transport. The metadata sync jobs, the bulk lyrics fetch and the cover extraction have checkpoints; other jobs run to the end. [app:monitor:job:cancel](../commands/app-monitor-job-cancel.md) lists the jobs and their checkpoints.

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
[app:monitor:transport](../commands/app-monitor-transport.md).

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
| `GET /api/monitor/transport/failed` | [`app:failed-message:list`](../commands/app-failed-message-list.md) |
| `GET /api/monitor/transport/failed/{id}` | `messenger:failed:show <id>` |
| `POST /api/monitor/transport/failed/{id}/retry` | `messenger:failed:retry <id> --force` |
| `DELETE /api/monitor/transport/failed/{id}` | `messenger:failed:remove <id> --force` |
| `POST /api/monitor/transport/failed/flush?confirm=true` | [`app:failed-message:flush`](../commands/app-failed-message-flush.md) |

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

