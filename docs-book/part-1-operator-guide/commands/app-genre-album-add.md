# app:genre:album:add

Assign a genre to an album. The command does what `POST /api/genres/{slug}/albums` does in the admin API, through the same port.

## Quick start

```bash
make exec cmd="php bin/console app:genre:album:add rock 0192a3b4-c5d6-7890-abcd-ef1234567890"
```

In a script that reads only the exit code:

```bash
make exec cmd="php bin/console app:genre:album:add rock 0192a3b4-c5d6-7890-abcd-ef1234567890 --json"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `slug` | Yes | The genre slug |
| `album-id` | Yes | The album UUID |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print nothing on success, as the admin API answers `204 No Content`; the exit code reports the outcome |

## Details

Assigning a genre the album already has succeeds and leaves one link, so a retry is harmless. An album UUID that names no album fails with `Album not found.`, which the API answers with `404`. To undo the assignment, use [app:genre:album:remove](app-genre-album-remove.md).

With `--json` the command prints nothing on success, because the API answers `204 No Content`; read the outcome from the exit code. Errors still go to stderr.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The album has the genre |
| 1 | No genre has the slug, no album has the album ID, or another error occurred; the message says why |
| 2 | The album ID is not a UUID |
