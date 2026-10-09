# app:scheduler:update

Change a scheduled job's name, cron expression, type, command, description or parameters. The command does what the **Edit** action on the admin **Scheduler** page and `PUT /api/admin/scheduler/jobs/{id}` do, with the same checks.

## Quick start

```bash
make exec cmd="php bin/console app:scheduler:update 0192a3b4-c5d6-7890-abcd-ef1234567890 --expression='30 4 * * *'"
```

Print the result as JSON, for a script:

```bash
make exec cmd="php bin/console app:scheduler:update 0192a3b4-c5d6-7890-abcd-ef1234567890 --expression='0 4 * * *' --json"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `id` | Yes | UUID of the scheduled job, as [app:scheduler:list](app-scheduler-list.md) prints it |

## Options

Every option is optional. Like the edit dialog, the command starts from the job's current values, and each option given replaces one of them.

| Option | Description |
|--------|-------------|
| `--name` | Job name, at most 255 characters |
| `--expression` | Cron expression, such as `0 3 * * *` |
| `--type` | `messenger` or `console` |
| `--command` | The command to run, as [app:scheduler:commands](app-scheduler-commands.md) lists it under its type |
| `--description` | Free-text description; an empty value (`--description=`) removes it |
| `--parameters` | The command's parameters as a JSON object; replaces all current parameters |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print only the result, as the admin API's `data` payload, in JSON |

## Details

The resulting job is checked as [app:scheduler:create](app-scheduler-create.md#details) describes, so rejected input gets the admin API's 422 message. When you change the command, give the parameters its schema expects; the old ones are kept otherwise and may not fit. Changing the expression recomputes the next run. The status does not change; use the pause, resume, enable and disable commands for that.

If the job changed between reading and saving, for example because a run finished or someone edited it in the admin panel, the update is refused with `Scheduled job changed. Reload it and try again.`, the conflict the API reports as 409. Nothing is changed; run the command again.

On success the command prints the updated job.

With `--json` the command prints only the updated job as the API's `data` object, the same fields [app:scheduler:show](app-scheduler-show.md) `--json` prints.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Job updated |
| 1 | No scheduled job has this UUID, the job changed concurrently, or it could not be stored; the message says why |
| 2 | The argument is not a UUID, the input was rejected, or `--parameters` is not a JSON object |
