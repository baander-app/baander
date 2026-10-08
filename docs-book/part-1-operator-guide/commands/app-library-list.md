# app:library:list

List every media library. The command shows what the admin panel's library page shows (`GET /api/libraries` in the API), with the same type filter.

## Quick start

```bash
make exec cmd="php bin/console app:library:list"
```

Only movie libraries, as JSON:

```bash
make exec cmd="php bin/console app:library:list --type=movie --json"
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--type` | — | Only libraries of this type: `music`, `podcast`, `audiobook`, `movie` or `tv_show` |
| `--json` | — | Print the libraries as the API's `data` array, in JSON, and nothing else |

## Details

The command prints a table with one row per library, ordered by sort order and then name:

| Column | Content |
|--------|---------|
| Name | The display name |
| Slug | The URL-friendly identifier the other `app:library:*` commands accept |
| Type | The library type |
| Path | The media directory inside the container |
| Scan status | `scanning`, `completed`, `failed`, or `-` for a library that was never scanned |
| Last scan | Time of the last completed scan in ISO 8601 format, or `never` |
| UUID | The identifier the API and the other `app:library:*` commands accept |

When no library exists, the command prints `No libraries exist.`

The command lists every library. In the API, a user who is not an admin sees only the libraries they were granted; shell access is not bound by grants.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | List printed, possibly empty |
| 1 | The libraries could not be read; the message says why |
| 2 | An unknown library type |
