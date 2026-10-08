# app:lyrics:status

Show the lyrics jobs that the job monitor recorded: when the last one was created, how many were created in the past 7 days, and how many finished or failed. The command shows what `GET /api/admin/lyrics/sync-status` returns in the admin API, which the sync status section of the admin lyrics page reads.

## Quick start

```bash
make exec cmd="php bin/console app:lyrics:status"
```

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print the status exactly as the API's `data` object, in JSON |

## Details

The lyrics jobs are the bulk fetch jobs that [app:lyrics:fetch](app-lyrics-fetch.md) and the admin page run, and the single-song fetches they queue. The finished and failed counts cover every such job the monitor still holds; [app:monitor:prune](app-monitor-prune.md) removes old ones. A fetch that found no lyrics on LRCLIB counts as finished.

| Metric | Content |
|--------|---------|
| Last job | Creation time of the newest lyrics job in ISO 8601, or `never` |
| Jobs in the past 7 days | Lyrics jobs created in the past 7 days |
| Finished jobs | Lyrics jobs that finished |
| Failed jobs | Lyrics jobs that failed |

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Status printed |
| 1 | The status could not be read; the message says why |
