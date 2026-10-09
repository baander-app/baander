# app:artist:delete

Delete an artist, its song and album credits, and its cover image. The command does what `DELETE /api/artists/{publicId}` does in the admin API, through the same use case.

## Quick start

```bash
make exec cmd="php bin/console app:artist:delete V1StGXR8_Z5jdHi6B-myT"
```

Without a terminal, for example in a script:

```bash
make exec cmd="php bin/console app:artist:delete V1StGXR8_Z5jdHi6B-myT --force"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `public-id` | Yes | The artist's public ID, as the API and the web app show it |

## Options

| Option | Description |
|--------|-------------|
| `--force` | Delete without asking; required when no terminal is attached |
| `--json` | Print the API response's `data` payload: the counts of deleted artists and cover images |

## Details

On a terminal the command asks before it deletes anything. Without a terminal and without `--force` it deletes nothing and exits with code 2. `--json` does not stand in for `--force`.

The artist and its credits are deleted; the songs and albums stay. After that, the cover image record, its file and its derived files are deleted. This cannot be undone.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Artist deleted |
| 1 | No artist has the public ID, the operator declined, or another error occurred; the message says why |
| 2 | The public ID is malformed, or no terminal is attached and `--force` was not given; nothing was deleted |
