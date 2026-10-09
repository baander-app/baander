# app:scheduler:disable

Disable a scheduled job, so it stops running on its schedule until it is enabled. The command does what **Disable** on the admin **Scheduler** page and `POST /api/admin/scheduler/jobs/{id}/disable` do.

## Quick start

```bash
make exec cmd="php bin/console app:scheduler:disable 0192a3b4-c5d6-7890-abcd-ef1234567890"
```

Print the result as JSON, for a script:

```bash
make exec cmd="php bin/console app:scheduler:disable 0192a3b4-c5d6-7890-abcd-ef1234567890 --json"
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

Disabling an active or a paused job sets its status to `disabled`. Disabling a job that is already disabled succeeds without changing it. A disabled job keeps its definition and can be enabled again with [app:scheduler:enable](app-scheduler-enable.md); it cannot be paused or resumed while disabled.

With `--json` the command prints only the job as the API's `data` object, the same fields [app:scheduler:show](app-scheduler-show.md) `--json` prints, with its new `status`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Job disabled, or it was already disabled |
| 1 | No scheduled job has this UUID, or the job changed concurrently; the message says why |
| 2 | The argument is not a UUID |
