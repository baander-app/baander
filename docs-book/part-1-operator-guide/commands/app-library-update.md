# app:library:update

Rename a media library or change its sort order. The command runs the same use case as editing a library in the admin panel (`PATCH /api/libraries/{id}` in the API).

## Quick start

```bash
make exec cmd="php bin/console app:library:update my-music --name='Music' --sort-order=1"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `library` | Yes | The library's UUID or slug |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--name` | — | The new name. Without the option, the name stays |
| `--sort-order` | — | The new sort order; lower numbers come first. Without the option, the order stays |

## Details

The slug, path and type do not change. To move a library to another directory, create a new library with [app:library:create](app-library-create.md).

On success, the command prints the library as it is now. Running it again with the same values succeeds and changes nothing but the update time. In the API, only admins may change a library.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Library updated |
| 1 | No library has the UUID or slug, or another error occurred; the message says why |
| 2 | A blank name, or a sort order that is not a whole number |
