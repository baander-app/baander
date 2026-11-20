# Catalog

The Catalog context owns artists, albums, songs, movies, videos and genres. It creates, reads, updates and deletes them, searches them with PGroonga, extracts cover art, and matches albums, songs and artists with MusicBrainz. It is the largest context by entity count.

This page explains catalog edits and their transaction boundaries. The [implementation inventory](catalog-inventory.md) lists the models, handlers, ports and entry points; [imports and rescans](catalog-imports.md) explain file discovery; [MusicBrainz matching](catalog-matching.md) covers comparison, apply, queues and artist sync.

## Field Locks

`Album`, `Song` and `Artist` each have a list of lockable fields, and they share the lock rules in `LockedFields`. A locked field keeps its value: `updateMetadata()` rejects the whole edit when it gives any locked field a value. Every lockable field is enforced, not only the title or name. Locking or unlocking a field that is not lockable is rejected too.

| Aggregate | Lockable fields |
|-----------|-----------------|
| `Album` | `title`, `type`, `year`, `label`, `catalogNumber`, `barcode`, `country`, `language`, `disambiguation`, `annotation` |
| `Song` | `title`, `track`, `disc`, `year`, `comment`, `lyrics`, `explicit` |
| `Artist` | `name`, `country`, `gender`, `type`, `lifeSpanBegin`, `lifeSpanEnd`, `disambiguation`, `sortName`, `biography` |

An update command takes the edit, a complete `lockedFields` list that replaces the current one, and fields to `lock` and `unlock`. `CatalogInput::lockChanges()` turns these into fields to unlock and fields to lock. The handler applies the unlocks, then the edit, then the locks, and saves only when all three are accepted. One request can therefore unlock a field and change it, or change a field and lock it. A field in both lists ends locked. On the console, `--lock` and `--unlock` can be repeated and accept comma-separated field names.

Automatic updates skip locked fields rather than fail. An [artist sync](catalog-matching.md#artist-sync) fills only empty fields that are not locked. A MusicBrainz match shows a locked field in its comparison but never ticks or writes it, and a manual apply whose ticked row has become locked answers 409 `match_stale`. A retag leaves a locked song field as it is. `AlbumMergeService` keeps the target album's value for a locked field, even when that value is empty. After the merge, the target also locks every field that was locked on the source album.

## Deletes

Every delete returns a `CatalogDeletionResult`: the rows deleted by kind and, when the delete included audio files, the files removed, already missing, and left on disk.

`AlbumPortInterface::delete()` and `SongPortInterface::delete()` delete rows only; they never touch audio files. A delete with files goes through Library's `LibraryMediaFilesInterface` (see [Library](library.md#media-file-deletion)). Before anything changes, `DeleteAlbumHandler` and `DeleteSongHandler` call `prepareDeletion()` for the song paths. It refuses the whole request with a 409 while a scan holds the library, when the library root is not available, or when the server cannot write a song's directory, and with a 422 when a path lies outside the library root. The handlers then delete the catalog rows and the file index rows in one transaction through `TransactionPortInterface`, and unlink the files after the commit. The next scan imports any file left on disk again. When a file is left, the console command exits with `FAILURE`.

`DeleteAlbumHandler` deletes the album's cover image after the commit unless `deleteCover` is false, so a rollback leaves the album with its cover. `DeleteArtistHandler` deletes the artist, then its cover image. `DeleteMovieHandler` deletes the movie and, in the same transaction, the videos that no other movie links (`VideoRepositoryInterface::deleteUnlinked()`).

The public delete routes pass only the public ID, so they keep the audio files and delete the album cover. `DELETE /api/admin/albums/{publicId}` takes `deleteFiles` and `deleteCover`, and `DELETE /api/admin/songs/{publicId}` takes `deleteFile`. On the console, `--delete-files` requires `--force` even on a terminal, `app:album:delete --keep-cover` keeps the cover, and `--dry-run` prints the preview without changing anything.

## Album Merges

`AlbumMergeService` implements `AlbumMergePortInterface`, which `POST /api/albums/merge` and `app:album:merge` call, and merges in one transaction under the [catalog lock order](catalog-matching.md#apply). `AlbumPortInterface::lockForMerge()` locks both album rows in ID order, the source `FOR UPDATE` and the target `FOR NO KEY UPDATE`; one statement then reads the song IDs of both albums, `SongPortInterface::lockInIdOrder()` locks those rows `FOR NO KEY UPDATE`, and the albums and songs are read through the refreshing finders.

Before the transaction the merge reads only the two titles a conflict message names, so it acts on what an apply, a retag or a hand change that held a row first committed, and it moves every song the source holds, without a cap. With the source's row held `FOR UPDATE`, a song an import inserts into the source waits on `fk_songs_album_id` and fails once the merge commits, instead of being deleted with the source by the cascade.

The merge moves every source song that is no duplicate in one flush. A source song is a duplicate when its hash is set and a target song holds it, and it duplicates the target's oldest song with that hash. Before any duplicate is deleted, the merge folds what users hold on it into the kept song: the Playlist port first locks, in ID order, the rows of every playlist that holds a duplicate and raises their `persistence_version`, so a save that read one of them earlier answers its existing 409; then each duplicate, in ID order, is folded through the Lyrics, Activity and Favorites ports.

The duplicates are deleted with their credits and genres, and each removal is logged with the song that holds its file. The source's favorites and plays then move to the target, the target takes the metadata it lacks and locks every field the source had locked, and the source is deleted without its cover. After the commit the source's cover is discarded and a match of the target is requested (see [Album match queue](catalog-matching.md#album-match-queue)); a failure of either is logged and the merge stands.

| Port | Context | Inside the merge's transaction |
|------|---------|--------------------------------|
| `AlbumPlaylistMergePortInterface::foldSongs()` | Playlist | Locks the playlists that hold a duplicate and raises their version. Each entry of a duplicate becomes the kept song's, with its ID, position and creation time; a playlist that holds both keeps the kept song's entry |
| `AlbumLyricsMergePortInterface::foldSong()` | Lyrics | Moves the duplicate's lyrics to the kept song when the kept song has none; otherwise the kept song's stay |
| `AlbumActivityMergePortInterface::foldSong()` | Activity | Leaves each user who played the duplicate one row of the kept song: play counts added, the latest last-played time with its platform and player, loved when any row was. It waits for a play recorded meanwhile and counts it |
| `AlbumActivityMergePortInterface::merge()` | Activity | Points every play of the source album at the target |
| `AlbumFavoriteMergePortInterface::foldSong()` | Favorites | Makes a favorite of the duplicate one of the kept song; a user who favorited both keeps the kept song's |
| `AlbumFavoriteMergePortInterface::merge()` | Favorites | Makes a favorite of the source album one of the target, with its ID and creation time; a user who favorited both keeps the target's |

Each port forms a single-class Deptrac contract layer that `Catalog Infrastructure` may use (`Playlist Album Merge Contract`, `Lyrics Album Merge Contract`, `Activity Album Merge Contract`, `Favorites Album Merge Contract`). Each runs as statements, loads no entity, and releases what the unit of work holds of the rows it changes; `AlbumRepository::delete()` releases the album's managed children before its flush. The playlist rows are the merge's last lock kind, after its song rows, and a playlist save never waits on a song row the merge holds `FOR NO KEY UPDATE`, so the two cannot wait in a cycle.

Because the merge holds every song row `FOR NO KEY UPDATE`, a playlist entry, lyrics or a first play that names no album, stored for a duplicate after the fold's statements ran, commits beside the merge and goes with the duplicate; a play that names the source album waits for the source's row and fails. The duplicate's audio file and file index row stay, and recommendations, listening-session queues and cached renditions that name it are not rewritten.

A `TransactionDeadlockException` becomes `ConflictException` with the reason `merge_busy`, logged with both albums. An album gone under the locks raises `NotFoundException` with the message the entry points answer before the merge, `Source album not found.` or `Target album not found.`. The same album, or albums in two libraries, raise `InvalidArgumentException`, which the route answers with 400 and the console with `INVALID`.

## Covers

`SetCoverHandler` accepts a JPEG, PNG or WebP file of at most 10 MB, detected with Filesystem's `MimeDetectorPortInterface`. The upload route passes the uploaded temporary file; the console passes a path in the container. The handler copies the file into Media storage under a new path for each cover (`images/<owner>/<owner UUID>/<UUID>.<ext>`), so a replacement never overwrites the file it replaces. It saves the image record and the owner in one transaction. If that fails before both saves complete, the handler removes the new file and the old cover stays. It deletes the old cover only after the commit.

`RemoveCoverHandler` clears the owner's cover, saves the owner, then deletes the image. Removing the cover of an owner that has none returns a 404. `CoverImageDiscarder` deletes a cover image that its owner no longer uses: first the image record through `ImagePortInterface`, then the file and its derived files through `StoragePortInterface`. It runs only after the owner's change has committed, and it logs a failure instead of throwing.

## Artist Credits

A credit links an artist to a song or an album (`CreditTarget`) with an `ArtistRole`. In `AddArtistCreditCommand`, `ChangeArtistCreditRoleCommand`, and `RemoveArtistCreditCommand`, the target song or album is named by its 21-character `PublicId`, which `ArtistCreditInput::targetId()` resolves to a `Uuid`. The `ArtistPortInterface` credit methods return `false` instead of throwing, and the handlers turn that into a 404 for an unknown artist, song or album, or an artist without the named credit. `ChangeArtistCreditRoleCommand` names the credit to change by its current role. The current role may be omitted when the artist has a single credit on the target; with several credits, omitting it is a 422. When the new role is one the artist already holds on the target, the changed credit is removed. A credit added by hand stores the artist's name at that moment as its credited name; renaming the artist later leaves it unchanged.

The three credit handlers write in one transaction through `ArtistCreditInput::inTransaction()`, and `ArtistCreditInput::lock()` takes the locks a match apply takes for an artist it credits, in the [catalog lock order](catalog-matching.md#apply): the album's row for an album target (`AlbumPortInterface::lockInIdOrder()`), the import identity keys of the artist's stored name and MusicBrainz ID (`SongPortInterface::lockImportIdentities()`), the artist's row (`ArtistMergePortInterface::lock()`), and the song's row for a song target (`SongPortInterface::lockInIdOrder()`). The artist is taken before the song because the credit's foreign key would otherwise lock the artist's row at the insert, after the song's, the reverse of an apply.

Before the transaction a handler only resolves the public IDs and reads the artist's name and ID for the keys; under the locks it reads the artist and the target again through the refreshing finders and decides on what it finds. An artist, song or album gone by then raises `NotFoundException`. An artist whose name or MusicBrainz ID differs from the one whose keys were taken raises `ConflictException` with the reason `credit_busy`, because taking the new key after the artist's row could close a cycle with an import that reaches the artist by it; a `TransactionDeadlockException` becomes the same conflict.

A change to a song's credits drops `credits` from the song's `matched_fields` (`Song::creditsChangedByHand()`) in the same transaction, so the next retag replaces the credits a match set; adding a credit the song already holds drops nothing. An album keeps no such mark.

`UpdateSongHandler` locks the song's row, reads the song through the refreshing finder and saves the edit in one transaction, so a field edit neither restores a `credits` mark a credit change dropped nor drops a mark an apply set meanwhile. It takes one lock and cannot wait in a cycle.

## Infrastructure

See [Infrastructure](catalog-inventory.md#infrastructure).
