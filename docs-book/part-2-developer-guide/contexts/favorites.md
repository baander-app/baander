# Favorites

The Favorites context lets a user mark catalog entities (songs, albums, artists) as favorites and remove them later. Favorites are scoped per-user and deduplicated by `(userId, entityType, entityPublicId)`.

## Domain Models

### Aggregate Roots

| Model | Purpose |
|-------|---------|
| `UserFavorite` | A user's favorite marker on a song, album, or artist |

### Value Objects

| Model | Purpose |
|-------|---------|
| `FavoriteType` | Favorite target enum (`song`, `album`, `artist`) |

## Commands & Handlers

| Command | Handler | Purpose |
|---------|---------|---------|
| `AddFavoriteCommand` | `AddFavoriteHandler` | Add a favorite (idempotent — returns existing if already favorited) |
| `RemoveFavoriteCommand` | `RemoveFavoriteHandler` | Remove a favorite (ownership-checked) |

## Ports

| Port | Purpose |
|------|---------|
| `FavoritesPortInterface` | Favorite add/remove, lookup by public ID / user-and-entity, paginated listing and counts |

## API Endpoints

All endpoints are prefixed with `/api` and require an authenticated user.

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/favorites/` | List the current user's favorites (paginated, optional `entityType` filter) |
| POST | `/api/favorites/` | Add a favorite |
| DELETE | `/api/favorites/{publicId}` | Remove a favorite |

## Cross-Context Relationships

| Direction | Context | Details |
|-----------|---------|---------|
| Depends on | Shared | `Uuid`, `PublicId`, `PaginatedResponse` |
| References | Catalog | Stores `entityPublicId` for songs / albums / artists (no hard import) |

## Infrastructure

| Component | Type | Purpose |
|-----------|------|---------|
| `UserFavoriteEntity` | Doctrine entity | Persistence for the `UserFavorite` aggregate |
| `UserFavoriteRepository` | Doctrine repository | Repository implementation backing `FavoritesPortInterface` |
| `FavoritesService` | Port implementation | Backs `FavoritesPortInterface` |

See the [Architecture](../architecture.md#bounded-context-map) page for context map placement.
