# Metadata

The Metadata context enriches catalog entities with data from external music and movie APIs: Discogs, Last.fm, Spotify, MusicBrainz, TasteDive, CoverArtArchive, and TMDB (`TmdbAdapter` and `MovieMetadataEnricher`). It handles metadata matching (pairing scanned files with external records), cover art retrieval, and audio file tag parsing (FLAC, ID3, OGG, WAV).

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
| `SyncMetadataCommand` | `SyncMetadataHandler` | The admin sync: without a source, one `SyncLibraryMessage` per library; with the `genres` source, only `SyncGenresMessage`. Returns the number of jobs queued |
| `SyncLibraryMessage` | `SyncLibraryHandler` | Enrich all entities in a library |
| `SyncAlbumMessage` | `SyncAlbumHandler` | Enrich a single album |
| `SyncArtistMessage` | `SyncArtistHandler` | Enrich a single artist |
| `SyncGenresMessage` | `SyncGenresHandler` | Queue a forced sync of every album and its songs; returns the number queued |
| `SyncSongMessage` | `SyncSongHandler` | Enrich a single song |

Application enricher services (`AlbumMetadataEnricher`, `ArtistMetadataEnricher`, `SongMetadataEnricher`, and `MovieMetadataEnricher` in `Application/MovieMetadataEnricher.php`) perform the external lookups and write enriched fields and genres back to Catalog through its ports.

## Automatic Sync of New Albums

While the `metadata.auto_sync` system setting is on, Catalog ingest asks Metadata to sync each album it creates. `FilesDiscoveredHandler` calls `AlbumMetadataSyncRequestInterface::requestSync()` once the flush that stores the new album has run, before it adds the album's songs or reports failed files. A retry or rescan finds the album and creates none, so it requests nothing. Because a retry would not request the sync again, the request is best-effort: if it throws, ingest logs the error with the album ID and carries on. A full library metadata sync from the admin panel picks the album up.

`AlbumMetadataSyncRequester` reads the setting on every call. When the setting is on, it calls `MetadataSyncOrchestrator::syncAlbum()` for each album. The orchestrator dispatches `SyncAlbumMessage`, which runs later from the Redis `async` transport, so ingest does not wait for the external lookups. The setting is off by default.

The admin sync, `POST /api/admin/metadata/trigger-sync`, and `app:metadata:sync` dispatch `SyncMetadataCommand` and ignore the setting. The command runs it through `JobMonitorAdministrationInterface::runInline()`, which records the run in the job monitor. An unknown source is rejected with a 422 response or an invalid-input exit.

## Ports

| Port | Purpose |
|------|---------|
| `MetadataAdminPortInterface` | Sync status and provider list for the admin API and `app:metadata:status` and `app:metadata:providers` |
| `AlbumMetadataSyncRequestInterface` | Published contract for Catalog ingest: requests a sync of newly created albums while `metadata.auto_sync` is on |

## API Endpoints

All endpoints are prefixed with `/api`.

| Method | Path | Controller | Purpose |
|--------|------|------------|---------|
| GET | `/api/metadata/search/artist` | `MetadataSearchController` | Search external APIs for an artist |
| GET | `/api/metadata/search/album` | `MetadataSearchController` | Search external APIs for an album |
| GET | `/api/metadata/search/song` | `MetadataSearchController` | Search external APIs for a song |
| GET | `/api/metadata/browse/artist/{mbid}` | `MetadataBrowseController` | Browse MusicBrainz artist details |
| GET | `/api/metadata/browse/release-group/{mbid}` | `MetadataBrowseController` | Browse MusicBrainz release group details |
| POST | `/api/metadata/extract` | `MetadataSyncController` | Extract metadata for a media file |
| POST | `/api/metadata/match` | `MetadataSyncController` | Match a scanned file against external records |

### Admin — Metadata

Routes under `/api/admin/metadata` (controller `MetadataAdminController`, gated `ROLE_ADMIN`).

| Method | Path | Controller | Purpose |
|--------|------|------------|---------|
| GET | `/api/admin/metadata/sync-status` | `MetadataAdminController` | Current enrichment/sync status |
| POST | `/api/admin/metadata/trigger-sync` | `MetadataAdminController` | Queue a sync for every library, or with `source: genres` only the genre sync; returns the number of jobs queued |
| GET | `/api/admin/metadata/providers` | `MetadataAdminController` | List configured metadata providers |

### Console Commands

| Command | Class | Purpose |
|---------|-------|---------|
| `app:metadata:sync` | `MetadataSyncCommand` | Queue a metadata sync for every library, or with `--source=genres` only the genre sync |
| `app:metadata:status` | `MetadataStatusCommand` | Show metadata sync status: track coverage, last sync time, and sync job counts by type |
| `app:metadata:providers` | `MetadataProvidersCommand` | List metadata providers and whether each one is enabled and configured |

## Cross-Context Dependencies

| Direction | Context | Relationship |
|-----------|---------|-------------|
| Depends on | Shared | Uses `Uuid` for identifiers, `PublicHttpClient` and `Async` for external HTTP calls, and `SystemSettingsPortInterface` for `metadata.auto_sync` |
| Depends on | Catalog | Updates albums, artists, songs, movies, and genres via `AlbumPortInterface`, `ArtistPortInterface`, `SongPortInterface`, `MoviePortInterface`, and `GenrePortInterface`; `MetadataContentReader` implements `MetadataContentReaderPortInterface` |
| Depends on | Library | `MetadataSyncOrchestrator` looks up libraries through `LibraryPortInterface` |
| Depends on | Media | `ExtractAlbumCoverHandler` stores cover art via `ImagePortInterface` and `StoragePortInterface` |
| Depended on by | Catalog | Ingest reads embedded tags via `MetadataContentReaderPortInterface`, dispatches `ExtractAlbumCoverCommand`, and requests a sync of each new album through `AlbumMetadataSyncRequestInterface` (the `Metadata Album Sync Request Contract` Deptrac layer) |

## Infrastructure

| Component | Purpose |
|-----------|---------|
| `MetadataContentReader` | Implements Catalog's `MetadataContentReaderPortInterface` by detecting audio format via `FormatDetector` and delegating to the matching tag reader |
| `MetadataAdminService` | Implements `MetadataAdminPortInterface`, querying `songs`, `genre_song`, and `job_monitors` for sync status and reporting configured providers |
| `MetadataMessagePayloadCodec` | Encodes and decodes `ExtractAlbumCoverCommand`, `SyncMetadataCommand`, `SyncAlbumMessage`, `SyncLibraryMessage`, and `SyncSongMessage` for the `async` transport and job monitor |

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

### Audio Format Readers

| Reader | Formats |
|--------|---------|
| `FlacReader` / `FlacParser` | FLAC |
| `Id3Reader` / `Id3Parser` | MP3 (ID3v2 tags) |
| `OggReader` / `OggParser` | OGG Vorbis |
| `WavReader` | WAV |

A `FormatDetector` selects the correct reader based on file MIME type.

### Matching

The `Infrastructure/Matching/` directory contains strategies and validators for pairing scanned files with external records. `MatchingStrategy` defines the matching contract, and `Validator/` contains domain-specific validation rules.
