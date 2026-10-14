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
| `AddCollaboratorCommand` | `AddCollaboratorHandler` | Name a collaborator on a playlist |
| `RemoveCollaboratorCommand` | `RemoveCollaboratorHandler` | Remove a collaborator, or let one leave |

## Ports

| Port | Purpose |
|------|---------|
| `PlaylistPortInterface` | Playlist CRUD operations |
| `PlaylistCollaboratorResolverInterface` | Turns a user's public id into the user id, through the Auth user-identifier contract's public-id lookup only |
| `PlaylistSongVisibilityInterface` | Answers whether a song is visible under a read scope, through the Catalog song-lookup contract |

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
| PUT | `/api/playlists/{publicId}/collaborators/{userPublicId}` | `PlaylistController` | Name a collaborator (owner only; idempotent) |
| DELETE | `/api/playlists/{publicId}/collaborators/{userPublicId}` | `PlaylistController` | Remove a collaborator (owner, repeat without effect), or leave (the collaborator's own id) |

## Collaborators and authorization

A playlist belongs to its owner and may be shared with named collaborators. The owner names and removes collaborators by the user's public id; a value that is not a public id answers `404` from the router before any lookup, an unknown user answers `404`, and the owner naming themself answers `422`. A collaborator may view the playlist and, while the owner keeps `isCollaborative` on, add, remove and reorder its songs; turning the flag off keeps the collaborator rows and ends their editing until it is turned on again. A collaborator may leave by deleting their own public id; a user who holds no collaborator row is refused as a leaver before the named user is looked up, so a second leave answers `403`. The owner alone updates metadata, deletes the playlist and manages collaborators; an administrator who is not the owner may view and nothing more; anyone else is refused.

`PlaylistVoter` decides every route on the aggregate with six attributes (`VIEW`, `EDIT_SONGS`, `EDIT`, `DELETE`, `MANAGE_COLLABORATORS`, `LEAVE`), and the controller refuses with one message per attribute, so a refusal names the rule that decided. A song is added only when the acting user can see it through their own library membership, judged by `PlaylistSongVisibilityInterface`; an unknown or invisible song answers `404`. Reordering places the listed songs first and keeps the entries it was not told about after them, so a client that sees only part of a playlist cannot delete the rest. Every song count a response carries is the viewer's visible count, and the list includes the playlists the actor collaborates on. Responses name the owner and the collaborators by public id; no user UUID leaves the API.

`tests/Integration/PlaylistMutationFirewallTest.php` proves this contract for owner, collaborator, collaborator with the flag off, unrelated member, administrator and anonymous on every mutation and both collaborator routes, through the production firewall.

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
| `PlaylistCollaboratorResolver` | Adapter for the collaborator resolver port |
| `PlaylistSongVisibility` | Adapter for the song visibility port |
