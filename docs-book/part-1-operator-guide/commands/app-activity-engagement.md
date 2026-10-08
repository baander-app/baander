# app:activity:engagement

Show how many users listened over a range of days and how much each played on average. The command shows what `GET /api/admin/activity/engagement` returns in the admin API, which the engagement row of the admin activity page reads.

## Quick start

```bash
make exec cmd="php bin/console app:activity:engagement"
```

For one week:

```bash
make exec cmd="php bin/console app:activity:engagement --from 2026-03-02 --to 2026-03-08"
```

## Options

| Option | Description |
|--------|-------------|
| `--from` | First day counted, `Y-m-d`, from its start in the server time zone; defaults to 30 days before now |
| `--to` | Last day counted, `Y-m-d`, up to the start of the next day; must not precede `--from`; defaults to today |
| `--json` | Print the numbers exactly as the API's `data` object, in JSON |

## Details

The range works as for [app:activity:summary](app-activity-summary.md): both days are included, and an activity counts when its last play falls inside the range. The command prints the range it read above the table.

| Metric | Content |
|--------|---------|
| Active users | Users with at least one play in the range |
| Average plays per user | The plays divided by the active users, to one decimal |
| Average listening time per user (seconds) | The listening time divided by the active users; the API names it `avg_session_length` |

With no activity in the range, every number is 0.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Numbers printed, possibly all 0 |
| 1 | The activity could not be read; the message says why |
| 2 | A date is not a valid `Y-m-d` day, or `--to` precedes `--from` |
