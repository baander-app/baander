# app:genre:song:add

Assign a genre to a song. The command does what `POST /api/genres/{slug}/songs` does in the admin API, through the same port.

## Quick start

```bash
make exec cmd="php bin/console app:genre:song:add rock 0192a3b4-c5d6-7890-abcd-ef1234567890"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `slug` | Yes | The genre slug |
| `song-id` | Yes | The song UUID |

## Details

Assigning a genre the song already has succeeds and leaves one link, so a retry is harmless. A song UUID that names no song fails with `Song not found.`, which the API answers with `404`. To undo the assignment, use [app:genre:song:remove](app-genre-song-remove.md).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The song has the genre |
| 1 | No genre has the slug, no song has the song ID, or another error occurred; the message says why |
| 2 | The song ID is not a UUID |
