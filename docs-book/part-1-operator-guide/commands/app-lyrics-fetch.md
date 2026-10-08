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

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--limit` (`-l`) | none | Most songs to queue; leave it out to queue every song without lyrics. Must be at least 1 |
| `--delay` (`-d`) | `500` | Milliseconds between the queued fetches. Must not be negative |

## Details

Without `--limit`, the command and the admin page both queue every song without lyrics. With the same limit, both queue the same songs: the job walks the songs in ID order and skips those that already have lyrics.

The command runs the bulk fetch job in its own process and records the run in the job monitor like a queued job. The job queues one fetch per song on the async queue and returns; the queue workers then fetch the lyrics from LRCLIB and store them. Each fetch waits `--delay` milliseconds longer than the one before it, so LRCLIB sees one request per delay. When the job finishes, the command prints the number of songs queued, how long the workers take to reach the last one, and the job ID. Follow the fetches with [app:monitor:jobs](app-monitor-jobs.md) or [app:lyrics:status](app-lyrics-status.md).

A song that LRCLIB has no lyrics for keeps no lyrics, so the next run queues it again. Increase `--delay` if LRCLIB rate-limits the workers.

The `lyrics.auto_fetch` [server setting](../configuration.md#server-settings) does not affect this command. That setting fetches lyrics automatically for new tracks as a scan adds them; this command backfills songs already in the catalog.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The job finished; the message gives the number of songs queued, possibly none |
| 1 | The job failed, for example when the queue was unavailable; the message says why |
| 2 | `--limit` or `--delay` is not an integer, the limit is below 1, or the delay is negative |
