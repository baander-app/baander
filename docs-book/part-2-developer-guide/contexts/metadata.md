# Metadata

The Metadata context enriches catalog entities with data from external music APIs: Discogs, Last.fm, Spotify, MusicBrainz, and TasteDive. It handles metadata matching (pairing scanned files with external records), cover art retrieval, and audio file tag parsing (FLAC, ID3, OGG, WAV).

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
| `SyncLibraryMessage` | `SyncLibraryHandler` | Enrich all entities in a library |
| `SyncAlbumMessage` | `SyncAlbumHandler` | Enrich a single album |
| `SyncArtistMessage` | `SyncArtistHandler` | Enrich a single artist |
| `SyncGenresMessage` | `SyncGenresHandler` | Sync genre data |
| `SyncSongMessage` | `SyncSongHandler` | Enrich a single song |

## Automatic Sync of New Albums

While the `metadata.auto_sync` system setting is on, Catalog ingest asks Metadata to sync each album it creates. `FilesDiscoveredHandler` calls `AlbumMetadataSyncRequestInterface::requestSync()` once the flush that stores the new album has run, before it adds the album's songs or reports failed files. A retry or rescan finds the album and creates none, so it requests nothing. Because a retry would not request the sync again, the request is best-effort: if it throws, ingest logs the error with the album ID and carries on. A full library metadata sync from the admin panel picks the album up.

`AlbumMetadataSyncRequester` reads the setting on every call. When the setting is on, it calls `MetadataSyncOrchestrator::syncAlbum()` for each album. The orchestrator dispatches `SyncAlbumMessage`, which runs later from the `swoole_task` queue, or from the Redis `async` transport outside the Swoole server, so ingest does not wait for the external lookups. The setting is off by default.

The admin sync, `POST /api/admin/metadata/trigger-sync`, dispatches its own message and ignores the setting. No console command starts a sync.

## Ports

| Port | Purpose |
|------|---------|
| `MetadataAdminPortInterface` | Sync status, manual sync trigger and provider list for the admin API |
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
| POST | `/api/admin/metadata/trigger-sync` | `MetadataAdminController` | Trigger a metadata sync run |
| GET | `/api/admin/metadata/providers` | `MetadataAdminController` | List configured metadata providers |

## Cross-Context Dependencies

| Direction | Context | Relationship |
|-----------|---------|-------------|
| Depends on | Shared | Uses `Uuid` for identifiers, and `SystemSettingsPortInterface` for `metadata.auto_sync` |
| Depends on | Catalog | Updates albums, artists, and songs with enriched data |
| Depends on | Library | `MetadataSyncOrchestrator` looks up libraries through `LibraryPortInterface` |
| Depended on by | Catalog | Ingest requests a sync of each new album through `AlbumMetadataSyncRequestInterface` (the `Metadata Album Sync Request Contract` Deptrac layer) |

## Infrastructure

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
