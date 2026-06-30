# Catalog Redesign — Requirements

**Date:** 2026-05-11
**Status:** Approved
**Scope:** `ui/web/src/features/catalog/` (rewrite), `ui/web/src/shared/` (new utilities), backend API enhancements

---

## Philosophy

Desktop-first music catalog that adapts its layout to context. The UI gets out of the way — content is the interface. Progressive disclosure: simple by default, powerful on demand. Interactions are precise and immediate. Motion communicates spatial continuity, never decoration.

Design reference: Apple products 2010-2020 — clarity, restraint, spatial hierarchy, content-first. Not Apple Music. Flat dark surfaces, no gradients, no fluid bubbles, no skeuomorphism.

---

## Interaction Model

| Input | Action |
|-------|--------|
| **Click** | Selects item — highlights row/card, shows preview in detail panel |
| **Enter / Space** | Commits — plays item or opens full detail page |
| **Right-click** | Native context menu with full action vocabulary |
| **Drag** | Shortcut for common actions — add to playlist, reorder queue |
| **Keyboard** | Fully customizable, discoverable defaults |

Selection is a first-class concept. Whatever is selected is what the detail panel shows, what keyboard shortcuts act on, what the context menu targets.

---

## Layout Adaptation

The main content area is not a fixed page — it reshapes based on context:

1. **Library browsing** → One of six view modes (see below)
2. **Item selected** (single click) → Context panel on right expands with preview. Browse view stays visible.
3. **Item opened** (Enter) → Full detail page replaces browse view
4. **Back** → Returns to previous view with scroll position and selection preserved

---

## Six View Modes

### 1. Grid
Album art cards. Responsive columns (auto-fill based on available width). Cover is the interaction target. Title + artist below. No chrome. Hover shows nothing — content is the affordance. Blurhash placeholder while cover loads.

### 2. List
Compact sortable table. Configurable columns via right-click on header. Default columns: #, Title, Artist, Album, Year, Genre, Duration. Available columns: Bitrate, Format, Sample Rate, Date Added, Play Count, Last Played, File Size, Rating. Media nerds live here. Column widths are draggable. Sort by clicking column headers (ascending → descending → none). Server-side sorting.

### 3. Column Browser
Genre → Artist → Album → Songs, cascading left to right. Each column is a virtualized scroll list. Keyboard navigable: arrow keys move within column, Tab/Shift+Tab move between columns, Enter selects and advances to next column. Columns slide in/out with 80ms animation. Widths are draggable between columns. The power-user home base.

### 4. Timeline
Albums arranged by release year. Horizontal axis grouped by decade, then year. Scroll vertically through decades. Each decade section is collapsible. Each year shows its albums as a horizontal shelf of cover art thumbnails. Covers are small (64px). Click year label to expand/collapse. Selecting an album shows preview in detail panel. This turns the library into a history lesson.

### 5. Activity
Listening history as primary organization. Grouped by time: Today, Yesterday, This Week, This Month, Older. Each group shows what was played with play counts and timestamps. Most-listened artists/albums get a "top" badge. Heatmap visualization of listening patterns (day of week × time of day, if data available). Answers "what have I been into lately?"

### 6. Discover
Recommendations from `/api/recommendations`. Organized as "Because you listened to X" clusters. Each cluster shows related albums/artists. Each recommendation shows *why* (same genre, same era, same label, related artist). "Refresh" button gets new recommendations. Selecting a recommendation shows preview in detail panel. The "I don't know what to play" view.

---

## Album Detail Page (Full View)

Opened by pressing Enter on a selected album. Replaces the browse view.

### Compact Fixed Header
One line. Sticks to top while tracks scroll.
- Cover thumbnail (32×32, rounded)
- Album title (semibold)
- Artist name (muted)
- Year (muted, inline with artist)
- Play button (primary)
- Shuffle button (secondary)
- Back button (left arrow, returns to browse view)
- Blurhash-derived accent as a 2px underline on the header

### Full-Height Track List
The star. Fills remaining viewport height. Virtualized.
- Columns: #, Title, Artist (compilations only, hidden for single-artist albums), Duration
- Currently playing track: small accent-colored equalizer icon replaces the track number
- Hover on row: reveals inline play button on the left (replacing the #)
- Click row: selects track (shows in detail panel)
- Enter on row: plays track with full album as queue context
- Right-click row: full song context menu

### Collapsible Metadata Section
Below the track list or as a drawer from bottom.
- Collapsed by default. One click to expand.
- Shows: Format, Bitrate, Sample Rate, Bit Depth, File Size, Replay Gain, MusicBrainz IDs, Label, Catalog Number, Original Release Date, Disc Number, Composer, Producer, Total Duration, Total Size
- Per-track metadata available by expanding individual tracks (disclosure triangle)

---

## Artist Detail Page (Full View)

Similar to album detail but adapted:
- Compact header: artist avatar (first letter or photo) + name + album count
- Discography section: albums in grid or list (respects current view mode preference)
- "Top Songs" section: most played songs from this artist (from activity data)
- "Related Artists" section: from recommendations API if available

---

## Context Menus

Native right-click only. No "..." buttons. No visible affordance — the UI stays clean.

### Song Context Menu
```
Play                    ⏎
Play Next
Play Last
─────────────
Go to Album             ⌘⇧A
Go to Artist            ⌘⇧R
─────────────
Add to Playlist       ▸
Add to Queue           Q
─────────────
Love / Unlove          L
─────────────
Get Info               ⌘I
```

### Album Context Menu
```
Play All                ⏎
Shuffle All
Play Next
Play Last
─────────────
Go to Artist            ⌘⇧R
─────────────
Add to Playlist       ▸
─────────────
Get Info               ⌘I
─────────────
Edit                    ⌘E
Download Cover Art
```

### Artist Context Menu
```
Shuffle All Songs       ⏎
Play All Albums
─────────────
Add to Playlist       ▸
─────────────
Get Info               ⌘I
```

---

## Keyboard Shortcuts

Fully customizable via a shortcuts panel (VS Code-style). Default bindings:

| Key | Action |
|-----|--------|
| ↑ / ↓ | Move selection up/down |
| J / K | Move selection up/down (vim) |
| Enter | Play / Open selected item |
| Space | Global play/pause |
| N | Next track |
| P | Previous track |
| / | Focus search |
| Q | Add selected to queue |
| L | Love/unlove selected |
| G → A | Go to artist of selected |
| G → L | Go to album of selected |
| 1–6 | Switch view mode |
| ⌘I | Get Info (expand metadata) |
| Esc | Back / Close panel |
| Tab | Move focus between panels |
| ? | Show shortcuts help |

Shortcuts are discoverable in a help overlay (?). Conflicts show a warning. All bindings are user-overridable and persisted to localStorage.

---

## Motion Language

Motion communicates *where things went*, not *how fancy the UI is*. Rule: if you can name the animation, it's too slow.

| Transition | Duration | Easing |
|-----------|----------|--------|
| View switch (Grid↔List↔Column) | 80ms crossfade | ease-out |
| Enter detail page | 120ms slide from right | ease-out |
| Back from detail | 100ms slide from left | ease-out |
| Column browser column change | 80ms slide | ease-out |
| Selection highlight | 0ms (instant snap) | none |
| Detail panel open/close | 120ms width animation | ease-out |
| Hover state | 60ms opacity/color | ease-out |
| Context menu | Native OS rendering | — |

No bounces. No springs. No overshoots. Linear or ease-out only.

---

## Accent Color System

Each album/artist page gets a subtle tint derived from its cover art.

1. **Decode blurhash** from `coverImage.blurhash` (already in API response) client-side — no image fetch needed
2. **Extract dominant color** from decoded blurhash palette
3. **Apply as subtle accent** — 2px underline on page header, playing indicator color, active selection glow
4. **Fallback** to global user accent when blurhash is unavailable or extracted color has low saturation / is too dark or light

The page tints before the cover image loads. Blurhash decodes in <1ms.

---

## Drag and Drop

| Source | Target | Action |
|--------|--------|--------|
| Song row | Queue panel | Insert at drop position |
| Song row | Sidebar playlist | Add to playlist |
| Album card | Sidebar playlist | Add all songs to playlist |
| Song row (within queue) | Queue position | Reorder queue |

DnD works alongside context menu. DnD is the shortcut for the most common action; context menu is the full vocabulary.

Visual feedback during drag: dragged item renders as a semi-transparent card. Drop target highlights with a 1px accent border. Invalid targets show no highlight.

---

## Technical Constraints

- **Desktop-first** — no mobile breakpoints needed initially
- **Dark theme** — #000 background, flat surfaces, thin 1px borders (#1a1a1f)
- **Typography** — Inter, tight tracking (-0.01em), font size 13-14px for body, 11px for labels
- **Components** — shadcn/ui as primitives. No raw `<button>` where shadcn Button applies.
- **Virtualization** — @tanstack/react-virtual for any list > 50 items
- **Type safety** — zero `as any`. Proper typed API response interfaces.
- **Server-side operations** — all filtering, sorting, pagination happen on the backend. Client never filters a paginated subset.
- **State** — Zustand stores with explicit interfaces. Selection state is a first-class store.
- **Tests** — every component has unit tests. Critical paths (selection, keyboard nav, queue building) have integration tests.

---

## Backend API Gaps

Required enhancements for the catalog redesign:

| Endpoint | Current | Needed |
|----------|---------|--------|
| `GET /api/albums` | `page` param only | Add `genre`, `artistId`, `sort` (title/year/artist/added), `order` (asc/desc), `search` params |
| `GET /api/songs` | `genres` param | Add `artistId`, `albumId`, `sort`, `order`, `search` params |
| `GET /api/artists` | `page` param only | Add `genre`, `sort`, `order`, `search` params |
| Artist→Albums | Does not exist | Either `GET /api/artists/{id}/albums` or `GET /api/albums?artistId={id}` |
| `GET /api/albums` response | `coverUrl` field inconsistent | Standardize: `coverImage: { url, blurhash }` on all album responses |
| `GET /api/albums` response | `publicId` field | Ensure consistent camelCase (not `public_id`) across all endpoints |
| `GET /api/recommendations` | Exists, unused | Verify response format matches Discover view needs |
| `GET /api/activity/history` | Exists, unused | Verify response includes timestamps, song/album/artist details |

---

## Phased Delivery

### Phase 1 — Foundation
Interaction model (selection state, context menus, keyboard shortcuts engine), Grid view, List view, Column Browser view, Album Detail page, Artist Detail page. Fix cover image rendering. Fix `as any` types. Backend API params for filtering/sorting.

### Phase 2 — Exploration
Timeline view, Activity view. Blurhash accent system. Drag and drop. Keyboard shortcut customization UI.

### Phase 3 — Discovery
Discover view (recommendations). Full metadata panel. Cover art download/edit. Artist detail enhancements (top songs, related artists).

---

## Success Criteria

- Every view renders real data without `as any` type casts
- Cover images render correctly on all pages
- Context menus work on songs, albums, and artists
- Keyboard shortcuts navigate all views without touching a mouse
- Album detail page has compact header + full-height track list
- Selection state persists across view switches
- No blob URL memory leaks
- Timeline shows albums organized chronologically
- Activity shows listening history by time period
- All motion is sub-150ms and functional, never decorative
