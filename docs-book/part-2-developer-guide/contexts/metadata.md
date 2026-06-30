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

| Command | Handler | Purpose |
|---------|---------|---------|
| `ExtractAlbumCoverCommand` | `ExtractAlbumCoverHandler` | Extract and store cover art from an album |
| `SyncLibraryMessage` | `SyncLibraryHandler` | Enrich all entities in a library |
| `SyncAlbumMessage` | `SyncAlbumHandler` | Enrich a single album |
| `SyncArtistMessage` | `SyncArtistHandler` | Enrich a single artist |
| `SyncSongMessage` | `SyncSongHandler` | Enrich a single song |

## Ports

None. The Metadata context does not define application ports.

## API Endpoints

All endpoints are prefixed with `/api`.

| Method | Path | Controller | Purpose |
|--------|------|------------|---------|
| GET | `/api/metadata/search/artist` | `MetadataSearchController` | Search external APIs for an artist |
| GET | `/api/metadata/search/album` | `MetadataSearchController` | Search external APIs for an album |
| GET | `/api/metadata/search/song` | `MetadataSearchController` | Search external APIs for a song |
| GET | `/api/metadata/browse/artist/{mbid}` | `MetadataBrowseController` | Browse MusicBrainz artist details |
| GET | `/api/metadata/browse/release-group/{mbid}` | `MetadataBrowseController` | Browse MusicBrainz release group details |

## Cross-Context Dependencies

| Direction | Context | Relationship |
|-----------|---------|-------------|
| Depends on | Shared | Uses `Uuid` for identifiers |
| Depends on | Catalog | Updates albums, artists, and songs with enriched data |
| Depends on | Lyrics | Stores lyrics retrieved during enrichment |
| Depended on by | Library | Triggers enrichment after file scanning completes |

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
