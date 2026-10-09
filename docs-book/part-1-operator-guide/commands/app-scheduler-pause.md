# app:scheduler:pause

Pause a scheduled job, so it stops running on its schedule until it is resumed. The command does what **Pause** on the admin **Scheduler** page and `POST /api/admin/scheduler/jobs/{id}/pause` do.

## Quick start

```bash
make exec cmd="php bin/console app:scheduler:pause 0192a3b4-c5d6-7890-abcd-ef1234567890"
```

Print the result as JSON, for a script:

```bash
make exec cmd="php bin/console app:scheduler:pause 0192a3b4-c5d6-7890-abcd-ef1234567890 --json"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `id` | Yes | UUID of the scheduled job, as [app:scheduler:list](app-scheduler-list.md) prints it |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print only the result, as the admin API's `data` payload, in JSON |

## Details

Pausing an active job sets its status to `paused`. Pausing a job that is already paused succeeds without changing it, so a retry is harmless. A disabled job cannot be paused: the command fails with `A disabled job cannot be paused. Enable it first.`, the conflict the API reports as 409.

Resume the job with [app:scheduler:resume](app-scheduler-resume.md).

With `--json` the command prints only the job as the API's `data` object, the same fields [app:scheduler:show](app-scheduler-show.md) `--json` prints, with its new `status`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Job paused, or it was already paused |
| 1 | No scheduled job has this UUID, the job is disabled, or it changed concurrently; the message says why |
| 2 | The argument is not a UUID |
