# app:scheduler:enable

Enable a disabled scheduled job, so it runs on its schedule again. The command does what **Enable** on the admin **Scheduler** page and `POST /api/admin/scheduler/jobs/{id}/enable` do.

## Quick start

```bash
make exec cmd="php bin/console app:scheduler:enable 0192a3b4-c5d6-7890-abcd-ef1234567890"
```

Print the result as JSON, for a script:

```bash
make exec cmd="php bin/console app:scheduler:enable 0192a3b4-c5d6-7890-abcd-ef1234567890 --json"
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

Enabling a disabled job sets its status to `active` and computes its next run from now. A job that is not disabled is already enabled: enabling an active or a paused job succeeds without changing it, and a paused job stays paused until it is resumed with [app:scheduler:resume](app-scheduler-resume.md).

With `--json` the command prints only the job as the API's `data` object, the same fields [app:scheduler:show](app-scheduler-show.md) `--json` prints, with its new `status`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Job enabled, or it was not disabled |
| 1 | No scheduled job has this UUID, or the job changed concurrently; the message says why |
| 2 | The argument is not a UUID |
