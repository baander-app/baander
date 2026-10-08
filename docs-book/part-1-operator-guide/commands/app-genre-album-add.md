# app:genre:album:add

Assign a genre to an album. The command does what `POST /api/genres/{slug}/albums` does in the admin API, through the same port.

## Quick start

```bash
make exec cmd="php bin/console app:genre:album:add rock 0192a3b4-c5d6-7890-abcd-ef1234567890"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `slug` | Yes | The genre slug |
| `album-id` | Yes | The album UUID |

## Details

Assigning a genre the album already has succeeds and leaves one link, so a retry is harmless. An album UUID that names no album fails with `Album not found.`, which the API answers with `404`. To undo the assignment, use [app:genre:album:remove](app-genre-album-remove.md).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The album has the genre |
| 1 | No genre has the slug, no album has the album ID, or another error occurred; the message says why |
| 2 | The album ID is not a UUID |
