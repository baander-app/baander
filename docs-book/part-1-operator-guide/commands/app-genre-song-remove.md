# app:genre:song:remove

Remove a genre from a song. The command does what `DELETE /api/genres/{slug}/songs/{songId}` does in the admin API, through the same port.

## Quick start

```bash
make exec cmd="php bin/console app:genre:song:remove rock 0192a3b4-c5d6-7890-abcd-ef1234567890"
```

In a script that reads only the exit code:

```bash
make exec cmd="php bin/console app:genre:song:remove rock 0192a3b4-c5d6-7890-abcd-ef1234567890 --json"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `slug` | Yes | The genre slug |
| `song-id` | Yes | The song UUID |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print nothing on success, as the admin API answers `204 No Content`; the exit code reports the outcome |

## Details

Only the link between the genre and the song is removed. Removing a link that does not exist succeeds without change, as in the API. A song UUID that names no song fails with `Song not found.`, which the API answers with `404`.

With `--json` the command prints nothing on success, because the API answers `204 No Content`; read the outcome from the exit code. Errors still go to stderr.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The song no longer has the genre |
| 1 | No genre has the slug, no song has the song ID, or another error occurred; the message says why |
| 2 | The song ID is not a UUID |
