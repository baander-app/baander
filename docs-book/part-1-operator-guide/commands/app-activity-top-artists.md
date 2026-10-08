# app:activity:top-artists

List the most played artists over a range of days, most played first. The command shows what `GET /api/admin/activity/top-artists` returns in the admin API, which the top artists table of the admin activity page reads.

## Quick start

```bash
make exec cmd="php bin/console app:activity:top-artists"
```

The top 25 of March 2026:

```bash
make exec cmd="php bin/console app:activity:top-artists --from 2026-03-01 --to 2026-03-31 --limit 25"
```

## Options

| Option | Description |
|--------|-------------|
| `--from` | First day counted, `Y-m-d`, from its start in the server time zone; defaults to 30 days before now |
| `--to` | Last day counted, `Y-m-d`, up to the start of the next day; must not precede `--from`; defaults to today |
| `--limit` | Artists to list, from 1 to 100; defaults to 10 |
| `--json` | Print the artists exactly as the API's `data` array, in JSON |

## Details

The range works as for [app:activity:summary](app-activity-summary.md): both days are included, and an activity counts when its last play falls inside the range. The command prints the range it read above the table.

The table has one row per artist with the artist's plays. When nothing was played in the range, the command prints `No artists were played in this range.`

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | List printed, possibly empty |
| 1 | The activity could not be read; the message says why |
| 2 | A date is not a valid `Y-m-d` day, `--to` precedes `--from`, or `--limit` is not an integer from 1 to 100 |
