# app:library:scan

Scan a media library for new, changed, or removed files. This indexes the contents of the library so Baander can serve them.

## Quick start

```bash
make exec cmd="php bin/console app:library:scan my-music"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `slug` | Yes | The slug of the library to scan |

## Details

The scan discovers media files in the library's directory, extracts metadata, and updates the database. For large libraries, this can take a while — the command runs synchronously and prints a summary table when done.

The slug is the same value shown when you created the library (or auto-generated from its name).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Scan completed successfully |
| 1 | Invalid slug or scan failed — check the error message |

## Tips

- Run this after adding new files to a library directory.
- For large initial imports, consider running the scan during off-peak hours.
- The scan is idempotent — running it multiple times on the same library is safe.
