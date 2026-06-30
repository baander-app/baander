# Plan: Catalog Admin — Genres, Artists, Relationships

**Date:** 2026-05-11
**Requirements:** `docs/brainstorms/2026-05-11-catalog-admin-requirements.md`

## Problem summary

The Catalog context lacks admin capabilities for managing genres, artists, and their relationships. A critical bug (`artist.public_id` vs `publicId` in `ArtistsPage.tsx`) breaks artist navigation. Beyond the bug fix, the API needs: (1) POST endpoints for creating genres and artists, (2) relationship management endpoints for artist↔song, artist↔album, genre↔song, and genre↔album, and (3) admin-only gating on all mutations.

## Relevant learnings

No prior solutions in `docs/solutions/` for this domain.

## Scope boundaries

**In scope:**
- Fix ArtistsPage.tsx `public_id` → `publicId` bug
- POST /api/genres (create genre, admin-only)
- POST /api/artists (create artist, admin-only)
- Artist-song relationship CRUD (add/remove/update role)
- Artist-album relationship CRUD (add/remove/update role)
- Genre-song relationship CRUD (add/remove)
- Genre-album relationship CRUD (add/remove) — requires new `GenreAlbumEntity`
- Admin role gating (`#[IsGranted('ROLE_ADMIN')]`) on all mutation endpoints

**Out of scope:**
- Artist merging
- Batch relationship operations
- Frontend admin UI changes
- Genre-album position field

## Implementation units

### Unit 1: Fix ArtistsPage publicId bug

**Goal:** Fix the snake_case mismatch that causes `/api/artists/undefined`.

**Files:**
- Modify: `ui/web/src/features/catalog/pages/ArtistsPage.tsx`

**Patterns to follow:**
- The generated API client at `ui/web/src/shared/api-client/gen/endpoints/index.ts` uses `publicId` (camelCase)
- `ArtistGridItem.tsx` already receives `publicId` as a prop correctly

**Test scenarios:**
- Existing test: `ui/web/tests/features/catalog/pages/ArtistDetailPage.test.tsx` should pass
- Manual: navigate to `/artists`, click an artist, verify URL is `/artists/{publicId}` not `/artists/undefined`

**Verification:**
```bash
cd ui/web && npx vitest run tests/features/catalog/pages/
```

**Dependencies:** None.

---

### Unit 2: Create genre endpoint (POST /api/genres)

**Goal:** Allow admins to create new genres.

**Files:**
- Create: `src/Catalog/Interface/Request/CreateGenreRequest.php`
- Modify: `src/Catalog/Interface/Controller/GenreController.php` — add `store()` method

**Patterns to follow:**
- Existing `update()` method in `GenreController` for the request/response pattern
- `Genre::create()` factory method with name, slug, parent, mbid
- `#[IsGranted('ROLE_ADMIN')]` class-level attribute on `AlbumCoverController` and `ExtractCoversController`

**Test scenarios:**
- Happy: POST valid name+slug returns 201 with genre resource
- Error: empty name returns 422
- Error: invalid slug returns 422
- Error: unauthenticated returns 401
- Error: non-admin returns 403

**Verification:**
```bash
make phpunit -- --filter=GenreController
```

**Dependencies:** None.

---

### Unit 3: Create artist endpoint (POST /api/artists)

**Goal:** Allow admins to create new artists.

**Files:**
- Create: `src/Catalog/Interface/Request/CreateArtistRequest.php`
- Modify: `src/Catalog/Interface/Controller/ArtistController.php` — add `store()` method

**Patterns to follow:**
- `Artist::create()` factory method with name + optional metadata fields
- Same `#[IsGranted('ROLE_ADMIN')]` pattern as Unit 2
- Return `ArtistResource` with 201 status

**Test scenarios:**
- Happy: POST valid name returns 201 with artist resource
- Error: empty name returns 422
- Error: unauthenticated returns 401
- Error: non-admin returns 403

**Verification:**
```bash
make phpunit -- --filter=ArtistController
```

**Dependencies:** None.

---

### Unit 4: Genre-album entity + migration

**Goal:** Create the `genre_album` join table and Doctrine entity, mirroring `GenreSongEntity` without the position field.

**Files:**
- Create: `src/Catalog/Infrastructure/Doctrine/Entity/GenreAlbumEntity.php`
- Create: DB migration for `genre_album` table

**Patterns to follow:**
- `GenreSongEntity` for structure — same UUID PK, ManyToOne relations, unique constraint on `(genre_id, album_id)`
- Omit the `position` field (simple many-to-many)

**Test scenarios:**
- Migration runs without errors
- Entity persists and loads correctly (covered by Unit 7 integration)

**Verification:**
```bash
make phpunit -- --filter=GenreAlbum
make doctrine-migrate
```

**Dependencies:** None. Can run in parallel with Units 2 and 3.

---

### Unit 5: Genre relationship endpoints

**Goal:** Add endpoints for managing genre↔song and genre↔album relationships.

**Files:**
- Modify: `src/Catalog/Application/Port/GenrePortInterface.php` — add relationship methods
- Modify: `src/Catalog/Infrastructure/GenreService.php` — implement new methods
- Modify: `src/Catalog/Infrastructure/Doctrine/Repository/GenreRepository.php` — add relationship queries
- Modify: `src/Catalog/Interface/Controller/GenreController.php` — add `addSong`, `removeSong`, `addAlbum`, `removeAlbum`

**New port methods needed on `GenrePortInterface`:**
```php
public function addSongToGenre(Uuid $genreId, Uuid $songId): void;
public function removeSongFromGenre(Uuid $genreId, Uuid $songId): void;
public function addAlbumToGenre(Uuid $genreId, Uuid $albumId): void;
public function removeAlbumFromGenre(Uuid $genreId, Uuid $albumId): void;
```

**Endpoints:**
- `POST /api/genres/{slug}/songs` — body: `{ "songId": "uuid" }`, admin-only
- `DELETE /api/genres/{slug}/songs/{songId}` — admin-only
- `POST /api/genres/{slug}/albums` — body: `{ "albumId": "uuid" }`, admin-only
- `DELETE /api/genres/{slug}/albums/{albumId}` — admin-only

**Patterns to follow:**
- Existing `GenreSongEntity` for Doctrine queries
- New `GenreAlbumEntity` from Unit 4 for album queries
- `SongPortInterface` / `AlbumPortInterface` to resolve song/album by UUID

**Test scenarios:**
- Happy: add song to genre returns 204
- Happy: remove song from genre returns 204
- Happy: add album to genre returns 204
- Happy: remove album from genre returns 204
- Error: duplicate assignment returns 409
- Error: genre not found returns 404
- Error: song/album not found returns 404
- Error: non-admin returns 403

**Verification:**
```bash
make phpunit -- --filter=GenreController
```

**Dependencies:** Unit 4 (GenreAlbumEntity).

---

### Unit 6: Artist relationship endpoints

**Goal:** Add endpoints for managing artist↔song and artist↔album relationships with roles.

**Files:**
- Modify: `src/Catalog/Application/Port/ArtistPortInterface.php` — add relationship methods
- Modify: `src/Catalog/Infrastructure/ArtistService.php` — implement new methods
- Modify: `src/Catalog/Infrastructure/Doctrine/Repository/ArtistRepository.php` — add relationship queries
- Modify: `src/Catalog/Interface/Controller/ArtistController.php` — add `addSong`, `removeSong`, `updateSongRole`, `addAlbum`, `removeAlbum`, `updateAlbumRole`

**New port methods needed on `ArtistPortInterface`:**
```php
public function addSongToArtist(Uuid $artistId, Uuid $songId, string $role): void;
public function removeSongFromArtist(Uuid $artistId, Uuid $songId): void;
public function updateSongRole(Uuid $artistId, Uuid $songId, string $role): void;
public function addAlbumToArtist(Uuid $artistId, Uuid $albumId, string $role): void;
public function removeAlbumFromArtist(Uuid $artistId, Uuid $albumId): void;
public function updateAlbumRole(Uuid $artistId, Uuid $albumId, string $role): void;
```

**Endpoints:**
- `POST /api/artists/{publicId}/songs` — body: `{ "songId": "uuid", "role": "primary" }`, admin-only
- `DELETE /api/artists/{publicId}/songs/{songId}` — admin-only
- `PATCH /api/artists/{publicId}/songs/{songId}` — body: `{ "role": "featured" }`, admin-only
- `POST /api/artists/{publicId}/albums` — body: `{ "albumId": "uuid", "role": "primary" }`, admin-only
- `DELETE /api/artists/{publicId}/albums/{albumId}` — admin-only
- `PATCH /api/artists/{publicId}/albums/{albumId}` — body: `{ "role": "featured" }`, admin-only

**Patterns to follow:**
- `ArtistSongEntity` and `ArtistAlbumEntity` for Doctrine queries
- `ArtistRole` enum for valid role values
- Resolve `publicId` → `Uuid` using `ArtistPortInterface::findByPublicId()`

**Test scenarios:**
- Happy: add artist-song with role returns 204
- Happy: update role returns 204
- Happy: remove relationship returns 204
- Happy: add artist-album with role returns 204
- Error: duplicate (same artist+song+role) returns 409
- Error: invalid role returns 422
- Error: artist not found returns 404
- Error: song/album not found returns 404
- Error: non-admin returns 403

**Verification:**
```bash
make phpunit -- --filter=ArtistController
```

**Dependencies:** None. Can run in parallel with Units 4 and 5.

---

### Unit 7: DDD lint + documentation sync

**Goal:** Verify DDD conventions and update context documentation after all changes.

**Files:**
- Potentially: `src/Catalog/README.md`
- Potentially: `dev-docs/context-map.md`

**Patterns to follow:**
- Run `/dddlint` skill on modified PHP files
- Run `/documentation-maintainer update Catalog readme` after structural changes

**Test scenarios:**
- DDD lint passes with no violations
- Context documentation reflects new endpoints and port methods

**Verification:**
```bash
# DDD lint (manual skill invocation)
# Documentation update (manual skill invocation)
make phpunit -- tests/Unit/Catalog/
```

**Dependencies:** Units 2–6 complete.

## Dependency graph

```
Unit 1 (bug fix) ─────────────── independent, ship first
Unit 2 (create genre) ────────── independent
Unit 3 (create artist) ───────── independent
Unit 4 (GenreAlbumEntity) ────── independent
Unit 5 (genre relationships) ─── depends on Unit 4
Unit 6 (artist relationships) ── independent
Unit 7 (lint + docs) ──────────── depends on Units 2–6
```

**Parallelization:** Units 1, 2, 3, 4, 6 can all run in parallel. Unit 5 starts after Unit 4. Unit 7 runs last.

## Verification strategy

1. **Per-unit:** Each unit has its own test filter command
2. **Full suite:** `make phpunit -- tests/Unit/Catalog/` after all units
3. **Integration:** Run `make doctrine-migrate` to verify the new migration
4. **Manual smoke test:**
   - `GET /api/artists` → click artist → verify detail page loads (no `undefined`)
   - `POST /api/genres` with admin token → verify 201
   - `POST /api/artists/{id}/songs` → verify relationship created
   - `DELETE /api/genres/{slug}/albums/{albumId}` → verify removal
