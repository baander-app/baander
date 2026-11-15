# Catalog

The Catalog context manages the core media catalog: artists, albums, songs, movies, videos, and genres. It provides CRUD operations, full-text search via PGroonga, and cover art extraction. This is the largest context by entity count.

## Domain Models

### Aggregate Roots

| Model | Purpose |
|-------|---------|
| `Artist` | Musical artist |
| `Album` | Music album (linked to artist) |
| `Song` | Individual track (linked to album) |
| `Movie` | Film |
| `Video` | Standalone video |
| `Genre` | Genre tag (music and video) |

### Value Objects

| Model | Purpose |
|-------|---------|
| `AlbumType` | Album type classification (album, EP, single, etc.) |
| `ArtistRole` | Artist role on a track (primary artist, featured, etc.) |
| `DiscogsId` | Discogs release identifier |
| `MusicbrainzId` | MusicBrainz identifier |
| `DuplicateGroup` | Group of duplicate albums sharing a normalized title and primary artist |
| `LockedFields` | Lockable-field rules shared by `Album`, `Song`, and `Artist` |
| `GenreReadView` | Read model (`Domain/ReadModel/`) for genre hierarchy and counts |

### Domain Services

| Service | Purpose |
|---------|---------|
| `AlbumDuplicateDetector` | Groups albums by normalized title and primary artist into `DuplicateGroup` instances |
| `TitleNormalizer` | Normalizes album titles for duplicate comparison |

## Commands & Handlers

The API controllers and the console commands dispatch the same commands and queries, so each edit, delete and cover change has one use case. A malformed public ID, an invalid value, or a rejected file raises `InvalidInputException` (HTTP 422, console exit `INVALID`). A missing item or credit raises `NotFoundException` (HTTP 404).

| Command | Handler | Purpose |
|---------|---------|---------|
| `BatchExtractCoversCommand` | `BatchExtractCoversHandler` | Extract cover art from albums (`POST /api/albums/covers/extract` and `app:album:extract-covers`) |
| `UpdateAlbumCommand` | `UpdateAlbumHandler` | Change an album's metadata and field locks (`app:album:update`) |
| `UpdateSongCommand` | `UpdateSongHandler` | Change a song's metadata and field locks (`app:song:update`) |
| `UpdateArtistCommand` | `UpdateArtistHandler` | Change an artist's metadata and field locks (`app:artist:update`) |
| `UpdateMovieCommand` | `UpdateMovieHandler` | Change a movie's title, year or summary (`app:movie:update`) |
| `CreateArtistCommand` | `CreateArtistHandler` | Create an artist that no scan found, such as a featured performer to credit by hand (`app:artist:create`) |
| `AddArtistCreditCommand` | `AddArtistCreditHandler` | Credit an artist on a song or an album with a role; a credit the artist already has stays a single credit (`app:artist:song:add`, `app:artist:album:add`) |
| `ChangeArtistCreditRoleCommand` | `ChangeArtistCreditRoleHandler` | Change the role of one of an artist's credits on a song or an album (`app:artist:song:role`, `app:artist:album:role`) |
| `RemoveArtistCreditCommand` | `RemoveArtistCreditHandler` | Remove every credit an artist has on a song or an album (`app:artist:song:remove`, `app:artist:album:remove`) |
| `SetCoverCommand` | `SetCoverHandler` | Store an image file as the cover of an album or an artist, replacing any cover it has (`app:album:cover:set`, `app:artist:cover:set`) |
| `RemoveCoverCommand` | `RemoveCoverHandler` | Remove an album's or artist's cover and delete the image (`app:album:cover:remove`, `app:artist:cover:remove`) |
| `DeleteAlbumCommand` | `DeleteAlbumHandler` | Delete an album and every song on it, optionally with the audio files and the cover (`app:album:delete`) |
| `DeleteSongCommand` | `DeleteSongHandler` | Delete a song, optionally with its audio file (`app:song:delete`) |
| `DeleteArtistCommand` | `DeleteArtistHandler` | Delete an artist, its credits, favorites, recommendations and cover image; songs and albums stay (`app:artist:delete`) |
| `DeleteMovieCommand` | `DeleteMovieHandler` | Delete a movie and the videos no other movie uses; the video files stay on disk (`app:movie:delete`) |
| `CreateGenreCommand` | `CreateGenreHandler` | Create a genre, optionally below a parent (`app:genre:create`) |
| `UpdateGenreCommand` | `UpdateGenreHandler` | Change a genre's name, slug, parent or MusicBrainz ID (`app:genre:update`) |
| `DeleteGenreCommand` | `DeleteGenreHandler` | Delete a genre; its children become root genres (`app:genre:delete`) |
| `ApplyMatchCommand` | `ApplyMatchHandler` | Apply a MusicBrainz candidate to an album, song or artist (`POST /api/admin/metadata/match/{type}/{publicId}/apply`, `app:metadata:match:apply`), or an automatic album match for the album match queue; see [MusicBrainz Matching](#musicbrainz-matching) |
| `RecoverAlbumMatchQueueCommand` | `RecoverAlbumMatchQueueHandler` | Start the album match queue again when a request is due and no chain of runs holds it; the scheduled job "Recover the album match queue" runs it every 10 minutes |

`FilesDiscoveredHandler` handles Library's `FilesDiscovered` message and imports the files a scan found. It reads the metadata of up to 50 audio files and probes their audio, then writes their songs, artist credits and genres in one transaction; a delete with files cannot claim the library while that transaction runs (see [Library](library.md#media-file-deletion)). When a delete holds the library, the handler puts the files it has not imported back on the queue.

A song's length comes from the audio probe, never from its tags. A song is credited to the artist its tags name, under a credited name: the first artist value names the artist, and the credited name joins every value with "; ", so the song shows the name it was tagged with even when the artist's own name differs. A file without an artist value is imported without a credit. Inside the transaction, after the library and file-index locks and before any song is written, one `ArtistIdentityResolverInterface::resolveReporting()` call finds or creates every artist the batch credits, and reports the artists it created with a MusicBrainz ID and those that took on an ID they did not hold. It takes a transaction-scoped advisory lock on each tagged name and on the MusicBrainz ID of each identity that has one, sorted and one key per statement, and resolves each identity by its MusicBrainz artist ID when the file carries exactly one valid ID, otherwise by its exact name; `ArtistIdentityIndex` states the tie-breaks. A file that names its artists one by one, in its `ARTISTS` values or else its artist values, and gives exactly one valid MusicBrainz artist ID per name credits each name by the ID in the same position: the first as primary under the joined artist values, every other one as featured under its own name. Imports running at once therefore never create two artists for one ID or, without an ID, for one name. The batch's genre locks sort after the artist locks and are taken after them.

A file whose hash a stored song holds never becomes a new song. At the song's path, or renamed within the song's directory while the old file is gone, it repairs that song, as on a rescan: the song moves to a renamed file's path, takes its length from the probe (a failed probe keeps the stored length) and, when it has no artist credit of any role, takes its tagged credit. A file elsewhere is left alone. A file with a hash no song holds, at the path of a song in the same library, is that song retagged: the song keeps its ID, album, MusicBrainz ID, lyrics and genres, and takes the new hash, technical fields, length and unlocked tag fields. A title, track, disc or year that the song's `matched_fields` lists changes only when the tags carry a value for it, and then leaves the list; a title or track number taken from the file name does not count. The new tags' credits replace the song's artist credits, unless `matched_fields` lists `credits` and the tags give the primary artist no MusicBrainz ID: the match's credits then stay, and the batch neither finds nor creates the tagged artist. When a batch holds retagged songs, its transaction first takes every artist and genre key the batch may write in one sorted pass, then locks the retagged songs' rows `FOR NO KEY UPDATE` in ID order, the order a match apply takes, and reads those songs again before it decides. The song stays in its album, and an album is created only for new songs. After the message's last batch, each album it touched that has no artist credit gets one, in a transaction that locks the album row `FOR NO KEY UPDATE`: from the first album-artist tag among the album's files in the message, in path order, or else among the files of its stored songs; else to the artist every song of the album is credited to; else none. An album that has a credit of any role keeps it. Ingest then asks `AlbumMatchRequestInterface::requestAfterImport()` for automatic MusicBrainz matches of the albums the message touched (see [Album match queue](#album-match-queue)); when that call throws, ingest logs the error and carries on. Right after each batch commits, beside its lyrics request, ingest passes the artists that batch's resolver call reported to `ArtistSyncRequestInterface::requestAfterImport()`, which queues their [artist sync](#artist-sync) while `metadata.auto_sync` is on, so a later batch's failure keeps them. An album artist that only the album credit after the last batch creates is not reported; the next admin sync fills it.

### Queries

| Query | Handler | Purpose |
|-------|---------|---------|
| `GetAlbumDeletePreviewQuery` | `GetAlbumDeletePreviewHandler` | What deleting an album would remove: its songs, their total size, the cover and the playlists that lose songs; with `deleteFiles`, each song file checked as the delete would check it (`GET /api/admin/albums/{publicId}/delete-preview`, `app:album:delete --dry-run`) |
| `GetSongDeletePreviewQuery` | `GetSongDeletePreviewHandler` | What deleting a song would remove: the song, its file and its playlist entries; with `deleteFile`, the file checked as the delete would check it (`GET /api/admin/songs/{publicId}/delete-preview`, `app:song:delete --dry-run`) |
| `SearchMatchCandidatesQuery` | `SearchMatchCandidatesHandler` | Up to five scored MusicBrainz candidates for an album, song or artist (`GET /api/admin/metadata/match/{type}/{publicId}/candidates`, `app:metadata:match:search`) |
| `CompareMatchQuery` | `CompareMatchHandler` | What matching an item with one candidate would set, row by row (`GET /api/admin/metadata/match/{type}/{publicId}/comparison`, `app:metadata:match:compare`) |
| `ListMatchReviewsQuery` | `ListMatchReviewsHandler` | The albums that need a match review, most recently checked first (`GET /api/admin/metadata/match/reviews`, `app:metadata:match:reviews`) |

The previews change nothing. They read playlists through Playlist's `PlaylistDeletionPreviewPortInterface` and check files through Library's `LibraryMediaFilesInterface::inspect()`, which returns a verdict for each path in a `FileDeletionPreview`.

## Field Locks

`Album`, `Song` and `Artist` each have a list of lockable fields, and they share the lock rules in `LockedFields`. A locked field keeps its value: `updateMetadata()` rejects the whole edit when it gives any locked field a value. Every lockable field is enforced, not only the title or name. Locking or unlocking a field that is not lockable is rejected too.

| Aggregate | Lockable fields |
|-----------|-----------------|
| `Album` | `title`, `type`, `year`, `label`, `catalogNumber`, `barcode`, `country`, `language`, `disambiguation`, `annotation` |
| `Song` | `title`, `track`, `disc`, `year`, `comment`, `lyrics`, `explicit` |
| `Artist` | `name`, `country`, `gender`, `type`, `lifeSpanBegin`, `lifeSpanEnd`, `disambiguation`, `sortName`, `biography` |

An update command takes the edit, a complete `lockedFields` list that replaces the current one, and fields to `lock` and `unlock`. `CatalogInput::lockChanges()` turns these into fields to unlock and fields to lock. The handler applies the unlocks, then the edit, then the locks, and saves only when all three are accepted. One request can therefore unlock a field and change it, or change a field and lock it. A field in both lists ends locked. On the console, `--lock` and `--unlock` can be repeated and accept comma-separated field names.

Automatic updates skip locked fields rather than fail. An [artist sync](#artist-sync) fills only empty fields that are not locked. A MusicBrainz match shows a locked field in its comparison but never ticks or writes it, and a manual apply whose ticked row has become locked answers 409 `match_stale`. A retag leaves a locked song field as it is. `AlbumMergeService` keeps the target album's value for a locked field, even when that value is empty. After the merge, the target also locks every field that was locked on the source album.

## Deletes

Every delete returns a `CatalogDeletionResult`: the rows deleted by kind and, when the delete included audio files, the files removed, already missing, and left on disk.

`AlbumPortInterface::delete()` and `SongPortInterface::delete()` delete rows only; they never touch audio files. A delete with files goes through Library's `LibraryMediaFilesInterface` (see [Library](library.md#media-file-deletion)). Before anything changes, `DeleteAlbumHandler` and `DeleteSongHandler` call `prepareDeletion()` for the song paths. It refuses the whole request with a 409 while a scan holds the library, when the library root is not available, or when the server cannot write a song's directory, and with a 422 when a path lies outside the library root. The handlers then delete the catalog rows and the file index rows in one transaction through `TransactionPortInterface`, and unlink the files after the commit. The next scan imports any file left on disk again. When a file is left, the console command exits with `FAILURE`.

`DeleteAlbumHandler` deletes the album's cover image after the commit unless `deleteCover` is false, so a rollback leaves the album with its cover. `DeleteArtistHandler` deletes the artist, then its cover image. `DeleteMovieHandler` deletes the movie and, in the same transaction, the videos that no other movie links (`VideoRepositoryInterface::deleteUnlinked()`).

The public delete routes pass only the public ID, so they keep the audio files and delete the album cover. `DELETE /api/admin/albums/{publicId}` takes `deleteFiles` and `deleteCover`, and `DELETE /api/admin/songs/{publicId}` takes `deleteFile`. On the console, `--delete-files` requires `--force` even on a terminal, `app:album:delete --keep-cover` keeps the cover, and `--dry-run` prints the preview without changing anything.

## Album Merges

`AlbumMergeService` implements `AlbumMergePortInterface`, which `POST /api/albums/merge` and `app:album:merge` call, and merges in one transaction under the [catalog lock order](#apply). `AlbumPortInterface::lockForMerge()` locks both album rows in ID order, the source `FOR UPDATE` and the target `FOR NO KEY UPDATE`; one statement then reads the song IDs of both albums, `SongPortInterface::lockInIdOrder()` locks those rows `FOR NO KEY UPDATE`, and the albums and songs are read through the refreshing finders. Before the transaction the merge reads only the two titles a conflict message names, so it acts on what an apply, a retag or a hand change that held a row first committed, and it moves every song the source holds, without a cap. With the source's row held `FOR UPDATE`, a song an import inserts into the source waits on `fk_songs_album_id` and fails once the merge commits, instead of being deleted with the source by the cascade.

The merge moves every source song that is no duplicate in one flush. A source song is a duplicate when its hash is set and a target song holds it, and it duplicates the target's oldest song with that hash. Before any duplicate is deleted, the merge folds what users hold on it into the kept song: the Playlist port first locks, in ID order, the rows of every playlist that holds a duplicate and raises their `persistence_version`, so a save that read one of them earlier answers its existing 409; then each duplicate, in ID order, is folded through the Lyrics, Activity and Favorites ports. The duplicates are deleted with their credits and genres, and each removal is logged with the song that holds its file. The source's favorites and plays then move to the target, the target takes the metadata it lacks and locks every field the source had locked, and the source is deleted without its cover. After the commit the source's cover is discarded and a match of the target is requested (see [Album match queue](#album-match-queue)); a failure of either is logged and the merge stands.

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

The three credit handlers write in one transaction through `ArtistCreditInput::inTransaction()`, and `ArtistCreditInput::lock()` takes the locks a match apply takes for an artist it credits, in the [catalog lock order](#apply): the album's row for an album target (`AlbumPortInterface::lockInIdOrder()`), the import identity keys of the artist's stored name and MusicBrainz ID (`SongPortInterface::lockImportIdentities()`), the artist's row (`ArtistMergePortInterface::lock()`), and the song's row for a song target (`SongPortInterface::lockInIdOrder()`). The artist is taken before the song because the credit's foreign key would otherwise lock the artist's row at the insert, after the song's, the reverse of an apply. Before the transaction a handler only resolves the public IDs and reads the artist's name and ID for the keys; under the locks it reads the artist and the target again through the refreshing finders and decides on what it finds. An artist, song or album gone by then raises `NotFoundException`. An artist whose name or MusicBrainz ID differs from the one whose keys were taken raises `ConflictException` with the reason `credit_busy`, because taking the new key after the artist's row could close a cycle with an import that reaches the artist by it; a `TransactionDeadlockException` becomes the same conflict. A change to a song's credits drops `credits` from the song's `matched_fields` (`Song::creditsChangedByHand()`) in the same transaction, so the next retag replaces the credits a match set; adding a credit the song already holds drops nothing. An album keeps no such mark.

`UpdateSongHandler` locks the song's row, reads the song through the refreshing finder and saves the edit in one transaction, so a field edit neither restores a `credits` mark a credit change dropped nor drops a mark an apply set meanwhile. It takes one lock and cannot wait in a cycle.

## MusicBrainz Matching

Catalog matches albums, songs and artists with MusicBrainz, because every write a match makes is a Catalog write. It reaches MusicBrainz and the Cover Art Archive only through Metadata's `MusicBrainzLookupInterface`, whose calls each wait for a turn under the shared `musicbrainz` rate limit. An album matches a release, one edition, and the release's group fills `albums.mbid`; a song matches a recording and an artist an artist. [External APIs](../../part-1-operator-guide/media/external-apis.md#musicbrainz-matching) describes matching for operators.

### Candidates and comparison

`SearchMatchCandidatesHandler` reads the item before it asks MusicBrainz, so an unknown item spends no request. An album runs a release search on its title and primary artist, ranks the hits by title, artist and track count, and looks up the best five with their tracks; a song runs one recording search on its title and the artist text it shows; an artist runs one artist search on its name. `AlbumReleaseSearch` searches an album's own title once more when it finds no release and ends in a comma-separated list in square brackets, as in "Ten Thousand Fists [Reprise Records,9362-49433-2,EU]": MusicBrainz matches a searched title only as a whole phrase, so `ReleaseSearchTitle` cuts the list for the second search and leaves other bracketed text, which can name another release. The candidates are scored against the title that found them, which the answer names as the title searched, and the stored title never changes. A title the admin typed is searched as typed, once. `CandidateScorer` scores each candidate from 0 to 100 after folding case, punctuation and featuring joiners: a looked-up release by title 25, artist 20, track count 20 and track lengths 35; a recording by title 45, artist 35 and length 20; an artist by its name, or by name 80 and disambiguation 20. `TrackPairing` pairs local tracks with release tracks by disc and position, then by a length within 3 seconds that fits one track only. `MusicBrainzAnswer` turns an outage into a 503 with the reason `musicbrainz_unavailable` and a refused request into a 409 with `musicbrainz_rejected`. A 404 of the candidate search, the comparison and the apply carries its cause in `details.reason`: `item_not_found` for an unknown or deleted item, and `candidate_not_found` when MusicBrainz has no candidate of the ID the comparison or the apply names.

`CompareMatchHandler` looks the candidate up once and builds a `MatchComparison` with `MatchComparisonBuilder`, which reads the catalog through `MatchCatalogReadInterface` and changes nothing. The comparison holds three kinds of rows:

- Field rows (`MatchFieldRow`): MusicBrainz IDs and empty fields are ticked, a field holding a different value is not, and a locked field is shown but never ticked.
- Credits rows (`MatchCreditsRow`), ticked when they change something: MusicBrainz's first credited artist becomes primary and the others featured. The primary credit keeps the song's or album's own credited name, and a featured credit takes the name MusicBrainz credits. Only primary and featured credits are replaced; credits of other roles stay. Each credited artist resolves as an import resolves it, so a row names the local artist, or the new one, each credit would go to.
- Artist actions (`MatchArtistAction`), ticked: an artist the ticked credits rows leave without any credit merges into the artist that takes over most of its primary credits, or is removed when it held none. An artist match whose ID another artist holds becomes a merge into that holder.

### Apply

`ApplyMatchHandler` handles `ApplyMatchCommand` and writes a match in one transaction, or nothing. Every MusicBrainz call runs before the transaction: a manual apply looks the candidate up again by its ID and compares it with the item as stored, never with values the client sent, and a front cover the match sets is downloaded and stored as a file (`MatchCovers`). When the Cover Art Archive refuses the cover, with a client error other than 404, 408 and 429, a manual apply that ticked it answers 409 `cover_refused` before its transaction and writes nothing. The transaction then takes its locks in the order every catalog writer takes them: the album row `FOR NO KEY UPDATE`; every artist and genre import key in one sorted pass; the rows of the artists the match may credit, merge, merge into or remove, `FOR UPDATE` through `ArtistMergePortInterface::lock()`; the rows of the songs it writes, `FOR NO KEY UPDATE` in ID order. Under those locks it reads the item again and builds the comparison once more. A manual apply whose ticked rows no longer show what the admin saw, or whose comparison now needs a lock it did not take, writes nothing and answers 409 `match_stale`; a deadlock answers 409 `match_busy`.

The apply writes fields, IDs and the credits that change, records on each song the fields a match set in `songs.matched_fields` (`title`, `track`, `disc`, `year`, and `credits` when it set the credits), and records an album's release in `albums.release_mbid` with the time in `albums.matched_at`. It sets `songs.match_checked_at` on every song the candidate was compared with, paired with a track or not, under the song row locks it took for them; a song apply sets it on its song. The column is bookkeeping, which the live-update capture does not watch. The apply then merges or removes the artists the new credits left without any. A manual album apply also deletes the album's match state, which takes it off the review list. Covers that merges, removals or a replaced cover free are discarded after the commit. A manual album apply that leaves the album with a release then calls `AlbumMatchRequestInterface::requestAfterImport()`: a song an import committed after the apply read the album was not compared, and the request that import made went with the match state. The call requests only while `metadata.auto_sync` is on and only when a song of the album has a null `match_checked_at`; a failure is logged and the apply stands. An album left without a release is not requested, so a search does not match over the admin's choice. Last, an album or song apply passes the artists its resolver call created with a MusicBrainz ID or gave one to `ArtistSyncRequestInterface::requestAfterMatch()`, which queues their [artist sync](#artist-sync) whatever the setting says; an artist apply queues none.

`ApplyMatchCommand::automatic()` carries the candidate the album match queue decided on. An automatic apply writes no admin ticks: it fills every MusicBrainz ID, field and cover that is empty and unlocked, writes every credits row that changes, and applies the merges and removals those credits cause. An apply to an album that held the release when the matcher read it (`releaseIdSeen`) matches the album's new songs and leaves the album's own credits row out (`MatchTicks::automatic()` with `ofMatchedAlbum`), and with it every merge or removal that needs that row, so credits an admin kept or changed by hand stay; it still fills the album's empty fields and a missing cover. Under its locks it also checks the songs the candidate compared: when one carries `match_checked_at` by then, because another match compared it, or when the songs as read under the locks pair with the release differently within the length tolerance (`TrackPairs::withinLengthTolerance()`), for example after a retag, it writes nothing and answers stale, and the queue retries the album after 15 minutes. A cover the Cover Art Archive refuses does not stop it: the match is applied without the cover, and its result and its log entry say that the cover was refused. It reports to the queue how it ended.

`ArtistMergePortInterface`, implemented by `ArtistMergeService`, runs inside the caller's transaction after the caller took the import keys and called `lock()`. A merge moves the source artist's credits, keeping the stronger role where both artists hold a song or album, and its favorites, play history and artist recommendations, through ports of Favorites, Activity and Recommendation, dropping rows that would collide; the target keeps its cover, else takes the source's. A removal deletes the artist with its credits, favorites and recommendations, and its play history stays without an artist. Both write through statements and release the managed entities they make stale.

### Album match queue

`album_match_states` holds at most one row per album. A `requested` row carries the request's `source`, `ingest` or `admin`, and its `due_at`; a `needs_review` row carries the review's `reason`, `best_score`, `checked_song_count` and `checked_at`. A `requested` row that a run holds also carries the run's match claim, `claim_id` and `claim_expires_at`, and in `next_due_at` the due time of a request made during the claim; `chk_album_match_states_claim` allows a claim only on a `requested` row and `next_due_at` only beside a claim. The mark lives in its own table rather than an album column because the album capture trigger would publish it to every library reader; its rows publish live changes to admins only. The capture leaves the three claim columns out, so a claim publishes no live update.

`AlbumMatchRequester` implements `AlbumMatchRequestInterface`. `FilesDiscoveredHandler` calls `requestAfterImport()` for the albums a message touched once their songs are committed; it does nothing while `metadata.auto_sync` is off, and its requests fall due after 300 seconds, so that an album's other discs land first. `RequestAlbumMatchHandler` calls `requestForAdmin()` for each `SyncAlbumMessage` the library and genre syncs dispatch; that request falls due at once whatever the setting says. Both skip an album with nothing to match: one marked for review whose song count is still the one the matcher checked, and a matched album whose songs all carry `match_checked_at`. A request for an album that already waits keeps the earlier due time, and an admin request stays an admin request. `AlbumMergeService` also calls `requestAfterImport()`, for the target album once the merge has committed: `Song::moveToAlbum()` clears `match_checked_at` on each song the merge moves, and the song keeps its recording ID and the fields a match set. That request is best-effort; a failure is logged and the merge stands. `ApplyMatchHandler` calls it after a manual album apply, as [Apply](#apply) describes. A request for an album a run holds waits beside the claim instead: it leaves `due_at` and the claim alone, keeps in `next_due_at` the earliest due time asked for meanwhile, and queues no run, because the run that holds the album brings one when it ends its claim.

`DrainAlbumMatchesMessage` runs on `async`, one album per message, and each run queues the next, so the runs form a chain. `AlbumMatchQueueChain` keeps one chain: the message carries the chain's token, and the application cache holds the current token for as long as the chain's next run needs, plus a margin. A run without a token starts a chain in place of any other, and a run whose chain was replaced ends without matching or queuing a successor. A request that brings an album's due time forward dispatches a run without a token, delayed until the request falls due, unless another request falls due at or before it. That earlier request's run, or the chain waiting for the earliest due time, reaches this one as well. A request parked for a later retry counts only when it falls due first, so it holds back no other album. A request a run holds counts from its claim's expiry. Each request writes its row and then looks for others, while a run removes or marks its album and then looks for what is left, so of a request and a finishing run that overlap, at least one sees the other's write. When nothing is left to wait for, the chain releases the queue.

`DrainAlbumMatchesHandler` claims the request that has been due longest in one statement, `FOR UPDATE SKIP LOCKED`: it writes a claim ID of its own and an expiry 15 minutes ahead on the database clock (`clock_timestamp()`), and never touches `due_at`. While the claim is live no other run takes the request. The run ends its claim by naming the album and the claim ID, each outcome in one statement that also decides on `next_due_at`: when a request waited there, the album waits for a match again from that request's due time, whatever the match found. Only an outage drops that request, because the album keeps its earlier due time. A claim that no longer holds the album, because another run took the request after the claim lapsed, a request ended the lapsed claim, or the state was removed, changes nothing. A run that died leaves its album held until the claim lapses, and another run then matches the album again. A song committed while a match runs was not compared, so it keeps a null `match_checked_at` and a later run pairs it. Runs of an old and a new chain can overlap until the old chain's next run ends, and every MusicBrainz call still waits for the shared rate limit. Live claims keep the runs on different albums. Each apply takes the album's row lock and checks the album's release again before it writes.

The run removes every import request when it claims one while `metadata.auto_sync` is off. Otherwise `AutomaticAlbumMatcher` matches the album. An album without a release runs the album search a candidate list runs for the album's own title, the second search included, and `MatchConfidence` decides: the best release, together with its twin editions that score within the lead and agree on every track's title, artist credit and length in track order, however their discs split the tracks, is confident when it has the album's track count, pairs every track within 3 seconds, scores at least 90 and leads the next release by at least 10. The candidate stands for the twin that keeps the most songs at their own disc and number, the earliest released of those: it is the release recorded, and its tracks give each song its disc and number. A recording ID is written where every twin has the same one at that place in the track order. A matched album looks up only its stored release and pairs against it the songs whose `match_checked_at` is null. Such a song keeps its pair only when both lengths are known and differ by at most 3 seconds; any other is left unpaired, so the apply marks it as compared and writes nothing to it. When the album is matched, or no longer exists, its request is removed; an album that needs review is marked with the reason, the best score and the song count checked. Removing the request is one `MERGE`, because a request can commit between the statement's snapshot and its write: under READ COMMITTED `MERGE` evaluates its `WHEN` clauses again on the row as that request left it and keeps the album waiting, where a `DELETE` and an `UPDATE` in one statement leave the row claimed until the claim lapses (`AlbumMatchClaimRaceTest` runs the race in two sessions). An apply that met changed rows (`match_stale`) or a deadlock gives back only that album's request, due again in 15 minutes, and a MusicBrainz refusal (`musicbrainz_rejected`) during the search or a lookup gives it back due in 6 hours; the queue goes on with the next album. A real outage, where MusicBrainz or the Cover Art Archive gives no usable answer, gives the request back with its due time and pauses the whole queue for 5, 15 and 60 minutes, then hourly. A cover the Cover Art Archive refuses during the apply is no outage: the album counts as matched. A matcher failure is logged, and its request is given back due an hour later. While requests wait, the run queues the next one a second later, or when the next request falls due.

A run lost to a failed dispatch, or to a message that used up its retries, would leave every request waiting. The scheduled job "Recover the album match queue", seeded by a migration with the expression `*/10 * * * *`, runs `RecoverAlbumMatchQueueCommand`: when a request is due and no chain holds the queue, `RecoverAlbumMatchQueueHandler` logs a warning and dispatches a run without a token, which starts a new chain. A chain that was only late gives way to the new one at its next run.

`ListMatchReviewsHandler` pages through the `needs_review` rows with `MatchReviewReadInterface`, one SQL statement per page, the most recently checked first; its cursor holds the check time and the album's public ID.

### Artist sync

An artist sync fills an artist's empty, unlocked details from MusicBrainz, looked up by the MusicBrainz ID the artist holds: sort name, type, country, disambiguation and the two ends of its life span. It never writes the name or the ID, and it never looks an artist up by name. Metadata's `SyncArtistMessage` carries one artist, the run of the admin sync that queued it or none, and the outages it met in a row; `SyncArtistsMessage` is the artist part of an admin sync. Both are routed on `async` (`metadata.sync_artist` and `metadata.sync_artists`) and handled in Catalog.

Three producers queue `SyncArtistMessage` through `ArtistSyncQueue`:

- `ApplyMatchHandler`, after an album or song apply commits, through `ArtistSyncRequestInterface::requestAfterMatch()`, whatever `metadata.auto_sync` says.
- `FilesDiscoveredHandler`, after each batch commits, through `ArtistSyncRequestInterface::requestAfterImport()`, only while `metadata.auto_sync` is on.
- `QueueArtistSyncsHandler`, which handles `SyncArtistsMessage`: it pages through the artists with a MusicBrainz ID and an empty field among the six (`ArtistPortInterface::identifiedWithMissingDetailsAfter()`), once for the catalog, and checks the job's cancellation before each artist.

`ArtistSyncRequester` implements the interface. `requestAfterImport()` reads the setting when it has an artist to queue, and `requestAfterMatch()` does not read it. The requester runs outside any transaction and reads no catalog row, so it takes no lock; a failure is logged with the artists it did not queue and changes nothing the apply or batch wrote. The resolver's report comes from its own write (`ArtistIdentityResolverInterface::resolveReporting()`), not from a read after the commit, which could not tell an ID the transaction gave from one the artist already held.

`QueuedArtistSyncsInterface`, implemented by `RedisQueuedArtistSyncs` under the key prefix `catalog:artist_sync`, keeps four kinds of expiring entries: a queued mark per artist, so no producer queues an artist twice; an answer mark per artist and MusicBrainz ID for 30 days, so an artist given another ID is asked about the new one; the run records of admin syncs; and one shared due time. One Lua script checks the marks, sets the artist's mark and moves the due time, so each fill falls due `ArtistSyncQueue::SPACING_MS` (2,000 ms) after the latest one reserved, whoever queued it, or at once when none is pending. A queued mark lasts until a day after its fill is due. A lost store asks every artist again at its next producer, which costs requests and writes nothing wrong.

`SyncArtistHandler` asks MusicBrainz only for an artist that holds an ID, misses an unlocked detail, and has no answer mark for that ID, with `MusicBrainzSlotWait::Unbounded`. Then, in one transaction, it locks the artist's row through `ArtistMergePortInterface::lock()`, reads the artist again, and writes only when the artist exists and still holds the ID that was looked up. The fields come from `MatchTicks::automatic()` over `MatchComparisonBuilder::artist()`, limited to the six, and `ArtistMatchWriter`, the writer an artist match uses, writes them; the comparison's merge actions are ignored, so a sync never merges an artist, and nothing is saved when nothing is filled. The row is the handler's only lock, so it cannot wait in a cycle. Every expected outcome ends the message with a log entry and no exception, so a sync adds no failed job to the metadata status:

| MusicBrainz answer | What the handler does |
|--------------------|-----------------------|
| The artist with the stored ID | Fills, then sets the answer mark once the write committed; a write that fails sets none, so the retry asks again |
| 404 | Writes nothing, sets the answer mark |
| `MusicBrainzMerged`, or a record of another ID | Writes nothing and merges nothing, logs both IDs, keeps the stored ID, sets the answer mark |
| `MusicBrainzRejected` | Writes nothing and sets no mark, because a refusal can end; the next producer that queues the artist asks again |
| `MusicBrainzUnavailable` | Queues the same fill again with its run, at the shared pace and no sooner than 5, 15 and then 60 minutes, renewing the queued mark; at the fourth outage in a row it ends, and the next admin sync asks again |

A fill whose run is recorded as cancelled ends without a request. `QueueArtistSyncsHandler` records its run cancelled when its job is cancelled while it queues, and records the run against its job when it finishes, so cancelling the finished job through the job monitor (`QueuedJobWorkInterface`) cancels the run until a day after its last fill was due. Cancelling a run sets the shared due time back to now, since the run's fills still queued make no request; a fill another producer queued meanwhile may then share a two-second step with a new one, which the shared MusicBrainz limit still spaces a second apart.

MusicBrainz answers the lookup of an artist ID it merged into another artist with a permanent redirect to the surviving artist's lookup. `MusicBrainzAdapter::fetchArtist()` does not follow it and answers `MusicBrainzMerged`, naming both IDs. `MusicBrainzAnswer` turns that answer into the 404 `candidate_not_found` of the artist comparison and apply, so an admin re-identifies such an artist by searching in the match dialog.

## Ports

| Port | Purpose |
|------|---------|
| `AlbumPortInterface` | Album CRUD operations; `delete()` removes the album, its songs through the database, and optionally its cover, but never audio files |
| `AlbumDuplicatePortInterface` | Duplicate album detection and grouping |
| `AlbumMergePortInterface` | Merges one album into another in one transaction (`AlbumMergeService`); see [Album Merges](#album-merges) |
| `ArtistPortInterface` | Artist CRUD operations and song and album credits (add, remove, list roles, change role); each credit method reports whether it found its target |
| `ArtistIdentityResolverInterface` | Finds or creates the artists an import or a match credits, under advisory locks on their names and MusicBrainz IDs, and with `resolveReporting()` names the artists it created with an ID or gave one (`ArtistIdentityResolver`) |
| `ArtistMergePortInterface` | Merges one artist into another or removes one, inside the caller's transaction (`ArtistMergeService`) |
| `AlbumMatchRequestInterface` | Requests automatic MusicBrainz matches of albums in the album match queue, after an import or for an admin sync (`AlbumMatchRequester`) |
| `ArtistSyncRequestInterface` | Queues the artist syncs of the artists an import batch or a match apply identified, after its commit (`ArtistSyncRequester`) |
| `QueuedArtistSyncsInterface` | The queued and answer marks, the admin sync runs and the shared due time of artist syncs, in Redis (`RedisQueuedArtistSyncs`) |
| `GenrePortInterface` | Genre CRUD operations |
| `MetadataContentReaderPortInterface` | Read metadata content for catalog entities |
| `MoviePortInterface` | Movie CRUD operations |
| `SongPortInterface` | Song CRUD operations; `delete()` never touches the audio file |
| `SongLookupInterface` | Song lookups published to Lyrics and Playlist |
| `VideoPortInterface` | Video lookup and source metadata operations (`VideoService`) |
| `SongAvailabilityPortInterface` | Batch file-availability checks for songs (`SongAvailability`) |

## Domain Events

| Event | Trigger |
|-------|---------|
| `AlbumCreated` | Album created |
| `MetadataSynced` | Metadata enrichment completed |
| `SongMetadataUpdated` | Song metadata updated |

## API Endpoints

All endpoints are prefixed with `/api`. Every route that writes requires `ROLE_ADMIN`. The list routes search with PGroonga when given a `q` parameter.

### Artists

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/artists` | List or search artists (page-paginated) |
| POST | `/api/artists` | Create artist |
| GET | `/api/artists/{publicId}` | Single artist |
| PATCH | `/api/artists/{publicId}` | Update artist |
| DELETE | `/api/artists/{publicId}` | Delete artist |
| POST | `/api/artists/{publicId}/songs` | Credit the artist on a song |
| PATCH | `/api/artists/{publicId}/songs/{songId}` | Change the role of a song credit |
| DELETE | `/api/artists/{publicId}/songs/{songId}` | Remove the artist's credits on a song |
| POST | `/api/artists/{publicId}/albums` | Credit the artist on an album |
| PATCH | `/api/artists/{publicId}/albums/{albumId}` | Change the role of an album credit |
| DELETE | `/api/artists/{publicId}/albums/{albumId}` | Remove the artist's credits on an album |
| POST | `/api/artists/{publicId}/cover` | Upload artist cover |
| DELETE | `/api/artists/{publicId}/cover` | Delete artist cover |

### Albums

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/albums` | List or search albums (page-paginated) |
| GET | `/api/albums/{publicId}` | Single album |
| PATCH | `/api/albums/{publicId}` | Update album |
| DELETE | `/api/albums/{publicId}` | Delete album and its songs |
| GET | `/api/albums/{publicId}/duplicates` | Duplicate groups containing the album |
| POST | `/api/albums/merge` | Merge a source album into a target album |
| POST | `/api/albums/covers/extract` | Queue embedded cover extraction for albums without a cover |
| GET | `/api/albums/{publicId}/cover` | Album cover image |
| POST | `/api/albums/{publicId}/cover` | Upload album cover |
| DELETE | `/api/albums/{publicId}/cover` | Delete album cover |

### Songs

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/songs` | List or search songs (cursor-paginated) |
| GET | `/api/songs/{publicId}` | Single song |
| PATCH | `/api/songs/{publicId}` | Update song |
| DELETE | `/api/songs/{publicId}` | Delete song |

### Movies

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/movies` | List or search movies (page-paginated) |
| GET | `/api/movies/{publicId}` | Single movie |
| PATCH | `/api/movies/{publicId}` | Update movie |
| DELETE | `/api/movies/{publicId}` | Delete movie and its unshared videos |

### Genres

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/genres` | List genres |
| POST | `/api/genres` | Create genre (ROLE_ADMIN) |
| GET | `/api/genres/{slug}` | Single genre |
| PATCH | `/api/genres/{slug}` | Update genre (ROLE_ADMIN) |
| DELETE | `/api/genres/{slug}` | Delete genre (ROLE_ADMIN) |
| POST | `/api/genres/{slug}/songs` | Assign the genre to a song (ROLE_ADMIN) |
| DELETE | `/api/genres/{slug}/songs/{songId}` | Remove the genre from a song (ROLE_ADMIN) |
| POST | `/api/genres/{slug}/albums` | Assign the genre to an album (ROLE_ADMIN) |
| DELETE | `/api/genres/{slug}/albums/{albumId}` | Remove the genre from an album (ROLE_ADMIN) |

### Admin — Duplicate Albums

Routes under `/api/admin/albums` (controller `AlbumDuplicateController`, gated `ROLE_ADMIN`).

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/admin/albums/duplicates` | List duplicate album groups for a library |

### Admin — MusicBrainz Matching

Routes under `/api/admin/metadata/match` (controller `MusicBrainzMatchController`, gated `ROLE_ADMIN`). `{type}` is `album`, `song` or `artist`. Each route has a console counterpart.

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/admin/metadata/match/{type}/{publicId}/candidates` | Scored MusicBrainz candidates, best first; `title` and `artist` refine the search |
| GET | `/api/admin/metadata/match/{type}/{publicId}/comparison` | Compare the item with the candidate `candidate` names |
| POST | `/api/admin/metadata/match/{type}/{publicId}/apply` | Apply the ticked rows of the comparison |
| GET | `/api/admin/metadata/match/reviews` | Albums that need a match review, cursor-paginated |

The album resource carries `mbid` (the release group), `releaseMbid` (the matched release) and `lockedFields`, the artist resource `mbid` and `lockedFields`, and the song resource `mbid` (the recording).

### Admin — Deletes With Files

Routes under `/api/admin/albums` and `/api/admin/songs` (controllers `AdminAlbumController` and `AdminSongController`, gated `ROLE_ADMIN`).

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/admin/albums/{publicId}/delete-preview` | Preview an album delete; `deleteFiles` checks each song file |
| DELETE | `/api/admin/albums/{publicId}` | Delete an album; `deleteFiles` deletes the song files, `deleteCover` (default true) the cover |
| GET | `/api/admin/songs/{publicId}/delete-preview` | Preview a song delete; `deleteFile` checks the file |
| DELETE | `/api/admin/songs/{publicId}` | Delete a song; `deleteFile` deletes its audio file |

## Cross-Context Relationships

| Direction | Context | Details |
|-----------|---------|---------|
| Depends on | Shared | `Uuid`, `PublicId`, `CursorPage`, `PgroongaSearchTrait`, `Searchable`, `TransactionPortInterface` |
| Depends on | Library | `LibraryMediaFilesInterface` checks and deletes audio files for album and song deletes and their previews; `CatalogStatsQuery` implements `LibraryContentStatsInterface` |
| Depends on | Media | `ImagePortInterface` and `StoragePortInterface` store and delete cover images |
| Depends on | Filesystem | `MimeDetectorPortInterface` checks cover files |
| Depends on | Playlist | `PlaylistDeletionPreviewPortInterface` names the playlists a delete affects; `AlbumMergeService` folds a duplicate song's playlist entries through `AlbumPlaylistMergePortInterface` (the `Playlist Album Merge Contract` layer) |
| Depends on | Lyrics | `FilesDiscoveredHandler` requests lyrics fetches through `LyricsFetchRequestInterface`; `AlbumMergeService` folds a duplicate song's lyrics through `AlbumLyricsMergePortInterface` (the `Lyrics Album Merge Contract` layer) |
| Depends on | Metadata | `FilesDiscoveredHandler` dispatches `ExtractAlbumCoverCommand`. Matching searches and looks up MusicBrainz through `MusicBrainzLookupInterface` (the `Metadata MusicBrainz Lookup Contract` Deptrac layer); `RequestAlbumMatchHandler` handles `SyncAlbumMessage`, `QueueArtistSyncsHandler` handles `SyncArtistsMessage` and `SyncArtistHandler` handles `SyncArtistMessage`, and the album match queue and `ArtistSyncRequester` read `metadata.auto_sync` from `MetadataSettingDefinitions` (the `Metadata Sync Contract` layer) |
| Depends on | Favorites, Activity, Recommendation | `ArtistMergeService` moves or deletes an artist's favorites, play history and recommendations through `ArtistFavoriteMergePortInterface`, `ArtistActivityMergePortInterface` and `ArtistRecommendationMergePortInterface`; `AlbumMergeService` moves a merged album's favorites and plays, and folds a duplicate song's, through `AlbumFavoriteMergePortInterface` and `AlbumActivityMergePortInterface` |
| Depends on | Transcode | `FilesDiscoveredHandler` probes audio and video files through `AudioProbePortInterface` and `VideoProbePortInterface` |
| Depended on by | Activity | References songs, albums, artists, and movies |
| Depended on by | Library | `CatalogStatsQuery` implements `LibraryContentStatsInterface` |
| Depended on by | Lyrics | Reads songs through `SongLookupInterface` |
| Depended on by | Media | Uses `VideoPortInterface`, `SongRepositoryInterface`, and `SongAvailabilityPortInterface` |
| Depended on by | Playlist | References songs through `SongLookupInterface` |
| Depended on by | Recommendation | References artists and genres |
| Depended on by | Metadata | Enriches catalog entities; its library and genre syncs dispatch the `SyncAlbumMessage` Catalog handles, and its admin sync the `SyncArtistsMessage` |

## Infrastructure

| Component | Type | Purpose |
|-----------|------|---------|
| Doctrine entities | ORM | Persistence for all 6 aggregate roots, plus `MovieCollectionEntity` and link entities |
| Doctrine repositories | ORM | Repository implementations with `PgroongaSearchTrait` for full-text search, plus `MovieCollectionRepository` |
| `CatalogStatsQuery`, `CatalogReadScopeQuery` | Doctrine query | Implement `LibraryContentStatsInterface` and catalog read-scope queries |
| `AdminRateLimitListener` | Event listener | Rate-limits `POST /api/albums/covers/extract` |
| `AlbumVoter` | Security | Voter for album access checks |
| `CatalogMessagePayloadCodec` | Messaging | Messenger payload codec for `BatchExtractCoversCommand` and `DrainAlbumMatchesMessage` |
| `AlbumService`, `SongService`, `ArtistService`, `MovieService`, `GenreService`, `VideoService`, `SongAvailability`, `AlbumDuplicateService`, `SongLookupService` | Port implementation | Implement the matching ports |
| `AlbumMergeService` | Port implementation | Implements `AlbumMergePortInterface`: in one transaction, moves songs, folds duplicates, moves favorites and plays, fills metadata the target lacks and skips the target's locked fields; after the commit, discards the source's cover and requests a match of the target as an import does |
| `RedisQueuedArtistSyncs` | Redis store | Implements `QueuedArtistSyncsInterface` and `QueuedJobWorkInterface`, so cancelling a finished admin sync's artist job cancels its run |
| `ArtistMergeService` | Port implementation | Implements `ArtistMergePortInterface` with statements on the caller's connection and transaction |
| `AlbumMatchStateRepository` | DBAL repository | The album match states: requests, claims and review marks, each decision in one statement |
| `MatchCatalogReadQuery`, `MatchReviewReadQuery` | Doctrine query | The catalog reads a comparison needs (`MatchCatalogReadInterface`), and the pages of the review list (`MatchReviewReadInterface`) |

### Console Commands

| Command | Purpose |
|---------|---------|
| `app:album:update`, `app:song:update`, `app:artist:update` | Change metadata; `--lock` and `--unlock` change field locks |
| `app:movie:update` | Change a movie's title, year or summary |
| `app:artist:create` | Create an artist |
| `app:artist:song:add`, `app:artist:album:add` | Credit an artist with a role |
| `app:artist:song:role`, `app:artist:album:role` | Change a credit's role; `--from` names the current role |
| `app:artist:song:remove`, `app:artist:album:remove` | Remove every credit an artist has on a song or an album |
| `app:album:cover:set`, `app:artist:cover:set` | Set or replace a cover from an image file in the container |
| `app:album:cover:remove`, `app:artist:cover:remove` | Remove a cover and delete its image files |
| `app:album:delete`, `app:song:delete` | Delete, with `--delete-files` (needs `--force`) and `--dry-run`; `app:album:delete --keep-cover` keeps the cover |
| `app:artist:delete`, `app:movie:delete` | Delete an artist or a movie |
| `app:album:merge`, `app:album:duplicates` | Merge albums; list duplicate album groups |
| `app:album:extract-covers` | Queue embedded cover extraction for every album without a cover |
| `app:metadata:match:search`, `app:metadata:match:compare`, `app:metadata:match:apply` | List MusicBrainz candidates, compare one with an album, song or artist, and apply it |
| `app:metadata:match:reviews` | List the albums that need a match review |
| `app:genre:list`, `app:genre:create`, `app:genre:update`, `app:genre:delete` | Manage genres |
| `app:genre:song:add`, `app:genre:song:remove`, `app:genre:album:add`, `app:genre:album:remove` | Assign or remove a genre |
| `app:e2e:ingest-video` | Ingest a test video file for end-to-end tests |

See the [Search](../development/search.md) page for details on PGroonga full-text search.
