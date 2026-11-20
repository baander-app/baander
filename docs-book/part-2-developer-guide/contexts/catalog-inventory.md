# Catalog Implementation Inventory

Use this reference to locate Catalog models, commands, ports and entry points. [Catalog](catalog.md) explains edits and merges; [MusicBrainz Matching](catalog-matching.md) explains automatic and manual matching.

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
| `AlbumType` | Album type classification (studio, live, compilation, single, EP, remix, soundtrack, spoken word, other) |
| `ArtistRole` | Artist role on a song or album (primary, featured, producer, composer, conductor, remixer, DJ mix, other) |
| `DiscogsId` | Discogs release identifier |
| `MusicbrainzId` | MusicBrainz identifier |
| `DuplicateGroup` | Group of at least two likely duplicate albums, with a confidence |
| `LockedFields` | Lockable-field rules shared by `Album`, `Song`, and `Artist` |
| `AlbumMatchState`, `AlbumMatchStatus`, `AlbumMatchSource`, `AlbumMatchReviewReason` | An album's row in the [album match queue](catalog-matching.md#album-match-queue): `requested` with its source (`ingest` or `admin`) and due time, or `needs_review` with the reason, best score and song count checked |
| `MatchedSongField` | A song field a MusicBrainz match set: `title`, `track`, `disc`, `year` or `credits` |
| `GenreReadView` | Read model (`Domain/ReadModel/`) for genre hierarchy and counts |

### Domain Services

| Service | Purpose |
|---------|---------|
| `AlbumDuplicateDetector` | Groups the albums of one library into `DuplicateGroup` instances: normalized titles at least 85% similar, artists that overlap by at least half, and the same year when both have one |
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
| `ApplyMatchCommand` | `ApplyMatchHandler` | Apply a MusicBrainz candidate to an album, song or artist (`POST /api/admin/metadata/match/{type}/{publicId}/apply`, `app:metadata:match:apply`), or an automatic album match for the album match queue; see [MusicBrainz Matching](catalog-matching.md#musicbrainz-matching) |
| `RecoverAlbumMatchQueueCommand` | `RecoverAlbumMatchQueueHandler` | Start the album match queue again when a request is due and no chain of runs holds it; the scheduled job "Recover the album match queue" runs it every 10 minutes |

Imports and rescans are described in [Catalog Imports](catalog-imports.md).

### Queries

| Query | Handler | Purpose |
|-------|---------|---------|
| `GetAlbumDeletePreviewQuery` | `GetAlbumDeletePreviewHandler` | What deleting an album would remove: its songs, their total size, the cover and the playlists that lose songs; with `deleteFiles`, each song file checked as the delete would check it (`GET /api/admin/albums/{publicId}/delete-preview`, `app:album:delete --dry-run`) |
| `GetSongDeletePreviewQuery` | `GetSongDeletePreviewHandler` | What deleting a song would remove: the song, its file and its playlist entries; with `deleteFile`, the file checked as the delete would check it (`GET /api/admin/songs/{publicId}/delete-preview`, `app:song:delete --dry-run`) |
| `SearchMatchCandidatesQuery` | `SearchMatchCandidatesHandler` | Up to five scored MusicBrainz candidates for an album, song or artist (`GET /api/admin/metadata/match/{type}/{publicId}/candidates`, `app:metadata:match:search`) |
| `CompareMatchQuery` | `CompareMatchHandler` | What matching an item with one candidate would set, row by row (`GET /api/admin/metadata/match/{type}/{publicId}/comparison`, `app:metadata:match:compare`) |
| `ListMatchReviewsQuery` | `ListMatchReviewsHandler` | The albums that need a match review, most recently checked first (`GET /api/admin/metadata/match/reviews`, `app:metadata:match:reviews`) |

The previews change nothing. They read playlists through Playlist's `PlaylistDeletionPreviewPortInterface` and check files through Library's `LibraryMediaFilesInterface::inspect()`, which returns a verdict for each path in a `FileDeletionPreview`.

## Ports

| Port | Purpose |
|------|---------|
| `AlbumPortInterface` | Album CRUD operations; `delete()` removes the album, its songs through the database, and optionally its cover, but never audio files |
| `AlbumDuplicatePortInterface` | Duplicate album detection and grouping |
| `AlbumMergePortInterface` | Merges one album into another in one transaction (`AlbumMergeService`); see [Album Merges](catalog.md#album-merges) |
| `ArtistPortInterface` | Artist CRUD operations and song and album credits (add, remove, list roles, change role); each credit method reports whether it found its target |
| `ArtistIdentityResolverInterface` | Finds or creates the artists an import or a match credits, under advisory locks on their names and MusicBrainz IDs, and with `resolveReporting()` names the artists it created with an ID or gave one (`ArtistIdentityResolver`) |
| `ArtistMergePortInterface` | Merges one artist into another or removes one, inside the caller's transaction (`ArtistMergeService`) |
| `AlbumMatchRequestInterface` | Requests automatic MusicBrainz matches of albums in the album match queue, after an import or for an admin sync (`AlbumMatchRequester`) |
| `ArtistSyncRequestInterface` | Queues the artist syncs of the artists an import batch or a match apply identified, after its commit (`ArtistSyncRequester`) |
| `QueuedArtistSyncsInterface` | The queued and answer marks, the admin sync runs and the shared due time of artist syncs, in Redis (`RedisQueuedArtistSyncs`) |
| `GenrePortInterface` | Genre CRUD operations |
| `MetadataContentReaderPortInterface` | Reads the embedded metadata of a media file by path, for ingest and cover extraction (Metadata's `MetadataContentReader`) |
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

No code in `src/` raises these events. Notification maps `AlbumCreated` to its media-changes category and leaves the other two out.

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
| Depended on by | Media | Uses `VideoPortInterface` and `SongRepositoryInterface`; `ImageEntity` references `AlbumEntity` and `ArtistEntity` |
| Depended on by | Playlist | References songs through `SongLookupInterface` |
| Depended on by | Recommendation | References artists and genres |
| Depended on by | Transcode | Reads videos through `VideoPortInterface` and `VideoRepositoryInterface` |
| Depended on by | Metadata | Enriches catalog entities; its library and genre syncs dispatch the `SyncAlbumMessage` Catalog handles, and its admin sync the `SyncArtistsMessage` |

## Infrastructure

| Component | Type | Purpose |
|-----------|------|---------|
| Doctrine entities | ORM | Persistence for all 6 aggregate roots, plus `MovieCollectionEntity`, `AlbumMatchStateEntity` and link entities |
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
| `app:e2e:ingest-video` | Ingest a directory of video files as a movie library for end-to-end tests |

See the [Search](../development/search.md) page for details on PGroonga full-text search.
