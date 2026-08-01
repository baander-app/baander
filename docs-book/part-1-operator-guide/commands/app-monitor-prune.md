# app:monitor:prune

Prune completed job monitors older than a given age. Removes finished, failed, and cancelled job monitor records to keep the monitor table from growing unbounded.

## Quick start

Prune monitors older than the default 7 days:

```bash
make exec cmd="php bin/console app:monitor:prune"
```

Preview what would be pruned without deleting:

```bash
make exec cmd="php bin/console app:monitor:prune --dry-run"
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--days` (`-d`) | `7` | Prune jobs older than this many days |
| `--dry-run` | off | Show how many jobs would be pruned without deleting |

## Details

The command computes a cutoff timestamp from `--days` and deletes finished, failed, and cancelled job monitors older than that cutoff. In dry-run mode it reports the count of completed monitors that would be eligible; it does not apply the age filter to the dry-run count.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Prune completed (or dry-run report shown) |
| 1 | `--days` was set to a value less than 1 |

## Tips

- Schedule this as a recurring cleanup to keep the monitor table bounded.
- Run with `--dry-run` first to gauge volume before deleting.
- Only terminal-state monitors (finished, failed, cancelled) are eligible — running jobs are never pruned.
