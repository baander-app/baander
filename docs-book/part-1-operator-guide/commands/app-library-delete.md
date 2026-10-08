# app:library:delete

Delete a media library. The command runs the same use case as deleting a library in the admin panel (`DELETE /api/libraries/{id}` in the API).

## Quick start

```bash
make exec cmd="php bin/console app:library:delete my-music"
```

Without a terminal, for example in a script:

```bash
make exec cmd="php bin/console app:library:delete my-music --force"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `library` | Yes | The library's UUID or slug |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--force` | — | Delete without asking. Required when no terminal is attached |

## Details

Deletion cannot be undone. The database removes the library's albums and their songs, its movies, its file index and the users' access grants with it. The media files on disk stay where they are.

On a terminal, the command asks before it deletes; answering no leaves the library as it was. Without a terminal and without `--force`, the command deletes nothing and exits with code 2. In the API, only admins may delete a library.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Library deleted |
| 1 | No library has the UUID or slug, the operator declined, or another error occurred; the message says why |
| 2 | No terminal is attached and `--force` was not given; nothing was deleted |
