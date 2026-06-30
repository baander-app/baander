# Album Duplicate Resolution

**Date:** 2026-05-22
**Status:** Draft
**Related:** None

---

## Problem Statement

Users experience duplicate albums in their library when scanning multiple times or syncing metadata from MusicBrainz. Example:
- Album 1: "Ten Thousand Fists" (14 songs, created 18:26:53)
- Album 2: "Ten Thousand Fists [Reprise Records,9362-49433-2,EU]" (0 songs, created 18:29:25)

**Root causes:**
1. MusicBrainz sync updates album titles with disambiguation suffixes (`[Label, Catalog#, Country]`)
2. `AlbumMetadataEnricher` does not check MBID before creating new albums
3. No duplicate detection during sync operations
4. No way to merge existing duplicates

---

## Goals

1. **Prevent** new duplicates from being created during sync
2. **Detect** existing duplicates automatically
3. **Resolve** duplicates through a simple, intuitive merge interface

---

## Non-Goals

- Automatic deletion without user confirmation
- Duplicate detection across different libraries (same library only)
- Handling artist or song duplicates (out of scope)

---

## Success Criteria

- Syncing an album with an existing MBID updates that album, never creates a duplicate
- Duplicate albums are surfaced to users for resolution
- Merge interface handles all edge cases (empty albums, overlapping songs, metadata conflicts)
- Users can confidently merge albums without fear of data loss

---

## Changes

### 1. Prevent Duplicates at Sync Time

#### 1.1 MBID-Based Matching

**Before creating or updating an album during sync:**

1. If MusicBrainz data includes an MBID:
   - Query for existing album in the same library by MBID
   - If found: update that album, skip title normalization
   - If not found: proceed with new album creation or update

2. If no MBID:
   - Fall back to title-based matching (current behavior)

#### 1.2 Title Normalization

When applying MusicBrainz titles, strip disambiguation suffixes:

```
Input:  "Ten Thousand Fists [Reprise Records,9362-49433-2,EU]"
Output: "Ten Thousand Fists"
```

**Algorithm:**
- Remove bracketed suffixes matching pattern `\[.*?\]$`
- Store extracted data (`Reprise Records`, `9362-49433-2`, `EU`) in existing fields:
  - Label → `label` field
  - Catalog# → `catalogNumber` field
  - Country → `country` field

**Implementation:**
- Modify `AlbumMetadataEnricher::applyData()` in `src/Metadata/Application/AlbumMetadataEnricher.php`
- Add helper method `normalizeTitleWithDisambiguation()` that returns `[normalizedTitle, extractedData]`

---

### 2. Duplicate Detection Algorithm

#### 2.1 Detection Rules

An album pair is considered a duplicate when **all** of these conditions are met:

1. **Same library**
2. **Title similarity ≥ 85%** (Levenshtein distance on normalized titles)
3. **Artist overlap ≥ 50%** (artists on album's songs, or album-level artists)
4. **Year match** (both null OR both same year)

#### 2.2 Similarity Scoring

**Title normalization before comparison:**
- Lowercase
- Remove diacritics (é → e, ø → o)
- Remove punctuation
- Remove extra whitespace

**Similarity calculation:**
```
similarity = 1 - (levenshtein(a, b) / max(length(a), length(b)))
```

**Artist overlap:**
```
artistsA = set of all artist names on album A's songs
artistsB = set of all artist names on album B's songs
overlap = |artistsA ∩ artistsB| / |artistsA ∪ artistsB|
```

#### 2.3 Detection Trigger

Run duplicate detection:
- After library scan completes
- After metadata sync completes
- On-demand via admin API

#### 2.4 Implementation

**Backend:**
- New class `App\Catalog\Application\DuplicateDetection\AlbumDuplicateDetector`
- Method: `findDuplicates(Uuid $libraryId): array<DuplicateGroup>`
- Returns groups of potentially duplicate albums

**Frontend:**
- Admin page at `/admin/duplicates` showing detected duplicate groups
- Each group shows albums side-by-side with merge action

---

### 3. Merge Interface

#### 3.1 Merge Dialog

**When:** User selects "Merge Albums" from admin duplicates page or album context menu

**Layout:**
- Two-column comparison (left = source, right = target)
- Target selection via radio button (either album can be target)
- Preview of merged result
- Confirm/Cancel buttons

**Content shown:**
| Field | Source | Target | Merged |
|-------|--------|--------|--------|
| Title | (editable) | (editable) | (from target) |
| Cover | (thumbnail) | (thumbnail) | (from target) |
| Songs | (count) | (list) | (all unique) |
| Year | (value) | (value) | (prefer non-null) |
| Label | (value) | (value) | (prefer non-null) |
| MBID | (value) | (value) | (prefer non-null) |

**Song merge behavior:**
- Deduplicate by song hash (file identity)
- If hashes differ but titles match, keep both (user can review)
- Target album's songs are never discarded

**Rules:**
- User must select which album to keep (target)
- Source album is deleted after merge
- If target has no songs but source does, auto-select source as target
- If both have songs, default to the one with more songs

#### 3.2 Confirmation

**Before executing merge:**
1. Show summary: "Merging Source into Target. Source will be deleted."
2. List any conflicts (e.g., "Both albums have different covers")
3. Require explicit "Merge" button click

**After merge:**
1. Redirect to target album detail page
2. Show toast notification: "Merged [Source Title] into [Target Title]"

#### 3.3 Implementation

**Backend:**
- Port interface: `App\Catalog\Application\Port\AlbumMergePortInterface`
- Method: `mergeAlbums(Uuid $targetId, Uuid $sourceId): MergeResult`
- Returns summary of what was merged

**Frontend:**
- Component: `MergeAlbumsDialog.tsx` in `ui/web/src/features/catalog/components/`
- Uses shadcn `Dialog` primitive
- Follows design language: `rounded-xl`, `ease-out` motion, no gradients
- Accessible from three locations:
  1. **Context menu** on any album → "Merge with duplicate..."
  2. **Album detail page** → Warning banner when duplicates detected
  3. **Admin duplicates view** → `/admin/duplicates` lists all detected groups

**API Endpoint:**
- `POST /api/albums/merge`
- Request: `{ targetId: string, sourceId: string }`
- Response: merged album resource

**Detection API:**
- `GET /api/albums/{publicId}/duplicates` → returns potential duplicates for a single album
- `GET /api/admin/duplicates` → returns all duplicate groups in the library (admin only)

---

## Scope Boundaries

### Deferred for later
- Undo/redo for merge operations
- Bulk merge (select multiple duplicate groups and merge all)
- Merge suggestions from scanning (automatic, not on-demand)
- Duplicate detection for artists or songs

### Outside this product's identity
- Automatic deletion without user consent
- Modifying audio file metadata during merge
- Cloud-based metadata lookup (local MusicBrainz only)

---

## Dependencies / Assumptions

**Dependencies:**
- Doctrine ORM for album queries
- Existing `Album`, `Song`, `Artist` domain models
- shadcn/ui Dialog component
- React Context for dialog state

**Assumptions:**
- MusicBrainz data is the source of truth for MBIDs
- Song hash values are reliable for deduplication
- Users understand the concept of "target vs source" in merge operations
- Libraries are managed independently (no cross-library duplicates)

---

## Resolved Decisions

| Question | Decision |
|----------|----------|
| Where should merge be accessible? | Context menu on any album + admin duplicates view + detail page warning banner when duplicates detected |
| Preserve merge audit trail? | Yes, store `mergedFrom` JSON array with source album IDs and timestamps |
| Handle locked albums during merge? | Merge unlocks fields on target, preserves source's locked values if different |
