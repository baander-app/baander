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
| `ReorderSongsCommand` | `ReorderSongsHandler` | Reorder songs against the displayed playlist revision |
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

Playlist detail responses include an opaque `revision` of 64 lowercase hexadecimal
characters. It describes the complete stored playlist snapshot used to assemble the
songs, including entries hidden from the viewer. List responses carry no revision.
The token changes after a persisted metadata, song or collaborator edit; song and
collaborator deletions cascaded from other contexts also invalidate it. Clients
must treat the token as opaque rather than deriving it from the visible songs.

Adding a song is an append operation. The handler refreshes the complete playlist,
including its parent metadata and collaborator identities, then checks the actor's
current editing authority and the song's visibility. A new membership receives one
greater than the largest stored position, or zero for an empty playlist. Hidden
songs count toward that ordering, and gaps left by catalog deletion do not cause
an append to reuse a surviving position. Existing positions are not renumbered.
The flat `201` response carries the public `songId` and the position from the
handler's returned membership. Adding a visible song already present succeeds
without a write and reports its existing position.

An addition committed before the handler's refreshed read is retained in the
append result. A conflicting write after that read still answers `409` through the
repository's existing compare-and-swap guard; the append handler does not retry it
automatically. A collaborator whose membership was removed or whose playlist had
collaboration disabled between those reads is refused, including for a duplicate
add. A playlist deleted before the mutation read answers `404`.

Reorder requests require `expectedRevision` alongside `songIds`. A missing token or
one outside the 64-character lowercase hexadecimal format answers `422`. The
handler loads the playlist, rechecks the actor's current editing permission, then
compares the revision before applying the requested order. A successful response
returns the resulting `revision`. A stale revision or a conflicting save answers
`409` through the standard error envelope. Refresh the detail and let the user
reconcile the new order before submitting another request; do not replay the old
reorder automatically. The partial reorder and song visibility rules above still
apply.

Collaborator addition is idempotent. Naming an existing collaborator succeeds
without a write. If another writer wins while a new collaborator is being saved,
the handler reloads the playlist and checks ownership again, for at most three
attempts in total. Exhaustion answers `409`; unrelated database failures are not
retried as conflicts.

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

Every save of an existing playlist uses compare-and-swap against its original
persistence version and complete song and collaborator membership. A stale writer
answers `409` instead of replacing the winning metadata or collections, and a
removed playlist cannot be recreated by a stale save. The repository also checks
membership removed by cascades and rolls back a conflicting write. Metadata-only
read projections cannot be saved as complete aggregates.

When a save runs inside an enclosing transaction, the aggregate's updated snapshot
remains provisional until that transaction commits. After an outer rollback,
callers must discard the retained aggregate and reload it; rollback does not rewind
the in-memory object.

### Services

| Service | Purpose |
|---------|---------|
| `PlaylistService` | Playlist and song management operations |
| `PlaylistCollaboratorResolver` | Adapter for the collaborator resolver port |
| `PlaylistSongVisibility` | Adapter for the song visibility port |
