# app:movie:delete

Delete a movie and the video records left without catalog links. The [command](../../../src/Catalog/Interface/Console/MovieDeleteCommand.php) runs the same use case as `DELETE /api/movies/{publicId}`.

## Quick start

```bash
make exec cmd="php bin/console app:movie:delete V1StGXR8_Z5jdHi6B-myT"
```

Without a terminal, for example in a script:

```bash
make exec cmd="php bin/console app:movie:delete V1StGXR8_Z5jdHi6B-myT --force"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `public-id` | Yes | The movie's public ID, as the API and the web app show it |

## Options

| Option | Description |
|--------|-------------|
| `--force` | Delete without asking; required when no terminal is attached |
| `--json` | Print the API response's `data` payload: the counts of deleted movies and videos |

## Details

On a terminal the command asks before it deletes anything. Without a terminal and without `--force` it deletes nothing and exits with code 2. `--json` does not stand in for `--force`.

The [delete handler](../../../src/Catalog/Application/CommandHandler/Movie/DeleteMovieHandler.php) considers the movie's features and extras in the same transaction. A video record survives if any movie or placement still links it, including a placement for a television episode or extra. The source files stay on disk. The record deletion cannot be undone.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Movie deleted |
| 1 | No movie has the public ID, the operator declined, or another error occurred; the message says why |
| 2 | The public ID is malformed, or no terminal is attached and `--force` was not given; nothing was deleted |
