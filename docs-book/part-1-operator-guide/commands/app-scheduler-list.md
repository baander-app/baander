# app:scheduler:list

List every scheduled job with its cron expression, type, command, status and last and next run. The command shows what the admin **Scheduler** page lists and what `GET /api/admin/scheduler/jobs` returns in the admin API.

## Quick start

```bash
make exec cmd="php bin/console app:scheduler:list"
```

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print the jobs as the admin API returns them, as a JSON array on stdout |

## Details

The command prints a table with one row per job:

| Column | Content |
|--------|---------|
| ID | Job UUID, which the other `app:scheduler:*` commands take |
| Name | Human-readable job name |
| Expression | Cron expression |
| Type | `messenger` or `console` |
| Command | The command the job runs |
| Status | `active`, `paused` or `disabled` |
| Last Run | Last execution time (`Y-m-d H:i`), or `-` if never |
| Next Run | Next scheduled run time (`Y-m-d H:i`), or `-` if not scheduled |

With `--json` the command prints every field of every job, including the description, parameters, run count and last error. If no jobs exist, the table form prints `No scheduled jobs found.` and the JSON form prints `[]`.

The command is read-only. [app:scheduler:show](app-scheduler-show.md) shows one job in full.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | List printed, possibly empty |
