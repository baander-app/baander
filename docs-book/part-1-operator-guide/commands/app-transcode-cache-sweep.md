# app:transcode:cache-sweep

Delete old transcode cache directories. Each video's segment directory and each track's audio renditions form one cache unit. The sweep deletes units that have been idle longer than the age limit, then evicts the least recently written units until the cache fits the size budget. A scheduled job can run this command; see [app:scheduler:create](app-scheduler-create.md).

## Quick start

Report what a sweep would delete, without deleting anything:

```bash
make exec cmd="php bin/console app:transcode:cache-sweep --dry-run"
```

Sweep with a 12-hour age limit and a 20 GB budget:

```bash
make exec cmd="php bin/console app:transcode:cache-sweep --ttl-hours=12 --max-gb=20"
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--ttl-hours` | `24` | Delete a cache unit whose newest file is older than this many hours |
| `--max-gb` | `50` | Cache size budget in gigabytes; the least recently written units beyond it are evicted |
| `--dry-run` | off | Report what would be deleted without deleting anything |
| `--active-window-seconds` | `1800` | A session updated, or a rendition written, within this many seconds protects its unit |

## Details

A unit is never deleted while it is in use: a video whose transcode job is still encoding or whose session was updated within the active window, or a track with an audio rendition encode that wrote within the window. The output lists the deleted units and the protected ones, with the cache size before and after the sweep and the space freed.

The age limit is checked first. If the cache is still over the budget afterwards, the remaining idle units are evicted, least recently written first, until it fits. The scheduler accepts `ttl-hours`, `max-gb` and `dry-run` as job parameters.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The sweep finished, or the dry-run report was shown |
| 1 | An error stopped the sweep; the message says why |
