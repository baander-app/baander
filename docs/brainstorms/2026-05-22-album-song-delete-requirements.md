# Album and Song Delete

**Date:** 2026-05-22
**Status:** Draft
**Related:** None

---

## Problem Statement

Currently, Baander has no way to delete albums or songs from the catalog. When users import incorrect metadata, duplicate files, or unwanted content, they must manually clean the database or live with the clutter. The system already supports deleting artists and movies, but albums and songs lack this capability.

---

## Goals

1. Enable deletion of individual songs from the catalog
2. Enable deletion of albums (with automatic cascade to songs)
3. Provide choice between metadata-only deletion and full file deletion
4. Show preview of what will be deleted before confirmation
5. Admin-only access to prevent accidental data loss

---

## Non-Goals

- Soft delete or trash/restore functionality
- Bulk delete operations (delete multiple albums/songs at once)
- User-scoped delete permissions (admin-only only)
- Undo/redo after deletion
- Deleting songs from within the album detail view (use individual song delete)

---

## Success Criteria

- Admins can delete songs with or without deleting the actual audio file
- Admins can delete albums, which cascades to all songs
- Before deletion, a preview shows exactly what will be affected
- Playlist entries are automatically cleaned up when referenced songs are deleted
- Album cover images are deleted when the album is deleted
- Database CASCADE handles junction table cleanup (artists, genres, playlists)

---

## Changes

### 1. Song Delete

#### 1.1 Preview Endpoint

**Endpoint:** `GET /api/admin/songs/{publicId}/delete-preview`

**Response:**
```json
{
  "song": {
    "id": "...",
    "title": "...",
    "album": { "id": "...", "title": "..." }
  },
  "file": {
    "path": "/music/artist/album/01-track.mp3",
    "size": 8493460
  },
  "affected": {
    "playlists": 2,
    "playlistNames": ["Chill Vibes", "Workout Mix"]
  }
}
```

#### 1.2 Delete Endpoint

**Endpoint:** `DELETE /api/admin/songs/{publicId}`

**Request Params:**
- `deleteFile` (boolean, optional, default: false) - Whether to delete the actual audio file

**Response:** 204 No Content

**Behavior:**
1. Fetch song by public ID
2. If `deleteFile=true`, call `StoragePortInterface::delete()` with the song's path
3. Delete song from database (CASCADE handles artist_song, genre_song, playlist_song)
4. Return 204

---

### 2. Album Delete

#### 2.1 Preview Endpoint

**Endpoint:** `GET /api/admin/albums/{publicId}/delete-preview`

**Response:**
```json
{
  "album": {
    "id": "...",
    "title": "...",
    "songCount": 12
  },
  "files": {
    "count": 12,
    "totalSize": 105896400
  },
  "coverImage": {
    "id": "...",
    "path": "/public/covers/album-123.jpg"
  },
  "affected": {
    "playlists": 5,
    "playlistNames": ["Chill Vibes", "Workout Mix", "Road Trip", "Focus", "Party"]
  }
}
```

#### 2.2 Delete Endpoint

**Endpoint:** `DELETE /api/admin/albums/{publicId}`

**Request Params:**
- `deleteFiles` (boolean, optional, default: false) - Whether to delete all audio files
- `deleteCover` (boolean, optional, default: true) - Whether to delete the cover image

**Response:** 204 No Content

**Behavior:**
1. Fetch album by public ID
2. Fetch all songs for the album
3. If `deleteFiles=true`, delete each song's file via `StoragePortInterface::delete()`
4. If `deleteCover=true` and album has a cover, delete the image via `ImagePortInterface::delete()`
5. Delete album from database (CASCADE handles all songs, artist_album, genre_album)

---

### 3. Backend Implementation

#### 3.1 Domain Layer

No domain changes required. Existing `Album` and `Song` aggregates work as-is.

#### 3.2 Port Interface

Add to `AlbumPortInterface`:
```php
public function delete(Album $album, bool $deleteFiles = false, bool $deleteCover = true): void;
```

Add to `SongPortInterface`:
```php
public function delete(Song $song, bool `deleteFile = false): void;
```

#### 3.3 Service Layer

Implement in `AlbumService`:
- `delete()` method that handles file deletion and album deletion
- Queries all songs in album before deletion to process files

Implement in `SongService`:
- `delete()` method that handles file deletion and song deletion

#### 3.4 Controllers

**New:** `AdminSongController` in `src/Catalog/Interface/Controller/`
- Route prefix: `/api/admin/songs`
- Methods: `deletePreview()`, `delete()`
- Attribute: `#[IsGranted('ROLE_ADMIN')]`

**New:** `AdminAlbumController` in `src/Catalog/Interface/Controller/`
- Route prefix: `/api/admin/albums`
- Methods: `deletePreview()`, `delete()`
- Attribute: `#[IsGranted('ROLE_ADMIN')]`

---

### 4. Frontend Implementation

#### 4.1 UI Entry Points

1. **Album detail page** - "Delete album" button in admin menu
2. **Song detail page** - "Delete song" button in admin menu
3. **Album list** - Delete option in context menu (admin-only)
4. **Song list** - Delete option in context menu (admin-only)

#### 4.2 Delete Confirmation Dialog

**Component:** `DeleteConfirmDialog.tsx`

**Props:**
- `type`: 'album' | 'song'
- `previewData`: from preview endpoint
- `onConfirm`: (options: { deleteFiles?: boolean, deleteCover?: boolean }) => void

**Layout:**
```
┌─────────────────────────────────────────┐
│ Delete Album: [Album Title]?            │
├─────────────────────────────────────────┤
│ This will delete:                       │
│ • 12 songs                              │
│ • 105.9 MB of files                     │
│ • 5 playlist entries                    │
│                                         │
│ [ ] Delete audio files from disk        │
│ [✓] Delete cover image                  │
│                                         │
│ [Cancel]              [Delete Album]    │
└─────────────────────────────────────────┘
```

**States:**
1. Loading - fetching preview data
2. Preview - showing what will be deleted
3. Confirming - executing deletion
4. Error - show error message with retry option
5. Success - close dialog, show toast, navigate away

#### 4.3 Toast Notifications

- Song deleted: "Deleted [Song Title]"
- Album deleted: "Deleted [Album Title] and 12 songs"
- Error: "Failed to delete: [reason]"

---

## Scope Boundaries

### Deferred for later
- Soft delete / trash functionality
- Bulk delete operations
- Undo/restore capability
- Delete history/audit log
- Delete by filter criteria (e.g., "delete all songs before 2020")

### Outside this product's identity
- User-scoped deletions (admin can delete anything in any library)
- Automatic deletion based on rules or policies
- Client-side file management (this is server-side catalog management)

---

## Dependencies / Assumptions

**Dependencies:**
- `StoragePortInterface` for file deletion
- `ImagePortInterface` for cover deletion
- Existing `Album`, `Song` domain models
- Doctrine CASCADE on junction tables (already configured)

**Assumptions:**
- Database CASCADE handles junction table cleanup (artist_song, genre_song, playlist_song, artist_album, genre_album)
- File paths in Song entities are accurate and exist
- Admin users understand the difference between metadata deletion and file deletion
- Storage deletion is idempotent (deleting a non-existent file doesn't error)

---

## Resolved Decisions

| Question | Decision |
|----------|----------|
| Who can delete? | Admin only (ROLE_ADMIN) |
| Delete files or just metadata? | Configurable per delete via request params |
| Album delete behavior? | Always cascades to all songs (no option to keep songs) |
| Cover image handling? | Deleted by default when album is deleted (opt-out via `deleteCover=false`) |
| Preview before delete? | Yes, two-step flow: preview then confirm |
| Playlist cleanup? | Automatic via database CASCADE on playlist_song table |
| Where accessible in UI? | Admin context menu + detail page delete button |
