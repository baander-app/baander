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
| `AddFavoriteCommand` | `AddFavoriteHandler` | Verify target visibility through `FavoriteTargetAccessInterface::assertVisible()`, then add a favorite (idempotent — returns existing if already favorited) |
| `RemoveFavoriteCommand` | `RemoveFavoriteHandler` | Remove a favorite (ownership-checked) |

`AddFavoriteHandler` calls `FavoriteTargetAccessInterface::assertVisible()` before reading or writing favorites. If the target song, album, or artist does not exist in a library visible to the user, `FavoriteTargetAccess` throws `NotFoundException` (HTTP `404`).

## Ports

| Port | Purpose |
|------|---------|
| `FavoritesPortInterface` | Favorite add/remove, lookup by public ID / user-and-entity, paginated listing and counts |
| `FavoriteTargetAccessInterface` | Assert that a song, album, or artist (`FavoriteType` + `PublicId`) exists in a library visible to the user (`src/Favorites/Application/Port/FavoriteTargetAccessInterface.php`) |

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
| Depends on | Shared | `Uuid`, `PublicId`, `PaginatedResponse`, `NotFoundException` |
| References | Catalog | Stores `entityPublicId` for songs / albums / artists (no hard import; `FavoriteTargetAccess` checks visibility via SQL) |

## Infrastructure

| Component | Type | Purpose |
|-----------|------|---------|
| `UserFavoriteEntity` | Doctrine entity | Persistence for the `UserFavorite` aggregate |
| `UserFavoriteRepository` | Doctrine repository | Implements `UserFavoriteRepositoryInterface`, which `FavoritesService` uses to back `FavoritesPortInterface` |
| `FavoritesService` | Port implementation | Backs `FavoritesPortInterface` |
| `FavoriteTargetAccess` | Port implementation | Implements `FavoriteTargetAccessInterface`, checking target existence and library access (`user_library_access`) |
| `FavoritesForeignKeys` | Doctrine foreign key provider | Declares `fk_user_favorites_user_id` (`user_favorites.user_id` → `users.id`, `ON DELETE CASCADE`) |

See the [Architecture](../architecture.md#bounded-context-map) page for context map placement.
