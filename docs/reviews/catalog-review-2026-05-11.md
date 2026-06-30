# Context Review: Frontend — catalog

**Date:** 2026-05-11
**Scope:** catalog feature (`ui/web/src/features/catalog/`)

## Summary
- Features: 1
- Files analyzed: 22 (14 components, 8 pages)
- Errors: 12 (must fix)
- Warnings: 18 (tech debt)
- Info: 14 (informational)

## File Inventory

### components/
- AlbumCard.tsx — Blob-fetched album cover with Link to detail
- AlbumGridItem.tsx — Grid card with click (context panel) / double-click (play)
- AlbumListItem.tsx — Table row variant of grid item
- ArtistCard.tsx — Link card for artist with album count
- ArtistGridItem.tsx — Button card for artist with click/double-click
- BrowserColumn.tsx — Virtualized listbox with keyboard navigation
- EmptyState.tsx — Empty state with i18n messages
- GenreCard.tsx — Static genre card with album count
- LoadingGrid.tsx — Skeleton grid placeholder
- PaginatedGrid.tsx — Grid wrapper with prev/next pagination
- SearchResults.tsx — Combined search results (artists, albums, songs)
- SongListItem.tsx — Table row for song with click/double-click
- SongRow.tsx — Simple grid row for song
- VirtualSongList.tsx — Virtualized song list with queue play

### pages/
- AlbumDetailPage.tsx — Album detail with song list and play controls
- AlbumsPage.tsx — Paginated album grid/list with sort and filter
- ArtistDetailPage.tsx — Artist detail with album grid
- ArtistsPage.tsx — Paginated artist grid
- GenresPage.tsx — Genre grid with navigation to filtered albums
- HomePage.tsx — Dashboard with recently added, recently played, playlists
- SearchPage.tsx — Search wrapper delegating to SearchResults
- SongsPage.tsx — iTunes-style 3-column browser + virtualized song list

## Errors

### E1. Blob URL memory leak — two-effect pattern race condition
**Files:** `components/AlbumCard.tsx:14`, `components/AlbumGridItem.tsx:25`, `components/AlbumListItem.tsx:22`, `pages/HomePage.tsx:70`

All four components use a two-effect pattern: effect 1 fetches the blob and calls `setSrc(url)`, effect 2 cleans up by revoking `src`. If the fetch resolves after the component unmounts (between renders), effect 2's cleanup holds a stale closure over `src = null` and the newly created blob URL leaks.

**Remediation:** Consolidate into a single effect. Track the object URL in a local variable inside the fetch effect and revoke it in that effect's cleanup:
```typescript
useEffect(() => {
  if (!imageUrl) { setSrc(null); return }
  let url: string | null = null
  let cancelled = false
  AXIOS_INSTANCE.get(imageUrl, { responseType: 'blob' })
    .then((res) => { if (!cancelled) { url = URL.createObjectURL(res.data); setSrc(url) } })
    .catch(() => { if (!cancelled) setSrc(null) })
  return () => { cancelled = true; if (url) URL.revokeObjectURL(url) }
}, [imageUrl])
```

### E2. Side effect during render — `getState().setSelectedItem()` outside useEffect
**Files:** `pages/AlbumDetailPage.tsx:48`, `pages/ArtistDetailPage.tsx:25`

Both pages call `useContextPanelStore.getState().setSelectedItem(...)` directly in the component body, not inside a `useEffect`. This mutates global state synchronously during render, which React forbids. In concurrent mode it can fire multiple times, cause stale writes, or throw.

**Remediation:** Wrap in `useEffect(() => { useContextPanelStore.getState().setSelectedItem({ type: 'album', publicId: publicId ?? '' }) }, [publicId])`.

### E3. `setState` called inside `useMemo` — side effect in derived computation
**File:** `pages/AlbumsPage.tsx:51`

`setAllGenres(...)` is called inside the `items` useMemo when `genreSet.size > 0 && allGenres.length === 0`. React's rules require useMemo to be pure — no side effects. In concurrent mode, the computation may be discarded and re-run, calling setState again unexpectedly.

**Remediation:** Extract genre extraction into a separate `useEffect` that runs when `rawItems` changes.

### E4. `<a href>` instead of React Router `<Link to>` — full page reloads
**File:** `pages/ArtistDetailPage.tsx:72`

Album links use `<a href={...}>` instead of `<Link to={...}>`. This causes full page reloads on navigation, losing all client-side state (player queue, context panel, zustand stores).

**Remediation:** Replace `<a>` with `<Link>` from `react-router-dom`.

### E5. Inline components duplicating extracted ones — HomePage
**File:** `pages/HomePage.tsx:23`

Four components (AlbumCard, RecentlyPlayedCard, PlaylistCard, SectionSkeleton) are defined inline in the page file. The inline AlbumCard duplicates the blob-URL pattern already in `components/AlbumCard.tsx`. This bloats the file to ~240 lines.

**Remediation:** Move each to its own file under `components/`. Reuse the existing `AlbumCard` component instead of the inline duplicate.

### E6. `as any` casts bypass generated API types — pervasive type unsafety
**Files:** `components/SearchResults.tsx:17`, `pages/AlbumsPage.tsx:60-62`, `pages/AlbumDetailPage.tsx:20-22`, `pages/ArtistDetailPage.tsx:20-22`, `pages/SongsPage.tsx` (~25 occurrences), `pages/HomePage.tsx:153-155`

Nearly every page casts generated API responses to `any` before accessing properties. This defeats TypeScript entirely: renaming or removing a backend field produces no compile error. The `eslint-disable-next-line @typescript-eslint/no-explicit-any` comments are a strong signal.

**Remediation:** Define typed interfaces for each API response (Album, Artist, Song, Genre) and type the generated API client correctly. Replace all `as any` with proper types.

### E7. Field name mismatch: `coverUrl` vs `coverImage.url`
**Files:** `pages/AlbumDetailPage.tsx:75`, `pages/ArtistDetailPage.tsx:76`, `components/SearchResults.tsx:80`, `pages/HomePage.tsx:14`

The generated `GetAlbumShow200` type has `coverImage?: { url?: string; blurhash?: string | null } | null`, not `coverUrl`. Code accessing `album?.coverUrl` will always get `undefined` — cover images won't render.

**Remediation:** Change all `coverUrl` references to `coverImage?.url` after properly typing the response.

### E8. Field name mismatch: `public_id` (snake_case) vs `publicId` (camelCase)
**Files:** `pages/ArtistsPage.tsx:39`, `pages/GenresPage.tsx:32`

ArtistsPage accesses `artist.public_id` and GenresPage accesses `genre.public_id`, while all other pages use camelCase `publicId`. Inconsistent casing suggests either the wrong field name is being used or the `asString()` coercion is masking the mismatch.

**Remediation:** Verify the actual API response format and normalize all field access to match.

### E9. No test files — 22 source files without any test coverage
**Scope:** Entire feature

Zero test files exist. All 14 components and 8 pages are untested. Critical untested behaviors include: queue building on double-click (VirtualSongList), cascading filter logic (SongsPage), blob URL lifecycle, keyboard navigation (BrowserColumn), play handlers across all pages, and error states.

## Warnings

### W1. Blob URL fetch logic duplicated across 4 files
**Files:** `components/AlbumCard.tsx`, `components/AlbumGridItem.tsx`, `components/AlbumListItem.tsx`, `pages/HomePage.tsx`

Identical useEffect-based blob URL fetch + revoke pattern. Should be a shared `useImageBlob(imageUrl?)` hook.

### W2. `formatDuration` duplicated in 5 files
**Files:** `components/VirtualSongList.tsx`, `components/SearchResults.tsx`, `pages/AlbumDetailPage.tsx`, `components/SongListItem.tsx`, `components/SongRow.tsx`

Identical function defined locally in each file. Extract to `shared/utils/format-duration.ts`.

### W3. `asString(val: any)` duplicated in 4 files
**Files:** `components/SearchResults.tsx:7`, `pages/AlbumDetailPage.tsx:8`, `pages/ArtistDetailPage.tsx:8`, `pages/SongsPage.tsx:14`

Copy-pasted helper suppressing the explicit-any lint identically. If the API client types were properly generated, this helper may not be needed.

### W4. God component — SongsPage at ~230 lines
**File:** `pages/SongsPage.tsx`

Combines three browser columns, virtualized song list, four API hooks, cascading filter state (genre→artist→album), lookup maps, data enrichment, and playlist dialog orchestration.

**Remediation:** Extract a `useSongBrowser()` custom hook encapsulating data-fetching, filtering, and selection state. The page component should only render layout.

### W5. Track list built twice in AlbumDetailPage
**File:** `pages/AlbumDetailPage.tsx:48`

Both `handlePlayAll` and `handlePlaySong` independently map `songs` to `Track[]`. The same mapping logic is duplicated.

**Remediation:** Extract a `buildTracks(songs, albumTitle)` helper or memoize the Track[].

### W6. Props not typed via named interface
**File:** `components/SearchResults.tsx`

Uses inline `{ query: string }` instead of a named `SearchResultsProps` interface. Inconsistent with all other catalog components.

### W7. Array index used as key in RecentlyPlayedCard list
**File:** `pages/HomePage.tsx:175`

`key={activity-${i}}` uses array index. If activity items are reordered or inserted, React will mis-match DOM nodes.

**Remediation:** Use `item.songPublicId` or `item.listenedAt + item.songPublicId`.

### W8. Cover image loading inconsistency — blob fetch vs direct URL
**Files:** `pages/AlbumDetailPage.tsx:80`, `components/SearchResults.tsx:80`

AlbumDetailPage and SearchResults load cover images directly via `<img src={url}>` while AlbumCard/AlbumGridItem/AlbumListItem use blob fetching. Either the blob pattern is unnecessary everywhere or these pages are broken for auth-protected images.

**Remediation:** Standardize: either all components use the `useImageBlob` hook or none do.

### W9. Double type assertion on API response
**File:** `pages/AlbumsPage.tsx:60`

`data as unknown as PaginatedResponse | undefined` bypasses TypeScript's structural checks entirely.

### W10. No error handling for API calls — most pages
**Files:** `pages/ArtistsPage.tsx`, `pages/GenresPage.tsx`, `pages/AlbumDetailPage.tsx`, `pages/ArtistDetailPage.tsx`, `pages/HomePage.tsx`, `pages/SongsPage.tsx`

These pages destructure only `{ data, isLoading }` from query hooks without `isError` or `refetch`. AlbumsPage is the only page that handles errors correctly. On API failure, pages silently show empty state.

**Remediation:** Destructure `isError` and `refetch`, add error state UI with retry button.

### W11. Client-side sort/filter on paginated data
**File:** `pages/AlbumsPage.tsx:61`

Sort and genre filter logic only operates on the current page's data. Genre filter options are extracted from the current page only, so most genres won't appear. Misleading UX.

**Remediation:** Move sort/filter parameters to the API request, or fetch all albums for the browse view.

### W12. Fetching artist albums by name search
**File:** `pages/ArtistDetailPage.tsx:16`

`useGetAlbumIndex({ q: artistName, limit: 100 })` does a text search by artist name. Fragile: common names return incorrect results, name changes won't be reflected.

**Remediation:** Use a proper artist→albums relationship endpoint or add an `artistId` filter parameter.

### W13. Dead code — unused `handleDoubleClick` in VirtualSongList
**File:** `components/VirtualSongList.tsx:46`

`handleDoubleClick` is defined but never used — `onDoubleClick` on rows uses `handlePlayWithContext` instead.

### W14. Incomplete Track shape on double-click
**File:** `components/AlbumGridItem.tsx:50`

`handleDoubleClick` calls `playTrack({ publicId, title, artistName })` — missing `albumName` and `duration`. Could cause UI issues in the player.

### W15. Album query fires with empty string before artist loads
**File:** `pages/ArtistDetailPage.tsx:19`

`useGetAlbumIndex({ q: artistName })` fires immediately with `q: ''` while artist query is loading. Wastes a network request.

**Remediation:** Add `query: { enabled: !!artistName }` to prevent firing until artist name is resolved.

### W16. Cover image fetched via blob instead of direct URL
**Files:** `components/AlbumCard.tsx`, `components/AlbumGridItem.tsx`, `components/AlbumListItem.tsx`

These components fetch cover images via `AXIOS_INSTANCE.get(url, { responseType: 'blob' })` then create object URLs. This doubles HTTP requests and prevents browser caching. Other pages use `<img src={url}>` directly.

**Remediation:** Use direct `<img src={url}>` unless there are auth/CORS restrictions requiring the blob approach.

### W17. Key fallback to array index
**File:** `components/SearchResults.tsx:89`

`key={asString(artist?.publicId) ?? i}` uses array index as fallback when publicId is missing.

### W18. Pervasive `as any` casts in SongsPage — ~25 occurrences
**File:** `pages/SongsPage.tsx`

Every data extraction from API responses loses type safety: `(genreData as any)?.data`, `(artistData as any)?.data?.data`, `(s: any) =>`, etc. Masks real API contract mismatches.

## Info

### I1. No React.memo on list/grid item components
**Files:** `components/AlbumCard.tsx`, `components/AlbumGridItem.tsx`, `components/AlbumListItem.tsx`

Rendered in lists/grids with 50+ items. Without `React.memo`, any parent re-render re-renders every item.

### I2. Click/double-click conflict on interactive items
**Files:** `components/AlbumGridItem.tsx`, `components/ArtistGridItem.tsx`

Both `onClick` and `onDoubleClick` are registered. A double-click fires two `onClick` events before `onDoubleClick`, causing the context panel to update twice. The update is idempotent, so this is a UX trade-off, not a bug.

### I3. Same album rendered twice on HomePage
**File:** `pages/HomePage.tsx:183`

`recentlyAdded` (8 items) is a subset of `allAlbums` (16 items). The same album appears in both sections with duplicate blob URL fetches.

### I4. Inconsistent shadcn/ui usage
**Scope:** Multiple files

AlbumsPage uses shadcn `Button`, `Skeleton`, `SortSelect`, `FilterBar`. Other pages use raw `<button>` and `<div>` with Tailwind for similar patterns.

### I5. Missing Props interfaces on components
**Files:** `components/LoadingGrid.tsx`, `components/SearchResults.tsx`, `components/EmptyState.tsx`

Components use inline prop types instead of named interfaces.

### I6. Backend endpoints with no frontend consumer
**Scope:** Catalog API

| Endpoint | Status |
|----------|--------|
| PATCH /api/albums/{id} | No UI |
| DELETE /api/albums/{id} | No UI |
| POST /api/albums/{id}/cover | No UI |
| DELETE /api/albums/{id}/cover | No UI |
| POST /api/albums/covers/extract | No UI |
| PATCH /api/artists/{id} | No UI |
| DELETE /api/artists/{id} | No UI |
| GET /api/genres/{slug} | No UI |
| PATCH /api/genres/{slug} | No UI |
| DELETE /api/genres/{slug} | No UI |
| GET /api/songs/{id} | No UI |
| PATCH /api/songs/{id} | No UI |
| DELETE /api/songs/{id} | No UI |
| GET /api/activity/loved | No UI |

Likely intentional — these appear to be admin-only endpoints not needed in the web UI yet.

### I7. No TanStack Query staleTime or cache invalidation configured
**Scope:** All catalog queries

All queries use defaults (staleTime: 0, gcTime: 5min). Every page mount triggers a refetch. No `invalidateQueries` usage anywhere in the catalog feature.

### I8. Artist filtering done client-side by name matching
**File:** `pages/SongsPage.tsx:105`

SongsPage filters by artist using lowercased name string comparison via `artistNameToId` map. Fragile with whitespace/Unicode edge cases.

## Agent Findings

### Component Architecture

The catalog feature has significant structural debt driven by code duplication. The blob URL fetch pattern is copy-pasted across 4 files, `formatDuration` across 5, and `asString` across 4. HomePage defines 4 components inline instead of extracting them. SongsPage at 230 lines is a god component mixing data fetching, transformation, filtering, and presentation. The duplication creates maintenance risk: fixing the blob URL race condition requires changing 4 places.

### TS/React Correctness

Two correctness bugs stand out: the two-effect blob URL pattern has a race condition causing memory leaks on unmount, and `getState().setSelectedItem()` is called during render (not in useEffect) in AlbumDetailPage and ArtistDetailPage, violating React's rules. AlbumsPage calls `setState` inside `useMemo`. All three issues can cause subtle bugs in concurrent mode.

### API Integration Completeness

The most pervasive issue is `as any` casts on every API response, which combines with field name mismatches (coverUrl vs coverImage.url, public_id vs publicId) to create silent failures. AlbumDetailPage likely never shows cover images because it accesses a non-existent `coverUrl` field. Only AlbumsPage handles API errors. Client-side filtering on paginated data provides a misleading UX. Artist albums are fetched by text search rather than a proper relationship endpoint.

### Test Verification

Zero test files exist for the entire catalog feature (22 source files). The most critical untested behaviors are: (1) queue building on double-click in VirtualSongList — the primary play mechanism, (2) keyboard navigation in BrowserColumn — the primary interaction model, (3) cascading filter logic in SongsPage — complex state management, (4) blob URL lifecycle — memory leak prevention, (5) context panel side effects in detail pages. Each component and page has specific behavioral gaps documented in the findings above.
