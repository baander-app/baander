# app:genre:list

List every genre in the catalog, or show the genre hierarchy. The command shows what `GET /api/genres/?flat=true` returns to an administrator in the admin API, which the genres tab of the admin library page reads.

## Quick start

```bash
make exec cmd="php bin/console app:genre:list"
```

As a hierarchy:

```bash
make exec cmd="php bin/console app:genre:list --tree"
```

## Options

| Option | Description |
|--------|-------------|
| `--tree` | Print one genre per line as `Name (slug)`, with each child indented two spaces below its parent |
| `--json` | Print the genres exactly as the API's `data` array, in JSON |

## Details

The shell reads with full authority, so the command lists every genre, including genres that no library album, song or movie uses yet. A member's view of the API shows only genres with media in libraries they can open.

The default table has one row per genre, sorted by name:

| Column | Content |
|--------|---------|
| Name | The genre name |
| Slug | The slug the other `app:genre:*` commands take |
| Parent | The parent genre's slug, or `-` for a root genre |
| UUID | The genre UUID, which `--parent` options take |
| MusicBrainz ID | The MusicBrainz ID, or `-` |

With `--tree`, root genres and siblings are sorted by name. `--json` always prints the flat list, also when `--tree` is given. When no genre exists, the command prints `No genres exist.`

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | List printed, possibly empty |
| 1 | The genres could not be read; the message says why |
