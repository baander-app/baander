# app:artist:create

Create an artist that no scan found, such as a featured performer to credit by hand. The command does what `POST /api/artists/` does in the admin API, through the same use case.

## Quick start

```bash
make exec cmd="php bin/console app:artist:create 'The Beatles' --country=GB --type=group --sort-name='Beatles, The'"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `name` | Yes | The artist name; it cannot be empty |

## Options

| Option | Description |
|--------|-------------|
| `--country=COUNTRY` | The country, such as `GB` |
| `--gender=GENDER` | The gender of a person |
| `--type=TYPE` | The artist type, such as `person` or `group` |
| `--disambiguation=TEXT` | A comment that tells artists of the same name apart |
| `--sort-name=NAME` | The name to sort by, such as `Beatles, The` |
| `--biography=TEXT` | The biography |
| `--json` | Print only the result, as the API's `data` payload, in JSON |

## Details

The command does not check for an existing artist with the same name; creating the same name twice creates two artists.

The command prints the new artist's public ID, which [app:artist:update](app-artist-update.md) takes. With `--json` it prints only the new artist as the API's `data` object, with `uuid`, `publicId`, `name`, `country`, `type`, `disambiguation`, `sortName` and `createdAt`. The API answers `201` with the same object.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Artist created |
| 1 | An error occurred; the message says why |
| 2 | The name is empty |
