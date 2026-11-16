# Favorites

The Favorites context lets a user mark songs, albums and artists as favorites and remove them later. Each favorite belongs to one user, and a unique constraint on `(userId, entityType, entityPublicId)` keeps a user from holding the same favorite twice.

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
| `ArtistFavoriteMergePortInterface` | Published contract for Catalog's artist merge (the `Favorites Artist Merge Contract` Deptrac layer): moves a merged artist's favorites to the artist it merges into, and deletes a removed artist's favorites (`ArtistFavoriteMerge`) |
| `AlbumFavoriteMergePortInterface` | Published contract for Catalog's album merge (the `Favorites Album Merge Contract` Deptrac layer): makes each favorite of the merged album one of the target, and each favorite of a duplicate song one of the kept song, with its ID, public ID and creation time; a user who favorited both keeps the favorite of the target or the kept song (`AlbumFavoriteMerge`) |

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
| Depends on | Auth | `FavoritesController` identifies the user through `AuthenticatedUserIdentityInterface` |
| References | Catalog | Stores `entityPublicId` for songs / albums / artists (no hard import; `FavoriteTargetAccess` checks visibility via SQL) |
| Depended on by | Catalog | `ArtistMergeService` and `AlbumMergeService` keep favorites on an artist, album or song that exists through the two merge ports, as statements inside the merge's transaction |

## Infrastructure

| Component | Type | Purpose |
|-----------|------|---------|
| `UserFavoriteEntity` | Doctrine entity | Persistence for the `UserFavorite` aggregate |
| `UserFavoriteRepository` | Doctrine repository | Implements `UserFavoriteRepositoryInterface`, which `FavoritesService` uses to back `FavoritesPortInterface` |
| `FavoritesService` | Port implementation | Backs `FavoritesPortInterface` |
| `FavoriteTargetAccess` | Port implementation | Implements `FavoriteTargetAccessInterface`, checking target existence and library access (`user_library_access`) |
| `FavoritesForeignKeys` | Doctrine foreign key provider | Declares `fk_user_favorites_user_id` (`user_favorites.user_id` → `users.id`, `ON DELETE CASCADE`) |

See the [Architecture](../architecture/overview.md#bounded-context-map) page for context map placement.
