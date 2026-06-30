# Plan: Catalog Redesign

## Problem summary

The catalog feature is scaffolding — 22 files with broken cover images, pervasive `as any` type unsafety, no context menus, no queue management UI, no server-side filtering, and zero tests. The redesign replaces this with a context-adaptive music catalog with six view modes (Grid, List, Column Browser, Timeline, Activity, Discover), precise interaction model (click-to-select, Enter-to-commit), blurhash-derived accent colors, and progressive disclosure metadata. See `docs/brainstorms/2026-05-11-catalog-redesign-requirements.md`.

## Relevant learnings

- Player store already has full queue management (`insertAfterCurrent`, `reorderQueue`, `addToQueue`, `playNext`, `playPrevious`, `clearQueue`). No backend work needed for queue.
- `SearchOptions` already supports `filters` array with `{field, operator, value}` — backend just needs controllers to accept and pass new filter params (`artistId`, `genre`, `sort`, `order`).
- `SongController::buildSearchOptions` already demonstrates the pattern for adding filters from query params.
- `AlbumResource::fromWithCoverAndArtists` already returns `coverImage: { url, blurhash }` — the frontend just isn't using it correctly.
- shadcn/ui `ContextMenu` component already exists in `ui/web/src/shared/components/ui/context-menu.tsx`.
- `@tanstack/react-virtual` is already in the project (used by `BrowserColumn` and `VirtualSongList`).

## Scope boundaries

### In scope
- Complete rewrite of `ui/web/src/features/catalog/` (all 22 files)
- New shared utilities: blurhash decoder, format-duration, selection store, context menu actions
- Backend: add `sort`, `order`, `artistId`, `genre` query params to album/song/artist index endpoints
- Backend: add `year` to album index response for Timeline view
- Regenerate OpenAPI client after backend changes
- Unit tests for all catalog components and hooks

### Out of scope (future phases)
- Keyboard shortcut customization UI (Phase 2)
- Drag and drop (Phase 2)
- Discover view — recommendations frontend (Phase 3)
- Edit metadata UI / cover art download (Phase 3)
- Mobile responsive breakpoints
- Audio analysis / Spectrum view

## Implementation units

---

### Unit 1: Backend — Album/Song/Artist index filter and sort params

**Goal:** Add `sort`, `order`, `artistId`, `genre` query parameters to album, song, and artist index endpoints. Add `year` field to album list response. Enable server-side filtering and sorting.

**Files:**
- `src/Catalog/Interface/Controller/AlbumController.php` — add params to `index()`, pass to SearchOptions as filters
- `src/Catalog/Interface/Controller/SongController.php` — add `artistId`, `albumId`, `sort`, `order` to `buildSearchOptions()`
- `src/Catalog/Interface/Controller/ArtistController.php` — add `genre`, `sort`, `order` params to `index()`
- `src/Catalog/Interface/Resource/AlbumResource.php` — ensure `year` included in index response (already present, verify)
- `src/Catalog/Infrastructure/Persistence/AlbumRepository.php` — add filter/sort support to search query
- `src/Catalog/Infrastructure/Persistence/SongRepository.php` — add filter/sort support
- `src/Catalog/Infrastructure/Persistence/ArtistRepository.php` — add filter/sort support
- Tests: `tests/Catalog/Interface/Controller/AlbumControllerTest.php`, etc.

**Patterns to follow:**
- `SongController::buildSearchOptions()` — existing filter pattern
- `SearchOptions::withFilters()` — existing filter infrastructure
- Filter format: `['field' => 'artistId', 'operator' => '=', 'value' => $artistId]`
- Sort format: add `sort` and `order` to `SearchOptions` or handle in repository layer

**Test scenarios:**
- Album index with `?sort=year&order=desc` returns albums sorted by year descending
- Album index with `?artistId={id}` returns only albums by that artist
- Album index with `?genre=rock` returns only albums in rock genre
- Song index with `?artistId={id}` returns only songs by that artist
- Song index with `?albumId={id}` returns only songs from that album
- Artist index with `?sort=name&order=asc` returns artists alphabetically
- All existing tests continue to pass (backward compatible)

**Verification:** `./vendor/bin/paratest --processes auto --tmp-dir var` — all tests pass

**Dependencies:** None — this is the foundation unit.

---

### Unit 2: Regenerate OpenAPI client + typed response interfaces

**Goal:** Regenerate `ui/web/src/shared/api-client/gen/endpoints/` from updated OpenAPI spec. Create proper TypeScript interfaces for all catalog response types. Eliminate all `as any` casts.

**Files:**
- `ui/web/src/shared/api-client/gen/endpoints/index.ts` — regenerated
- `ui/web/src/features/catalog/types/album.ts` — Album, AlbumSummary, AlbumDetail interfaces
- `ui/web/src/features/catalog/types/artist.ts` — Artist, ArtistSummary interfaces
- `ui/web/src/features/catalog/types/song.ts` — Song, SongSummary interfaces
- `ui/web/src/features/catalog/types/genre.ts` — Genre interface
- `ui/web/src/features/catalog/types/index.ts` — barrel export
- `ui/web/src/features/catalog/types/api.ts` — PaginatedAlbumResponse, CursorSongResponse, etc.

**Patterns to follow:**
- Match backend resource shapes exactly: `AlbumResource::fromWithCoverAndArtists` → `AlbumSummary` type
- `coverImage: { url: string; blurhash: string | null } | null` — the correct shape
- Use generated types where they're correct; supplement with local interfaces where generated types are `unknown`

**Test scenarios:**
- Type compilation passes with `tsc --noEmit`
- No `as any` in catalog feature files
- AlbumSummary type matches actual API response shape

**Verification:** `cd ui/web && yarn tsc --noEmit` — no type errors

**Dependencies:** Unit 1 (backend API changes)

---

### Unit 3: Shared utilities — blurhash, format-duration, use-image-blob, selection store

**Goal:** Create the shared foundation that all catalog views depend on.

**Files:**
- `ui/web/src/shared/utils/format-duration.ts` — shared formatter with edge case handling
- `ui/web/src/shared/utils/blurhash.ts` — decode blurhash, extract dominant color, convert to hex
- `ui/web/src/shared/hooks/use-image-blob.ts` — consolidated blob URL fetch hook (fixes memory leak)
- `ui/web/src/features/catalog/stores/selection-store.ts` — selection state (selectedItemId, selectedItemType, selectedItems[])
- `ui/web/src/features/catalog/hooks/use-catalog-keyboard.ts` — keyboard navigation for selection
- Tests for each utility

**Patterns to follow:**
- `useImageBlob` hook: single useEffect with local `url` variable, revoke in cleanup (fixes E1 memory leak)
- Blurhash: use `blurhash` npm package for decoding. Extract 4×3 pixel grid, find most saturated pixel, convert to hex.
- Selection store: Zustand with `{ selectedId: string | null, selectedType: 'album' | 'artist' | 'song' | null, select(id, type), clear() }`
- format-duration: `NaN`/`Infinity`/negative → `'—'`, normal → `'m:ss'`

**Test scenarios:**
- formatDuration(0) → '0:00', formatDuration(61) → '1:01', formatDuration(NaN) → '—'
- useImageBlob: blob URL created on successful fetch, revoked on unmount, no leak on cancelled request
- blurhash decode: returns valid hex color, handles null blurhash gracefully
- Selection store: select sets id+type, clear resets, persists across navigation

**Verification:** `cd ui/web && yarn vitest run src/shared/utils src/shared/hooks src/features/catalog/stores src/features/catalog/hooks`

**Dependencies:** None (can parallel with Unit 1)

---

### Unit 4: Context menu system

**Goal:** Native right-click context menus for songs, albums, and artists. Uses existing shadcn ContextMenu component wired to player store and navigation.

**Files:**
- `ui/web/src/features/catalog/components/menus/SongContextMenu.tsx`
- `ui/web/src/features/catalog/components/menus/AlbumContextMenu.tsx`
- `ui/web/src/features/catalog/components/menus/ArtistContextMenu.tsx`
- `ui/web/src/features/catalog/hooks/use-context-actions.ts` — shared action handlers (play, queue, navigate, love)
- Tests for each menu component and action hook

**Patterns to follow:**
- shadcn `ContextMenu` from `@/shared/components/ui/context-menu`
- Player store actions: `playTrack`, `addToQueue`, `insertAfterCurrent`
- Navigation: `useNavigate()` for Go to Album/Artist
- Playlist: `useGetPlaylistIndex` for submenu

**Test scenarios:**
- SongContextMenu: "Play" calls playTrack, "Play Next" calls insertAfterCurrent, "Go to Album" navigates, "Add to Playlist" opens dialog
- AlbumContextMenu: "Play All" plays all songs, "Shuffle All" plays shuffled, "Go to Artist" navigates
- ArtistContextMenu: "Shuffle All" fetches and shuffles all artist songs, "Play All Albums" fetches all albums

**Verification:** `cd ui/web && yarn vitest run src/features/catalog/components/menus src/features/catalog/hooks/use-context-actions`

**Dependencies:** Unit 2 (typed interfaces), Unit 3 (selection store for knowing what's selected)

---

### Unit 5: Grid view

**Goal:** Album art grid view — the primary browse mode. Responsive columns, blurhash placeholder, cover is the interaction target. Click selects, Enter opens detail.

**Files:**
- `ui/web/src/features/catalog/views/GridView.tsx`
- `ui/web/src/features/catalog/components/AlbumGridCard.tsx` — single album card
- `ui/web/src/features/catalog/hooks/use-grid-view-model.ts` — data fetching, pagination, virtualization setup
- Tests

**Patterns to follow:**
- `useGetAlbumIndex` hook with proper typed params (sort, order, page, artistId, genre)
- Virtualized grid via `@tanstack/react-virtual` for large libraries
- Blurhash placeholder (decoded color as background before image loads)
- `useImageBlob` for authenticated cover fetching
- Click → selection store, Enter → navigation to detail

**Test scenarios:**
- Renders album cards with title and artist
- Click selects album (selection store updated)
- Enter navigates to album detail
- Right-click opens AlbumContextMenu
- Loading state shows blurhash-colored placeholders
- Empty state shows message
- Pagination loads more albums
- Error state shows retry

**Verification:** `cd ui/web && yarn vitest run src/features/catalog/views/GridView`

**Dependencies:** Units 2, 3, 4

---

### Unit 6: List view

**Goal:** Compact sortable table with configurable columns. Media nerd home base.

**Files:**
- `ui/web/src/features/catalog/views/ListView.tsx`
- `ui/web/src/features/catalog/components/ListHeader.tsx` — sortable column headers
- `ui/web/src/features/catalog/components/ListRow.tsx` — single row with context menu
- `ui/web/src/features/catalog/components/ColumnConfig.tsx` — column visibility toggle (right-click header)
- `ui/web/src/features/catalog/hooks/use-list-view-model.ts` — sort state, column config, data fetching
- `ui/web/src/features/catalog/stores/list-column-store.ts` — persisted column visibility and widths
- Tests

**Patterns to follow:**
- Server-side sorting via `sort` and `order` API params
- Column config persisted to localStorage via Zustand persist
- Available columns: #, Title, Artist, Album, Year, Genre, Duration, Bitrate, Format, Date Added
- Default visible: Title, Artist, Album, Year, Duration
- Virtualized rows via @tanstack/react-virtual

**Test scenarios:**
- Renders rows with configured columns
- Click column header sorts ascending, click again descending, click again removes sort
- Right-click header shows column visibility menu
- Click row selects, Enter opens detail
- Column widths are draggable (future — basic fixed widths for now)
- Server-side sort sends correct API params

**Verification:** `cd ui/web && yarn vitest run src/features/catalog/views/ListView`

**Dependencies:** Units 2, 3, 4

---

### Unit 7: Column Browser view

**Goal:** Genre → Artist → Album → Songs cascading columns. Keyboard navigable. The power-user home base.

**Files:**
- `ui/web/src/features/catalog/views/ColumnBrowserView.tsx`
- `ui/web/src/features/catalog/components/BrowserColumn.tsx` — rewritten with server-side filtering
- `ui/web/src/features/catalog/components/BrowserSongColumn.tsx` — virtualized song list column
- `ui/web/src/features/catalog/hooks/use-column-browser.ts` — cascading filter state, data fetching per column
- Tests

**Patterns to follow:**
- Current `BrowserColumn.tsx` as starting reference but rewritten for server-side filtering
- Each column fetches data filtered by previous column's selection
- Genre column: `useGetGenreIndex`
- Artist column: `useGetArtistIndex({ genre: selectedGenre })` (new param from Unit 1)
- Album column: `useGetAlbumIndex({ artistId: selectedArtist })` (new param)
- Song column: `useGetSongIndex({ albumId: selectedAlbum })` (new param)
- Arrow keys navigate within column, Tab/Shift+Tab between columns
- Columns animate in/out at 80ms

**Test scenarios:**
- All four columns render with data
- Selecting genre filters artists
- Selecting artist filters albums
- Selecting album filters songs
- Arrow keys navigate within column
- Tab moves between columns
- Enter plays selected song
- Keyboard navigation works end-to-end without mouse

**Verification:** `cd ui/web && yarn vitest run src/features/catalog/views/ColumnBrowserView`

**Dependencies:** Units 1, 2, 3, 4

---

### Unit 8: Album Detail page

**Goal:** Full album detail with compact fixed header, full-height track list, collapsible metadata, blurhash accent.

**Files:**
- `ui/web/src/features/catalog/pages/AlbumDetailPage.tsx` — complete rewrite
- `ui/web/src/features/catalog/components/AlbumTrackList.tsx` — virtualized track list with play indicators
- `ui/web/src/features/catalog/components/AlbumMetadata.tsx` — collapsible metadata panel
- `ui/web/src/features/catalog/components/AlbumHeader.tsx` — compact fixed header with cover, title, play
- `ui/web/src/features/catalog/hooks/use-album-detail.ts` — data fetching, blurhash accent
- `ui/web/src/features/catalog/hooks/use-blurhash-accent.ts` — extracts dominant color, applies CSS variable
- Tests

**Patterns to follow:**
- `useGetAlbumShow` with proper typed response
- Compact header: cover thumbnail 32×32, title, artist, play button — one line
- Track list: #, title, artist (compilations), duration. Playing track shows accent equalizer icon.
- Hover on row: inline play button replaces #.
- Metadata: collapsible section, shows all fields from SongResource (bitrate, format, etc.)
- Blurhash accent: decode blurhash → extract color → set `--color-accent-derived` CSS variable
- Enter on track: playTrack(track, fullAlbumTracks)

**Test scenarios:**
- Renders album title, artist, cover image
- Track list shows all songs with correct numbers
- Playing track shows equalizer icon
- Hover reveals play button
- Click track selects, Enter plays with full album queue
- Metadata panel collapsed by default, expands on click
- Back button returns to previous view
- Error state with retry
- Loading state with skeleton
- Blurhash accent applied to header underline

**Verification:** `cd ui/web && yarn vitest run src/features/catalog/pages/AlbumDetailPage`

**Dependencies:** Units 2, 3, 4

---

### Unit 9: Artist Detail page

**Goal:** Artist detail with compact header, discography grid/list, and "top songs" section.

**Files:**
- `ui/web/src/features/catalog/pages/ArtistDetailPage.tsx` — complete rewrite
- `ui/web/src/features/catalog/components/ArtistDiscography.tsx` — album grid/list for artist
- `ui/web/src/features/catalog/hooks/use-artist-detail.ts` — data fetching with `artistId` filter
- Tests

**Patterns to follow:**
- `useGetArtistShow` for artist info
- `useGetAlbumIndex({ artistId })` for discography (new param from Unit 1)
- Compact header: avatar (first letter), name, album count, play/shuffle buttons
- Discography: respects current view mode (grid/list)
- Back button returns to previous view
- No `<a href>` — use `<Link>` everywhere

**Test scenarios:**
- Renders artist name, album count
- Albums fetched with artistId filter (not name search)
- Album grid/list renders correctly
- Click album selects, Enter opens album detail
- Context menu works on albums
- Error/loading states

**Verification:** `cd ui/web && yarn vitest run src/features/catalog/pages/ArtistDetailPage`

**Dependencies:** Units 1, 2, 3, 4, 5 or 6

---

### Unit 10: View mode router and shell

**Goal:** The catalog shell that hosts the six view modes. View mode switching, URL routing, preserved state across navigation. This is the "main content area" that adapts to context.

**Files:**
- `ui/web/src/features/catalog/CatalogShell.tsx` — main content area host
- `ui/web/src/features/catalog/components/ViewModeSwitcher.tsx` — 1-6 view mode selector
- `ui/web/src/features/catalog/stores/view-mode-store.ts` — persisted view mode preference
- `ui/web/src/features/layout/routes.tsx` — update catalog routes
- Tests

**Patterns to follow:**
- View mode persisted to localStorage via Zustand
- URL scheme: `/` = home (Grid), `/browse?view=list`, `/browse?view=columns`, `/browse?view=timeline`, `/browse?view=activity`, `/browse?view=discover`
- Or simpler: `/albums` always shows albums, view mode is a preference
- Album detail: `/albums/:publicId` replaces browse view (slide transition)
- Artist detail: `/artists/:publicId` replaces browse view
- Back button returns to browse view with preserved scroll and selection

**Test scenarios:**
- Switching view modes renders correct component
- View mode preference persists across navigation
- Entering album detail slides from right
- Back returns to browse view with correct view mode
- Keyboard 1-6 switches view modes

**Verification:** `cd ui/web && yarn vitest run src/features/catalog/CatalogShell`

**Dependencies:** Units 5, 6, 7 (at least one view must exist)

---

### Unit 11: Timeline view

**Goal:** Albums organized by release year. Horizontal shelves per year, collapsible decades. Chronological exploration.

**Files:**
- `ui/web/src/features/catalog/views/TimelineView.tsx`
- `ui/web/src/features/catalog/components/TimelineDecade.tsx` — decade section with collapse/expand
- `ui/web/src/features/catalog/components/TimelineYear.tsx` — year shelf with album thumbnails
- `ui/web/src/features/catalog/hooks/use-timeline-view-model.ts` — fetches all albums, groups by year
- Tests

**Patterns to follow:**
- Fetch albums with `?sort=year&order=asc&limit=1000` (or paginated with all pages)
- Group by decade → year
- Virtualize decades (not individual albums — too few decades)
- Album thumbnails at 64px, blurhash background
- Decade headers are collapsible
- Click album selects, Enter opens detail
- Context menu on albums

**Test scenarios:**
- Albums grouped by decade and year
- Decades are collapsible
- Years show album thumbnails
- Albums sorted chronologically within year
- Click selects, Enter opens detail
- Albums without year go into "Unknown" section

**Verification:** `cd ui/web && yarn vitest run src/features/catalog/views/TimelineView`

**Dependencies:** Units 2, 3, 4

---

### Unit 12: Activity view

**Goal:** Listening history as primary organization. Grouped by time period. Play counts and last-played dates.

**Files:**
- `ui/web/src/features/catalog/views/ActivityView.tsx`
- `ui/web/src/features/catalog/components/ActivityGroup.tsx` — time period group (today, this week, etc.)
- `ui/web/src/features/catalog/components/ActivityItem.tsx` — single activity entry
- `ui/web/src/features/catalog/hooks/use-activity-view-model.ts` — fetches and groups activity
- Tests

**Patterns to follow:**
- `useGetActivityHistory` for data
- Group by: Today, Yesterday, This Week, This Month, Older
- Each item shows: song title, artist, album, played at timestamp
- Click selects, Enter replays
- Context menu on activity items (same as SongContextMenu)

**Test scenarios:**
- Activity items grouped by time period
- Each item shows song details and timestamp
- Click selects, Enter plays
- Context menu works
- Empty state for no activity
- Loading state

**Verification:** `cd ui/web && yarn vitest run src/features/catalog/views/ActivityView`

**Dependencies:** Units 2, 3, 4

---

### Unit 13: Discover view (basic)

**Goal:** Recommendations from API, organized by source. "Because you listened to X" clusters.

**Files:**
- `ui/web/src/features/catalog/views/DiscoverView.tsx`
- `ui/web/src/features/catalog/components/RecommendationCluster.tsx` — source-based group
- `ui/web/src/features/catalog/hooks/use-discover-view-model.ts` — fetches recommendations
- Tests

**Patterns to follow:**
- `useGetRecommendationIndex` for data
- Group recommendations by source entity
- Each cluster: "Because you listened to {source}" + recommended albums/artists
- Album/artist cards with click-to-select, Enter-to-open
- Context menus
- "Refresh" button to get new recommendations

**Test scenarios:**
- Recommendation clusters render with source label
- Each cluster shows recommended items
- Click selects, Enter opens detail
- Refresh button fetches new data
- Empty state when no recommendations
- Loading state

**Verification:** `cd ui/web && yarn vitest run src/features/catalog/views/DiscoverView`

**Dependencies:** Units 2, 3, 4

---

### Unit 14: Detail panel integration

**Goal:** When an item is selected (single click), the context panel on the right shows a preview. Quick glance without leaving the browse view.

**Files:**
- `ui/web/src/features/layout/components/panels/AlbumDetailsPanel.tsx` — rewrite
- `ui/web/src/features/layout/components/panels/ArtistDetailsPanel.tsx` — rewrite
- `ui/web/src/features/catalog/components/panels/SongPreviewPanel.tsx` — new
- `ui/web/src/features/layout/hooks/use-context-panel-selection.ts` — update to use new selection store
- Tests

**Patterns to follow:**
- Selection store drives panel content
- Panel shows: cover art (or avatar), title, artist, key metadata, play button
- For songs: also shows album link, duration, format
- Panel opens/closes with 120ms width animation
- Clicking "Open" in panel navigates to full detail page

**Test scenarios:**
- Selecting album shows album preview in panel
- Selecting artist shows artist preview in panel
- Selecting song shows song preview in panel
- Panel opens with animation
- Clicking "Open" navigates to detail page
- Clearing selection closes panel

**Verification:** `cd ui/web && yarn vitest run src/features/catalog/components/panels src/features/layout/hooks/use-context-panel-selection`

**Dependencies:** Units 2, 3

---

### Unit 15: Integration tests and final wiring

**Goal:** End-to-end wiring of all views, navigation transitions, keyboard shortcuts, and state preservation.

**Files:**
- Integration tests for navigation flows
- Route updates in `ui/web/src/features/layout/routes.tsx`
- Remove old catalog pages, wire new views
- Verify motion transitions work (view switch crossfade, detail slide)

**Patterns to follow:**
- React Router v6 with `useNavigate`, `useParams`
- View state preservation via Zustand stores (scroll position, selection)
- Motion: CSS transitions with `transition-[opacity,transform] duration-[80ms] ease-out`

**Test scenarios:**
- Navigate from grid to album detail and back — selection preserved
- Switch view modes via keyboard shortcuts (1-6)
- Switch view modes via UI selector
- Navigate to album detail from any view
- Navigate to artist detail from album detail
- Back button returns to correct view with correct state

**Verification:** `cd ui/web && yarn vitest run` — full test suite passes

**Dependencies:** All previous units

---

## Verification strategy

### Per-unit
Each unit has its own verification command — `yarn vitest run <path>` for frontend, `./vendor/bin/paratest` for backend.

### Integration
- `cd ui/web && yarn tsc --noEmit` — zero type errors across catalog feature
- `cd ui/web && yarn vitest run` — all tests pass
- `cd ui/web && yarn build` — production build succeeds
- `grep -r 'as any' ui/web/src/features/catalog/` — returns zero matches
- Manual: open app, browse library in all six views, select items, open detail pages, use keyboard navigation, right-click context menus

### Smoke test
1. Open app → Grid view loads with album covers
2. Switch to List view → sortable table appears
3. Switch to Column Browser → four cascading columns
4. Click album → detail panel previews on right
5. Press Enter → full album detail page slides in
6. See track list, click track, press Enter → plays
7. Right-click track → context menu → Play Next → verify queue updated
8. Press Back → returns to Grid view, same scroll position
9. Switch to Timeline → see albums by year
10. Switch to Activity → see listening history
11. Switch to Discover → see recommendations
12. Press ? → see keyboard shortcuts
13. No `as any`, no memory leaks, no broken cover images

## Execution order

```
Unit 1 (Backend API)  ──────────────────────────────────────────────┐
                                                                      │
Unit 3 (Shared utils) ──────────────────────────────┐                │
                                                      │                │
Unit 2 (OpenAPI + types) ──── depends on Unit 1 ─────┤                │
                                                      │                │
Unit 4 (Context menus) ──── depends on 2, 3 ─────────┤                │
                                                      │                │
Unit 5 (Grid)  ──────────── depends on 2, 3, 4 ──────┤                │
Unit 6 (List)  ──────────── depends on 2, 3, 4 ──────┤                │
Unit 7 (Columns) ────────── depends on 1, 2, 3, 4 ──┤                │
Unit 8 (Album detail) ───── depends on 2, 3, 4 ──────┤               │
Unit 9 (Artist detail) ──── depends on 1, 2, 3, 4 ──┤               │
Unit 11 (Timeline) ──────── depends on 2, 3, 4 ──────┤               │
Unit 12 (Activity) ──────── depends on 2, 3, 4 ──────┤               │
Unit 13 (Discover) ──────── depends on 2, 3, 4 ──────┤               │
Unit 14 (Detail panel) ──── depends on 2, 3 ──────────┤              │
                                                      │                │
Unit 10 (Shell + routing) ─ depends on 5, 6, 7 ──────┤              │
                                                      │                │
Unit 15 (Integration) ───── depends on all ───────────┘               │
```

Units 5, 6, 8, 11, 12, 13 can run in parallel (all depend on 2/3/4 only).
Units 7 and 9 additionally depend on Unit 1 (backend).
Unit 10 depends on at least one view existing.
Unit 15 is the final integration.
