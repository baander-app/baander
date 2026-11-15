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

Print the result as JSON, for a script:

```bash
make exec cmd="php bin/console app:album:merge keptAlbumPublicId0001 mergedAlbumPublicId02 --force --json"
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
| `--json` | Print only the result, as the admin API's `data` payload, in JSON |

## Details

[app:album:duplicates](app-album-duplicates.md) lists the public IDs of albums that look like duplicates.

On a terminal the command names both albums and asks before it merges. Without a terminal and without `--force` it changes nothing and exits with code 2.

The merge moves the source album's songs to the target. A source song whose file hash matches a target song is deleted instead. Metadata the target lacks is taken from the source. The target records the merge in its merge history, and the source album is deleted. The merge cannot be undone.

A moved song keeps its MusicBrainz recording ID and artist credits, and counts again as a song no match has compared yet. While the `metadata.auto_sync` setting is on, the merge queues the target for [automatic matching](../media/external-apis.md#automatic-matching) as an import does for an album it touches, and the request falls due five minutes later. The match of a matched target compares the moved songs with the target's release: a moved song takes a track's recording ID and credits only when its length is within 3 seconds of the track's, and otherwise stays as it is. While the setting is off, the merge queues nothing; an administrator's metadata sync, such as [app:metadata:sync](app-metadata-sync.md), queues the match. If the request cannot be recorded, the merge still succeeds and the error is logged.

Both albums must be in the same library, and an album cannot be merged into itself.

With `--json` the command prints only the merged album as the API's `data` object, the same album resource `POST /api/albums/merge` returns. `--json` does not stand in for `--force`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Albums merged |
| 1 | No album has the target or source public ID, the operator declined, or another error occurred; the message says why |
| 2 | A public ID is malformed, the albums are in different libraries or are the same album, or no terminal is attached and `--force` was not given; nothing was merged |
