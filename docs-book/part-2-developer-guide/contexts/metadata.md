# Metadata

The Metadata context enriches catalog entities with data from external music and movie APIs: Discogs, Last.fm, Spotify, MusicBrainz, TasteDive, CoverArtArchive, and TMDB (`TmdbAdapter` and `MovieMetadataEnricher`). It handles metadata matching (pairing scanned files with external records), cover art retrieval, and audio file tag parsing (FLAC, ID3, OGG Vorbis and Opus, WAV).

## Domain Models

| Model | Type | Purpose |
|-------|------|---------|
| `CoverArt` | Value Object | Typed cover art container (type, MIME, description, image data) |
| `ExtractedMetadata` | Value Object | Parsed audio tags from a media file |
| `MatchQuality` | Value Object | Match scoring between a scanned file and an external record |
| `MetadataMatch` | Value Object | A match result pairing a local file with an external record |

## Commands and Handlers

| Command / Message | Handler | Purpose |
|---------|---------|---------|
| `ExtractAlbumCoverCommand` | `ExtractAlbumCoverHandler` | Extract and store cover art from an album |
| `SyncMetadataCommand` | `SyncMetadataHandler` | The admin sync: without a source, one `SyncLibraryMessage` per library and one `SyncArtistsMessage`; with the `genres` source, only `SyncGenresMessage`. Returns the number of jobs queued |
| `SyncLibraryMessage` | `SyncLibraryHandler` | Queue a `SyncAlbumMessage` for every album in a library and, with `includeSongs`, a `SyncSongMessage` for each of its songs |
| `SyncAlbumMessage` | Catalog's `RequestAlbumMatchHandler` | Request the album's MusicBrainz match in Catalog's album match queue, as an admin request |
| `SyncArtistsMessage` | Catalog's `QueueArtistSyncsHandler` | Queue a `SyncArtistMessage` for every artist with a MusicBrainz ID and missing details |
| `SyncArtistMessage` | Catalog's `SyncArtistHandler` | Fill one artist's empty details from MusicBrainz by the ID it holds |
| `SyncGenresMessage` | `SyncGenresHandler` | Queue a `SyncAlbumMessage` for every album and a `SyncSongMessage` for each of its songs; returns the number queued |
| `SyncSongMessage` | `SyncSongHandler` | Add the genres of the MusicBrainz recording found for the song's title |

Application enricher services (`SongMetadataEnricher`, and `MovieMetadataEnricher` in `Application/MovieMetadataEnricher.php`) perform the external lookups and write enriched fields and genres back to Catalog through its ports. `SongMetadataEnricher` writes genres only, and a forced run adds them the same way; a song's recording ID comes only from a Catalog match. Metadata has no album enricher: an album's MusicBrainz data comes from a match in Catalog, and no album lookup falls back to Discogs. Metadata has no artist enricher either: an artist's details come from Catalog's [artist sync](catalog-matching.md#artist-sync), by the MusicBrainz ID the artist holds.

## Album Syncs and Automatic Matching

Catalog owns MusicBrainz matching, the album match queue and the artist sync; see [Catalog](catalog.md). Metadata keeps four classes that Catalog reads, which form the `Metadata Sync Contract` Deptrac layer: `SyncAlbumMessage`, `SyncArtistsMessage`, `SyncArtistMessage` and `MetadataSettingDefinitions`, which defines the `metadata.auto_sync` system setting, labelled "Automatic MusicBrainz matching" and off by default. Metadata itself does not read the setting.

The library sync and the genre sync dispatch one `SyncAlbumMessage` per album on the Redis `async` transport. Catalog's `RequestAlbumMatchHandler` handles it by requesting the album's match as an admin request, which falls due at once whatever the setting says. Its `forceUpdate` flag, which the genre sync sets, changes nothing, because an automatic match only fills empty fields. Ingest requests automatic matches through Catalog's own `AlbumMatchRequestInterface`; nothing in Metadata takes part.

Without a source, the admin sync also dispatches one `SyncArtistsMessage` (`MetadataSyncOrchestrator::syncArtists()`), so it reports one job more than there are libraries. Catalog's handler queues one `SyncArtistMessage` per artist that holds a MusicBrainz ID and misses details, two seconds apart; an album or song match apply and an import queue the same message for the artists they identify. Both messages run on `async`, with the wire names `metadata.sync_artists` and `metadata.sync_artist`. The genre sync dispatches no artist sync.

The admin sync, `POST /api/admin/metadata/trigger-sync`, and `app:metadata:sync` dispatch `SyncMetadataCommand` and ignore the setting. The command runs it through `JobMonitorAdministrationInterface::runInline()`, which records the run in the job monitor. An unknown source is rejected with a 422 response or an invalid-input exit.

## Ports

| Port | Purpose |
|------|---------|
| `MetadataAdminPortInterface` | Sync status and provider list for the admin API and `app:metadata:status` and `app:metadata:providers` |
| `MediaFileMetadataInterface` | Reads the tags of a file on the server and ranks candidate records against them, for the extract and match routes and `app:metadata:file:extract` and `app:metadata:file:match` |
| `MusicBrainzLookupInterface` | Published contract for Catalog matching and the artist sync (the `Metadata MusicBrainz Lookup Contract` Deptrac layer, with its DTOs in `Application/DTO/MusicBrainz/`): searches and looks up MusicBrainz releases, recordings and artists, and fetches a release's front cover from the Cover Art Archive. No method throws; each answer tells a missing record, a refused request and an outage apart, and an artist lookup also answers `MusicBrainzMerged` for an ID MusicBrainz merged into another artist. `MusicBrainzAdapter` implements it |

## API Endpoints

All endpoints are prefixed with `/api`.

| Method | Path | Controller | Purpose |
|--------|------|------------|---------|
| GET | `/api/metadata/search/artist` | `MetadataSearchController` | Search external APIs for an artist |
| GET | `/api/metadata/search/album` | `MetadataSearchController` | Search external APIs for an album |
| GET | `/api/metadata/search/song` | `MetadataSearchController` | Search external APIs for a song |
| GET | `/api/metadata/browse/artist/{mbid}` | `MetadataBrowseController` | Browse MusicBrainz artist details |
| GET | `/api/metadata/browse/release-group/{mbid}` | `MetadataBrowseController` | Browse MusicBrainz release group details |
| POST | `/api/metadata/extract` | `MetadataSyncController` | Read the tags of a file on the server; admin only |
| POST | `/api/metadata/match` | `MetadataSyncController` | Rank candidate records against the tags of a file on the server; admin only |

The search and browse routes are open to any signed-in user. A MusicBrainz search or lookup there first spends the user's own allowance of one call per 10 seconds, the `musicbrainz_user` limiter keyed by user ID, and answers 429 when it is spent. It then waits at most 10 seconds for a turn under the `musicbrainz` limiter that every process shares, and answers 503 when no turn comes or the limiter state cannot be read. Discogs and Last.fm searches spend no allowance.

Extract and match open any path the server can read, so both need `ROLE_ADMIN`. A path with no file, or a file no tag reader supports, is rejected with 422. Both read files with the scan's own readers, so WAV and Opus files return their tags too.

### Admin — Metadata

Routes under `/api/admin/metadata` (controller `MetadataAdminController`, gated `ROLE_ADMIN`).

| Method | Path | Controller | Purpose |
|--------|------|------------|---------|
| GET | `/api/admin/metadata/sync-status` | `MetadataAdminController` | Current enrichment/sync status |
| POST | `/api/admin/metadata/trigger-sync` | `MetadataAdminController` | Queue a sync for every library and an artist sync, or with `source: genres` only the genre sync; returns the number of jobs queued |
| GET | `/api/admin/metadata/providers` | `MetadataAdminController` | List configured metadata providers |

### Console Commands

| Command | Class | Purpose |
|---------|-------|---------|
| `app:metadata:sync` | `MetadataSyncCommand` | Queue a metadata sync for every library and an artist sync, or with `--source=genres` only the genre sync |
| `app:metadata:status` | `MetadataStatusCommand` | Show metadata sync status: track coverage, last sync time, and sync job counts by type |
| `app:metadata:providers` | `MetadataProvidersCommand` | List metadata providers and whether each one is enabled and configured |
| `app:metadata:file:extract` | `MetadataFileExtractCommand` | Read the tags embedded in a media file on the server |
| `app:metadata:file:match` | `MetadataFileMatchCommand` | Rank candidate records by how well they fit the tags of a media file on the server |

## Cross-Context Dependencies

| Direction | Context | Relationship |
|-----------|---------|-------------|
| Depends on | Shared | Uses `Uuid` for identifiers, `PublicHttpClient` and `Async` for external HTTP calls, and `SettingDefinitionProviderInterface` to define `metadata.auto_sync` |
| Depends on | Catalog | Reads and updates albums, songs, movies, and genres via `AlbumPortInterface`, `SongPortInterface`, `MoviePortInterface`, and `GenrePortInterface`; `MetadataContentReader` implements `MetadataContentReaderPortInterface` |
| Depends on | Library | `MetadataSyncOrchestrator` looks up libraries through `LibraryPortInterface` |
| Depends on | Media | `ExtractAlbumCoverHandler` stores cover art via `ImagePortInterface` and `StoragePortInterface` |
| Depended on by | Catalog | Ingest reads embedded tags via `MetadataContentReaderPortInterface` and dispatches `ExtractAlbumCoverCommand`. `RequestAlbumMatchHandler` handles `SyncAlbumMessage`, `QueueArtistSyncsHandler` and `SyncArtistHandler` handle `SyncArtistsMessage` and `SyncArtistMessage`, and the album match queue and the artist sync requester read `metadata.auto_sync` through `MetadataSettingDefinitions` (the `Metadata Sync Contract` Deptrac layer) |
| Depended on by | Catalog | MusicBrainz matching and the artist sync search and read MusicBrainz through `MusicBrainzLookupInterface` (the `Metadata MusicBrainz Lookup Contract` Deptrac layer) |

## Infrastructure

| Component | Purpose |
|-----------|---------|
| `MetadataContentReader` | Implements Catalog's `MetadataContentReaderPortInterface` by detecting audio format via `FormatDetector` and delegating to the matching tag reader |
| `MediaFileMetadata` | Implements `MediaFileMetadataInterface` with `MetadataContentReader`'s readers and `MatchingStrategy`, so extract and match read every format the scan reads |
| `MetadataAdminService` | Implements `MetadataAdminPortInterface`, querying `songs`, `genre_song`, and `job_monitors` for sync status and reporting configured providers |
| `MetadataMessagePayloadCodec` | Encodes and decodes `ExtractAlbumCoverCommand`, `SyncMetadataCommand`, `SyncAlbumMessage`, `SyncLibraryMessage`, `SyncSongMessage`, `SyncArtistsMessage`, and `SyncArtistMessage` for the `async` transport and job monitor |

### External API Adapters

Each adapter lives under `Infrastructure/Api/<Provider>/` and includes its own DTOs for request/response mapping.

| Adapter | Location |
|---------|----------|
| `DiscogsAdapter` | `Infrastructure/Api/Discogs/` |
| `LastFmAdapter` | `Infrastructure/Api/LastFm/` |
| `SpotifyAdapter` | `Infrastructure/Api/Spotify/` |
| `MusicBrainzAdapter` | `Infrastructure/Api/MusicBrainz/` |
| `TasteDiveAdapter` | `Infrastructure/Api/TasteDive/` |
| `CoverArtArchiveAdapter` | `Infrastructure/Api/CoverArtArchive/` |
| `TmdbAdapter` | `Infrastructure/Api/Tmdb/` (`Infrastructure/Api/Tmdb/TmdbAdapter.php`; DTOs: `TmdbMovieDto`, `TmdbCollectionDto`, `TmdbGenreDto`, `TmdbSearchResultDto`) |

`MusicBrainzAdapter` implements `MusicBrainzLookupInterface` and keeps the older search and lookup methods that the song enricher and the open search and browse routes call. Every request it makes, including the Cover Art Archive fetch behind `fetchFrontCover()`, first reserves a turn under the `musicbrainz` rate limiter: one request per second, kept in Redis and shared by every process. Each call takes a `MusicBrainzSlotWait`. `Bounded`, the port's default and what the open routes pass, gives up after 10 seconds without a turn; `Unbounded`, the older methods' default, waits as long as the queue ahead needs. The wait for a turn, and the back-off after a 503 before the request is asked again, sleep through `Async::sleepThroughSignals()`, so they last their full time in a worker, whose keepalive alarm ends a plain blocking sleep early. Without a turn, a port method returns `MusicBrainzUnavailable` and an older method throws `ServiceUnavailableException`, which HTTP reports as 503. The port's searches quote and escape every field; the older searches escape the free text and quote an artist given with it. Either way, a title such as "AC/DC" is searched as text, not as Lucene syntax.

MusicBrainz answers the lookup of an ID it merged into another record with a permanent redirect (301) to the surviving record's lookup. The adapter follows no redirect. For `fetchArtist()` it reads the redirect and answers `MusicBrainzMerged` with the requested and the surviving ID, when the target is an artist lookup on `musicbrainz.org`; any other redirect counts as MusicBrainz being unavailable.

### Audio Format Readers

| Reader | Formats |
|--------|---------|
| `FlacReader` / `FlacParser` | FLAC |
| `Id3Reader` / `Id3Parser` | MP3 (ID3v2 tags) |
| `OggReader` / `OggParser` | OGG Vorbis and Opus |
| `WavReader` | WAV |

A `FormatDetector` selects the reader from the magic bytes of the file's header, and from the file extension when those match no format.

### Matching

The `Infrastructure/Matching/` directory contains strategies and validators for pairing scanned files with external records. `MatchingStrategy` defines the matching contract, and `Validator/` contains domain-specific validation rules.
