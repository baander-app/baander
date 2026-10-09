# app:monitor:job:show

Show one background job: its status, attempts, timing, error and stored message. The command shows what `GET /api/monitor/jobs/{jobId}` returns in the admin API and what the job panel of the admin **Job Monitor** displays.

## Quick start

```bash
make exec cmd="php bin/console app:monitor:job:show <jobId>"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `jobId` | Yes | The job ID, as [app:monitor:jobs](app-monitor-jobs.md) lists it |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--json` | off | Print the `data` payload of `GET /api/monitor/jobs/{jobId}` as JSON instead of a list |

## Details

The command prints the job's type, queue, status, progress, attempt number, whether it was retried, whether it can be cancelled with [app:monitor:job:cancel](app-monitor-job-cancel.md), its creation, start and finish times, and its run time in seconds. For a failed job it also prints the error class and message. **Message** is the stored message that [app:monitor:job:retry](app-monitor-job-retry.md) dispatches again; it reads `(too large to store)` when the message was too large to keep.

The run time is that of the current attempt. A job that has not finished has none.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Job printed |
| 1 | No job has this ID, or the job monitor could not be read; the message says which |
