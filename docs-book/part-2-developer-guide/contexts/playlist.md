# Playlist

The Playlist context manages user-created playlists (manual song collections) and smart playlists (dynamically populated by rules). It supports adding, removing, and reordering songs within a playlist.

## Domain Models

| Model | Type | Purpose |
|-------|------|---------|
| `Playlist` | Aggregate Root | A playlist with name, description, visibility, and song list |
| `SmartPlaylist` | Aggregate Root | A playlist with dynamic content populated by rules |
| `PlaylistSong` | Model | A song within a playlist with an ordered position |

## Domain Events

| Event | Purpose |
|-------|---------|
| `PlaylistCreated` | Emitted when a new playlist is created |
| `SmartPlaylistSynced` | Emitted when a smart playlist refreshes its contents |

## Commands and Handlers

| Command | Handler | Purpose |
|---------|---------|---------|
| `CreatePlaylistCommand` | `CreatePlaylistHandler` | Create a new playlist |
| `UpdatePlaylistCommand` | `UpdatePlaylistHandler` | Update playlist metadata |
| `AddSongCommand` | `AddSongHandler` | Add a song to a playlist |
| `RemoveSongCommand` | `RemoveSongHandler` | Remove a song from a playlist |

## Ports

| Port | Purpose |
|------|---------|
| `PlaylistPortInterface` | Playlist CRUD operations |

## API Endpoints

All endpoints are prefixed with `/api`.

| Method | Path | Controller | Purpose |
|--------|------|------------|---------|
| GET | `/api/playlists` | `PlaylistController` | List user's playlists (cursor-paginated) |
| POST | `/api/playlists` | `PlaylistController` | Create a new playlist |
| GET | `/api/playlists/{publicId}` | `PlaylistController` | Get a single playlist with its songs |
| PATCH | `/api/playlists/{publicId}` | `PlaylistController` | Update playlist metadata |
| DELETE | `/api/playlists/{publicId}` | `PlaylistController` | Delete a playlist |
| POST | `/api/playlists/{publicId}/songs` | `PlaylistController` | Add a song to a playlist |
| DELETE | `/api/playlists/{publicId}/songs/{songId}` | `PlaylistController` | Remove a song from a playlist |
| POST | `/api/playlists/{publicId}/reorder` | `PlaylistController` | Reorder songs within a playlist |

## Cross-Context Dependencies

| Direction | Context | Relationship |
|-----------|---------|-------------|
| Depends on | Shared | Uses `Uuid`, `PublicId`, `CursorPaginatedResponse` |
| Depends on | Catalog | References songs from the catalog |
| Depended on by | Activity | Playlist play events feed listen history |
| Depended on by | Recommendation | Playlist data informs music suggestions |

## Infrastructure

### Doctrine Entities

| Entity | Purpose |
|--------|---------|
| `PlaylistEntity` | Playlist persistence |
| `PlaylistSongEntity` | Playlist song entries with position |
| `PlaylistCollaboratorEntity` | Collaborator access to shared playlists |
| `PlaylistStatisticEntity` | Playlist usage statistics |

### Services

| Service | Purpose |
|---------|---------|
| `PlaylistService` | Playlist and song management operations |
