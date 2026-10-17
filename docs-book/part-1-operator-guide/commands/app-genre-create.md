# app:genre:create

Create a genre, as a root genre or below an existing parent genre. The command does what `POST /api/genres/` does in the admin API, through the same use case and with the same validation.

## Quick start

```bash
make exec cmd="php bin/console app:genre:create 'Hard Rock' hard-rock --parent=aB3dE5fG7hJ9kL1mN3pQr"
```

Print the result as JSON, for a script:

```bash
make exec cmd="php bin/console app:genre:create 'Hard Rock' hard-rock --json"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `name` | Yes | The genre name; leading and trailing spaces are removed |
| `slug` | Yes | The URL slug: lowercase letters, digits and single hyphens, such as `hard-rock` |

## Options

| Option | Description |
|--------|-------------|
| `--parent=PUBLIC_ID` | The public ID of the parent genre, as [app:genre:list](app-genre-list.md) shows it |
| `--mbid=ID` | The genre's MusicBrainz ID |
| `--json` | Print only the result, as the admin API's `data` payload, in JSON |

## Details

On success the command prints the new genre's name, slug and public ID.

The command rejects a blank name, a malformed slug or MusicBrainz ID, a parent ID that is not a public ID, and a parent that is not an existing genre. The API answers the same cases with `422`, and both name the same problem.

With `--json` the command prints only the new genre as the API's `data` object, with `publicId`, `name`, `slug`, `parentId` and `mbid`, so a script can read the public ID from `publicId`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Genre created |
| 1 | Another genre has the slug, or another error occurred; the message says why |
| 2 | The name, slug, MusicBrainz ID or parent was rejected; the message says why |
