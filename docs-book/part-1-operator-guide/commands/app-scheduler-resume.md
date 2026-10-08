# app:scheduler:resume

Resume a paused scheduled job, so it runs on its schedule again. The command does what **Resume** on the admin **Scheduler** page and `POST /api/admin/scheduler/jobs/{id}/resume` do.

## Quick start

```bash
make exec cmd="php bin/console app:scheduler:resume 0192a3b4-c5d6-7890-abcd-ef1234567890"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `id` | Yes | UUID of the scheduled job, as [app:scheduler:list](app-scheduler-list.md) prints it |

## Details

Resuming a paused job sets its status to `active` and computes its next run from now. Resuming a job that is already active succeeds without changing it. A disabled job cannot be resumed: the command fails with `A disabled job cannot be resumed. Enable it first.`, the conflict the API reports as 409. Use [app:scheduler:enable](app-scheduler-enable.md) for a disabled job.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Job resumed, or it was already active |
| 1 | No scheduled job has this UUID, the job is disabled, or it changed concurrently; the message says why |
| 2 | The argument is not a UUID |
