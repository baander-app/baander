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

Without `--limit`, the command and the admin page both queue every song without lyrics that has an artist and a length. With the same limit, both queue the same songs: the job walks the songs in ID order and skips those that already have lyrics or are already queued.

LRCLIB matches a song by its artist, title, album and length, so a lookup needs a primary artist credit and a length above zero. The job does not queue a song without lyrics that lacks either. It counts the song as skipped for a missing artist or for a missing length, and the song does not count toward `--limit`. Songs usually lack an artist because their files carry no artist tag, or because the library has not been rescanned since they were imported. Fix the tags or rescan the library with `app:library:scan --rescan`, which credits each song without an artist credit to its tagged artist ([What a rescan repairs](app-library-scan.md#what-a-rescan-repairs)). While `lyrics.auto_fetch` is on, the rescan itself queues a fetch for each such song it repairs. Otherwise, run the command again once the rescan's imports have finished; songs whose import is still queued count as missing an artist.

The command runs the bulk fetch job in its own process and records the run in the job monitor like a queued job, as the admin page does. The job queues one fetch per song on the async queue and returns; the queue workers then fetch the lyrics from LRCLIB and store them. Each fetch waits `--delay` milliseconds longer than the one before it, so LRCLIB sees one request per delay. When the job finishes, the command prints the number of songs queued, how long the workers take to reach the last one, and the job ID, then the number of songs skipped for each cause. Follow the fetches with [app:monitor:jobs](app-monitor-jobs.md), or with [app:lyrics:status](app-lyrics-status.md), which shows what the latest run's fetches found.

A song stays marked as queued until its fetch has run, or until a day after the fetch was due if it never runs. A run that starts while an earlier run's fetches are still waiting skips the songs already queued and queues only the rest. This happens with a manual run during a scheduled one, or with a daily schedule whose fetches take longer than a day. Each run spaces its own fetches from its own start, so while two runs both have fetches waiting, LRCLIB receives requests from both.

A song that LRCLIB has no lyrics for keeps no lyrics, so the next run queues it again. Increase `--delay` if LRCLIB rate-limits the workers.

## Cancelling a run

Cancel the run with [app:monitor:job:cancel](app-monitor-job-cancel.md), or with **Cancel Job** in the admin **Job Monitor**, using the job ID the command printed. A run started from the admin page appears in the job monitor as `BulkFetchLyricsCommand`.

Queueing takes seconds even for a large catalog, while the fetches can run for hours, so a run can be cancelled at either stage:

- While the job is still queueing, it stops before its next song, and the command prints `Job "<jobId>" has been cancelled.`
- Once the job has finished queueing, cancelling it cancels the fetches still waiting. The job's status changes from `finished` to `cancelled`. Cancelling stays possible until a day after the run's last fetch was due.

Either way, the fetches the run queued are skipped when they come due, without calling LRCLIB, and their songs can be queued again by the next run. Fetches that already ran keep the lyrics they stored.

The `lyrics.auto_fetch` [server setting](../configuration/server-settings.md#server-settings) does not affect this command. That setting fetches lyrics automatically for new tracks as a scan adds them, and for songs a rescan repairs ([What a rescan repairs](app-library-scan.md#what-a-rescan-repairs)); this command backfills songs already in the catalog.

With `--json` the command prints only `{"jobsEnqueued": 500, "skippedNoArtist": 3, "skippedNoLength": 0, "jobId": "..."}`. The first three fields are the API's `data` payload: the number of songs queued, and the songs without lyrics skipped for a missing artist and for a missing length. `jobId` is the job-monitor ID of the bulk fetch run, which the API does not return; pass it to [app:monitor:job:show](app-monitor-job-show.md).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The job finished; the message gives the number of songs queued, possibly none, and the number skipped for each cause |
| 1 | The job failed, for example when the queue was unavailable, or it was cancelled; the message says which |
| 2 | `--limit` or `--delay` is not an integer, the limit is below 1, or the delay is negative |
