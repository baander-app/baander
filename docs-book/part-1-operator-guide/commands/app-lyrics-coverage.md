# app:lyrics:coverage

Show how many tracks have lyrics and where the stored lyrics came from. The command shows what `GET /api/admin/lyrics/coverage` returns in the admin API, which the coverage section of the admin lyrics page reads.

## Quick start

```bash
make exec cmd="php bin/console app:lyrics:coverage"
```

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print the coverage exactly as the API's `data` object, in JSON |

## Details

The first table gives the total number of tracks, those with and without lyrics, and the coverage as a percentage with two decimals. The second table gives the number of stored lyrics per source, such as `lrclib` or `embedded`, most first. When no lyrics are stored, the command prints `No lyrics are stored.` instead of the second table.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Coverage printed |
| 1 | The coverage could not be read; the message says why |
