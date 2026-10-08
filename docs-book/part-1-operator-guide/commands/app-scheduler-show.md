# app:scheduler:show

Show one scheduled job: its schedule, command, parameters, status and the result of its last run. The command shows what `GET /api/admin/scheduler/jobs/{id}` returns in the admin API.

## Quick start

```bash
make exec cmd="php bin/console app:scheduler:show 0192a3b4-c5d6-7890-abcd-ef1234567890"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `id` | Yes | UUID of the scheduled job, as [app:scheduler:list](app-scheduler-list.md) prints it |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print the job as the admin API returns it, as a JSON object on stdout |

## Details

The command prints the job's ID, name, description, cron expression, type, command, parameters (as a JSON object), status, last and next run, last result, run count, last failure and last error, and when it was created and last updated. Times are in ISO 8601 format; a value that is not set is shown as `-`.

The command is read-only.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Job shown |
| 1 | No scheduled job has this UUID |
| 2 | The argument is not a UUID |
