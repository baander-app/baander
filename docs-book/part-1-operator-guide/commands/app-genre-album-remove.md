# app:genre:album:remove

Remove a genre from an album. The command does what `DELETE /api/genres/{slug}/albums/{albumId}` does in the admin API, through the same port.

## Quick start

```bash
make exec cmd="php bin/console app:genre:album:remove rock 0192a3b4-c5d6-7890-abcd-ef1234567890"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `slug` | Yes | The genre slug |
| `album-id` | Yes | The album UUID |

## Details

Only the link between the genre and the album is removed. Removing a link that does not exist succeeds without change, as in the API. An album UUID that names no album fails with `Album not found.`, which the API answers with `404`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The album no longer has the genre |
| 1 | No genre has the slug, no album has the album ID, or another error occurred; the message says why |
| 2 | The album ID is not a UUID |
