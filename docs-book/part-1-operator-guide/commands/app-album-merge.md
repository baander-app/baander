# app:album:merge

Merge a source album into a target album and delete the source. The command does what `POST /api/albums/merge` does when an administrator merges duplicates on the Duplicates tab of the admin library page, through the same merge.

## Quick start

```bash
make exec cmd="php bin/console app:album:merge keptAlbumPublicId0001 mergedAlbumPublicId02"
```

Without a terminal, for example in a script:

```bash
make exec cmd="php bin/console app:album:merge keptAlbumPublicId0001 mergedAlbumPublicId02 --force"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `target` | Yes | The public ID of the album to keep |
| `source` | Yes | The public ID of the album to merge into the target and delete |

## Options

| Option | Description |
|--------|-------------|
| `--force` | Merge without asking; required when no terminal is attached |

## Details

[app:album:duplicates](app-album-duplicates.md) lists the public IDs of albums that look like duplicates.

On a terminal the command names both albums and asks before it merges. Without a terminal and without `--force` it changes nothing and exits with code 2.

The merge moves the source album's songs to the target. A source song whose file hash matches a target song is deleted instead. Metadata the target lacks is taken from the source. The target records the merge in its merge history, and the source album is deleted. The merge cannot be undone.

Both albums must be in the same library, and an album cannot be merged into itself.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Albums merged |
| 1 | No album has the target or source public ID, the operator declined, or another error occurred; the message says why |
| 2 | A public ID is malformed, the albums are in different libraries or are the same album, or no terminal is attached and `--force` was not given; nothing was merged |
