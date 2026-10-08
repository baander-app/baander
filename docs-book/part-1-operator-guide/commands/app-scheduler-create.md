# app:scheduler:create

Create a scheduled job that runs a schedulable command on a cron schedule. The command does what the **Create Job** dialog on the admin **Scheduler** page and `POST /api/admin/scheduler/jobs` do, with the same checks.

## Quick start

```bash
make exec cmd="php bin/console app:scheduler:create --name='Nightly cache sweep' --expression='0 3 * * *' --type=console --command=app:transcode:cache-sweep --parameters='{\"ttl-hours\": 24}'"
```

## Options

| Option | Required | Description |
|--------|----------|-------------|
| `--name` | Yes | Job name, at most 255 characters |
| `--expression` | Yes | Cron expression, such as `0 3 * * *` |
| `--type` | Yes | `messenger` or `console` |
| `--command` | Yes | The command to run, as [app:scheduler:commands](app-scheduler-commands.md) lists it under its type |
| `--description` | No | Free-text description |
| `--parameters` | No | The command's parameters as a JSON object, such as `'{"ttl-hours": 24}'`; defaults to `{}` |

## Details

A new job starts active, and its next run is computed from the expression.

The input is checked in two steps, as in the admin API. First the fields themselves: a missing name, expression, type or command, a name over 255 characters, an expression that is not a valid cron expression, or a type other than `messenger` or `console` is rejected with `Validation failed.` and one line per field, such as `expression: Invalid cron expression: ...`. These are the message and the per-field messages of the API's 422 response. Then the command and its parameters: the command must be registered as schedulable under the given type, every parameter must be in the command's schema with the schema's type (`int`, `string`, `bool`, `float` or `array`), and every required parameter must be present. A failure names the problem, such as `Invalid parameter "ttl-hours": expected int, got string.` Nothing is stored when the input is rejected.

On success the command prints the new job with its ID.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Job created |
| 1 | The job could not be stored; the message says why |
| 2 | The input was rejected, or `--parameters` is not a JSON object; the message says why |
