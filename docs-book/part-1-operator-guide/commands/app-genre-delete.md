# app:genre:delete

Delete a genre. The command does what `DELETE /api/genres/{slug}` does in the admin API, through the same use case.

## Quick start

```bash
make exec cmd="php bin/console app:genre:delete hard-rock"
```

Without a terminal, for example in a script:

```bash
make exec cmd="php bin/console app:genre:delete hard-rock --force"
```

In a script that reads only the exit code:

```bash
make exec cmd="php bin/console app:genre:delete hard-rock --force --json"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `slug` | Yes | The slug of the genre to delete |

## Options

| Option | Description |
|--------|-------------|
| `--force` | Delete without asking; required when no terminal is attached |
| `--json` | Print nothing on success, as the admin API answers `204 No Content`; the exit code reports the outcome |

## Details

On a terminal the command asks before it deletes anything. Without a terminal and without `--force` it deletes nothing and exits with code 2.

Deletion cannot be undone. The genre's child genres become root genres, and its links to albums, songs and movies are removed. The albums, songs and movies themselves stay.

With `--json` the command prints nothing on success, because the API answers `204 No Content`; read the outcome from the exit code. Errors still go to stderr. `--json` does not stand in for `--force`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Genre deleted |
| 1 | No genre has the slug, the operator declined, or another error occurred; the message says why |
| 2 | No terminal is attached and `--force` was not given; nothing was deleted |
