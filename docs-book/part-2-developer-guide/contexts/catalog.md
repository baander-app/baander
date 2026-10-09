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

## Commands & Handlers

The API controllers and the console commands dispatch the same commands and queries, so each edit, delete and cover change has one use case. A malformed public ID or UUID, an invalid value, or a rejected file raises `InvalidInputException` (HTTP 422, console exit `INVALID`). A missing item or credit raises `NotFoundException` (HTTP 404).

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
| `DeleteArtistCommand` | `DeleteArtistHandler` | Delete an artist, its credits and its cover image; songs and albums stay (`app:artist:delete`) |
| `DeleteMovieCommand` | `DeleteMovieHandler` | Delete a movie and the videos no other movie uses; the video files stay on disk (`app:movie:delete`) |
| `CreateGenreCommand` | `CreateGenreHandler` | Create a genre, optionally below a parent (`app:genre:create`) |
| `UpdateGenreCommand` | `UpdateGenreHandler` | Change a genre's name, slug, parent or MusicBrainz ID (`app:genre:update`) |
| `DeleteGenreCommand` | `DeleteGenreHandler` | Delete a genre; its children become root genres (`app:genre:delete`) |

`FilesDiscoveredHandler` handles Library's `FilesDiscovered` message and imports the files a scan found.

### Queries

| Query | Handler | Purpose |
|-------|---------|---------|
| `GetAlbumDeletePreviewQuery` | `GetAlbumDeletePreviewHandler` | What deleting an album would remove: its songs, their total size, the cover and the playlists that lose songs; with `deleteFiles`, each song file checked as the delete would check it (`GET /api/admin/albums/{publicId}/delete-preview`, `app:album:delete --dry-run`) |
| `GetSongDeletePreviewQuery` | `GetSongDeletePreviewHandler` | What deleting a song would remove: the song, its file and its playlist entries; with `deleteFile`, the file checked as the delete would check it (`GET /api/admin/songs/{publicId}/delete-preview`, `app:song:delete --dry-run`) |

The previews change nothing. They read playlists through Playlist's `PlaylistDeletionPreviewPortInterface` and check files through Library's `LibraryMediaFilesInterface::inspect()`, which returns a verdict for each path in a `FileDeletionPreview`.

## Field Locks

`Album`, `Song` and `Artist` each have a list of lockable fields, and they share the lock rules in `LockedFields`. A locked field keeps its value: `updateMetadata()` rejects the whole edit when it gives any locked field a value. Every lockable field is enforced, not only the title or name. Locking or unlocking a field that is not lockable is rejected too.

| Aggregate | Lockable fields |
|-----------|-----------------|
| `Album` | `title`, `type`, `year`, `label`, `catalogNumber`, `barcode`, `country`, `language`, `disambiguation`, `annotation` |
| `Song` | `title`, `track`, `disc`, `year`, `comment`, `lyrics`, `explicit` |
| `Artist` | `name`, `country`, `gender`, `type`, `lifeSpanBegin`, `lifeSpanEnd`, `disambiguation`, `sortName`, `biography` |

An update command takes the edit, a complete `lockedFields` list that replaces the current one, and fields to `lock` and `unlock`. `CatalogInput::lockChanges()` turns these into fields to unlock and fields to lock. The handler applies the unlocks, then the edit, then the locks, and saves only when all three are accepted. One request can therefore unlock a field and change it, or change a field and lock it. A field in both lists ends locked. On the console, `--lock` and `--unlock` can be repeated and accept comma-separated field names.

Automatic updates skip locked fields rather than fail. Metadata's `AlbumMetadataEnricher` and `ArtistMetadataEnricher` leave locked fields out of the values they apply. `AlbumMergeService` keeps the target album's value for a locked field, even when that value is empty. After the merge, the target also locks every field that was locked on the source album.

## Deletes

Every delete returns a `CatalogDeletionResult`: the rows deleted by kind and, when the delete included audio files, the files removed, already missing, and left on disk.

`AlbumPortInterface::delete()` and `SongPortInterface::delete()` delete rows only; they never touch audio files. A delete with files goes through Library's `LibraryMediaFilesInterface` (see [Library](library.md#media-file-deletion)). Before anything changes, `DeleteAlbumHandler` and `DeleteSongHandler` call `prepareDeletion()` for the song paths. It refuses the whole request with a 409 while a scan holds the library or when the server cannot write a song's directory, and with a 422 when a path lies outside the library root. The handlers then delete the catalog rows and the file index rows in one transaction through `TransactionPortInterface`, and unlink the files after the commit. The next scan imports any file left on disk again. When a file is left, the console command exits with `FAILURE`.

`DeleteAlbumHandler` deletes the album's cover image after the commit unless `deleteCover` is false, so a rollback leaves the album with its cover. `DeleteArtistHandler` deletes the artist, then its cover image. `DeleteMovieHandler` deletes the movie and, in the same transaction, the videos that no other movie links (`VideoRepositoryInterface::deleteUnlinked()`).

The public delete routes pass only the public ID, so they keep the audio files and delete the album cover. `DELETE /api/admin/albums/{publicId}` takes `deleteFiles` and `deleteCover`, and `DELETE /api/admin/songs/{publicId}` takes `deleteFile`. On the console, `--delete-files` requires `--force` even on a terminal, `app:album:delete --keep-cover` keeps the cover, and `--dry-run` prints the preview without changing anything.

## Covers

`SetCoverHandler` accepts a JPEG, PNG or WebP file of at most 10 MB, detected with Filesystem's `MimeDetectorPortInterface`. The upload route passes the uploaded temporary file; the console passes a path in the container. The handler copies the file into Media storage under a new path for each cover (`images/<owner>/<owner UUID>/<UUID>.<ext>`), so a replacement never overwrites the file it replaces. It saves the image record and the owner in one transaction. If that fails before both saves complete, the handler removes the new file and the old cover stays. It deletes the old cover only after the commit.

`RemoveCoverHandler` clears the owner's cover, saves the owner, then deletes the image. Removing the cover of an owner that has none returns a 404. `CoverImageDiscarder` deletes a cover image that its owner no longer uses: first the image record through `ImagePortInterface`, then the file and its derived files through `StoragePortInterface`. It runs only after the owner's change has committed, and it logs a failure instead of throwing.

## Artist Credits

A credit links an artist to a song or an album (`CreditTarget`) with an `ArtistRole`; the song or album is named by its UUID. The `ArtistPortInterface` credit methods return `false` instead of throwing, and the handlers turn that into a 404 for an unknown artist, song or album, or an artist without the named credit. `ChangeArtistCreditRoleCommand` names the credit to change by its current role. The current role may be omitted when the artist has a single credit on the target; with several credits, omitting it is a 422. When the new role is one the artist already holds on the target, the changed credit is removed.

## Ports

| Port | Purpose |
|------|---------|
| `AlbumPortInterface` | Album CRUD operations; `delete()` removes the album, its songs through the database, and optionally its cover, but never audio files |
| `AlbumDuplicatePortInterface` | Duplicate album detection and grouping |
| `AlbumMergePortInterface` | Album merge operations |
| `ArtistPortInterface` | Artist CRUD operations and song and album credits (add, remove, list roles, change role); each credit method reports whether it found its target |
| `GenrePortInterface` | Genre CRUD operations |
| `MetadataContentReaderPortInterface` | Read metadata content for catalog entities |
| `MoviePortInterface` | Movie CRUD operations |
| `SongPortInterface` | Song CRUD operations; `delete()` never touches the audio file |
| `SongLookupInterface` | Song lookups published to Lyrics and Playlist |

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
| Depends on | Library | `LibraryMediaFilesInterface` checks and deletes audio files for album and song deletes and their previews |
| Depends on | Media | `ImagePortInterface` and `StoragePortInterface` store and delete cover images |
| Depends on | Filesystem | `MimeDetectorPortInterface` checks cover files |
| Depends on | Playlist | `PlaylistDeletionPreviewPortInterface` names the playlists a delete affects |
| Depended on by | Activity | References songs and albums |
| Depended on by | Playlist | References songs |
| Depended on by | Recommendation | References artists and genres |
| Depended on by | Metadata | Enriches catalog entities |

## Infrastructure

| Component | Type | Purpose |
|-----------|------|---------|
| Doctrine entities | ORM | Persistence for all 6 aggregate roots |
| Doctrine repositories | ORM | Repository implementations with `PgroongaSearchTrait` for full-text search |
| Event listeners | Event | React to domain events (e.g., metadata sync reactions) |
| `AlbumService`, `SongService`, `ArtistService`, `MovieService`, `GenreService`, `AlbumDuplicateService`, `SongLookupService` | Port implementation | Implement the matching ports |
| `AlbumMergeService` | Port implementation | Implements `AlbumMergePortInterface`: moves songs, fills metadata the target lacks, and skips the target's locked fields |

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
| `app:genre:list`, `app:genre:create`, `app:genre:update`, `app:genre:delete` | Manage genres |
| `app:genre:song:add`, `app:genre:song:remove`, `app:genre:album:add`, `app:genre:album:remove` | Assign or remove a genre |
| `app:e2e:ingest-video` | Ingest a test video file for end-to-end tests |

See the [Search](../search.md) page for details on PGroonga full-text search.
