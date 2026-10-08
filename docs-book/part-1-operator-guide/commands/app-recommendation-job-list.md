# app:recommendation:job:list

List the most recent recommendation jobs, newest first. It is the shell counterpart of the job list on the admin Recommendations page, `GET /api/admin/recommendations/jobs`. Jobs started from the web, the scheduler and the console all appear.

## Quick start

```bash
make exec cmd="php bin/console app:recommendation:job:list"
```

Only the failed jobs:

```bash
make exec cmd="php bin/console app:recommendation:job:list --status failed"
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--status` | all | Only jobs with this status: `pending`, `in_progress`, `completed`, `failed` or `cancelled` |
| `--limit` | `20` | Jobs to list. Values outside 1-100 are moved to the nearest bound, as the API does |
| `--json` | | Print the API's `data` array instead of a table |

## Details

The table has one row per job:

| Column | Content |
|--------|---------|
| Public ID | The ID the other `app:recommendation:job:*` commands take |
| Status | `pending`, `in_progress`, `completed`, `failed` or `cancelled` |
| Mode | `full` or `incremental` |
| Strategy | The strategy the job is running or ran last, or `-` |
| Created | Creation time in ISO 8601 format |
| Completed | When the job completed, failed or was cancelled, or `-` |
| Fail reason | Why the job failed, or `-` |

When no job matches, the command prints `No recommendation jobs are recorded.`

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | List printed, possibly empty |
| 1 | The jobs could not be read; the message says why |
| 2 | Unknown `--status`, or a `--limit` that is not an integer |
