# app:activity:top-tracks

List the most played tracks over a range of days, most played first. The command shows what `GET /api/admin/activity/top-tracks` returns in the admin API, which the top tracks table of the admin activity page reads.

## Quick start

```bash
make exec cmd="php bin/console app:activity:top-tracks"
```

The top 25 of March 2026:

```bash
make exec cmd="php bin/console app:activity:top-tracks --from 2026-03-01 --to 2026-03-31 --limit 25"
```

## Options

| Option | Description |
|--------|-------------|
| `--from` | First day counted, `Y-m-d`, from its start in the server time zone; defaults to 30 days before now |
| `--to` | Last day counted, `Y-m-d`, up to the start of the next day; must not precede `--from`; defaults to today |
| `--limit` | Tracks to list, from 1 to 100; defaults to 10 |
| `--json` | Print the tracks exactly as the API's `data` array, in JSON |

## Details

The range works as for [app:activity:summary](app-activity-summary.md): both days are included, and an activity counts when its last play falls inside the range. The command prints the range it read above the table.

The table has one row per track with its title, artist, album and plays. A missing artist or album shows as `-`. When nothing was played in the range, the command prints `No tracks were played in this range.`

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | List printed, possibly empty |
| 1 | The activity could not be read; the message says why |
| 2 | A date is not a valid `Y-m-d` day, `--to` precedes `--from`, or `--limit` is not an integer from 1 to 100 |
