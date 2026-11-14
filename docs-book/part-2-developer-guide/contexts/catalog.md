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

A song's length comes from the audio probe, never from its tags. A song is credited to the artist its tags name, under a credited name: the first artist value names the artist, and the credited name joins every value with "; ", so the song shows the name it was tagged with even when the artist's own name differs. A file without an artist value is imported without a credit. Inside the transaction, after the library and file-index locks and before any song is written, one `ArtistIdentityResolverInterface::resolve()` call finds or creates every artist the batch credits. It takes a transaction-scoped advisory lock on each tagged name and on the MusicBrainz ID of each identity that has one, sorted and one key per statement, and resolves each identity by its MusicBrainz artist ID when the file carries exactly one valid ID, otherwise by its exact name; `ArtistIdentityIndex` states the tie-breaks. A file that names its artists one by one, in its `ARTISTS` values or else its artist values, and gives exactly one valid MusicBrainz artist ID per name credits each name by the ID in the same position: the first as primary under the joined artist values, every other one as featured under its own name. Imports running at once therefore never create two artists for one ID or, without an ID, for one name. The batch's genre locks sort after the artist locks and are taken after them.

A file whose hash a stored song holds never becomes a new song. At the song's path, or renamed within the song's directory while the old file is gone, it repairs that song, as on a rescan: the song moves to a renamed file's path, takes its length from the probe (a failed probe keeps the stored length) and, when it has no artist credit of any role, takes its tagged credit. A file elsewhere is left alone. A file with a hash no song holds, at the path of a song in the same library, is that song retagged: the song keeps its ID, album, MusicBrainz ID, lyrics and genres, and takes the new hash, technical fields, length and unlocked tag fields. A title, track, disc or year that the song's `matched_fields` lists changes only when the tags carry a value for it, and then leaves the list; a title or track number taken from the file name does not count. The new tags' credits replace the song's artist credits, unless `matched_fields` lists `credits` and the tags give the primary artist no MusicBrainz ID: the match's credits then stay, and the batch neither finds nor creates the tagged artist. When a batch holds retagged songs, its transaction first takes every artist and genre key the batch may write in one sorted pass, then locks the retagged songs' rows `FOR NO KEY UPDATE` in ID order, the order a match apply takes, and reads those songs again before it decides. The song stays in its album, and an album is created only for new songs. After the message's last batch, each album it touched that has no artist credit gets one, in a transaction that locks the album row `FOR NO KEY UPDATE`: from the first album-artist tag among the album's files in the message, in path order, or else among the files of its stored songs; else to the artist every song of the album is credited to; else none. An album that has a credit of any role keeps it. Ingest then asks `AlbumMatchRequestInterface::requestAfterImport()` for automatic MusicBrainz matches of the albums the message touched (see [Album match queue](#album-match-queue)); when that call throws, ingest logs the error and carries on.

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

Automatic updates skip locked fields rather than fail. Metadata's `ArtistMetadataEnricher` leaves locked fields out of the values it applies. A MusicBrainz match shows a locked field in its comparison but never ticks or writes it, and a manual apply whose ticked row has become locked answers 409 `match_stale`. A retag leaves a locked song field as it is. `AlbumMergeService` keeps the target album's value for a locked field, even when that value is empty. After the merge, the target also locks every field that was locked on the source album.

## Deletes

Every delete returns a `CatalogDeletionResult`: the rows deleted by kind and, when the delete included audio files, the files removed, already missing, and left on disk.

`AlbumPortInterface::delete()` and `SongPortInterface::delete()` delete rows only; they never touch audio files. A delete with files goes through Library's `LibraryMediaFilesInterface` (see [Library](library.md#media-file-deletion)). Before anything changes, `DeleteAlbumHandler` and `DeleteSongHandler` call `prepareDeletion()` for the song paths. It refuses the whole request with a 409 while a scan holds the library, when the library root is not available, or when the server cannot write a song's directory, and with a 422 when a path lies outside the library root. The handlers then delete the catalog rows and the file index rows in one transaction through `TransactionPortInterface`, and unlink the files after the commit. The next scan imports any file left on disk again. When a file is left, the console command exits with `FAILURE`.

`DeleteAlbumHandler` deletes the album's cover image after the commit unless `deleteCover` is false, so a rollback leaves the album with its cover. `DeleteArtistHandler` deletes the artist, then its cover image. `DeleteMovieHandler` deletes the movie and, in the same transaction, the videos that no other movie links (`VideoRepositoryInterface::deleteUnlinked()`).

The public delete routes pass only the public ID, so they keep the audio files and delete the album cover. `DELETE /api/admin/albums/{publicId}` takes `deleteFiles` and `deleteCover`, and `DELETE /api/admin/songs/{publicId}` takes `deleteFile`. On the console, `--delete-files` requires `--force` even on a terminal, `app:album:delete --keep-cover` keeps the cover, and `--dry-run` prints the preview without changing anything.

## Covers

`SetCoverHandler` accepts a JPEG, PNG or WebP file of at most 10 MB, detected with Filesystem's `MimeDetectorPortInterface`. The upload route passes the uploaded temporary file; the console passes a path in the container. The handler copies the file into Media storage under a new path for each cover (`images/<owner>/<owner UUID>/<UUID>.<ext>`), so a replacement never overwrites the file it replaces. It saves the image record and the owner in one transaction. If that fails before both saves complete, the handler removes the new file and the old cover stays. It deletes the old cover only after the commit.

`RemoveCoverHandler` clears the owner's cover, saves the owner, then deletes the image. Removing the cover of an owner that has none returns a 404. `CoverImageDiscarder` deletes a cover image that its owner no longer uses: first the image record through `ImagePortInterface`, then the file and its derived files through `StoragePortInterface`. It runs only after the owner's change has committed, and it logs a failure instead of throwing.

## Artist Credits

A credit links an artist to a song or an album (`CreditTarget`) with an `ArtistRole`. In `AddArtistCreditCommand`, `ChangeArtistCreditRoleCommand`, and `RemoveArtistCreditCommand`, the target song or album is named by its 21-character `PublicId`, which `ArtistCreditInput::targetId()` resolves to a `Uuid`. The `ArtistPortInterface` credit methods return `false` instead of throwing, and the handlers turn that into a 404 for an unknown artist, song or album, or an artist without the named credit. `ChangeArtistCreditRoleCommand` names the credit to change by its current role. The current role may be omitted when the artist has a single credit on the target; with several credits, omitting it is a 422. When the new role is one the artist already holds on the target, the changed credit is removed. A credit added by hand stores the artist's name at that moment as its credited name; renaming the artist later leaves it unchanged.

## MusicBrainz Matching

Catalog matches albums, songs and artists with MusicBrainz, because every write a match makes is a Catalog write. It reaches MusicBrainz and the Cover Art Archive only through Metadata's `MusicBrainzLookupInterface`, whose calls each wait for a turn under the shared `musicbrainz` rate limit. An album matches a release, one edition, and the release's group fills `albums.mbid`; a song matches a recording and an artist an artist. [External APIs](../../part-1-operator-guide/media/external-apis.md#musicbrainz-matching) describes matching for operators.

### Candidates and comparison

`SearchMatchCandidatesHandler` reads the item before it asks MusicBrainz, so an unknown item spends no request. An album runs one release search on its title and primary artist, ranks the hits by title, artist and track count, and looks up the best five with their tracks; a song runs one recording search on its title and the artist text it shows; an artist runs one artist search on its name. `CandidateScorer` scores each candidate from 0 to 100 after folding case, punctuation and featuring joiners: a looked-up release by title 25, artist 20, track count 20 and track lengths 35; a recording by title 45, artist 35 and length 20; an artist by its name, or by name 80 and disambiguation 20. `TrackPairing` pairs local tracks with release tracks by disc and position, then by a length within 3 seconds that fits one track only. `MusicBrainzAnswer` turns an outage into a 503 with the reason `musicbrainz_unavailable` and a refused request into a 409 with `musicbrainz_rejected`.

`CompareMatchHandler` looks the candidate up once and builds a `MatchComparison` with `MatchComparisonBuilder`, which reads the catalog through `MatchCatalogReadInterface` and changes nothing. The comparison holds three kinds of rows:

- Field rows (`MatchFieldRow`): MusicBrainz IDs and empty fields are ticked, a field holding a different value is not, and a locked field is shown but never ticked.
- Credits rows (`MatchCreditsRow`), ticked when they change something: MusicBrainz's first credited artist becomes primary and the others featured. The primary credit keeps the song's or album's own credited name, and a featured credit takes the name MusicBrainz credits. Only primary and featured credits are replaced; credits of other roles stay. Each credited artist resolves as an import resolves it, so a row names the local artist, or the new one, each credit would go to.
- Artist actions (`MatchArtistAction`), ticked: an artist the ticked credits rows leave without any credit merges into the artist that takes over most of its primary credits, or is removed when it held none. An artist match whose ID another artist holds becomes a merge into that holder.

### Apply

`ApplyMatchHandler` handles `ApplyMatchCommand` and writes a match in one transaction, or nothing. Every MusicBrainz call runs before the transaction: a manual apply looks the candidate up again by its ID and compares it with the item as stored, never with values the client sent, and a front cover the match sets is downloaded and stored as a file (`MatchCovers`). The transaction then takes its locks in the order every catalog writer takes them: the album row `FOR NO KEY UPDATE`; every artist and genre import key in one sorted pass; the rows of the artists the match may credit, merge, merge into or remove, `FOR UPDATE` through `ArtistMergePortInterface::lock()`; the rows of the songs it writes, `FOR NO KEY UPDATE` in ID order. Under those locks it reads the item again and builds the comparison once more. A manual apply whose ticked rows no longer show what the admin saw, or whose comparison now needs a lock it did not take, writes nothing and answers 409 `match_stale`; a deadlock answers 409 `match_busy`.

The apply writes fields, IDs and the credits that change, records on each song the fields a match set in `songs.matched_fields` (`title`, `track`, `disc`, `year`, and `credits` when it set the credits), and records an album's release in `albums.release_mbid` with the time in `albums.matched_at`. It sets `songs.match_checked_at` on every song the candidate was compared with, paired with a track or not, under the song row locks it took for them; a song apply sets it on its song. The column is bookkeeping, which the live-update capture does not watch. The apply then merges or removes the artists the new credits left without any. A manual album apply also deletes the album's match state, which takes it off the review list. Covers that merges, removals or a replaced cover free are discarded after the commit.

`ApplyMatchCommand::automatic()` carries the candidate the album match queue decided on. An automatic apply writes no admin ticks: it fills every MusicBrainz ID, field and cover that is empty and unlocked, writes every credits row that changes, and applies the merges and removals those credits cause. It reports to the queue how it ended.

`ArtistMergePortInterface`, implemented by `ArtistMergeService`, runs inside the caller's transaction after the caller took the import keys and called `lock()`. A merge moves the source artist's credits, keeping the stronger role where both artists hold a song or album, and its favorites, play history and artist recommendations, through ports of Favorites, Activity and Recommendation, dropping rows that would collide; the target keeps its cover, else takes the source's. A removal deletes the artist with its credits, favorites and recommendations, and its play history stays without an artist. Both write through statements and release the managed entities they make stale.

### Album match queue

`album_match_states` holds at most one row per album. A `requested` row carries the request's `source`, `ingest` or `admin`, and its `due_at`; a `needs_review` row carries the review's `reason`, `best_score`, `checked_song_count` and `checked_at`. The mark lives in its own table rather than an album column because the album capture trigger would publish it to every library reader; its rows publish live changes to admins only.

`AlbumMatchRequester` implements `AlbumMatchRequestInterface`. `FilesDiscoveredHandler` calls `requestAfterImport()` for the albums a message touched once their songs are committed; it does nothing while `metadata.auto_sync` is off, and its requests fall due after 300 seconds, so that an album's other discs land first. `RequestAlbumMatchHandler` calls `requestForAdmin()` for each `SyncAlbumMessage` the library and genre syncs dispatch; that request falls due at once whatever the setting says. Both skip an album with nothing to match: one marked for review whose song count is still the one the matcher checked, and a matched album whose songs all carry `match_checked_at`. A request for an album that already waits keeps the earlier due time, and an admin request stays an admin request.

`DrainAlbumMatchesMessage` runs on `async`, one album per message, and each run queues the next, so the runs form a chain. `AlbumMatchQueueChain` keeps one chain: the message carries the chain's token, and the application cache holds the current token for as long as the chain's next run needs, plus a margin. A run without a token starts a chain in place of any other, and a run whose chain was replaced ends without matching or queuing a successor. A request that brings an album's due time forward dispatches a run without a token, delayed until the request falls due, unless another request falls due at or before it. That earlier request's run, or the chain waiting for the earliest due time, reaches this one as well. A request parked for a later retry counts only when it falls due first, so it holds back no other album. Each request writes its row and then looks for others, while a run removes or marks its album and then looks for what is left, so of a request and a finishing run that overlap, at least one sees the other's write. When nothing is left to wait for, the chain releases the queue.

`DrainAlbumMatchesHandler` claims the request that has been due longest in one statement, `FOR UPDATE SKIP LOCKED`, moving its `due_at` to the end of a one-hour claim; `due_at` identifies the claim. A request made for the album during the claim moves `due_at` earlier, which ends the claim, and the run's later writes leave that request alone. A song committed while a match runs was not compared, so it keeps a null `match_checked_at` and a later run pairs it. Runs of an old and a new chain can overlap until the old chain's next run ends, and every MusicBrainz call still waits for the shared rate limit. The claims keep the runs on different albums, with one exception: a request made for an album during its claim can start a new chain, whose run takes the album while the old run still matches it. Each apply takes the album's row lock and checks the album's release again before it writes.

The run removes every import request when it claims one while `metadata.auto_sync` is off. Otherwise `AutomaticAlbumMatcher` matches the album. An album without a release runs the album search a candidate list runs, and `MatchConfidence` decides: the best release, together with its twin editions that score within the lead and agree on every track's title, artist credit and length, is confident when it has the album's track count, pairs every track within 3 seconds, scores at least 90 and leads the next release by at least 10. A matched album looks up only its stored release and pairs against it the songs whose `match_checked_at` is null. When the album is matched, or no longer exists, its request is removed; an album that needs review is marked with the reason, the best score and the song count checked. An apply that met changed rows (`match_stale`) or a deadlock gives back only that album's request, due again in 15 minutes, and a MusicBrainz refusal (`musicbrainz_rejected`) during the search or a lookup gives it back due in 6 hours; the queue goes on with the next album. A real outage, where MusicBrainz or the Cover Art Archive gives no usable answer, gives the request back with its due time and pauses the whole queue for 5, 15 and 60 minutes, then hourly. A Cover Art Archive refusal during the apply counts as such an outage. A matcher failure is logged, and its request falls due again when the claim lapses. While requests wait, the run queues the next one a second later, or when the next request falls due.

A run lost to a failed dispatch, or to a message that used up its retries, would leave every request waiting. The scheduled job "Recover the album match queue", seeded by a migration with the expression `*/10 * * * *`, runs `RecoverAlbumMatchQueueCommand`: when a request is due and no chain holds the queue, `RecoverAlbumMatchQueueHandler` logs a warning and dispatches a run without a token, which starts a new chain. A chain that was only late gives way to the new one at its next run.

`ListMatchReviewsHandler` pages through the `needs_review` rows with `MatchReviewReadInterface`, one SQL statement per page, the most recently checked first; its cursor holds the check time and the album's public ID.

## Ports

| Port | Purpose |
|------|---------|
| `AlbumPortInterface` | Album CRUD operations; `delete()` removes the album, its songs through the database, and optionally its cover, but never audio files |
| `AlbumDuplicatePortInterface` | Duplicate album detection and grouping |
| `AlbumMergePortInterface` | Album merge operations |
| `ArtistPortInterface` | Artist CRUD operations and song and album credits (add, remove, list roles, change role); each credit method reports whether it found its target |
| `ArtistIdentityResolverInterface` | Finds or creates the artists an import or a match credits, under advisory locks on their names and MusicBrainz IDs (`ArtistIdentityResolver`) |
| `ArtistMergePortInterface` | Merges one artist into another or removes one, inside the caller's transaction (`ArtistMergeService`) |
| `AlbumMatchRequestInterface` | Requests automatic MusicBrainz matches of albums in the album match queue, after an import or for an admin sync (`AlbumMatchRequester`) |
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
| Depends on | Playlist | `PlaylistDeletionPreviewPortInterface` names the playlists a delete affects |
| Depends on | Lyrics | `FilesDiscoveredHandler` requests lyrics fetches through `LyricsFetchRequestInterface` |
| Depends on | Metadata | `FilesDiscoveredHandler` dispatches `ExtractAlbumCoverCommand`. Matching searches and looks up MusicBrainz through `MusicBrainzLookupInterface` (the `Metadata MusicBrainz Lookup Contract` Deptrac layer); `RequestAlbumMatchHandler` handles `SyncAlbumMessage`, and the album match queue reads `metadata.auto_sync` from `MetadataSettingDefinitions` (the `Metadata Album Sync Contract` layer) |
| Depends on | Favorites, Activity, Recommendation | `ArtistMergeService` moves or deletes an artist's favorites, play history and recommendations through `ArtistFavoriteMergePortInterface`, `ArtistActivityMergePortInterface` and `ArtistRecommendationMergePortInterface` |
| Depends on | Transcode | `FilesDiscoveredHandler` probes audio and video files through `AudioProbePortInterface` and `VideoProbePortInterface` |
| Depended on by | Activity | References songs, albums, artists, and movies |
| Depended on by | Library | `CatalogStatsQuery` implements `LibraryContentStatsInterface` |
| Depended on by | Lyrics | Reads songs through `SongLookupInterface` |
| Depended on by | Media | Uses `VideoPortInterface`, `SongRepositoryInterface`, and `SongAvailabilityPortInterface` |
| Depended on by | Playlist | References songs through `SongLookupInterface` |
| Depended on by | Recommendation | References artists and genres |
| Depended on by | Metadata | Enriches catalog entities; its library and genre syncs dispatch the `SyncAlbumMessage` Catalog handles |

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
| `AlbumMergeService` | Port implementation | Implements `AlbumMergePortInterface`: moves songs, fills metadata the target lacks, and skips the target's locked fields |
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
