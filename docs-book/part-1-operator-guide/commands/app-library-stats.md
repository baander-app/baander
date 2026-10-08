# app:library:stats

Show how much content one media library holds. The command shows the counts on the admin panel's library detail page (`GET /api/libraries/{id}/stats` in the API).

## Quick start

```bash
make exec cmd="php bin/console app:library:stats my-music"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `library` | Yes | The library's UUID or slug |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--json` | — | Print the counts as the API's `data` object, in JSON, and nothing else |

## Details

The command prints the number of songs, albums, artists and genres in the library, the total file size in bytes and the total duration in seconds.

The command reads any library. In the API, a user who is not an admin sees only the libraries they were granted; shell access is not bound by grants.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Counts printed |
| 1 | No library has the UUID or slug, or another error occurred; the message says why |
