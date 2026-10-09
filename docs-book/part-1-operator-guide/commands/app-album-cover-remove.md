# app:album:cover:remove

Remove an album's cover and delete its image files. The command does what `DELETE /api/albums/{publicId}/cover` does in the admin API, through the same use case.

## Quick start

```bash
make exec cmd="php bin/console app:album:cover:remove V1StGXR8_Z5jdHi6B-myT"
```

Without a terminal, for example in a script:

```bash
make exec cmd="php bin/console app:album:cover:remove V1StGXR8_Z5jdHi6B-myT --force"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `public-id` | Yes | The album's public ID, as the API and the web app show it |

## Options

| Option | Description |
|--------|-------------|
| `--force` | Remove without asking; required when no terminal is attached |
| `--json` | Print nothing on success, as the admin API answers `204 No Content`; the exit code reports the outcome |

## Details

On a terminal the command asks before it removes anything. Without a terminal and without `--force` it removes nothing and exits with code 2. `--json` does not stand in for `--force`.

The album is saved without a cover first. Then the command deletes the image record, its file and its derived WebP files. This cannot be undone.

A library scan may later extract a cover from the album's audio files again, as it does for any album without a cover. To give the album a cover yourself, use [app:album:cover:set](app-album-cover-set.md).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Cover removed |
| 1 | No album has the public ID, the album has no cover, the operator declined, or another error occurred; the message says why |
| 2 | The public ID is malformed, or no terminal is attached and `--force` was not given; nothing was removed |
