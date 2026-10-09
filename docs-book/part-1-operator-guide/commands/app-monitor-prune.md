# app:monitor:prune

Prune completed job monitors older than a given age. Removes finished, failed, and cancelled job monitor records to keep the monitor table from growing unbounded. The command does what `POST /api/monitor/prune` does in the admin API, with the same rules.

## Quick start

Prune monitors older than the default 7 days:

```bash
make exec cmd="php bin/console app:monitor:prune"
```

Preview what would be pruned without deleting:

```bash
make exec cmd="php bin/console app:monitor:prune --dry-run"
```

Print the result as JSON, for a script:

```bash
make exec cmd="php bin/console app:monitor:prune --days=30 --json"
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--days` (`-d`) | `7` | Prune jobs older than this many days |
| `--dry-run` | off | Show how many jobs would be pruned without deleting |
| `--json` | off | Print only the result, as the admin API's `data` payload, in JSON |

## Details

The command computes a cutoff timestamp from `--days` and deletes finished, failed, and cancelled job monitors created before that cutoff. In dry-run mode it counts the same monitors and deletes nothing.

With `--json` the command prints only `{"pruned": 42, "olderThan": "2026-09-08T10:00:00+00:00"}`, the API's `data` object with the cutoff as an ISO 8601 timestamp. With `--dry-run`, `pruned` is the number of jobs that would be removed.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Prune completed (or dry-run report shown) |
| 1 | The job monitor could not be changed; the message says why |
| 2 | `--days` was set to a value less than 1 |

## Tips

- Schedule this as a recurring cleanup to keep the monitor table bounded.
- Run with `--dry-run` first to gauge volume before deleting.
- Only terminal-state monitors (finished, failed, cancelled) are eligible — running jobs are never pruned.
