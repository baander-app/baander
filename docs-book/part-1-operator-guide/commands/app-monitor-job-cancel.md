# app:monitor:job:cancel

Request cooperative cancellation of a background job. The command does what the **Cancel** action of the admin **Job Monitor** does through `POST /api/monitor/jobs/{jobId}/cancel`, with the same checks.

## Quick start

```bash
make exec cmd="php bin/console app:monitor:job:cancel <jobId>"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `jobId` | Yes | The job's ID, as [app:monitor:jobs](app-monitor-jobs.md) lists it |

## Details

The command sets the job's cancellation flag in Redis for one hour. A job handler that checks for cancellation stops at its next checkpoint. Cancellation does not interrupt a handler that never checks, and it does not change the job's status in the monitor.

Cancelling a job that has finished or failed is refused. Cancelling a job again sets the flag again and succeeds.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Cancellation was requested |
| 1 | No job has this ID, the job has finished or failed, or Redis could not be reached; the message says which |
