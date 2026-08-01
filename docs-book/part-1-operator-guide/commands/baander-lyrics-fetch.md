# baander:lyrics:fetch

Bulk-fetch lyrics from LRCLIB for songs that are missing them. Finds songs without lyrics and dispatches a fetch job for each one (up to the limit), pacing the requests with a configurable delay. Use this to backfill lyrics for an existing catalog.

## Quick start

Fetch up to the default 100 songs:

```bash
make exec cmd="php bin/console baander:lyrics:fetch"
```

Fetch up to 500 songs with a 1-second delay between each:

```bash
make exec cmd="php bin/console baander:lyrics:fetch --limit=500 --delay=1000"
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--limit` (`-l`) | `100` | Maximum number of songs to process |
| `--delay` (`-d`) | `500` | Delay between fetches in milliseconds |

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Fetch completed, or no songs needed lyrics (all already have them) |
| 1 | Invalid `--limit` (< 1), invalid `--delay` (< 0), or the fetch failed (message is printed) |

## Tips

- The `baander:` namespace distinguishes this project-level batch tool from the `app:` operational commands.
- Increase `--delay` if LRCLIB rate-limits your requests; the default 500 ms is conservative.
- The command only queues the fetch work — it reports how many songs were dispatched, not the fetch results. Check the worker logs for per-song outcomes.
- If every song already has lyrics, the command exits 0 with a "no songs required fetching" message.
