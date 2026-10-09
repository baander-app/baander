# app:scheduler:run

Request one run of a scheduled job outside its schedule, for example to backfill data or to re-run a failed job. The command does what **Trigger Now** on the admin **Scheduler** page and `POST /api/admin/scheduler/jobs/{id}/trigger` do: it records a durable manual request, and the scheduler runs the job asynchronously.

## Quick start

```bash
make exec cmd="php bin/console app:scheduler:run 0192a3b4-c5d6-7890-abcd-ef1234567890"
```

Print the result as JSON, for a script:

```bash
make exec cmd="php bin/console app:scheduler:run 0192a3b4-c5d6-7890-abcd-ef1234567890 --json"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `id` | Yes | UUID of the scheduled job, as [app:scheduler:list](app-scheduler-list.md) prints it |

## Options

| Option | Description |
|--------|-------------|
| `--request-id=UUID` | The request's UUID; leave it out to generate one. Reuse it after an uncertain result, as the API's `Idempotency-Key` header does |
| `--json` | Print only the result, as the admin API's `data` payload, in JSON |

## Details

The command prints the request UUID before it records anything. If the command ends without a clear result, for example because the database connection dropped, run it again with `--request-id` set to that UUID: a retry returns the request that was already recorded instead of recording a second one. A request UUID that already belongs to another job fails.

The command waits only until the request is recorded, not until the job has run. The job runs with the command and parameters stored on the job when the request was recorded. A paused job can still be run this way.

With `--json` the command prints only `{"occurrenceId": "...", "jobId": "..."}`, the API's `data` object, and writes the request UUID and any error to stderr.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The request was recorded, or an earlier request with the same UUID was found |
| 1 | No scheduled job has this UUID, or the request UUID belongs to another job |
| 2 | The job ID or the request UUID is not a UUID |
