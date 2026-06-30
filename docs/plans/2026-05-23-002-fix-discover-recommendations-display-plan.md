# Fix Discover Recommendations Display

**Date:** 2026-05-23
**Status:** active
**Type:** fix
**Origin:** `docs/brainstorms/fix-discover-recommendations-display-requirements.md`

## Problem Statement

The browse → discover page displays raw recommendation data instead of user-friendly information:
- Album/artist UUIDs are shown as titles
- Type labels ("album"/"artist") are shown as subtitles
- No cover art is displayed

The `/api/recommendations/` endpoint returns raw recommendation records with IDs and types but not the display data for target entities.

## Success Criteria

1. Album recommendations display the album title as the primary label.
2. Album recommendations display the artist name as the subtitle (not "album").
3. Artist recommendations display the artist name as both title and subtitle.
4. Cover art displays when available.
5. SVG placeholder displays when cover art is missing.
6. No UUIDs or internal type labels are visible to users.

## Scope Boundaries

### Included
- Album and artist recommendation display fixes
- Cover art display with SVG placeholder fallback
- Backend response enrichment
- Frontend type and component updates
- Adding `findByUuids()` method to `ArtistRepositoryInterface`

### Excluded
- Song recommendations in discover (handled by `/for-you` endpoint)
- Pagination changes
- New recommendation strategies
- Performance optimization beyond basic batch fetching
- Mobile/RN UI updates

### Deferred to Follow-Up Work
- Blurhash/dominant color support for recommendations

## Key Technical Decisions

**Add bulk fetch method for artists.** `ArtistRepositoryInterface` lacks `findByUuids()` unlike `AlbumRepositoryInterface`. Adding this method provides a consistent pattern, enables efficient bulk fetching, and avoids N+1 queries in the recommendation handler.

**Enrichment happens in the handler, not the resource.** Following the `GetPersonalizedRecommendationsHandler` pattern, the enrichment logic lives in `GetRecommendationsForUserHandler` because it requires additional repository lookups. The resource layer remains a simple transformation of enriched data.

**Image URLs use public IDs.** Cover image URLs are constructed as `/api/images/{publicId}/file` using the image's public ID, not internal UUID. This matches the pattern used in `AlbumController` and `ImageController`. The handler will inject `RequestStack` to get the current request's scheme and host for URL construction.

**Frontend handles missing data gracefully.** The component checks for existence of `target_title` and `cover_image_url` before rendering, falling back to placeholder behavior for backwards compatibility with any unenriched responses. Empty string is treated as missing (fallback to target_id) to handle edge cases consistently.

**Cross-context repository access follows existing pattern.** The handler injects Catalog repositories directly, matching `GetPersonalizedRecommendationsHandler`. This is documented technical debt — a future refactor should introduce Catalog ports (`AlbumLookupPortInterface`, `ArtistLookupPortInterface`) to properly respect bounded context boundaries.

## Implementation Units

### U1. Add findByUuids() to ArtistRepositoryInterface

**Goal:** Enable bulk fetching of artists by UUID, matching the pattern already established for `AlbumRepositoryInterface`.

**Requirements:** R1, R6 (from origin doc — efficient data fetching)

**Dependencies:** None

**Files:**
- `src/Catalog/Domain/Repository/ArtistRepositoryInterface.php`
- `src/Catalog/Infrastructure/Doctrine/Repository/ArtistRepository.php`
- `src/Catalog/Infrastructure/Doctrine/Entity/ArtistEntity.php`
- `tests/Functional/Repository/ArtistRepositoryTest.php` (create if not exists)

**Approach:**
1. Add `findByUuids(array $uuids): array<string, Artist>` method to the interface with PHPDoc indicating it returns an array keyed by UUID string
2. Implement in `ArtistRepository` using DQL or `findBy()` with criteria, matching the `AlbumRepository::findByUuids()` pattern
3. The implementation should handle empty arrays gracefully (return empty array)

**Patterns to follow:**
- `AlbumRepositoryInterface::findByUuids()` signature and PHPDoc
- `AlbumRepository::findByUuids()` implementation pattern

**Test scenarios:**
- Happy path: Fetch multiple artists by valid UUIDs returns correctly keyed array
- Happy path: Result array keys exactly match input UUID strings (contract verification)
- Edge case: Empty array input returns empty array without database query
- Edge case: Non-existent UUIDs are ignored (not in result)
- Edge case: Single UUID input returns correctly keyed array
- Edge case: Duplicate UUIDs in input return single result (no duplicates)

**Verification:** Method exists on interface, implementation returns array keyed by UUID strings, test passes.

---

### U2. Implement GetRecommendationsForUserHandler Enrichment

**Goal:** Modify the handler to fetch and include album/artist display data (names, cover art URLs) in the API response.

**Requirements:** R1-R5 (all display success criteria), R6 (backend enrichment)

**Dependencies:** U1

**Files:**
- `src/Recommendation/Application/QueryHandler/GetRecommendationsForUserHandler.php`
- `src/Recommendation/Interface/Resource/RecommendationResource.php`
- `tests/Functional/Recommendation/QueryHandler/GetRecommendationsForUserHandlerTest.php` (create if not exists)

**Approach:**
1. Inject `AlbumRepositoryInterface`, `ArtistRepositoryInterface`, `ImagePortInterface`, and `RequestStack` into the handler constructor
2. After fetching recommendations, separate target IDs by type (album vs artist)
3. Bulk fetch albums using `AlbumRepositoryInterface::findByUuids()`
4. Bulk fetch artists using new `ArtistRepositoryInterface::findByUuids()` from U1
5. Bulk fetch cover images using `ImagePortInterface::findByUuids()` (collect all non-null coverImageIds)
6. Bulk fetch artist names for albums using `AlbumRepositoryInterface::getArtistNamesForAlbums()`
7. Build enriched response, adding `target_title`, `target_artist_name`, and `cover_image_url` fields
8. Get scheme and host from `RequestStack::getCurrentRequest()` to construct full image URLs (`/api/images/{publicId}/file`)
9. For albums: `target_title` = album title, `target_artist_name` = first artist name or null if no artists
10. For artists: `target_title` = artist name, `target_artist_name` = null
11. Handle missing entities gracefully: return recommendation with null display fields, don't skip
12. Handle image lookup failures: set `cover_image_url` to null, don't fail the entire response

**Patterns to follow:**
- `GetPersonalizedRecommendationsHandler` lines 63-89 (aggregation and enrichment pattern)
- `AlbumController` lines 111-126 (image URL construction pattern)

**Test scenarios:**
- Happy path: Album recommendations include title, artist name, and cover URL
- Happy path: Artist recommendations include name and cover URL
- Happy path: Image URLs include scheme, host, and path (`/api/images/{publicId}/file`)
- Edge case: Recommendation targets missing album/artist returns with null display fields
- Edge case: Album without cover art has `cover_image_url: null`
- Edge case: Album with no associated artists has `target_artist_name: null`
- Edge case: Album with multiple artists shows first artist name
- Edge case: Image lookup failure returns recommendation with `cover_image_url: null`
- Edge case: Partial image lookup results (some found, some not) handles both cases correctly
- Edge case: Empty recommendations list returns empty array
- Edge case: Large recommendation set (200 items) completes without N+1 queries

**Verification:** API response includes new fields for all recommendations, curl/Postman test shows enriched data.

---

### U3. Update Frontend TypeScript Types

**Goal:** Add new optional fields to the `Recommendation` interface to match the enriched API response.

**Requirements:** R1-R5 (display success criteria)

**Dependencies:** U2

**Files:**
- `ui/web/src/features/catalog/types/recommendation.ts`

**Approach:**
1. Add `target_title?: string` field
2. Add `target_artist_name?: string | null` field
3. Add `cover_image_url?: string | null` field

Fields are optional to maintain backwards compatibility in case of API rollback or unenriched responses.

**Patterns to follow:**
- Existing TypeScript optional field patterns in the file

**Test expectation:** none -- type-only change, runtime behavior unchanged

**Verification:** TypeScript compilation succeeds, no type errors in components using the interface.

---

### U4. Update RecommendationClusterRow Component

**Goal:** Render proper album/artist names and cover art instead of UUIDs and type labels.

**Requirements:** R1-R5 (all display success criteria)

**Dependencies:** U3

**Files:**
- `ui/web/src/features/catalog/components/RecommendationCluster.tsx`
- `ui/web/src/features/catalog/components/__tests__/RecommendationCluster.test.tsx` (create if not exists)

**Approach:**
1. Import `useImageBlob` hook from `@/shared/hooks/use-image-blob`
2. Replace `rec.target_id` display with `rec.target_title ?? rec.target_id` (null coalescing, not OR, to distinguish empty string from null)
3. Replace `rec.target_type` display with conditional logic:
   - If `rec.target_artist_name` exists and is non-empty, display it (album case)
   - Otherwise, show nothing (artist case shows name in title only)
4. Add `useImageBlob` hook for each recommendation's `cover_image_url`
5. Render cover image using `<img>` tag when `src` is available
6. Keep existing SVG placeholder as fallback when no image
7. Follow `AlbumGridCard` pattern for image rendering structure

**Patterns to follow:**
- `AlbumGridCard.tsx` lines 62-91 (image rendering with fallback)
- `TimelineYear.tsx` (useImageBlob usage pattern)

**Test scenarios:**
- Happy path: Album recommendation displays album title and artist name
- Happy path: Artist recommendation displays artist name with no subtitle
- Happy path: Cover image renders when URL provided
- Edge case: Missing cover image displays SVG placeholder (null, undefined, or empty URL)
- Edge case: Missing target_title (null/undefined) falls back to target_id
- Edge case: Empty string target_title displays empty string (not fallback)
- Edge case: Missing target_artist_name shows no subtitle
- Integration: Component unmounts without blob URL memory leaks
- Integration: Rapid mount/unmount cycles handle blob cleanup correctly

**Verification:** Component renders names instead of UUIDs, cover images display when available, SVG placeholder shows when missing.

---

## Dependencies / Assumptions

- `AlbumRepositoryInterface::findByUuids()` exists and works (verified during research)
- `AlbumRepositoryInterface::getArtistNamesForAlbums()` exists and works (verified during research)
- `ImagePortInterface::findByUuids()` exists and works (verified during research)
- Image API endpoint `/api/images/{publicId}/file` works with authenticated requests
- No other API consumers depend on the current response structure (confirmed by user)

## System-Wide Impact

**Frontend only:** The recommendation display change only affects the web UI's discover view. No mobile, API, or downstream systems are affected.

**API contract change:** The `/api/recommendations/` response structure changes by adding three optional fields. Existing consumers ignoring unknown fields will continue to work, but any consumers expecting exact field matching would need updates. User confirmed no other consumers exist.

## Risk Analysis & Mitigation

| Risk | Impact | Mitigation |
|------|--------|------------|
| Cross-context repository coupling | Medium | Follows existing `GetPersonalizedRecommendationsHandler` pattern. Documented as technical debt; future refactor should use Catalog ports. |
| N+1 query performance | Medium | Use bulk fetch methods (`findByUuids`) for all entity lookups; verify with test for 200-item set |
| Missing cover images cause layout shift | Low | Fixed aspect-square container maintains layout |
| API response size increase | Low | Only three new string fields per recommendation; minimal impact |
| Artist bulk fetch implementation bugs | Medium | Add functional test coverage for `findByUuids` method including keyed array structure |
| RequestStack returns null (no active request) | Low | During CLI/background execution, image URLs may use relative paths; acceptable since discover is web-only |

## Future Considerations

- Blurhash/dominant color support could be added to recommendations for smoother image loading (currently deferred)
- The enrichment pattern could be extracted to a shared service if more endpoints need similar behavior
- Artist recommendations could benefit from additional metadata (genre, album count) for richer display
