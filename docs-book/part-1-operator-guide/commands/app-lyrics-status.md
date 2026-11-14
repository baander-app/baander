# app:lyrics:status

Show the lyrics jobs that the job monitor recorded: when the last one was created, how many were created in the past 7 days, and how many finished or failed. Below them it shows the outcome of the latest bulk fetch run: the songs it skipped and what its fetches found. The command shows what `GET /api/admin/lyrics/sync-status` returns in the admin API, which the sync status section of the admin lyrics page reads.

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

### Latest bulk run

The latest bulk run is the run of [app:lyrics:fetch](app-lyrics-fetch.md), the admin page or a schedule that finished queueing last, including a run that skipped every song and queued nothing. The run stays recorded until a day after its last fetch was due, or for a day when it queued nothing. While no run is recorded, the command prints `Latest bulk run: no recent run` instead of the table, and `latestRun` is `null` in JSON.

| Metric | Content |
|--------|---------|
| Finished queueing | When the run finished queueing, in ISO 8601 |
| Job ID | The job-monitor ID of the run, or `none` for a run outside a job |
| Queued | Fetches the run queued |
| Found | Fetches that found lyrics on LRCLIB and stored them, or found that the song had lyrics by then |
| No match | Fetches for which LRCLIB had no lyrics |
| Skipped for a missing artist | Songs without lyrics and without a primary artist credit, which the run did not queue |
| Skipped for a missing length | Songs without lyrics whose length is missing or not above zero, which the run did not queue |
| Rejected | Fetches whose request LRCLIB rejected with a client error |
| Unavailable | Fetches that found LRCLIB unavailable: a timeout, rate limiting, a server error or an unreadable answer |

A fetch counts once a queue worker has handled it, so while fetches are still due, the found, no match, rejected and unavailable counts add up to less than `Queued`. A fetch that the queue delivers twice counts once. The fetches of a run cancelled in the job monitor are skipped and never count. A song that loses its artist credit or its length after the run queued it counts as skipped for that cause when its fetch runs.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Status printed |
| 1 | The status could not be read; the message says why |
