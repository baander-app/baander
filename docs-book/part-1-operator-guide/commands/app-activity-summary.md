# app:activity:summary

Show how much was played over a range of days: the number of plays, distinct tracks and artists, and the total listening time. The command shows what `GET /api/admin/activity/summary` returns in the admin API, which the summary row of the admin activity page reads.

## Quick start

```bash
make exec cmd="php bin/console app:activity:summary"
```

For March 2026:

```bash
make exec cmd="php bin/console app:activity:summary --from 2026-03-01 --to 2026-03-31"
```

## Options

| Option | Description |
|--------|-------------|
| `--from` | First day counted, `Y-m-d`, from its start in the server time zone; defaults to 30 days before now |
| `--to` | Last day counted, `Y-m-d`, up to the start of the next day; must not precede `--from`; defaults to today |
| `--json` | Print the numbers exactly as the API's `data` object, in JSON |

## Details

Plays count by the activity's last play: an activity last played inside the range counts with all its plays. Both days are included, so `--from 2026-03-10 --to 2026-03-10` covers 10 March from midnight up to, but not including, midnight on 11 March. The command prints the range it read above the table.

| Metric | Content |
|--------|---------|
| Total plays | The plays of the activities in the range |
| Unique tracks | The distinct tracks among them |
| Unique artists | The distinct artists among them |
| Listening time (seconds) | Each track's length times its plays, summed |

With no activity in the range, every number is 0.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Numbers printed, possibly all 0 |
| 1 | The activity could not be read; the message says why |
| 2 | A date is not a valid `Y-m-d` day, or `--to` precedes `--from` |
