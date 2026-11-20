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

The merge moves the source album's songs to the target. A source song whose file hash a target song holds is folded into that song instead, as [Duplicate songs](#duplicate-songs) describes. Metadata the target lacks is taken from the source. The target records the merge in its merge history, and the source album is deleted. The merge cannot be undone.

The merge commits all of its changes or none. It waits for a MusicBrainz match, a retag or a hand change to credits that holds one of the two albums or their songs, and then merges what that change left. Every song the source holds by then is moved or folded, however many there are. A song an import adds to the source while the merge runs waits for the merge and then fails, rather than being deleted with the source. When the merge fails, both albums, their songs and the source's cover stay as they were; the source's cover image is deleted only once the merge has committed.

Each user's favorite of the source album becomes a favorite of the target, and a user who favorited both keeps one. The plays recorded for the source's songs count for the target album.

A moved song keeps its MusicBrainz recording ID and artist credits, and counts again as a song no match has compared yet. While the `metadata.auto_sync` setting is on, the merge queues the target for [automatic matching](../media/musicbrainz-matching.md#automatic-matching) as an import does for an album it touches, and the request falls due five minutes later. The match of a matched target compares the moved songs with the target's release: a moved song takes a track's recording ID and credits only when its length is within 3 seconds of the track's, and otherwise stays as it is. While the setting is off, the merge queues nothing; an administrator's metadata sync, such as [app:metadata:sync](app-metadata-sync.md), queues the match. If the request cannot be recorded, the merge still succeeds and the error is logged.

Both albums must be in the same library, and an album cannot be merged into itself.

An album deleted while the merge waited for it fails as an unknown public ID does, with `Source album not found.` or `Target album not found.`, which the API answers with `404`. When the database ends the merge to break a deadlock with another change to the same albums or songs, such as an import of the same directories, nothing is merged and the command fails with `Another change to "<source>" or "<target>" ran at the same time and nothing was merged. Merge the albums again.`. The API answers `409` with the reason `merge_busy`, and the merge dialogs of the web app show the same message. Merging again may succeed.

With `--json` the command prints only the merged album as the API's `data` object, the same album resource `POST /api/albums/merge` returns. `--json` does not stand in for `--force`.

### Duplicate songs

A source song whose file hash is set and held by a target song is a duplicate of that song, the oldest one when several target songs hold the hash. A song without a file hash is always moved. The merge folds what users hold on a duplicate into the song it keeps, then deletes the duplicate:

- Each playlist entry of the duplicate becomes an entry of the kept song at the same position. A playlist that holds both songs keeps the kept song's entry where it is and loses the duplicate's. An edit that read such a playlist before the merge and saves it afterwards fails with "The playlist has changed. Refresh it before trying again.", as after any other concurrent edit.
- The duplicate's lyrics move to the kept song when the kept song has none; otherwise the kept song keeps its own.
- A user's plays of both songs become one play of the kept song: the play counts add up, the later last-played time stays with its platform and player, and the song is loved when either was.
- A user's favorite of the duplicate becomes a favorite of the kept song, and a user who favorited both keeps one.

The duplicate's own fields, credits and genres are deleted with it. Recommendations, listening-session queues and cached renditions that name it are not moved to the kept song. A playlist entry, lyrics, or a user's first play that names no album, stored for a duplicate while the merge runs, goes with the duplicate. The duplicate's audio file stays on disk with its file index entry, and a later scan creates no song for it, because the kept song holds its content.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Albums merged |
| 1 | No album has the target or source public ID, also when one was deleted while the merge waited, another change to the same albums or songs ran at the same time (`merge_busy`), the operator declined, or another error occurred; nothing was merged, and the message says why |
| 2 | A public ID is malformed, the albums are in different libraries or are the same album, or no terminal is attached and `--force` was not given; nothing was merged |
