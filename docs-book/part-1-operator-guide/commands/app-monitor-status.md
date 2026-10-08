# app:monitor:status

Show how many background jobs the job monitor holds in each status, and which jobs are running now. The command shows what `GET /api/monitor/status` returns in the admin API and what the status overview of the admin **Job Monitor** displays.

## Quick start

```bash
make exec cmd="php bin/console app:monitor:status"
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--json` | off | Print the `data` payload of `GET /api/monitor/status` as JSON instead of tables |

## Details

The command prints two tables. **Jobs by status** has one row per status that has jobs: `queued`, `running`, `finished`, `failed` or `cancelled`. **Running** has one row per running job, longest-running first:

| Column | Content |
|--------|---------|
| Job ID | The ID that [app:monitor:job:show](app-monitor-job-show.md) takes |
| Type | The message's class name, such as `ScanLibraryCommand` |
| Queue | The transport the job was received from, or `-` for a job run inline by a console command |
| Started | Start time of the current attempt in ISO 8601 format |
| Progress | Percent done, or `-` when the job reports none |

With `--json` the output is an object with `counts` (job counts keyed by status) and `running` (the running jobs with `jobId`, `name`, `queue`, `startedAt` and `progress`).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Status printed |
| 1 | The job monitor could not be read; the message says why |
