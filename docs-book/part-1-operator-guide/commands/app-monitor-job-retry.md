# app:monitor:job:retry

Dispatch a failed background job's stored message again, under a new job ID. The command does what the **Retry** action of the admin **Job Monitor** does through `POST /api/monitor/jobs/{jobId}/retry`, with the same checks.

## Quick start

```bash
make exec cmd="php bin/console app:monitor:job:retry <jobId>"
```

Print the result as JSON, for a script:

```bash
make exec cmd="php bin/console app:monitor:job:retry <jobId> --json"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `jobId` | Yes | The failed job's ID, as `app:monitor:jobs --status=failed` lists it |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print only the result, as the admin API's `data` payload, in JSON |

## Details

The message goes to the transport the job was received from. A job that a console command ran inline has no transport, so its message goes through its normal routing. The new job appears in [app:monitor:jobs](app-monitor-jobs.md) once a worker starts it; the command prints its ID.

A job is retried at most once. The original job is marked as retried, and its audit log records the new job ID, the time and the actor `cli`. A retry from the admin panel records the admin's email address instead.

The command refuses a job that has not failed, one that was already retried, and one whose message was not stored or cannot be read. When two retries of the same job run at once, only one dispatches the message. If the dispatch itself fails, the job stays retriable.

With `--json` the command prints only `{"newJobId": "..."}`, the API's `data` object with the ID of the dispatched job.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The message was dispatched again |
| 1 | No job has this ID, the job cannot be retried, or the dispatch failed; the message says which |
