# app:movie:update

Change a movie's title, year or summary. The command does what `PATCH /api/movies/{publicId}` does in the admin API, through the same use case.

## Quick start

```bash
make exec cmd="php bin/console app:movie:update V1StGXR8_Z5jdHi6B-myT --title='Help!' --year=1965"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `public-id` | Yes | The public ID of the movie |

## Options

| Option | Description |
|--------|-------------|
| `--title=TITLE` | The new title; it cannot be empty |
| `--year=YEAR` | The new release year, as an integer |
| `--summary=TEXT` | The new summary |
| `--json` | Print only the result, as the API's `data` payload, in JSON |

## Details

An option that is left out keeps its current value; neither the command nor the API can clear a field. Movies have no field locks.

With `--json` the command prints only the updated movie as the API's `data` object.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Movie updated |
| 1 | No movie has the public ID, or another error occurred; the message says why |
| 2 | The public ID is malformed or a value was rejected |
