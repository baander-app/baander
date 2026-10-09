# app:artist:cover:remove

Remove an artist's cover image and delete its image files. The command does what `DELETE /api/artists/{publicId}/cover` does in the admin API, through the same use case.

## Quick start

```bash
make exec cmd="php bin/console app:artist:cover:remove V1StGXR8_Z5jdHi6B-myT"
```

Without a terminal, for example in a script:

```bash
make exec cmd="php bin/console app:artist:cover:remove V1StGXR8_Z5jdHi6B-myT --force"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `public-id` | Yes | The artist's public ID, as the API and the web app show it |

## Options

| Option | Description |
|--------|-------------|
| `--force` | Remove without asking; required when no terminal is attached |
| `--json` | Print nothing on success, as the admin API answers `204 No Content`; the exit code reports the outcome |

## Details

On a terminal the command asks before it removes anything. Without a terminal and without `--force` it removes nothing and exits with code 2. `--json` does not stand in for `--force`.

The artist is saved without a cover first. Then the command deletes the image record, its file and its derived WebP files. This cannot be undone. To give the artist a cover again, use [app:artist:cover:set](app-artist-cover-set.md).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Cover removed |
| 1 | No artist has the public ID, the artist has no cover, the operator declined, or another error occurred; the message says why |
| 2 | The public ID is malformed, or no terminal is attached and `--force` was not given; nothing was removed |
