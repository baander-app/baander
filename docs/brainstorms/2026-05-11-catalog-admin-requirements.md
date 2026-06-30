# Requirements: Catalog Admin — Genres, Artists, Relationships

**Date:** 2026-05-11
**Status:** Draft
**Context:** Catalog bounded context (`App\Catalog`)

## Problem

The Catalog context has domain models and basic CRUD for genres, artists, albums, and songs, but lacks:

1. A critical bug where the artists page sends `undefined` as the artist ID, breaking artist detail navigation
2. CREATE endpoints for genres and artists
3. Any API surface for managing relationships between artists↔songs, artists↔albums, genres↔songs, and genres↔albums
4. Admin-only permission gating on mutations

## Goals

1. **Fix the `undefined` publicId bug** in `ArtistsPage.tsx` — the page accesses `artist.public_id` (snake_case) but the API returns `publicId` (camelCase)
2. **Add POST endpoints** for creating genres and artists (admin-only)
3. **Add relationship management endpoints** nested under parent resources (admin-only):
   - Artist ↔ Song: add/remove artists, change roles
   - Artist ↔ Album: add/remove artists, change roles
   - Genre ↔ Song: assign/remove genres
   - Genre ↔ Album: assign/remove genres (new `GenreAlbumEntity`, simple many-to-many)
4. **Admin role gating** on all mutation endpoints

## Non-goals

- Artist merging (separate concern)
- Genre-album position field (simple many-to-many only)
- Frontend admin UI (API-only for now)
- Batch relationship operations (can be added later)

## Approach Options

### Option A: Minimal — Fix + Create endpoints only

Fix the bug, add POST for genres/artists, skip relationship endpoints. Existing relationships continue to be managed only via metadata sync.

- **Pros:** Ships fast, minimal scope
- **Cons:** Doesn't solve the core relationship admin gap. Artists and genres can only be associated through file metadata sync.

### Option B: Full — Fix + Create + Relationship endpoints (recommended)

Fix the bug, add POST for genres/artists, add all relationship endpoints nested under parent resources. Uses existing join entities and the `ArtistRole` enum. Genre-album needs a new `GenreAlbumEntity` mirroring the existing pattern. Admin gating via existing role system.

- **Pros:** Complete solution, leverages existing infrastructure (join entities, ports, enum), no architectural changes
- **Cons:** More endpoints to build and test

### Option C: Full + Batch operations

Option B plus batch endpoints for bulk relationship changes (e.g., replace all genres on a song in one call).

- **Pros:** Better for admin UI workflows
- **Cons:** More complex, premature without a frontend consuming it

## Recommended Direction

**Option B.** The relationship infrastructure (join entities, `ArtistRole` enum, ports) already exists at the Doctrine level. The work is primarily API surface — controllers, request DTOs, port method additions, and a new `GenreAlbumEntity`. No architectural changes needed.

## Success Criteria

- [ ] Clicking an artist on `/artists` navigates to their detail page correctly (no more `undefined`)
- [ ] `POST /api/genres` creates a new genre (admin-only)
- [ ] `POST /api/artists` creates a new artist (admin-only)
- [ ] `POST /api/artists/{id}/songs` adds an artist-song relationship with role
- [ ] `DELETE /api/artists/{id}/songs/{songId}` removes the relationship
- [ ] `PATCH /api/artists/{id}/songs/{songId}` updates the role
- [ ] `POST /api/artists/{id}/albums` adds an artist-album relationship with role
- [ ] `DELETE /api/artists/{id}/albums/{albumId}` removes the relationship
- [ ] `PATCH /api/artists/{id}/albums/{albumId}` updates the role
- [ ] `POST /api/genres/{slug}/songs` assigns a genre to a song
- [ ] `DELETE /api/genres/{slug}/songs/{songId}` removes the assignment
- [ ] `POST /api/genres/{slug}/albums` assigns a genre to an album
- [ ] `DELETE /api/genres/{slug}/albums/{albumId}` removes the assignment
- [ ] All mutation endpoints return 403 for non-admin users

## Existing Infrastructure

| Asset | Location | Status |
|-------|----------|--------|
| `ArtistSongEntity` | `Catalog/Infrastructure/Doctrine/Entity/` | Exists, no API |
| `ArtistAlbumEntity` | `Catalog/Infrastructure/Doctrine/Entity/` | Exists, no API |
| `GenreSongEntity` | `Catalog/Infrastructure/Doctrine/Entity/` | Exists, no API |
| `GenreAlbumEntity` | `Catalog/Infrastructure/Doctrine/Entity/` | **Needs creation** |
| `ArtistRole` enum | `Catalog/Domain/ValueObject/` | Exists (Primary, Featured, Producer, etc.) |
| `GenrePortInterface` | `Catalog/Application/Port/` | Exists, needs relationship methods |
| `ArtistPortInterface` | `Catalog/Application/Port/` | Exists, needs relationship methods |
| `AdminRateLimitListener` | `Catalog/Infrastructure/EventListener/` | Exists for reference |

## Files That Will Change

### Bug fix
- `ui/web/src/features/catalog/pages/ArtistsPage.tsx` — fix `public_id` → `publicId`

### New files
- `src/Catalog/Infrastructure/Doctrine/Entity/GenreAlbumEntity.php`
- `src/Catalog/Interface/Request/CreateGenreRequest.php`
- `src/Catalog/Interface/Request/CreateArtistRequest.php`
- `src/Catalog/Interface/Request/ArtistSongRequest.php`
- `src/Catalog/Interface/Request/ArtistAlbumRequest.php`
- `src/Catalog/Interface/Request/GenreSongRequest.php`
- `src/Catalog/Interface/Request/GenreAlbumRequest.php`

### Modified files
- `src/Catalog/Interface/Controller/GenreController.php` — add `store`, `addSong`, `removeSong`, `addAlbum`, `removeAlbum`
- `src/Catalog/Interface/Controller/ArtistController.php` — add `store`, `addSong`, `removeSong`, `updateSongRole`, `addAlbum`, `removeAlbum`, `updateAlbumRole`
- `src/Catalog/Application/Port/GenrePortInterface.php` — add relationship methods
- `src/Catalog/Application/Port/ArtistPortInterface.php` — add relationship methods
- `src/Catalog/Infrastructure/GenreService.php` — implement new port methods
- `src/Catalog/Infrastructure/ArtistService.php` — implement new port methods
- `src/Catalog/Infrastructure/Doctrine/Repository/GenreRepository.php` — add relationship queries
- `src/Catalog/Infrastructure/Doctrine/Repository/ArtistRepository.php` — add relationship queries
- DB migration for `genre_album` table
