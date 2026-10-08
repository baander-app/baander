# app:monitor:jobs

List background jobs from the job monitor, with the same filters, sorting and cursor pages as `GET /api/monitor/jobs` in the admin API and the job list of the admin **Job Monitor**.

## Quick start

The five most recent failed jobs:

```bash
make exec cmd="php bin/console app:monitor:jobs --status=failed --limit=5"
```

The next page, with the cursor the previous page printed:

```bash
make exec cmd="php bin/console app:monitor:jobs --status=failed --limit=5 --cursor=<cursor>"
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--status` | all | Only jobs with this status: `queued`, `running`, `finished`, `failed` or `cancelled` |
| `--type` | all | Only jobs whose type name contains this text, such as `Scan`; the API's `name` filter |
| `--queue` | all | Only jobs received from this transport, by exact name, such as `async` |
| `--sort` | `createdAt` | Sort by `createdAt`, `startedAt`, `finishedAt` or `duration` |
| `--direction` | `desc` | `asc` or `desc` |
| `--limit` | `50` | Jobs per page; values outside 1-200 are clamped |
| `--cursor` | none | Continue from the cursor a previous page printed |
| `--json` | off | Print the `data` payload of `GET /api/monitor/jobs` as JSON instead of a table |

## Details

The command prints one row per job:

| Column | Content |
|--------|---------|
| Job ID | The ID that [app:monitor:job:show](app-monitor-job-show.md) takes |
| Type | The message's class name |
| Queue | The transport the job was received from, or `-` for a job run inline by a console command |
| Status | The job's status, followed by `(retried)` once it has been retried |
| Attempt | Deliveries started so far |
| Created | Creation time in ISO 8601 format |
| Finished | Finish time of the current attempt, or `-` |

When more jobs follow, the command ends with `More jobs follow. Continue with --cursor=<cursor>`. Run it again with the same filters and that cursor to get the next page. An unreadable cursor starts at the first page, as the API does. An unknown `--sort` or `--direction` falls back to the default, also as the API does.

With `--json` the output is an object with `items`, `next_cursor`, `has_next_page` and `per_page`, exactly as the API returns it.

Console commands that run long work inline record each run as a job without a queue, so such runs appear in this list too.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | List printed, possibly empty |
| 1 | The job monitor could not be read; the message says why |
| 2 | `--status` is not a known status, or `--limit` is not an integer |
