---
title: Album Duplicate Resolution
type: feat
status: active
created: 2026-05-22
origin: docs/brainstorms/2026-05-22-album-duplicate-resolution-requirements.md
---

# Album Duplicate Resolution

**Plan ID:** 2026-05-22-001
**Type:** Feature
**Status:** Active
**Origin:** [Album Duplicate Resolution Requirements](../brainstorms/2026-05-22-album-duplicate-resolution-requirements.md)

---

## Summary

Prevent, detect, and resolve duplicate albums in the music library. Duplicates are created when MusicBrainz sync adds disambiguation suffixes to album titles (e.g., `[Reprise Records,9362-49433-2,EU]`), causing re-scans to create new albums instead of matching existing ones.

This plan implements:
1. **Prevention:** MBID-based matching before creating new albums, plus title normalization that extracts disambiguation data into proper fields
2. **Detection:** Algorithmic duplicate detection using title similarity, artist overlap, and year matching
3. **Resolution:** Merge interface accessible from context menu, admin duplicates view, and album detail page warning

---

## Problem Statement

Users experience duplicate albums when scanning multiple times or syncing from MusicBrainz. Example from production:
- Album 1: "Ten Thousand Fists" (14 songs, created 18:26:53)
- Album 2: "Ten Thousand Fists [Reprise Records,9362-49433-2,EU]" (0 songs, created 18:29:25)

**Root causes:**
1. `AlbumMetadataEnricher` does not check MBID before applying metadata
2. MusicBrainz sync writes disambiguation suffixes directly to the title field
3. No duplicate detection during sync or scan operations
4. No merge interface exists to combine duplicates

---

## Requirements Traceability

From the origin document:

| R-ID | Requirement | Implementation Unit |
|------|-------------|---------------------|
| R1 | Prevent duplicates via MBID matching | U1, U2 |
| R2 | Normalize titles, extract disambiguation to proper fields | U2 |
| R3 | Detect duplicates algorithmically | U3, U4 |
| R4 | Merge interface accessible from three locations | U5, U6, U7, U8 |
| R5 | Preserve merge audit trail | U5 |

Success criteria (origin):
- Syncing an album with an existing MBID updates that album, never creates a duplicate
- Duplicate albums are surfaced to users for resolution
- Merge interface handles all edge cases (empty albums, overlapping songs, metadata conflicts)
- Users can confidently merge albums without fear of data loss

---

## Key Technical Decisions

### 1. MBID Lookup Before Metadata Application

When `AlbumMetadataEnricher.enrich()` receives MusicBrainz data with an MBID:

1. Query for existing albums in the same library by MBID
2. If found, update that album directly (skip the target album entirely)
3. If not found, proceed with normal enrichment on the target album

**Rationale:** MBID is the authoritative identifier from MusicBrainz. Matching by MBID before applying any title changes prevents the duplicate-from-title-update scenario entirely.

### 2. Title Normalization Pattern

Disambiguation suffixes follow this pattern: `[Label, Catalog#, Country]`

Extraction algorithm:
1. Match regex `/\s*\[([^\]]+)\]\s*$/` on the MusicBrainz title
2. Split contents by comma: first segment = label, second = catalog number, third = country
3. Strip the suffix from the title
4. Apply extracted data to existing album fields (`label`, `catalogNumber`, `country`)
5. Only update title if the normalized version differs from current

**Rationale:** The MusicBrainz data structure embeds discrete fields in the title string. Extracting them preserves data granularity without polluting the title field.

### 3. Duplicate Detection Algorithm

An album pair is a duplicate when **all** conditions are met:

1. **Same library** (exact UUID match)
2. **Title similarity ≥ 85%** (Levenshtein on normalized lowercase, diacritic-stripped, punctuation-removed titles)
3. **Artist overlap ≥ 50%** (Jaccard index on artist name sets from songs or album-level artists)
4. **Year match** (both null OR equal)

**Rationale:** Each condition independently eliminates false positives. Requiring all four ensures high confidence while catching real duplicates with minor title variations.

### 4. Merge Behavior

- Target album is preserved; source album is deleted
- Songs are deduplicated by hash (file identity), then merged into target
- Metadata fields prefer non-null values (target first, then source)
- Lockable fields on target are unlocked; source locked values override if different
- Audit trail stored as JSON array on target: `mergedFrom: [{id, title, timestamp}]`

**Rationale:** Song hash deduplication prevents file duplication while allowing different track versions. Metadata preference follows least-surprise (keep existing unless empty). Audit trail enables debugging and potential rollback.

---

## System-Wide Impact

### Backend Changes
- **Catalog context:** New duplicate detection service, merge port implementation, updated enricher logic
- **Metadata context:** Modified enricher to check MBID before applying changes
- **Database schema:** No migrations required (uses existing `mbid`, `label`, `catalogNumber`, `country`, `lockedFields`, `annotation` fields)
- **API surface:** New admin endpoints, new merge endpoint on albums controller

### Frontend Changes
- **Admin section:** New duplicates listing page at `/admin/duplicates`
- **Album detail:** Warning banner when duplicates detected
- **Context menu:** "Merge with duplicate..." action on albums
- **Shared components:** New merge dialog component

### Performance Considerations
- Duplicate detection runs per-library, not globally (limits query scope)
- MBID lookup adds one query per enrich operation (indexed column)
- Merge operation uses transaction with song reassignment (potential for long-running transaction on large albums)

---

## Scope Boundaries

### Included in this plan
- MBID-based matching to prevent sync-time duplicates
- Title normalization with disambiguation extraction
- Duplicate detection algorithm and admin UI
- Merge dialog with two-column comparison
- Merge API endpoint and backend implementation
- Audit trail for merged albums
- Tests for all new behaviors

### Deferred for later (see origin: "Deferred for later")
- Undo/redo for merge operations
- Bulk merge (select multiple groups and merge all)
- Automatic merge suggestions during scan (on-demand only for now)

### Outside this product's identity (see origin: "Outside this product's identity")
- Automatic deletion without user consent
- Modifying audio file metadata during merge
- Cloud-based metadata lookup (local MusicBrainz only)

### Deferred to Follow-Up Work
- Optimizing duplicate detection for large libraries (>10,000 albums)
- Parallel processing of merge operations

---

## Implementation Units

### U1. Add MBID Lookup Method to Album Repository

**Goal:** Enable querying albums by MusicBrainz ID within a specific library.

**Requirements:** R1 (MBID-based matching)

**Dependencies:** None

**Files:**
- `src/Catalog/Domain/Repository/AlbumRepositoryInterface.php` (modify)
- `src/Catalog/Infrastructure/Doctrine/Repository/AlbumRepository.php` (modify)
- `tests/Unit/Catalog/Infrastructure/Doctrine/Repository/AlbumRepositoryTest.php` (modify)

**Approach:**
- Add `findByMbidAndLibrary(?MusicbrainzId $mbid, Uuid $libraryId): ?Album` to interface
- Implement DQL query filtering by `mbid` and `libraryId`
- Handle null MBID by returning null (no match possible)

**Patterns to follow:**
- Existing `findByMbid()` method in same interface
- Existing repository implementation patterns (use `createQueryBuilder`, `where`, `getQuery->getResult`)

**Test scenarios:**
- Covers AE: MBID lookup returns matching album in same library
- Covers AE: MBID lookup returns null when MBID is null
- Covers AE: MBID lookup returns null for albums in different libraries
- Covers AE: MBID lookup handles multiple albums with same MBID (returns first)

**Verification:** Unit tests pass; query returns correct album for matching MBID+library, null otherwise.

---

### U2. Update AlbumMetadataEnricher with MBID Matching and Title Normalization

**Goal:** Prevent duplicates by matching existing albums via MBID before applying metadata; normalize titles to extract disambiguation data.

**Requirements:** R1 (MBID matching), R2 (title normalization)

**Dependencies:** U1 (MBID lookup method)

**Files:**
- `src/Metadata/Application/AlbumMetadataEnricher.php` (modify)
- `src/Metadata/Application/AlbumMetadataEnricherTest.php` (create)

**Approach:**
1. At start of `enrich()`, if MusicBrainz data includes MBID:
   - Call `$this->albumService->findByMbidAndLibrary()`
   - If found, switch enrichment target to that album (caller's album is skipped)
2. Add `normalizeTitleWithDisambiguation(string $mbTitle): array{string, array}` method:
   - Extract suffix using regex `/\s*\[([^\]]+)\]\s*$/`
   - Parse contents into label, catalogNumber, country
   - Return `[normalizedTitle, extractedData]`
3. Modify `applyData()` to call normalization before applying title
   - Apply extracted data to respective fields
   - Only update title if normalized version differs

**Technical design (directional guidance):**
```php
// Pseudo-code for enrich() MBID check
public function enrich(Album $album, bool $forceUpdate = false): EnrichmentResult
{
    $data = $this->searchGeneral($album);
    if ($data === null) { return EnrichmentResult::noMatch(); }

    // NEW: MBID-based target switching
    if (!empty($data['mbid'])) {
        $existingByMbid = $this->albumService->findByMbidAndLibrary(
            new MusicbrainzId($data['mbid']),
            $album->getLibraryId()
        );
        if ($existingByMbid !== null && $existingByMbid->getId()->toString() !== $album->getId()->toString()) {
            $album = $existingByMbid; // Switch target to existing album
        }
    }

    return $this->applyData($album, $data, 'general', $forceUpdate);
}

// Pseudo-code for title normalization
private function normalizeTitleWithDisambiguation(string $title): array
{
    if (!preg_match('/^(.+?)\s*\[([^\]]+)\]\s*$/', $title, $matches)) {
        return [$title, []];
    }

    $normalizedTitle = $matches[1];
    $suffixParts = array_map('trim', explode(',', $matches[2]));

    $extracted = [];
    if (isset($suffixParts[0])) { $extracted['label'] = $suffixParts[0]; }
    if (isset($suffixParts[1])) { $extracted['catalogNumber'] = $suffixParts[1]; }
    if (isset($suffixParts[2])) { $extracted['country'] = $suffixParts[2]; }

    return [$normalizedTitle, $extracted];
}
```

**Patterns to follow:**
- Existing enricher structure and `EnrichmentResult` return type
- Existing field update pattern using `$album->updateMetadata()`

**Test scenarios:**
- Covers AE: Enrichment with MBID finds and updates existing album instead of target
- Covers AE: Enrichment with MBID in same library switches target correctly
- Covers AE: Title normalization strips disambiguation suffix
- Covers AE: Extracted data applies to label, catalogNumber, country fields
- Covers AE: Normalization handles malformed suffixes gracefully (no match = no change)
- Edge: Empty MBID does not trigger lookup
- Edge: MBID exists in different library (does not switch target)

**Verification:** Unit tests confirm MBID matching prevents duplicate creation; disambiguation is extracted correctly; existing album is updated when MBID matches.

---

### U3. Create Duplicate Detection Service

**Goal:** Implement algorithmic detection of duplicate album pairs within a library.

**Requirements:** R3 (algorithmic duplicate detection)

**Dependencies:** None

**Files:**
- `src/Catalog/Domain/Service/AlbumDuplicateDetector.php` (create)
- `src/Catalog/Domain/Service/TitleNormalizer.php` (create)
- `src/Catalog/Domain/ValueObject/DuplicateGroup.php` (create)
- `src/Catalog/Domain/Service/AlbumDuplicateDetectorTest.php` (create)

**Approach:**
1. Create `TitleNormalizer` value object:
   - `normalize(string): string` — lowercase, remove diacritics, remove punctuation, trim whitespace
2. Create `DuplicateGroup` value object:
   - Holds array of album UUIDs that are potential duplicates
   - Provides `getAlbumIds(): array`, `getConfidence(): float`
3. Create `AlbumDuplicateDetector` service:
   - `findDuplicates(Uuid $libraryId): array<DuplicateGroup>`
   - Fetch all albums in library with their artists and years
   - Compare each pair using the four-condition algorithm
   - Return array of groups (each group contains 2+ album IDs)

**Technical design (directional guidance):**
```php
// Pseudo-code for duplicate detection
public function findDuplicates(Uuid $libraryId): array
{
    $albums = $this->albumRepository->findByLibrary($libraryId);
    $albumData = [];

    foreach ($albums as $album) {
        $albumData[$album->getId()->toString()] = [
            'album' => $album,
            'normalizedTitle' => $this->titleNormalizer->normalize($album->getTitle()),
            'artists' => $this->getArtistNames($album),
            'year' => $album->getYear(),
        ];
    }

    $groups = [];
    $processed = [];

    foreach ($albumData as $id1 => $data1) {
        if (isset($processed[$id1])) { continue; }

        $matches = [$id1];

        foreach ($albumData as $id2 => $data2) {
            if ($id1 === $id2 || isset($processed[$id2])) { continue; }

            if ($this->isDuplicate($data1, $data2)) {
                $matches[] = $id2;
                $processed[$id2] = true;
            }
        }

        if (count($matches) > 1) {
            $groups[] = new DuplicateGroup($matches, $this->calculateConfidence($data1, $matches));
        }

        $processed[$id1] = true;
    }

    return $groups;
}

private function isDuplicate(array $a, array $b): bool
{
    // Condition 1: Same library (already filtered by fetch)
    // Condition 2: Title similarity ≥ 85%
    $titleSimilarity = 1 - (levenshtein($a['normalizedTitle'], $b['normalizedTitle'])
        / max(strlen($a['normalizedTitle']), strlen($b['normalizedTitle'])));
    if ($titleSimilarity < 0.85) { return false; }

    // Condition 3: Artist overlap ≥ 50%
    $artistsA = array_flip($a['artists']);
    $artistsB = array_flip($b['artists']);
    $intersection = count(array_intersect_key($artistsA, $artistsB));
    $union = count(array_unique(array_merge($a['artists'], $b['artists'])));
    $artistOverlap = $intersection / $union;
    if ($artistOverlap < 0.5) { return false; }

    // Condition 4: Year match
    if ($a['year'] !== null && $b['year'] !== null && $a['year'] !== $b['year']) {
        return false;
    }

    return true;
}
```

**Patterns to follow:**
- Domain service pattern in `src/Catalog/Domain/Service/`
- Value object pattern for `DuplicateGroup` (immutable, readonly)
- Existing artist fetching via `AlbumRepository::getArtistNamesForAlbums()`

**Test scenarios:**
- Covers AE: Detection finds duplicates with 85%+ title similarity
- Covers AE: Detection requires artist overlap ≥ 50%
- Covers AE: Detection requires year match when both have years
- Covers AE: Detection handles null years correctly (both null = match)
- Edge: Albums with different titles but same artists are not duplicates
- Edge: Albums with identical titles but no artist overlap are not duplicates
- Edge: Single album in library returns empty groups
- Edge: Three albums with similar titles create one group of three

**Verification:** Unit tests confirm algorithm matches specification; detection finds known duplicate pairs; rejects non-duplicates.

---

### U4. Create Duplicate Detection Admin API

**Goal:** Expose duplicate detection and listing endpoints for admin UI.

**Requirements:** R3 (detect duplicates), R4 (merge interface access)

**Dependencies:** U3 (duplicate detection service)

**Files:**
- `src/Catalog/Application/Port/AlbumDuplicatePortInterface.php` (create)
- `src/Catalog/Infrastructure/AlbumDuplicateService.php` (create)
- `src/Catalog/Interface/Controller/AlbumDuplicateController.php` (create)
- `src/Catalog/Interface/Request/FindDuplicatesRequest.php` (create)
- `src/Catalog/Interface/Request/MergeAlbumsRequest.php` (create)
- `src/Catalog/Interface/Resource/DuplicateGroupResource.php` (create)
- `config/services.yaml` (modify for port alias)
- `tests/Functional/Catalog/Interface/Controller/AlbumDuplicateControllerTest.php` (create)

**Approach:**
1. Create port interface with methods:
   - `findDuplicates(Uuid $libraryId): array<DuplicateGroup>`
   - `findDuplicatesForAlbum(Uuid $albumId): array<DuplicateGroup>`
2. Implement service wired to `AlbumDuplicateDetector`
3. Create admin-only controller:
   - `GET /api/admin/albums/duplicates` — list all duplicate groups in library
   - `GET /api/albums/{publicId}/duplicates` — list duplicates for specific album
   - Authentication: `#[IsGranted('ROLE_ADMIN')]`
4. Create request DTOs with validation attributes
5. Create resource transforming `DuplicateGroup` to API response

**Patterns to follow:**
- Port interface pattern in `Catalog/Application/Port/`
- Service implementation in `Catalog/Infrastructure/`
- Admin controller pattern from `src/Metadata/Interface/Controller/MetadataAdminController.php`
- OpenAPI attributes for endpoint documentation
- `ApiResponsesTrait` for consistent responses

**Test scenarios:**
- Integration: Admin user can access duplicates endpoint
- Integration: Non-admin user receives 403
- Integration: Duplicates endpoint returns groups with album IDs
- Integration: Single-album duplicates endpoint returns only groups containing that album
- Edge: Empty library returns empty array
- Edge: Library with no duplicates returns empty array

**Verification:** Functional tests pass; endpoints return correct duplicate groups; authentication works.

---

### U5. Implement Album Merge Backend

**Goal:** Create port, service, and endpoint for merging two albums.

**Requirements:** R4 (merge interface), R5 (audit trail)

**Dependencies:** U1 (repository methods), U4 (port pattern)

**Files:**
- `src/Catalog/Application/Port/AlbumMergePortInterface.php` (create)
- `src/Catalog/Infrastructure/AlbumMergeService.php` (create)
- `src/Catalog/Interface/Controller/AlbumController.php` (modify — add merge endpoint)
- `src/Catalog/Interface/Request/MergeAlbumsRequest.php` (create from U4)
- `src/Catalog/Interface/Resource/MergeResultResource.php` (create)
- `src/Catalog/Domain/Model/Album.php` (modify — add `mergedFrom` field to state if not present)
- `src/Catalog/Infrastructure/Doctrine/Entity/AlbumEntity.php` (modify — add `mergedFrom` column)
- `src/Catalog/Infrastructure/Doctrine/Repository/AlbumRepository.php` (modify — add merge methods)
- `migrations/VERSION-AddAlbumMergedFrom.php` (create)
- `config/services.yaml` (modify for port alias)
- `tests/Integration/Catalog/Infrastructure/AlbumMergeServiceTest.php` (create)

**Approach:**
1. Schema migration: Add `merged_from` JSONB column to `albums` table
2. Update `AlbumState` to include `mergedFrom` array
3. Update `Album` aggregate with `getMergedFrom()` and `addMergeRecord()`
4. Create merge port interface:
   - `mergeAlbums(Uuid $targetId, Uuid $sourceId): MergeResult`
5. Implement merge service:
   - Validate both albums exist and are in same library
   - Fetch songs from both albums, deduplicate by hash
   - Reassign source songs to target album (update `album_id` foreign key)
   - Merge metadata: prefer non-null target values, then source values
   - Handle locked fields: unlock target, preserve source locked values if different
   - Add merge record to target's `mergedFrom`
   - Delete source album
   - Return result with summary (songs merged, metadata changes)
6. Add merge endpoint to `AlbumController`:
   - `POST /api/albums/merge` with `{targetId, sourceId}` request body
   - Returns merged `AlbumResource`

**Technical design (directional guidance):**
```php
// Pseudo-code for merge operation
public function mergeAlbums(Uuid $targetId, Uuid $sourceId): MergeResult
{
    $target = $this->albumRepository->findByUuid($targetId);
    $source = $this->albumRepository->findByUuid($sourceId);

    // Validation
    if ($target === null || $source === null) { throw new NotFound(); }
    if ($target->getLibraryId()->toString() !== $source->getLibraryId()->toString()) {
        throw new InvalidMerge('Albums must be in same library');
    }
    if ($targetId->toString() === $sourceId->toString()) {
        throw new InvalidMerge('Cannot merge album with itself');
    }

    $songsMerged = 0;
    $metadataChanges = [];

    // Merge songs (deduplicate by hash)
    $targetSongs = $this->songRepository->findByAlbum($targetId);
    $sourceSongs = $this->songRepository->findByAlbum($sourceId);
    $targetHashes = array_flip(array_map(fn($s) => $s->getHash(), $targetSongs));

    foreach ($sourceSongs as $song) {
        if (!isset($targetHashes[$song->getHash()])) {
            $song->moveToAlbum($targetId);
            $this->songRepository->save($song);
            $songsMerged++;
        }
    }

    // Merge metadata (prefer non-null)
    $this->mergeMetadata($target, $source, $metadataChanges);

    // Handle locked fields
    $this->handleLockedFields($target, $source);

    // Record merge
    $target->addMergeRecord($source->getId(), $source->getTitle());

    // Delete source
    $this->albumRepository->delete($source);
    $this->albumRepository->flush();

    return new MergeResult($target, $songsMerged, $metadataChanges);
}
```

**Patterns to follow:**
- Transaction handling via Doctrine (implicit flush)
- State object pattern for `Album` modifications
- Resource transformation for API responses
- Existing `Album::updateMetadata()` method pattern

**Test scenarios:**
- Covers AE: Merge combines songs from both albums
- Covers AE: Merge deduplicates songs by hash
- Covers AE: Merge prefers non-null metadata from target
- Covers AE: Merge preserves audit trail on target album
- Covers AE: Source album is deleted after merge
- Covers AE: Locked fields on target are unlocked correctly
- Edge: Merging albums in different libraries throws error
- Edge: Merging album with itself throws error
- Edge: Merge handles empty source album (no songs to merge)
- Integration: Endpoint returns 404 for non-existent albums

**Verification:** Integration tests confirm merge behavior; songs are reassigned correctly; audit trail is preserved; source is deleted.

---

### U6. Create Admin Duplicates Listing Page

**Goal:** Build admin UI showing all detected duplicate groups with merge actions.

**Requirements:** R4 (merge interface access — admin view)

**Dependencies:** U4 (admin API endpoints)

**Files:**
- `ui/web/src/features/admin/api/album-duplicates-api.ts` (create)
- `ui/web/src/features/admin/pages/AlbumDuplicatesPage.tsx` (create)
- `ui/web/src/features/admin/components/DuplicateGroupCard.tsx` (create)
- `ui/web/src/features/admin/components/AdminSidebar.tsx` (modify — add Duplicates nav item)

**Approach:**
1. Create API module with methods:
   - `listDuplicates(libraryId: string): Promise<DuplicateGroup[]>`
   - `scanForDuplicates(libraryId: string): Promise<void>`
2. Create duplicates page following `MetadataPage.tsx` pattern:
   - Page header with title/description
   - "Scan for Duplicates" button (triggers detection)
   - Stats cards showing total duplicates, total albums affected
   - List of duplicate groups as cards
3. Create `DuplicateGroupCard` component:
   - Shows albums side-by-side with cover thumbnails
   - Displays metadata differences (title, year, song count)
   - "Merge" button opens merge dialog (pre-selects albums)
4. Add "Duplicates" to admin sidebar Content nav group

**Patterns to follow:**
- Admin page structure from `ui/web/src/features/admin/pages/MetadataPage.tsx`
- API client pattern using `AXIOS_INSTANCE`
- Shadcn/ui Card, Button, Dialog components
- Zustand store for duplicates state (or TanStack Query for data fetching)

**Test scenarios:**
- Integration: Page loads and displays duplicate groups
- Integration: Scan button triggers detection API call
- Integration: Duplicate group card shows album covers and metadata
- Integration: Merge button opens dialog with correct albums pre-selected
- Edge: Empty state shows when no duplicates found
- Edge: Loading state during scan

**Verification:** Page loads in browser; duplicates display correctly; merge action works.

---

### U7. Create Album Merge Dialog Component

**Goal:** Build two-column comparison dialog for reviewing and confirming album merges.

**Requirements:** R4 (merge interface)

**Dependencies:** U5 (merge API endpoint), U6 (triggering context)

**Files:**
- `ui/web/src/features/catalog/api/catalog-admin-api.ts` (modify — add merge endpoint)
- `ui/web/src/features/catalog/components/MergeAlbumsDialog.tsx` (create)
- `ui/web/src/features/catalog/components/MergePreviewColumn.tsx` (create)
- `ui/web/src/features/catalog/stores/merge-store.ts` (create)

**Approach:**
1. Create merge store (Zustand) with state:
   - `sourceId: string | null`
   - `targetId: string | null`
   - `isOpen: boolean`
   - Actions: `openMerge()`, `setTarget()`, `closeMerge()`
2. Create `MergePreviewColumn` component:
   - Displays album cover, title, artist, year, song count
   - Highlights which album is selected as target
   - Radio button for target selection
3. Create `MergeAlbumsDialog` component:
   - Two-column layout (source vs target)
   - Uses shadcn `Dialog` primitive
   - Shows metadata field comparison
   - Shows song merge preview (counts, conflicts)
   - Confirm button disabled until target selected
   - On confirm, calls merge API, closes dialog, shows toast
4. Wire dialog to trigger from:
   - Duplicate group card (pre-selects both albums)
   - Album context menu (opens with current album, shows duplicate selector)
   - Album detail page warning banner

**Patterns to follow:**
- Dialog component from `ui/web/src/shared/components/ui/dialog.tsx`
- Design language: `rounded-xl`, `ease-out` motion, no gradients
- Zustand store pattern from `ui/web/src/features/player/stores/player-store.ts`
- API call via `catalog-admin-api.mergeAlbums()`

**Test scenarios:**
- Integration: Dialog opens with correct albums pre-loaded
- Integration: Target selection radio buttons work correctly
- Integration: Confirm button calls merge API with correct IDs
- Integration: Dialog closes after successful merge
- Integration: Toast notification displays merge result
- Edge: Dialog shows loading state during merge API call
- Edge: Error message displays when merge fails

**Verification:** Dialog renders correctly; merge action completes; UI updates after merge.

---

### U8. Add Duplicate Warning to Album Detail Page

**Goal:** Show warning banner on album detail page when duplicates are detected.

**Requirements:** R4 (merge interface access — detail page warning)

**Dependencies:** U4 (duplicate detection API for single album), U7 (merge dialog)

**Files:**
- `ui/web/src/features/catalog/pages/AlbumDetailPage.tsx` (create or modify)
- `ui/web/src/features/catalog/components/DuplicateWarningBanner.tsx` (create)
- `ui/web/src/features/catalog/api/album-duplicates-api.ts` (create or extend)

**Approach:**
1. Create API method `getAlbumDuplicates(albumId: string)`
2. Create `DuplicateWarningBanner` component:
   - Shows when duplicates exist for current album
   - Displays count of duplicates found
   - "Review duplicates" button opens merge dialog
   - Dismissible (user can hide for session)
3. Integrate into album detail page:
   - Fetch duplicates when album loads
   - Conditionally render banner above album content
   - Pass album ID and duplicate IDs to merge dialog on action

**Patterns to follow:**
- Banner component pattern using `variant="warning"` styling
- Data fetching via TanStack Query `useQuery()`
- Merge dialog integration via `merge-store`

**Test scenarios:**
- Integration: Banner displays when duplicates exist
- Integration: Banner is hidden when no duplicates found
- Integration: "Review duplicates" button opens merge dialog
- Integration: Dismissing banner hides it for session
- Edge: Loading state while fetching duplicates
- Edge: Error state when API call fails

**Verification:** Banner appears correctly on detail pages with duplicates; merge action works.

---

## Dependencies / Prerequisites

- PostgreSQL with Doctrine ORM configured
- Existing MBID column on `albums` table (already present)
- Swoole async runtime for MusicBrainz API rate limiting
- Shadcn/ui Dialog component available in frontend

---

## Risk Analysis & Mitigation

| Risk | Impact | Mitigation |
|------|--------|------------|
| **MBID collisions** — Different releases share same MBID (shouldn't happen but data quality issues exist) | High — Wrong album could be updated | Validate release type and basic metadata before switching target; add logging for MBID mismatches |
| **Large merge transactions** — Albums with 100+ songs could cause long-running transaction | Medium — Potential timeout on slow disks | Batch song reassignment in chunks; run merge in background job for large albums |
| **False positive duplicates** — Algorithm flags non-duplicates as duplicates | Medium — User merges wrong albums | Require explicit user confirmation; show clear preview; provide undo via audit trail reversion |
| **Title normalization over-strips** — Legitimate bracketed content removed from titles | Low — Edge case for unusual album titles | Only strip suffixes matching `[Label, Catalog#, Country]` pattern; preserve other bracketed content |
| **Performance on large libraries** — O(n²) comparison slow for 10k+ albums | Medium — Scan could take minutes | Cache results; run in background; show progress indicator; library-scoped detection (not global) |

---

## Phased Delivery

### Phase 1: Prevention (U1, U2)
- MBID lookup in repository
- Enricher updates to check MBID and normalize titles
- **Outcome:** No new duplicates created during sync

### Phase 2: Detection (U3, U4)
- Duplicate detection service
- Admin API endpoints
- **Outcome:** Users can see existing duplicates

### Phase 3: Resolution (U5, U6, U7, U8)
- Merge backend and API
- Admin duplicates page
- Merge dialog component
- Album detail warning
- **Outcome:** Users can resolve duplicates

---

## Documentation Plan

### API Documentation
- OpenAPI attributes auto-generated from controller attributes
- Document new endpoints in API docs if separate

### User Documentation
- Add "Managing Duplicate Albums" section to user guide
- Explain how duplicates are detected
- Provide merge workflow instructions

### Developer Documentation
- Update `CLAUDE.md` with duplicate detection pattern if reusable
- Document merge port interface pattern for future merge operations

---

## Future Considerations

1. **Undo functionality** — Audit trail enables reversion; implement undo UI if user demand emerges
2. **Bulk merge** — Allow selecting multiple duplicate groups and merging all at once
3. **Automatic merge suggestions** — Run detection during scan and prompt users immediately
4. **Cross-library duplicate detection** — Detect duplicates across different libraries (for users with multiple libraries)
5. **Performance optimization** — Cache detection results, parallelize comparisons for large libraries

---

## Success Metrics

- **Zero new duplicates** created after Phase 1 deployment (measured by monitoring duplicate count over time)
- **Merge completion rate** — % of detected duplicates that are successfully merged
- **User-reported issues** — No bug reports about duplicates after full deployment
- **Detection accuracy** — Manual spot-check of 100 detected duplicate groups confirms >95% are true duplicates
