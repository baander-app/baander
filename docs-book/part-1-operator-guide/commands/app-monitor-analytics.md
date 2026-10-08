# app:monitor:analytics

Show background job analytics for a time range: a summary, timing statistics or failure statistics. The command shows what `GET /api/monitor/analytics/summary`, `/timing` and `/failures` return in the admin API and what the analytics section of the admin **Job Monitor** displays.

## Quick start

The summary of the last 24 hours:

```bash
make exec cmd="php bin/console app:monitor:analytics"
```

Failures on 15 March 2026, UTC:

```bash
make exec cmd="php bin/console app:monitor:analytics --section=failures --from=2026-03-15T00:00:00Z --to=2026-03-16T00:00:00Z"
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--section` | `summary` | `summary`, `timing` or `failures` |
| `--from` | 24 hours ago | Inclusive start of the job creation range, as an RFC 3339 timestamp with a timezone and at most six fractional digits |
| `--to` | now | Exclusive end of the range, in the same format; must be after `--from` |
| `--limit` | `50` | Recent failures to list with `--section=failures`; values outside 1-200 are clamped |
| `--json` | off | Print the `data` payload of the matching API endpoint as JSON instead of tables |

## Details

The range covers jobs created from `--from` up to, but not including, `--to`. A range longer than 90 days ends 90 days after `--from`. The command prints the range it used.

- **summary** prints the success rate (finished jobs out of finished, failed and cancelled ones), completed jobs per hour, job counts by status and job counts by type.
- **timing** prints the average, median and 95th percentile run time of finished jobs per type, and the average time between a job's creation and its start.
- **failures** prints how many failed jobs were retried, the ten most failing job types, the ten most frequent error classes and the most recent failures.

With `--json` the output is the API's `data` object for that section.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Analytics printed |
| 1 | The job monitor could not be read; the message says why |
| 2 | `--section` is unknown, `--limit` is not an integer, `--from` or `--to` is not a valid timestamp, or `--to` is not after `--from` |
