# app:monitor:prune

Delete the job monitor records of finished, failed and cancelled jobs older than a given number of days, so that the monitor table does not grow without bound. The command does what `POST /api/monitor/prune` does in the admin API, with the same rules.

## Quick start

Count the records older than the default 7 days, without deleting them:

```bash
make exec cmd="php bin/console app:monitor:prune --dry-run"
```

Delete them. The command does not ask first, and a deleted record cannot be restored:

```bash
make exec cmd="php bin/console app:monitor:prune"
```

Print the result as JSON, for a script:

```bash
make exec cmd="php bin/console app:monitor:prune --days=30 --json"
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--days` (`-d`) | `7` | Prune jobs older than this many days; a whole number from 1 to 36500 (one hundred years) |
| `--dry-run` | off | Show how many jobs would be pruned without deleting |
| `--json` | off | Print only the result, as the admin API's `data` payload, in JSON |

## Details

The command computes a cutoff timestamp from `--days` and deletes finished, failed, and cancelled job monitors created before that cutoff. In dry-run mode it counts the same monitors and deletes nothing. A `--days` value that is not a whole number, such as `5x`, or that lies outside 1 to 36500, is refused before anything is counted or deleted. The upper limit exists because a much larger value would overflow the cutoff into the future and delete every record.

With `--json` the command prints only `{"pruned": 42, "olderThan": "2026-09-08T10:00:00+00:00"}`, the API's `data` object with the cutoff as an ISO 8601 timestamp. With `--dry-run`, `pruned` is the number of jobs that would be removed.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Prune completed (or dry-run report shown) |
| 1 | The job monitor could not be changed; the message says why |
| 2 | `--days` is not a whole number (`--days must be an integer.`), or lies outside 1 to 36500 |

## Tips

- Run the command on a recurring schedule, such as a host cron job, to keep the monitor table bounded. It is not one of the commands [app:scheduler:create](app-scheduler-create.md) can schedule.
- Run with `--dry-run` first to see how many records a prune would delete.
- Only records of finished, failed and cancelled jobs are deleted; queued and running jobs are never pruned.
