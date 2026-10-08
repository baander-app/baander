# app:scheduler:delete

Delete a scheduled job. The command does what **Delete** on the admin **Scheduler** page and `DELETE /api/admin/scheduler/jobs/{id}` do.

## Quick start

```bash
make exec cmd="php bin/console app:scheduler:delete 0192a3b4-c5d6-7890-abcd-ef1234567890 --force"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `id` | Yes | UUID of the scheduled job, as [app:scheduler:list](app-scheduler-list.md) prints it |

## Options

| Option | Description |
|--------|-------------|
| `--force` | Delete without asking; required when no terminal is attached |

## Details

Deletion cannot be undone. On a terminal the command names the job and asks before deleting it. Without a terminal, as in scripts and `make exec` runs without a TTY, it refuses unless `--force` is given. To stop a job without losing its definition, use [app:scheduler:disable](app-scheduler-disable.md) instead.

If the job changed while it was being deleted, the delete is refused with `Scheduled job changed. Reload it and try again.`, the conflict the API reports as 409.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Job deleted |
| 1 | No scheduled job has this UUID, the operator declined, or the job changed concurrently; the message says why |
| 2 | The argument is not a UUID, or no terminal is attached and `--force` was not given |
