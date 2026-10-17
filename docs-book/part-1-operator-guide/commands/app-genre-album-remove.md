# app:genre:album:remove

Remove a genre from an album. The command does what `DELETE /api/genres/{slug}/albums/{albumId}` does in the admin API, through the same port.

## Quick start

```bash
make exec cmd="php bin/console app:genre:album:remove rock aB3dE5fG7hJ9kL1mN3pQr"
```

In a script that reads only the exit code:

```bash
make exec cmd="php bin/console app:genre:album:remove rock aB3dE5fG7hJ9kL1mN3pQr --json"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `slug` | Yes | The genre slug |
| `album-id` | Yes | The album public ID |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print nothing on success, as the admin API answers `204 No Content`; the exit code reports the outcome |

## Details

Only the link between the genre and the album is removed. Removing a link that does not exist succeeds without change, as in the API. An album public ID that names no album fails with `Album not found.`, which the API answers with `404`.

With `--json` the command prints nothing on success, because the API answers `204 No Content`; read the outcome from the exit code. Errors still go to stderr.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The album no longer has the genre |
| 1 | No genre has the slug, no album has the album ID, or another error occurred; the message says why |
| 2 | The album ID is not a public ID |
