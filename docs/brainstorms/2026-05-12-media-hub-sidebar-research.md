# Media Hub Sidebar — UX/UI Research & Design Specification

**Date:** 2026-05-12
**Status:** Research → Decisions locked
**Context:** Sidebar navigation redesign to support multi-media expansion (Music, Movies, TV Shows, Podcasts, Concerts, Ebooks)
**Decisions:** See Section 14 for locked decisions on selector placement, navigation behavior, thumbnail size, and search scope.

---

## 1. Problem Statement

The current sidebar is a flat list of 10 undifferentiated text links. Every item carries identical visual weight. There is no grouping, no hierarchy, no spatial logic. The user must scan the full list to find anything.

With the planned expansion into Movies, TV Shows, Podcasts, Concerts, and Ebooks, the flat list grows to 30–40+ items. A flat list of that size becomes a wall of text — unusable.

**Core question:** How do you organize 6 media types, each with 5–8 sub-sections, in a 224px sidebar without sacrificing discoverability, speed, or the design language?

---

## 2. Apple 2015-Era Design Principles (Our Foundation)

Apple's design philosophy from 2012–2020 rests on three principles we carry forward:

### 2.1 Deference
> "The UI serves content. It never competes with it."

The sidebar is a tool for reaching content. It should feel like it *recedes* — present when needed, invisible when not. The moment the sidebar draws attention to itself (decorative icons, bright highlights, animated transitions), it has failed.

**Application:** The sidebar surface (`#080809`) is darker than the main content (`#000000` is the same black, but the sidebar feels recessed by virtue of having less visual activity). No colored backgrounds on active items. No icons next to every nav item.

### 2.2 Clarity
> "Every element has a single, unambiguous meaning."

In the current flat list, "Radio" and "Songs" appear at the same level and in the same visual style, even though one is a passive content category and the other is a dynamic playback mode. Clarity means the visual hierarchy *means* something.

**Application:** Grouping labels, section dividers, and the media type selector must all communicate structure, not just organize space.

### 3.3 Depth
> "Layering and realistic motion help users understand the relationship between objects."

Apple used translucency, z-ordering, and spatial animation to communicate where things live. We don't use translucency (no blur-on-sidebar), but we use **z-layering**: the sidebar is behind the content plane. The content panel is the primary layer.

**Application:** When the sidebar switches media modes, the content area responds instantly (80ms ease-out). The sidebar itself is a stable, unmoving frame. Motion happens in the content, not the chrome.

---

## 3. Where Apple Went Wrong (and what we do instead)

### Apple's mistakes post-2020:
- **Liquid Glass (2025):** Decorative translucency that obscures content hierarchy. Every surface fights for attention.
- **SF Symbols overuse:** Icons on every nav item turned sidebars into visual noise. When everything has an icon, nothing stands out.
- **Tab bar proliferation:** Apple Music's bottom tab bar on macOS has 6 items with no semantic grouping — the same flat problem we have.

### Our correction:
- **No decorative translucency.** Surfaces are flat, opaque, distinguished by lightness values alone.
- **Icons only where they earn their weight.** Per the existing design language: default is no icon. Icons are reserved for destructive actions and the 3 most-used actions in a given context.
- **Structure is conveyed through typography and spacing**, not decoration. Section headers, indentation, and dividers do the work.

---

## 4. Competitive Analysis

### 4.1 Plex Web App
**Pattern:** Horizontal top-level media type tabs (Movies, TV Shows, Music, Photos, …), with a left sidebar showing sub-navigation for the active media type.

**What works:**
- Clean separation between "what kind of content" (tabs) and "what view" (sidebar)
- Sidebar content changes completely when you switch tabs
- Persistent "Settings" and user controls outside the tab/sidebar system

**What fails:**
- Tabs compete with the main content for horizontal space
- Too many visual styles (colored labels, poster thumbnails in sidebar)
- The sidebar still feels like a list of links, not a navigation surface

**Verdict:** The two-level pattern (media type → sidebar sections) is proven. The execution is cluttered.

### 4.2 Jellyfin Web
**Pattern:** Left sidebar with expandable sections. Media types appear as top-level items ("Movies", "TV Shows") that expand to show sub-items.

**What works:**
- Single sidebar, no tabs
- Familiar tree-navigation pattern

**What fails:**
- Expansion adds visual complexity — collapsed states hide content
- The sidebar becomes tall quickly with multiple expanded sections
- No content preview — purely structural navigation
- Feels like a file browser, not a media experience

**Verdict:** Tree expansion is the pattern to **avoid**. It optimizes for information density at the cost of visual calm.

### 4.3 Spotify Desktop
**Pattern:** Left sidebar with fixed sections (Your Library, Playlists). No multi-media type switching — music only.

**What works:**
- Library section shows actual content (playlist covers, artist thumbnails) inline
- "Your Library" as a compact list with small thumbnails creates content-first navigation
- Search is always at the top
- Flat but visually differentiated by section headers

**What fails:**
- Doesn't solve multi-media (by design — they're music-only)
- Playlist-heavy sidebar becomes long

**Verdict:** The "show content items, not just labels" pattern is the key insight. Spotify's library list with tiny thumbnails is closer to the Hub concept than Plex's pure link sidebar.

### 4.4 Apple Music (macOS, 2023–2025)
**Pattern:** Left sidebar with sections: Apple Music (Browse, Radio), Your Library (Recently Added, Artists, Albums, Songs…), Playlists. No multi-media.

**What works:**
- Section headers are subtle, typographic only
- Active item is a subtle background tint, not bold/color
- "Recently Added" shows content previews as a mini-grid in the main area

**What fails:**
- Sidebar is still just links
- Apple added a "category" sidebar filter for Apple Music vs Library vs Radio — but it's three radio buttons that feel disconnected from the content

**Verdict:** Apple's sidebar is the closest to our aesthetic. But even Apple hasn't solved multi-media in a sidebar. We need to go beyond them here.

### 4.5 Kodi (Estuary skin)
**Pattern:** Full-screen horizontal main menu. Media types (Pictures, Videos, Music, Programs, System) are large horizontal items. Selecting one reveals sub-categories below.

**What works:**
- Media types as large, distinct navigation targets
- Clear hierarchy: media type → category → content

**What fails:**
- Designed for TV remote (10-foot UI), not desktop
- Horizontal layout wastes vertical space
- No sidebar at all — navigation replaces content

**Verdict:** The idea of media types as *primary* navigation (not secondary) is right. The execution is wrong for desktop.

---

## 5. Synthesis: The Baander Pattern

### 5.1 Core Insight

**Media types are not menu items. They are modes.**

This is the key departure from every competitor. Plex puts media types in horizontal tabs. Jellyfin puts them in expandable tree nodes. Apple doesn't handle multi-media at all.

In Baander, selecting "Music" vs "Movies" changes the *entire navigation vocabulary* — not just the content area. The sidebar becomes a different instrument for each media type.

This is closer to how a professional tool works: Logic Pro's sidebar changes when you switch from "Mixer" to "Editor" mode. Final Cut's browser changes when you switch from "Effects" to "Transitions." The sidebar adapts to the task.

### 5.2 Design Principles for the Media Hub Sidebar

1. **The sidebar is two zones: selector + content.**
   - **Selector zone** (top): The media type switcher. Compact, always visible, independent of scroll.
   - **Content zone** (below): Navigation items for the active media type. Scrollable.

2. **Navigation items are grouped by purpose, not by data model.**
   - Groups: "Quick Jump", "Library", "Collections", "Recent", "Discover"
   - These are *task-oriented*, not *entity-oriented*. "Quick Jump" is where you go to browse. "Library" is where you go to find something specific. "Collections" is where you go for personal organization. "Recent" is where you go to resume.

3. **The Recent section is content-first.**
   - It shows actual album covers, movie posters, podcast artwork — tiny, inline, next to the item name and a relative timestamp.
   - This is the Hub DNA. The sidebar *shows you your stuff*, not just labels for where your stuff lives.
   - Maximum 4 items. Auto-populated from play/view history. No configuration.

4. **Settings and global tools are pinned.**
   - Below a divider, always visible regardless of scroll position or active media type.
   - Search, Settings, Equalizer — tools that don't belong to any media type.

5. **No icons on navigation items.**
   - Per existing design language. The section headers and grouping provide enough visual structure. Icons would add noise.
   - Exception: The media type selector items may use a minimal glyph (not a full icon) to differentiate types when horizontal space is limited. These glyphs are not colorful — they are `muted-foreground` silhouettes, 14px, acting as tiny visual anchors.

---

## 6. UI Specification

### 6.1 Dimensions & Layout

```
┌──────────────────────────────────────────────────────────────┐
│                        App Shell                              │
│ ┌─ Sidebar ─┐ ┌─ Main Content ──────────────┐ ┌─ Panel ──┐ │
│ │  224px     │ │  flex-1                     │ │  0–360px  │ │
│ │  w-56      │ │                             │ │           │ │
│ │            │ │                             │ │           │ │
│ │            │ │                             │ │           │ │
│ │            │ │                             │ │           │ │
│ └────────────┘ └─────────────────────────────┘ └───────────┘ │
└──────────────────────────────────────────────────────────────┘
```

Sidebar width stays at `w-56` (224px). No width increase. The constraint forces discipline.

### 6.2 Selector Zone — Media Type Switcher

```
┌─────────────────────────────────┐
│  Bånder                         │  ← Logo, h-12 (48px), px-4
│                                 │
│  ┌───────────────────────────┐  │
│  │ 🔍 Search...              │  │  ← Search input first, px-3, py-2
│  └───────────────────────────┘  │
│                                 │
│  ┌───────────────────────────┐  │
│  │ Music · Movies · TV · …   │  │  ← Media type selector below search, px-3
│  └───────────────────────────┘  │
│                                 │
│  ─── CONTENT ZONE (scrollable)  │
```

**Media type selector:**
- Horizontal layout, positioned below the search input (search is global, selector is a secondary context switch).
- Items separated by `·` (middle dot) in `muted-foreground`.
- Active media type: `text-foreground`, `font-medium`.
- Inactive: `text-muted-foreground`, `hover:text-foreground` with 60ms ease-out.
- Horizontal scroll if more than 4 media types. No wrapping.
- No background highlight. No tab underline. Just typographic weight change.
- Height: ~28px. Compact. It's a switcher, not a hero element.

**Typography:**
- Media type labels: `text-xs` (12px), `tracking-tight`.
- This is smaller than the nav items below — intentional. The selector is a utility, not a primary action. It should feel like a light switch, not a headline.

**Interaction:**
- Click to switch. No confirmation. Instant (0ms) — the sidebar content swaps, and the main content area transitions in 80ms ease-out.
- Keyboard: `Cmd+1` through `Cmd+6` for media types.
- Persisted: Last-active media type remembered across sessions (Zustand + localStorage).

### 6.3 Content Zone — Navigation Sections

Each section follows a consistent pattern:

```
── LIBRARY ────────────────────    ← Section header
   Albums                          ← Nav item
   Artists
   Songs
   Genres
```

**Section header:**
- `text-[11px]`, `uppercase`, `tracking-wider`, `font-medium`, `text-muted-foreground`
- This already exists in the design language. No new typography.
- Padding: `px-4 pt-4 pb-1` (first section) or `px-4 pt-3 pb-1` (subsequent)
- The `───` dashed line is NOT rendered. It's conveyed through spacing alone — a generous top padding on the section header creates a clear visual break. No horizontal rules between sections.

**Nav item:**
- `text-sm` (14px), `text-muted-foreground`
- Active: `text-foreground` + `bg-accent` background (subtle, `#141514` on `#080809`)
- Hover: `bg-accent/50`, 60ms ease-out
- Padding: `px-2.5 py-1.5` (per current)
- Border-radius: `rounded-md` (6px)
- No left-border highlight. No icon. No dot. Just text + background.

**Click behavior:**
- `page_link`: Navigate via React Router `<NavLink>`
- `panel_action`: Open context panel to specific tab
- `smart_filter`: Navigate to filtered view

### 6.4 The Recent Section — Content-First Navigation

This is the defining feature. It's what makes this sidebar a *Hub*, not a menu.

```
── RECENT ─────────────────────

  ┌──┐ OK Computer          2h ago
  │  │ Radiohead
  └──┘
  ┌──┐ Blade Runner 2049    yesterday
  │  │ Denis Villeneuve
  └──┘
  ┌──┐ The Secret History   3 days ago
  │  │ Donna Tartt
  └──┘
```

**Layout:**
- Each item: 32×32px thumbnail + two-line text block + relative timestamp
- Thumbnail: album cover, movie poster, book cover, podcast artwork — whatever matches the media type
- Rounded `rounded-md` (6px). No border.
- Text line 1: Item title, `text-sm`, `text-foreground`, `truncate`
- Text line 2: Subtitle (artist, director, author), `text-xs`, `text-muted-foreground`, `truncate`
- Timestamp: `text-[11px]`, `text-muted-foreground`, right-aligned or trailing
- Row height: 36px total. Compact.
- Maximum: 4 items. When history exceeds 4, oldest items drop off.

**Interaction:**
- Click: Navigate to the detail page for that item (album, movie, etc.)
- Right-click: Context menu (same as clicking the item in a grid/list view)

**Data source:**
- `GET /api/user/recent?mediaType=music&limit=4`
- Auto-populated. No user configuration.
- Stale items (>30 days) are automatically removed.
- The section header shows "RECENT" regardless of whether items exist.
- Empty state: "Nothing played yet" in `text-muted-foreground`, single line.

**Authentication for thumbnails:**
- Use existing `useImageBlob` hook. The sidebar component requests thumbnails via the same authenticated blob URL pattern.
- Thumbnails are loaded lazily. The section is below the fold in most cases, so images load after the structural nav items render.
- Thumbnails are 32×32px — large enough to recognize cover art, small enough to fit 4 items in ~128px vertical space.

### 6.5 Pinned Footer Zone

```
────────────────────────────────
  Search · Settings · Equalizer
```

- Below a `border-border` divider line.
- `shrink-0` — does not scroll.
- Items are inline, separated by `·`, `text-xs`, `text-muted-foreground`.
- Not affected by media type switching. Always visible.
- Compact: single line, `py-2 px-4`.
- These are not nav items — they're utility links. No hover background. Just color change on hover (`hover:text-foreground`, 60ms).

---

## 7. Media Type Schemas

Each media type defines its own navigation vocabulary. The schema is:

```
{
  mediaType: string
  sections: [
    {
      id: string
      label: string          // Section header ("LIBRARY", "COLLECTIONS")
      items: [
        {
          id: string
          type: 'page_link' | 'panel_action' | 'smart_filter'
          label: string
          route?: string
          config?: Record<string, unknown>
        }
      ]
    }
  ]
}
```

### Music
| Section | Items |
|---------|-------|
| Quick Jump | Home, Browse |
| Library | Albums, Artists, Songs, Genres |
| Collections | Playlists, Favorites |
| Discover | Radio, Recommended |
| Recent | (auto-populated, max 4) |

### Movies
| Section | Items |
|---------|-------|
| Quick Jump | Home, Browse |
| Library | Movies, Directors, Genres |
| Collections | Watchlists, Favorites |
| Discover | Recommended |
| Recent | (auto-populated, max 4) |

### TV Shows
| Section | Items |
|---------|-------|
| Quick Jump | Home, Browse |
| Library | Shows, Seasons, Episodes |
| Collections | Watchlists, Favorites |
| Discover | Continue Watching, Recommended |
| Recent | (auto-populated, max 4) |

### Podcasts
| Section | Items |
|---------|-------|
| Quick Jump | Home, Browse |
| Library | Podcasts, Episodes |
| Collections | Subscriptions |
| Discover | Discover, Trending |
| Recent | (auto-populated, max 4) |

### Concerts
| Section | Items |
|---------|-------|
| Quick Jump | Home, Browse |
| Library | Concerts, Venues, Artists |
| Collections | Favorites |
| Discover | Nearby, Recommended |
| Recent | (auto-populated, max 4) |

### Ebooks
| Section | Items |
|---------|-------|
| Quick Jump | Home, Browse |
| Library | Books, Authors, Series |
| Collections | Shelves, Reading Lists |
| Discover | Recommended |
| Recent | (auto-populated, max 4) |

---

## 8. Route Architecture

Media types become route prefixes:

```
/music/          → Music Home (Hub page for music)
/music/albums    → Music Albums page
/music/albums/:id → Album detail
/movies/         → Movies Home
/movies/browse   → Movie browser
/movies/:id      → Movie detail
/tv/             → TV Home
/tv/shows/:id    → Show detail
/podcasts/       → Podcasts Home
/concerts/       → Concerts Home
/ebooks/         → Ebooks Home
```

**Migration from current routes:**
- `/albums` → `/music/albums`
- `/artists` → `/music/artists`
- `/songs` → `/music/songs`
- `/genres` → `/music/genres`
- `/playlists` → `/music/playlists`
- `/radio` → `/music/radio`
- `/browse` → `/music/browse`
- `/search` → `/search` (stays global, no media prefix)
- `/equalizer` → `/equalizer` (stays global)
- `/settings` → `/settings` (stays global)

**The Home page (`/`) behavior:**
- `/` redirects to the active media type's home: `/music` (or `/movies`, `/tv`, etc.)
- Each media type's Home page is a full Hub view — content-first cards for new releases, recently played, recommendations, etc.
- This replaces the current `HomePage` with a media-aware version.

---

## 9. Interaction Design

### 9.1 Switching Media Types

```
User clicks "Movies" in the selector zone
  → Selector updates activeMedia to "movies" (instant, 0ms)
  → Sidebar content zone re-renders with Movies schema (instant, React state)
  → Main content area navigates to /movies/ (Movies Home) regardless of current route
     Transition: 80ms ease-out
```

The sidebar and content area always agree on the active media type. Switching modes always takes you to that media type's Home page. This provides a clean, predictable mental model — no sidebar/content mismatch states.

Exception: If already on a page within the target media type (e.g., on `/movies/browse` and clicking "Movies" selector), no navigation occurs.

### 9.2 The Sidebar Is Mode-Aware, Content Always Follows Mode

The sidebar and content area are always in sync. When you switch modes:

- The sidebar re-renders with the new media type's schema (instant)
- The content area navigates to that media type's Home page (80ms ease-out)
- The active nav item highlight follows the Home page URL

If you navigate to `/settings` (global page) from within a media mode, the sidebar retains the last-active media type's schema. Returning to media navigation (clicking any sidebar nav item) resumes in that media type's context. Switching modes from a global page still navigates to the new media type's Home.

### 9.3 Search Scoping

Search respects the active media mode:

- Default scope: Active media type only. In Movies mode, search returns movie results.
- A toggle (`All` / current type label) appears next to the search results header.
- Clicking `All` expands to global results, grouped by media type.
- The toggle state does not persist — each new search starts scoped.
- The search input itself is media-type-agnostic (same input, same placeholder). Scoping happens at the results level.
- URL structure: `/search?q=blade&scope=movies` (scoped) vs `/search?q=blade&scope=all` (global)

### 9.4 Keyboard Shortcuts

| Shortcut | Action |
|----------|--------|
| `Cmd+1` – `Cmd+6` | Switch media type (Music, Movies, TV, Podcasts, Concerts, Ebooks) |
| `Cmd+K` | Spotlight search (existing) |
| `Cmd+L` | Focus sidebar search input |
| `Cmd+Shift+F` | Toggle search scope (current type ↔ all) |
| `↑` / `↓` | Navigate between sidebar items (when sidebar is focused) |
| `Enter` | Activate focused sidebar item |

### 9.4 Empty Media Types

When a media type has no libraries configured:

- The selector still shows the media type.
- The sidebar shows its full schema with all navigation items.
- Clicking any Library item shows the empty state in the main content area: "Add a [media type] library to get started."
- The Recent section shows: "Nothing played yet."
- No hidden tabs. No disabled items. Everything is discoverable.

---

## 10. Motion & Transitions

All values per the existing design language. No new animation patterns.

| Transition | Duration | Easing |
|-----------|----------|--------|
| Media type switch (sidebar content swap) | 0ms | none (instant) |
| Media type switch (content area route change) | 80ms | ease-out |
| Nav item hover | 60ms | ease-out |
| Nav item active highlight | 0ms | none (instant) |
| Recent section item load (thumbnail) | 120ms | ease-out (fade-in) |
| Sidebar scroll | Native OS | — |

**No animation on the selector zone.** The active media type changes by text weight and color — no underline sliding, no background fading, no tab indicator moving. It should feel like switching a label, not animating a component.

---

## 11. Implementation Architecture

### 11.1 New Components

```
features/layout/
├── components/
│   ├── AppShell.tsx              (existing, unchanged)
│   ├── Sidebar.tsx               (rewrite)
│   ├── SidebarSelector.tsx       (new — media type switcher)
│   ├── SidebarContent.tsx        (new — renders sections for active media type)
│   ├── SidebarSection.tsx        (new — renders a single section with header + items)
│   ├── SidebarRecentItems.tsx    (new — Recent section with thumbnails)
│   ├── SidebarPinnedFooter.tsx   (new — Settings, Equalizer, Search)
│   └── SidebarEditor.tsx         (existing, extended to support media type schemas)
├── hooks/
│   ├── use-sidebar-config.ts     (existing, extended)
│   └── use-media-mode.ts         (new — manages active media type)
├── stores/
│   ├── sidebar-store.ts          (existing, extended)
│   └── media-mode-store.ts       (new — Zustand store for activeMedia)
└── schemas/
    ├── music-sidebar.ts          (new — default sidebar schema for Music)
    ├── movies-sidebar.ts         (new)
    ├── tv-sidebar.ts             (new)
    ├── podcasts-sidebar.ts       (new)
    ├── concerts-sidebar.ts       (new)
    └── ebooks-sidebar.ts         (new)
```

### 11.2 Data Model Changes

```typescript
// Current
interface SidebarItemData {
  id: string
  type: 'page_link' | 'smart_filter' | 'panel_action'
  label: string
  icon: string
  config: Record<string, unknown>
}

// Proposed
type MediaType = 'music' | 'movies' | 'tv' | 'podcasts' | 'concerts' | 'ebooks'

interface SidebarSection {
  id: string
  label: string  // "LIBRARY", "COLLECTIONS", "RECENT"
  type: 'navigation' | 'recent'
  items: SidebarItemData[]
}

interface MediaSidebarSchema {
  mediaType: MediaType
  sections: SidebarSection[]
}

// sidebar-store gains:
interface SidebarState {
  // ...existing fields
  activeMedia: MediaType
  schemas: Record<MediaType, MediaSidebarSchema>
  setActiveMedia: (media: MediaType) => void
  setSchema: (media: MediaType, schema: MediaSidebarSchema) => void
}
```

### 11.3 Backend API

```
GET  /api/user/sidebar-config/:mediaType   → MediaSidebarSchema
PUT  /api/user/sidebar-config/:mediaType   → saves custom schema
GET  /api/user/recent?mediaType=music&limit=4  → Recent items with thumbnail URLs
```

### 11.4 Route Changes

```typescript
// New route structure
{
  element: <AppShell />,
  children: [
    { path: '/', element: <Navigate to="/music" replace /> },
    // Music
    { path: '/music', element: <MusicHomePage /> },
    { path: '/music/albums', element: <AlbumsPage /> },
    { path: '/music/albums/:publicId', element: <AlbumDetailPage /> },
    { path: '/music/artists', element: <ArtistsPage /> },
    { path: '/music/artists/:publicId', element: <ArtistDetailPage /> },
    { path: '/music/songs', element: <SongsPage /> },
    { path: '/music/genres', element: <GenresPage /> },
    { path: '/music/browse', element: <CatalogShell /> },
    { path: '/music/playlists', element: <PlaylistsPage /> },
    { path: '/music/radio', element: <RadioPage /> },
    // Movies
    { path: '/movies', element: <MoviesHomePage /> },
    { path: '/movies/browse', element: <MovieBrowserPage /> },
    { path: '/movies/:publicId', element: <MovieDetailPage /> },
    // ... similar for TV, Podcasts, Concerts, Ebooks
    // Global
    { path: '/search', element: <SearchPage /> },
    { path: '/equalizer', element: <EqualizerPage /> },
    { path: '/settings', element: <SettingsPage /> },
  ]
}
```

---

## 12. Accessibility

- **Media type selector:** Uses `role="tablist"` with `role="tab"` children. Arrow key navigation between tabs. `aria-selected` on active tab.
- **Nav items:** Standard `<a>` or `<button>` elements. No custom roles needed.
- **Section headers:** `role="group"` with `aria-labelledby` pointing to the section header text.
- **Recent items:** Each has `aria-label` with full context: "OK Computer by Radiohead, played 2 hours ago".
- **Keyboard focus:** `Tab` enters the sidebar at the selector zone, then moves through nav items. `Escape` returns focus to the main content area.
- **Focus visible:** The existing focus ring pattern (`ring` from shadcn) applies. No custom focus styles.

---

## 13. What This Is Not

- **Not a tree view.** No expand/collapse. All sections for the active media type are visible. Scrolling is the mechanism, not expansion.
- **Not a dashboard.** The Recent section shows content previews, but the sidebar is still a navigation tool. No widgets, no stats, no charts.
- **Not a replacement for the Home page.** Each media type's Home page (`/music`, `/movies`) is the full Hub experience. The sidebar is a compressed table of contents for reaching any section quickly.
- **Not configurable per-section.** The user can customize which items appear in their sidebar (via the existing SidebarEditor), but the section structure (Library, Collections, Recent, Discover) is defined by the schema. Users hide items, not sections.

---

## 14. Decisions (Locked)

| # | Decision | Choice | Rationale |
|---|----------|--------|-----------|
| 1 | Selector placement | **Below search** — search first, selector second | Search is global and should be the first interactive element. The media type selector is a context switch, not the primary action — it sits below search as a secondary utility. |
| 2 | Mode switch navigation | **Always navigate to new media type Home** | Clean mental model: sidebar and content area always agree on the active media type. Switching to Movies while viewing an album takes you to `/movies`. You can always go back. |
| 3 | Recent thumbnails | **32×32px** | Balanced — recognizable cover art without eating too much vertical space. 4 items = ~128px. |
| 4 | Search scope | **Scoped to active type, toggle to expand** | When in Movies mode, search shows movie results first. A toggle (`All` / current type) lets the user expand to global results. More predictable results, respects the mode concept. |
