# Fix Discover Recommendations Display

**Date:** 2026-05-23
**Status:** Ready for Planning
**Related:** None

## Problem Statement

The browse → discover page displays raw recommendation data instead of user-friendly information:
- Album/artist UUIDs are shown as titles
- Type labels ("album"/"artist") are shown as subtitles
- No cover art is displayed

## Context

The `/api/recommendations/` endpoint returns raw recommendation records with IDs and types but not the display data for target entities. The `/for-you` endpoint already implements enrichment for song recommendations — this fix applies the same pattern to albums and artists in the discover view.

## Success Criteria

1. Album recommendations display the album title as the primary label
2. Album recommendations display the artist name as the subtitle (not "album")
3. Artist recommendations display the artist name as both title and subtitle
4. Cover art displays when available
5. SVG placeholder displays when cover art is missing
6. No UUIDs or internal type labels are visible to users

## Requirements

### Backend

**Enrich recommendation response with target entity data:**

Modify `GetRecommendationsForUserHandler` to:
1. Fetch all recommendations for the user
2. Group by source entity (existing behavior)
3. For each unique target ID, fetch the corresponding Album or Artist entity
4. Build enriched response including:
   - `target_title`: Album title or artist name
   - `target_artist_name`: Artist name for albums (null for artists)
   - `cover_image_url`: `/api/images/{id}` if cover image ID exists, null otherwise

**Response structure:**
```php
[
    'id' => string,
    'name' => string, // recommendation source name (for grouping)
    'source_type' => string,
    'source_id' => string,
    'target_type' => string,
    'target_id' => string,
    'score' => float,
    'position' => int,
    'target_title' => string, // NEW
    'target_artist_name' => ?string, // NEW
    'cover_image_url' => ?string, // NEW
    'user_id' => ?string,
    'created_at' => string,
    'updated_at' => string,
]
```

**Repositories to use:**
- `AlbumRepositoryInterface::findByUuids()` - bulk fetch albums
- `AlbumRepositoryInterface::getArtistNamesForAlbums()` - bulk fetch artist names
- `ArtistRepositoryInterface::findByUuid()` or new bulk method - fetch artists

### Frontend

**Update TypeScript types:**

Update `ui/web/src/features/catalog/types/recommendation.ts`:
```typescript
export interface Recommendation {
  id: string
  name: string
  source_type: string
  source_id: string
  target_type: string
  target_id: string
  score: number
  position: number
  target_title: string // NEW
  target_artist_name?: string | null // NEW
  cover_image_url?: string | null // NEW
  user_id: string | null
  created_at: string
  updated_at: string
}
```

**Update RecommendationClusterRow component:**

Modify `ui/web/src/features/catalog/components/RecommendationCluster.tsx`:
1. Display `rec.target_title` instead of `rec.target_id`
2. Display `rec.target_artist_name` (if album) or nothing (if artist) as subtitle
3. Use `useImageBlob` hook for `rec.cover_image_url`
4. Render `img` tag when `src` is available, otherwise show SVG placeholder (existing pattern)
5. Follow existing `AlbumGridCard` pattern for image rendering with blurhash/dominant color support (if applicable)

## Scope Boundaries

### Included
- Album and artist recommendation display fixes
- Cover art display with SVG placeholder fallback
- Backend response enrichment
- Frontend type and component updates

### Excluded
- Song recommendations in discover (handled by `/for-you` endpoint)
- Pagination changes
- New recommendation strategies
- Performance optimization beyond the basic batch fetching
- Mobile/RN UI updates (if applicable)

### Deferred
- Batch fetching for artists (may need `findByUuids` method added)
- Blurhash/dominant color support for recommendations (can add later)

## Dependencies / Assumptions

- `AlbumRepositoryInterface::findByUuids()` exists and works
- `AlbumRepositoryInterface::getArtistNamesForAlbums()` exists and works
- `ArtistRepositoryInterface` may need a bulk `findByUuids()` method
- Image API endpoint `/api/images/{id}` works with authenticated requests via `AXIOS_INSTANCE`
- No other API consumers depend on the current response structure
