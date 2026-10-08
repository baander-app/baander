# app:library:show

Show one media library. The command shows what the admin panel's library detail page shows (`GET /api/libraries/{id}` in the API).

## Quick start

```bash
make exec cmd="php bin/console app:library:show my-music"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `library` | Yes | The library's UUID or slug |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--json` | — | Print the library as the API's `data` object, in JSON, and nothing else |

## Details

The command prints the library's UUID, name, slug, path, type, filesystem, sort order, scan status, last scan, creation time and last update. Times are in ISO 8601 format.

The command reads any library. In the API, a user who is not an admin sees only the libraries they were granted; shell access is not bound by grants.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Library printed |
| 1 | No library has the UUID or slug, or another error occurred; the message says why |
