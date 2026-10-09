# app:lyrics:fetch

Queue a lyrics fetch from LRCLIB for every song that has no lyrics yet, or for up to `--limit` of them. The command does what the **Fetch Missing Lyrics** button on the admin lyrics page does, `POST /api/admin/lyrics/bulk-fetch` in the admin API, through the same bulk fetch job.

## Quick start

```bash
make exec cmd="php bin/console app:lyrics:fetch"
```

Queue at most 500 songs, with the fetches one second apart:

```bash
make exec cmd="php bin/console app:lyrics:fetch --limit=500 --delay=1000"
```

Print the result as JSON, for a script:

```bash
make exec cmd="php bin/console app:lyrics:fetch --limit=500 --json"
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--limit` (`-l`) | none | Most songs to queue; leave it out to queue every song without lyrics. Must be at least 1 |
| `--delay` (`-d`) | `500` | Milliseconds between the queued fetches. Must not be negative |
| `--json` | off | Print only the result in JSON: the API's `data` payload plus the job ID |

## Details

Without `--limit`, the command and the admin page both queue every song without lyrics. With the same limit, both queue the same songs: the job walks the songs in ID order and skips those that already have lyrics or are already queued.

The command runs the bulk fetch job in its own process and records the run in the job monitor like a queued job. The job queues one fetch per song on the async queue and returns; the queue workers then fetch the lyrics from LRCLIB and store them. Each fetch waits `--delay` milliseconds longer than the one before it, so LRCLIB sees one request per delay. When the job finishes, the command prints the number of songs queued, how long the workers take to reach the last one, and the job ID. Follow the fetches with [app:monitor:jobs](app-monitor-jobs.md) or [app:lyrics:status](app-lyrics-status.md).

A song stays marked as queued until its fetch has run, or until a day after the fetch was due if it never runs. A run that starts while an earlier run's fetches are still waiting skips the songs already queued and queues only the rest. This happens with a manual run during a scheduled one, or with a daily schedule whose fetches take longer than a day. Each run spaces its own fetches from its own start, so while two runs both have fetches waiting, LRCLIB receives requests from both.

A song that LRCLIB has no lyrics for keeps no lyrics, so the next run queues it again. Increase `--delay` if LRCLIB rate-limits the workers.

## Cancelling a run

Cancel the job with [app:monitor:job:cancel](app-monitor-job-cancel.md) while it is still queueing; [app:monitor:jobs](app-monitor-jobs.md) in another shell shows its job ID. The job stops before its next song, and the command prints `Job "<jobId>" has been cancelled.` The fetches it queued before it stopped are skipped when they come due, without calling LRCLIB, and their songs can be queued again by the next run.

Queueing takes seconds even for a large catalog, while the fetches can run for hours. Once the job has finished queueing, the job monitor refuses to cancel it, and the fetches it queued run.

The `lyrics.auto_fetch` [server setting](../configuration.md#server-settings) does not affect this command. That setting fetches lyrics automatically for new tracks as a scan adds them; this command backfills songs already in the catalog.

With `--json` the command prints only `{"jobsEnqueued": 500, "jobId": "..."}`. `jobsEnqueued` is the API's `data` payload, the number of songs queued. `jobId` is the job-monitor ID of the bulk fetch run, which the API does not return; pass it to [app:monitor:job:show](app-monitor-job-show.md).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The job finished; the message gives the number of songs queued, possibly none |
| 1 | The job failed, for example when the queue was unavailable, or it was cancelled; the message says which |
| 2 | `--limit` or `--delay` is not an integer, the limit is below 1, or the delay is negative |
