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

| Command | Handler | Purpose |
|---------|---------|---------|
| `BatchExtractCoversCommand` | `BatchExtractCoversHandler` | Extract cover art from albums |

## Ports

| Port | Purpose |
|------|---------|
| `AlbumPortInterface` | Album CRUD operations |
| `ArtistPortInterface` | Artist CRUD operations |
| `GenrePortInterface` | Genre CRUD operations |
| `MoviePortInterface` | Movie CRUD operations |
| `SongPortInterface` | Song CRUD operations |

## Domain Events

| Event | Trigger |
|-------|---------|
| `AlbumCreated` | Album created |
| `MetadataSynced` | Metadata enrichment completed |
| `SongMetadataUpdated` | Song metadata updated |

## API Endpoints

All endpoints are prefixed with `/api`.

### Artists

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/artists` | List artists (cursor-paginated) |
| GET | `/api/artists/search` | Search artists (PGroonga) |
| GET | `/api/artists/{publicId}` | Single artist |
| PATCH | `/api/artists/{publicId}` | Update artist |
| DELETE | `/api/artists/{publicId}` | Delete artist |

### Albums

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/albums` | List albums (cursor-paginated) |
| GET | `/api/albums/search` | Search albums (PGroonga) |
| GET | `/api/albums/{publicId}` | Single album |
| PATCH | `/api/albums/{publicId}` | Update album |
| DELETE | `/api/albums/{publicId}` | Delete album |
| GET | `/api/albums/{publicId}/cover` | Album cover image |

### Songs

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/songs` | List songs (cursor-paginated) |
| GET | `/api/songs/search` | Search songs (PGroonga) |
| GET | `/api/songs/{publicId}` | Single song |
| PATCH | `/api/songs/{publicId}` | Update song |
| DELETE | `/api/songs/{publicId}` | Delete song |

### Movies

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/movies` | List movies (cursor-paginated) |
| GET | `/api/movies/search` | Search movies (PGroonga) |
| GET | `/api/movies/{publicId}` | Single movie |
| PATCH | `/api/movies/{publicId}` | Update movie |
| DELETE | `/api/movies/{publicId}` | Delete movie |

### Genres

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/genres` | List genres |
| POST | `/api/genres` | Create genre (ROLE_ADMIN) |
| GET | `/api/genres/{slug}` | Single genre |
| PATCH | `/api/genres/{slug}` | Update genre (ROLE_ADMIN) |
| DELETE | `/api/genres/{slug}` | Delete genre (ROLE_ADMIN) |

## Cross-Context Relationships

| Direction | Context | Details |
|-----------|---------|---------|
| Depends on | Shared | `Uuid`, `PublicId`, `CursorPage`, `PgroongaSearchTrait`, `Searchable` |
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

See the [Search](../search.md) page for details on PGroonga full-text search.
