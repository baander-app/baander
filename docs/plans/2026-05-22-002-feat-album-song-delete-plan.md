# Album and Song Delete with File Cleanup

**Date:** 2026-05-22
**Status:** active
**Type:** feat
**Origin:** docs/brainstorms/2026-05-22-album-song-delete-requirements.md
**Sequence:** 002

---

## Summary

Admin-only delete endpoints for albums and songs with a two-step confirmation flow (preview → confirm) and optional file deletion. Album deletion cascades to all songs. Cover images are deleted by default. Database CASCADE handles junction table cleanup.

---

## Problem Statement

Baander has no way to delete albums or songs from the catalog. The existing `destroy()` methods on AlbumController and SongController are not admin-gated and do not handle file deletion. Users need a way to remove incorrect metadata, duplicate files, or unwanted content with clear visibility into what will be affected.

---

## Requirements

From the origin document (see origin):

1. **Two-step delete flow** - Preview endpoint returns what will be deleted (songs, files, playlists), then confirm endpoint executes
2. **Optional file deletion** - `deleteFile`/`deleteFiles` query parameter controls whether audio files are deleted
3. **Cover image deletion** - Deleted by default (`deleteCover=true`), opt-out available
4. **Admin-only access** - `#[IsGranted('ROLE_ADMIN')]` on all endpoints
5. **Album cascade behavior** - Deleting an album always deletes all its songs
6. **Playlist cleanup** - Automatic via database CASCADE on playlist_song table

---

## Scope Boundaries

### In scope
- Two admin controllers (`AdminAlbumController`, `AdminSongController`) under `/api/admin/*`
- Enhanced port interface methods for delete with file cleanup
- Preview endpoints showing affected playlists, file sizes
- File deletion via StoragePortInterface
- Cover image deletion via ImagePortInterface
- Repository query methods for playlist info

### Deferred for later (see origin)
- Soft delete / trash functionality
- Bulk delete operations
- Undo/restore capability
- Delete history/audit log

### Outside this product's identity (see origin)
- User-scoped deletions (admin can delete anything)
- Automatic deletion based on policies

---

## Key Technical Decisions

1. **New admin controllers, not modified existing ones** - Create `AdminAlbumController` and `AdminSongController` rather than modifying existing `AlbumController::destroy()` and `SongController::destroy()`. The existing endpoints remain for owner-initiated deletion via Voters; new admin-only endpoints get enhanced capabilities (preview, file deletion).

2. **Class-level security attribute** - Apply `#[IsGranted('ROLE_ADMIN')]` at the controller class level (following `AlbumCoverController` pattern) rather than per-method, ensuring all methods are admin-only.

3. **Query parameter for file deletion** - Use `?deleteFile=true` and `?deleteFiles=true&deleteCover=false` query parameters rather than request body. This keeps the DELETE interface simple and aligns with REST conventions for parameterized deletes.

4. **Storage deletion is idempotent** - `StoragePortInterface::delete()` and `FlysystemStorage::delete()` check file existence before deletion. Plan assumes this behavior—no additional guards needed.

5. **Playlist info from junction table** - Preview queries the `playlist_song` junction table directly via a new repository method. No new domain methods needed—this is admin read-only data for display purposes.

6. **Service layer orchestrates deletion** - Enhanced service methods (`AlbumService::deleteWithFiles()`, `SongService::deleteWithFiles()`) handle both database and file deletion. The repository layer remains focused on persistence only.

---

## Implementation Units

### U1. Add Playlist Repository Query Method

**Goal:** Enable admin preview to show which playlists contain a song.

**Requirements:** Preview endpoints must show affected playlists (see origin).

**Dependencies:** None

**Files:**
- `src/Playlist/Domain/Repository/PlaylistRepositoryInterface.php`
- `src/Playlist/Infrastructure/Doctrine/Repository/PlaylistRepository.php`

**Approach:**
- Add `findPlaylistNamesContainingSong(Uuid $songId): array` to PlaylistRepositoryInterface
- Implement via DQL query on `playlist_song` joining `playlist` table
- Return array of `['uuid' => string, 'name' => string]` for complete display data
- Controllers inject `PlaylistRepositoryInterface` directly (admin-only read access, no domain methods needed)

**Patterns to follow:**
- Existing `findWithSongs()` method in PlaylistRepository for DQL patterns
- Use `createQueryBuilder` with innerJoin on `ps.song` and `ps.playlist`

**Test scenarios:**
- Happy path: Song exists in 2 playlists, returns both with names and UUIDs
- Edge case: Song exists in no playlists, returns empty array
- Integration: Query joins correctly through junction table

**Verification:** Repository method returns playlist data for songs that exist in playlists; empty array for songs with no playlist entries.

---

### U2. Extend AlbumPortInterface::delete() with File Cleanup Parameters

**Goal:** Add optional parameters to existing port interface method for album deletion with file cleanup.

**Requirements:** Album deletion with optional file and cover deletion (see origin).

**Dependencies:** None

**Files:**
- `src/Catalog/Application/Port/AlbumPortInterface.php`

**Approach:**
- Add method signature:
  ```php
  public function delete(Album $album, bool $deleteFiles = false, bool $deleteCover = true): void;
  ```
- This extends the existing `delete(Album $album): void` method with optional parameters, maintaining backward compatibility via default values

**Patterns to follow:**
- No implementation in interface (port pattern)
- Boolean flags follow requirements doc defaults

**Test expectation:** none — interface change only

**Verification:** Interface compiles; existing delete method remains unchanged.

---

### U3. Extend SongPortInterface::delete() with File Cleanup Parameter

**Goal:** Add optional parameter to existing port interface method for song deletion with file cleanup.

**Requirements:** Song deletion with optional file deletion (see origin).

**Dependencies:** None

**Files:**
- `src/Catalog/Application/Port/SongPortInterface.php`

**Approach:**
- Add method signature:
  ```php
  public function delete(Song $song, bool $deleteFile = false): void;
  ```
- This extends the existing `delete(Song $song): void` method with an optional parameter, maintaining backward compatibility via default value

**Patterns to follow:**
- Same pattern as U2 for consistency

**Test expectation:** none — interface change only

**Verification:** Interface compiles; existing delete method remains unchanged.

---

### U4. Implement Enhanced AlbumService::delete() with File Cleanup

**Goal:** Service layer orchestrates album, song, file, and cover deletion.

**Requirements:** Album deletion cascades to songs with optional file deletion; cover deletion default true (see origin).

**Dependencies:** U2, U5

**Files:**
- `src/Catalog/Infrastructure/AlbumService.php`

**Approach:**
- Inject `StoragePortInterface $storage` and `ImagePortInterface $imagePort`
- Extend the existing `delete()` method to accept optional boolean flags:
  1. Fetch all songs for album via `SongPortInterface::findByAlbum()`
  2. If `deleteFiles=true`, iterate songs and call `$storage->delete($song->getPath())`
  3. If `deleteCover=true` and album has cover:
     - Call `$storage->delete($coverImage->getPath())` to delete the cover file from storage
     - Call `$imagePort->delete($coverImage)` to remove from database
  4. Call `$this->albumRepository->delete($album)` — CASCADE handles songs in database

**Patterns to follow:**
- Constructor injection pattern from existing services
- File deletion assumes idempotent storage implementation

**Technical design:**
```php
public function delete(Album $album, bool $deleteFiles = false, bool $deleteCover = true): void
{
    // Fetch songs before deletion
    $songs = $this->songPort->findByAlbum($album->getId(), limit: 1000);

    // Delete files if requested
    if ($deleteFiles) {
        foreach ($songs as $song) {
            $this->storage->delete($song->getPath());
        }
    }

    // Delete cover if requested (both file and database record)
    if ($deleteCover) {
        $coverImageId = $album->getCoverImageId();
        if ($coverImageId !== null) {
            $coverImage = $this->imagePort->findByUuid($coverImageId);
            if ($coverImage !== null) {
                $this->storage->delete($coverImage->getPath());
                $this->imagePort->delete($coverImage);
            }
        }
    }

    // Database deletion (CASCADE handles songs, junction tables)
    $this->albumRepository->delete($album);
}
```

**Test scenarios:**
- Happy path: Delete album with deleteFiles=true, files removed, album removed from DB
- Happy path: Delete album with deleteFiles=false, album removed from DB, files intact
- Edge case: Album with no songs, cover deleted successfully
- Edge case: Album with no cover, deletion proceeds without error
- Error path: Storage deletion fails (file missing), operation continues (idempotent)
- Integration: Songs removed from playlist_song via CASCADE

**Verification:** Service method deletes files when flag is true; cover deleted when flag is true (default); database cascade works; no errors when files or covers missing.

---

### U5. Implement Enhanced SongService::delete() with File Cleanup

**Goal:** Service layer orchestrates song and file deletion.

**Requirements:** Song deletion with optional file deletion (see origin).

**Dependencies:** U3

**Files:**
- `src/Catalog/Infrastructure/SongService.php`

**Approach:**
- Inject `StoragePortInterface $storage`
- Extend the existing `delete()` method to accept an optional boolean flag:
  1. If `deleteFile=true`, call `$storage->delete($song->getPath())`
  2. Call `$this->songRepository->delete($song)` — CASCADE handles junction tables

**Patterns to follow:**
- Same pattern as U4 for consistency

**Test scenarios:**
- Happy path: Delete song with deleteFile=true, file removed, song removed from DB
- Happy path: Delete song with deleteFile=false, song removed from DB, file intact
- Edge case: Song file doesn't exist, deletion proceeds (idempotent storage)
- Integration: Song removed from playlist_song via CASCADE

**Verification:** Service method deletes file when flag is true; database cascade works; no errors when file missing.

---

### U6. Create AdminAlbumController

**Goal:** Admin-only controller for album deletion with preview.

**Requirements:** Admin-only access, two-step flow (preview → confirm), optional file deletion (see origin).

**Dependencies:** U1, U2, U4

**Files:**
- `src/Catalog/Interface/Controller/AdminAlbumController.php` (new)
- Tests: `tests/Catalog/Interface/Controller/AdminAlbumControllerTest.php`

**Approach:**
- Class-level `#[IsGranted('ROLE_ADMIN')]` attribute
- Route prefix: `/api/admin/albums`
- Constructor injects `AlbumPortInterface`, `SongPortInterface`, `PlaylistRepositoryInterface`, `StoragePortInterface`, `ImagePortInterface`
- Methods:
  - `deletePreview(string $publicId)` - GET route, returns preview data
  - `delete(string $publicId, Request $request)` - DELETE route, extracts query params and calls `$this->albumPort->delete()` with file deletion flags
- Preview response structure matches origin doc:
  ```json
  {
    "album": { "id", "title", "songCount" },
    "files": { "count", "totalSize" },
    "coverImage": { "id", "path" } | null,
    "affected": { "playlists": count, "playlistNames": [] }
  }
  ```

**Patterns to follow:**
- `AlbumCoverController` for class-level security and response patterns
- `ArtistController::destroy()` for PublicId resolution pattern
- `ApiResponsesTrait` for JSON responses

**Technical design (preview method):**
```php
#[Route('/{publicId}/delete-preview', name: 'delete_preview', methods: ['GET'])]
public function deletePreview(string $publicId): JsonResponse
{
    $resolvedPublicId = PublicId::fromString($publicId);
    $album = $this->albumPort->findByPublicId($resolvedPublicId);
    if ($album === null) { return $this->notFound(); }

    $songs = $this->songPort->findByAlbum($album->getId(), limit: 1000);
    $totalSize = array_sum(array_map(fn($s) => $s->getSize(), $songs));

    // Get playlists affected (count and names) via PlaylistRepositoryInterface
    $playlistData = $this->getPlaylistDataForSongs($songs);

    return $this->successResponse([
        'album' => ['id' => $album->getPublicId()->toString(), 'title' => $album->getTitle(), 'songCount' => count($songs)],
        'files' => ['count' => count($songs), 'totalSize' => $totalSize],
        'coverImage' => $album->getCoverImageId() ? ['id' => $album->getCoverImageId()->toString()] : null,
        'affected' => ['playlists' => $playlistData['count'], 'playlistNames' => $playlistData['names']],
    ]);
}

private function getPlaylistDataForSongs(array $songs): array
{
    $allPlaylistNames = [];
    foreach ($songs as $song) {
        $playlists = $this->playlistRepo->findPlaylistNamesContainingSong($song->getId());
        $allPlaylistNames = array_merge($allPlaylistNames, $playlists);
    }
    $uniqueNames = array_unique($allPlaylistNames, SORT_REGULAR);
    $nameOnly = array_map(fn($p) => $p['name'], $uniqueNames);

    return [
        'count' => count($uniqueNames),
        'names' => array_values($nameOnly),
    ];
}
```

**Test scenarios:**
- Happy path: Preview returns correct album, song count, file size
- Happy path: Preview returns correct playlist count and names
- Edge case: Album not found, returns 404
- Edge case: Invalid PublicId, returns 422
- Security: Non-admin user cannot access (403)
- Security: Unauthenticated user cannot access (401)
- Happy path: Delete with deleteFiles=true removes files and album
- Happy path: Delete with deleteFiles=false keeps files
- Happy path: Delete with deleteCover=true removes cover
- Happy path: Delete with deleteCover=false keeps cover
- Integration: CASCADE removes songs from playlist_song

**Verification:** Preview endpoint returns correct data structure; delete endpoint respects flags; admin-only access enforced; responses return correct HTTP status codes.

---

### U7. Create AdminSongController

**Goal:** Admin-only controller for song deletion with preview.

**Requirements:** Admin-only access, two-step flow (preview → confirm), optional file deletion (see origin).

**Dependencies:** U1, U3, U5

**Files:**
- `src/Catalog/Interface/Controller/AdminSongController.php` (new)
- Tests: `tests/Catalog/Interface/Controller/AdminSongControllerTest.php`

**Approach:**
- Class-level `#[IsGranted('ROLE_ADMIN')]` attribute
- Route prefix: `/api/admin/songs`
- Constructor injects `SongPortInterface`, `PlaylistRepositoryInterface`, `StoragePortInterface`
- Methods:
  - `deletePreview(string $publicId)` - GET route, returns preview data
  - `delete(string $publicId, Request $request)` - DELETE route, extracts query param and calls `$this->songPort->delete()` with file deletion flag
- Preview response structure matches origin doc:
  ```json
  {
    "song": { "id", "title", "album": { "id", "title" } },
    "file": { "path", "size" },
    "affected": { "playlists": count, "playlistNames": [] }
  }
  ```

**Patterns to follow:**
- Same as U6 for consistency

**Technical design (preview method):**
```php
#[Route('/{publicId}/delete-preview', name: 'delete_preview', methods: ['GET'])]
public function deletePreview(string $publicId): JsonResponse
{
    $resolvedPublicId = PublicId::fromString($publicId);
    $song = $this->songPort->findByPublicId($resolvedPublicId);
    if ($song === null) { return $this->notFound(); }

    $album = $this->albumPort->findByUuid($song->getAlbumId());
    $playlists = $this->playlistRepo->findPlaylistNamesContainingSong($song->getId());
    $playlistNames = array_map(fn($p) => $p['name'], $playlists);

    return $this->successResponse([
        'song' => [
            'id' => $song->getPublicId()->toString(),
            'title' => $song->getTitle(),
            'album' => $album ? ['id' => $album->getPublicId()->toString(), 'title' => $album->getTitle()] : null,
        ],
        'file' => ['path' => $song->getPath(), 'size' => $song->getSize()],
        'affected' => ['playlists' => count($playlists), 'playlistNames' => array_values($playlistNames)],
    ]);
}
```

**Test scenarios:**
- Happy path: Preview returns correct song, album, file size
- Happy path: Preview returns correct playlist count and names
- Edge case: Song not found, returns 404
- Edge case: Invalid PublicId, returns 422
- Security: Non-admin user cannot access (403)
- Security: Unauthenticated user cannot access (401)
- Happy path: Delete with deleteFile=true removes file and song
- Happy path: Delete with deleteFile=false keeps file
- Integration: CASCADE removes song from playlist_song

**Verification:** Preview endpoint returns correct data structure; delete endpoint respects flag; admin-only access enforced; responses return correct HTTP status codes.

---

### U8. Wire Service Dependencies

**Goal:** Update services.yaml with constructor dependency hints for autowiring.

**Requirements:** Services must inject StoragePortInterface and ImagePortInterface.

**Dependencies:** U4, U5

**Files:**
- `config/services.yaml`

**Approach:**
- AlbumService already has AlbumRepositoryInterface alias; no changes needed for port
- SongService already has SongRepositoryInterface alias; no changes needed for port
- Verify autowiring works for new dependencies (StoragePortInterface, ImagePortInterface)
- Add explicit aliases only if autowiring fails

**Patterns to follow:**
- Existing port aliases in services.yaml

**Test expectation:** none — configuration only

**Verification:** Application boots without autowiring errors; services can be instantiated with new dependencies.

---

## Dependencies / Prerequisites

- Doctrine CASCADE configured on `album_id` in songs table (already exists)
- Doctrine CASCADE configured on junction tables (already exists)
- StoragePortInterface wired in services.yaml (already exists)
- ImagePortInterface wired in services.yaml (already exists)

---

## Risk Analysis & Mitigation

| Risk | Impact | Mitigation |
|------|--------|------------|
| File deletion fails (permissions, missing files) | Medium | Storage implementation is idempotent; checks existence before deletion |
| Album with thousands of songs causes performance issues | Low | Preview limit of 1000 songs; file deletion loop is linear but acceptable |
| Playlist query on junction table is slow | Low | Admin-only endpoint; not on hot path; can add index later if needed |
| Database CASCADE fails (foreign key constraint) | Low | CASCADE already configured; schema migration not needed |

---

## System-Wide Impact

### Authentication/Authorization
- New endpoints require `ROLE_ADMIN`
- Existing `AlbumController::destroy()` and `SongController::destroy()` remain unchanged (owner-initiated deletion via Voters)

### Database
- No schema changes required (CASCADE already configured)
- Junction table cleanup is automatic

### Storage
- Audio files and cover images deleted from filesystem when flags are set
- Idempotent deletion means re-running delete is safe

### Frontend
- New API endpoints available for admin UI integration
- Two-step flow requires UI to call preview then confirm

---

## Open Questions

None — all decisions are knowable from requirements and codebase patterns.

---

## Success Metrics

- Admins can delete songs via API with optional file deletion
- Admins can delete albums via API with optional file/cover deletion
- Preview accurately reports affected playlists, file sizes
- Database CASCADE cleans up junction tables
- No orphaned files remain when deleteFile/deleteFiles=true
- Admin-only access enforced on all endpoints
