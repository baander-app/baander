# app:scheduler:list

List all scheduled jobs. Prints a table of every registered job with its cron expression, type, command, status, and last/next run times. Use this to inspect what the scheduler is configured to run.

## Quick start

```bash
make exec cmd="php bin/console app:scheduler:list"
```

## What the command prints

A table with one row per job:

| Column | Content |
|--------|---------|
| ID | Job UUID |
| Name | Human-readable job name |
| Expression | Cron expression |
| Type | Job type |
| Command | The command the job runs |
| Status | Current job status |
| Last Run | Last execution time (`Y-m-d H:i`), or `-` if never |
| Next Run | Next scheduled run time (`Y-m-d H:i`), or `-` if not scheduled |

If no jobs exist, the command prints `No scheduled jobs found.`

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Success (including the empty-list case) |

## Tips

- This command is read-only — it makes no changes and is safe to run any time.
- Use the IDs it prints as input to `app:scheduler:run` when you want to trigger a job manually.
