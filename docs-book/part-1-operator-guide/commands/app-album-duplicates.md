# app:album:duplicates

List the groups of albums in a library that look like duplicates. The command shows what `GET /api/admin/albums/duplicates` returns, which the Duplicates tab of the admin library page reads.

## Quick start

```bash
make exec cmd="php bin/console app:album:duplicates 0199bf3c-8a00-7000-8000-0000000000f6"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `library` | Yes | The UUID of the library to search, as `app:library:scan` prints it |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print the groups exactly as the API's `data` array, in JSON |

## Details

The command prints one row per album, with a separator line between groups:

| Column | Content |
|--------|---------|
| Group | The group number, on the group's first row |
| Confidence | How likely the albums are duplicates, as a percentage, on the group's first row |
| Public ID | The album's public ID, which [app:album:merge](app-album-merge.md) takes |
| Title | The album title |
| Year | The release year, or `-` |
| Label | The record label, or `-` |
| Artists | The album's artists, or `-` |

The shell reads with full authority, so the command searches any library. A library without duplicates, or a UUID that names no library, prints `No duplicate albums found in the library.`

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | List printed, possibly empty |
| 1 | The duplicates could not be read; the message says why |
| 2 | The library argument is not a UUID |
