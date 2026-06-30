# Media Hub Sidebar — Implementation Plan

**Date:** 2026-05-12
**Requirements:** `docs/brainstorms/2026-05-12-media-hub-sidebar-research.md`
**Status:** Ready for implementation
**Scope:** Frontend-only (React/TypeScript). No backend API changes in this phase — media mode store uses localStorage persistence + static schemas.

---

## Overview

Redesign the sidebar from a flat list of 10 text links into a two-zone **media hub** pattern: a **selector zone** (media type switcher) at the top, and a **content zone** (sectioned navigation) below. Each media type (Music, Movies, TV Shows, Podcasts, Concerts, Ebooks) defines its own navigation vocabulary via schemas.

### Current State
- `Sidebar.tsx`: flat list of nav items, single-level, no grouping
- `sidebar-store.ts`: flat `SidebarItemData[]`, no media type awareness
- `use-sidebar-config.ts`: fetches from `/api/user/sidebar-config/` with hardcoded defaults
- `routes.tsx`: flat routes (`/albums`, `/artists`, etc.) — no media prefix
- `SidebarEditor.tsx`: drag-reorder customizer — will be extended

### Target State
- Sidebar split into: Logo → Search → Media Type Selector → Scrollable Sectioned Nav → Pinned Footer
- `media-mode-store.ts`: new Zustand store for `activeMedia`, persisted to localStorage
- 6 sidebar schemas (one per media type) with section-grouped items
- Routes restructured under media prefixes (`/music/albums`, `/movies/browse`, etc.)
- Recent section showing content-first thumbnails (depends on API — placeholder in this phase)
- Keyboard shortcuts for media type switching (Cmd+1 through Cmd+6)

---

## Implementation Units

### Unit 1: Media Mode Store

**Goal:** Create the Zustand store that manages active media type selection and persistence.

**Files:**
- `src/features/layout/stores/media-mode-store.ts` (new)
- `tests/features/layout/stores/media-mode-store.test.ts` (new)

**Dependencies:** None. This is the foundation.

**RED (failing test first):**
```typescript
// Test: media-mode-store.test.ts
describe('media-mode-store', () => {
  beforeEach(() => { localStorage.clear() })

  it('defaults to music', () => {
    expect(useMediaModeStore.getState().activeMedia).toBe('music')
  })

  it('sets active media type', () => {
    useMediaModeStore.getState().setActiveMedia('movies')
    expect(useMediaModeStore.getState().activeMedia).toBe('movies')
  })

  it('persists activeMedia to localStorage', () => {
    useMediaModeStore.getState().setActiveMedia('tv')
    const stored = localStorage.getItem('baander-media-mode')
    expect(stored).toBeTruthy()
    expect(JSON.parse(stored!).state.activeMedia).toBe('tv')
  })

  it('restores activeMedia from localStorage on init', () => {
    // Set directly in localStorage, then verify store reads it
    localStorage.setItem('baander-media-mode', JSON.stringify({
      state: { activeMedia: 'podcasts' },
      version: 0
    }))
    // Rehydration is handled by zustand/middleware persist
    // This test validates the persist middleware config
  })

  it('media type labels match expected values', () => {
    const labels = useMediaModeStore.getState().mediaTypeLabels
    expect(labels.music).toBe('Music')
    expect(labels.movies).toBe('Movies')
    expect(labels.tv).toBe('TV')
    expect(labels.podcasts).toBe('Podcasts')
    expect(labels.concerts).toBe('Concerts')
    expect(labels.ebooks).toBe('Ebooks')
  })
})
```

**GREEN (minimum code):**
```typescript
// src/features/layout/stores/media-mode-store.ts
import { create } from 'zustand'
import { persist } from 'zustand/middleware'

export type MediaType = 'music' | 'movies' | 'tv' | 'podcasts' | 'concerts' | 'ebooks'

export const MEDIA_TYPES: MediaType[] = ['music', 'movies', 'tv', 'podcasts', 'concerts', 'ebooks']

export const MEDIA_TYPE_LABELS: Record<MediaType, string> = {
  music: 'Music',
  movies: 'Movies',
  tv: 'TV',
  podcasts: 'Podcasts',
  concerts: 'Concerts',
  ebooks: 'Ebooks',
}

interface MediaModeState {
  activeMedia: MediaType
  setActiveMedia: (media: MediaType) => void
}

export const useMediaModeStore = create<MediaModeState>()(
  persist(
    (set) => ({
      activeMedia: 'music',
      setActiveMedia: (activeMedia) => set({ activeMedia }),
    }),
    {
      name: 'baander-media-mode',
    }
  )
)
```

**REFACTOR:** Clean up if needed. Verify persist middleware config matches existing store patterns (compare with `context-panel-store.ts`).

**Verification:** `npx vitest run tests/features/layout/stores/media-mode-store.test.ts` — all tests pass.

---

### Unit 2: Sidebar Schema Definitions

**Goal:** Define the 6 media-type sidebar schemas as static TypeScript data, with a shared type system.

**Files:**
- `src/features/layout/schemas/types.ts` (new)
- `src/features/layout/schemas/music-sidebar.ts` (new)
- `src/features/layout/schemas/movies-sidebar.ts` (new)
- `src/features/layout/schemas/tv-sidebar.ts` (new)
- `src/features/layout/schemas/podcasts-sidebar.ts` (new)
- `src/features/layout/schemas/concerts-sidebar.ts` (new)
- `src/features/layout/schemas/ebooks-sidebar.ts` (new)
- `src/features/layout/schemas/index.ts` (new — barrel export + ALL_SCHEMAS map)
- `tests/features/layout/schemas/sidebar-schemas.test.ts` (new)

**Dependencies:** None. Pure data.

**RED:**
```typescript
// Test: sidebar-schemas.test.ts
describe('sidebar schemas', () => {
  it('every MediaType has a schema', () => {
    MEDIA_TYPES.forEach((mt) => {
      const schema = ALL_SCHEMAS[mt]
      expect(schema).toBeDefined()
      expect(schema.mediaType).toBe(mt)
    })
  })

  it('every schema has at least 3 sections', () => {
    MEDIA_TYPES.forEach((mt) => {
      const schema = ALL_SCHEMAS[mt]
      expect(schema.sections.length).toBeGreaterThanOrEqual(3)
    })
  })

  it('every section has a unique id within its schema', () => {
    MEDIA_TYPES.forEach((mt) => {
      const ids = ALL_SCHEMAS[mt].sections.map((s) => s.id)
      expect(new Set(ids).size).toBe(ids.length)
    })
  })

  it('every item has a unique id within its schema', () => {
    MEDIA_TYPES.forEach((mt) => {
      const ids = ALL_SCHEMAS[mt].sections.flatMap((s) => s.items.map((i) => i.id))
      expect(new Set(ids).size).toBe(ids.length)
    })
  })

  it('music schema matches brainstorm spec', () => {
    const music = ALL_SCHEMAS.music
    const sectionLabels = music.sections.map((s) => s.label)
    expect(sectionLabels).toContain('Quick Jump')
    expect(sectionLabels).toContain('Library')
    expect(sectionLabels).toContain('Collections')
    expect(sectionLabels).toContain('Discover')
  })

  it('every page_link item has a route in config', () => {
    MEDIA_TYPES.forEach((mt) => {
      ALL_SCHEMAS[mt].sections.forEach((section) => {
        section.items.forEach((item) => {
          if (item.type === 'page_link') {
            expect(item.config?.route).toBeDefined()
            expect(item.config?.route).toMatch(/^\//)
          }
        })
      })
    })
  })

  it('schemas reference only routes that exist or will exist', () => {
    // Validates route format: /{mediaType}/... for media routes, or /global for global
    MEDIA_TYPES.forEach((mt) => {
      ALL_SCHEMAS[mt].sections.forEach((section) => {
        section.items.forEach((item) => {
          if (item.type === 'page_link' && item.config?.route) {
            const route = item.config.route as string
            // Routes for this media type should start with /{mt}
            // OR be a global route
            const isMediaRoute = route.startsWith(`/${mt}`)
            const isGlobalRoute = ['/search', '/settings', '/equalizer'].includes(route)
            expect(
              isMediaRoute || isGlobalRoute,
              `Route ${route} in ${mt} schema is neither a media route nor global`
            ).toBe(true)
          }
        })
      })
    })
  })
})
```

**GREEN:** Implement types and all 6 schemas per brainstorm Section 7.

Types:
```typescript
// schemas/types.ts
import type { SidebarItemData } from '../stores/sidebar-store'

export type MediaType = 'music' | 'movies' | 'tv' | 'podcasts' | 'concerts' | 'ebooks'

export interface SidebarSection {
  id: string
  label: string  // "LIBRARY", "COLLECTIONS", "RECENT"
  type: 'navigation' | 'recent'
  items: SidebarItemData[]
}

export interface MediaSidebarSchema {
  mediaType: MediaType
  sections: SidebarSection[]
}
```

Then 6 schema files following the brainstorm tables exactly, and an index barrel:
```typescript
// schemas/index.ts
export { ALL_SCHEMAS } from './music-sidebar' // etc.
// ALL_SCHEMAS: Record<MediaType, MediaSidebarSchema>
```

**REFACTOR:** Ensure schemas follow consistent patterns. Check item IDs are kebab-case and globally unique.

**Verification:** `npx vitest run tests/features/layout/schemas/sidebar-schemas.test.ts`

---

### Unit 3: Sidebar Store Extension

**Goal:** Extend `sidebar-store.ts` to support media-type-aware sectioned navigation, replacing the flat `items[]` model.

**Files:**
- `src/features/layout/stores/sidebar-store.ts` (modify)
- `tests/features/layout/stores/sidebar-store.test.ts` (new)

**Dependencies:** Unit 2 (schemas for default data).

**RED:**
```typescript
describe('sidebar-store (extended)', () => {
  beforeEach(() => {
    useSidebarStore.setState({
      items: [],
      activeMedia: 'music',
      schemas: ALL_SCHEMAS,
      isLoading: false,
      error: null,
      isEditorOpen: false,
    })
  })

  it('holds activeMedia state', () => {
    expect(useSidebarStore.getState().activeMedia).toBe('music')
  })

  it('setActiveMedia updates activeMedia', () => {
    useSidebarStore.getState().setActiveMedia('movies')
    expect(useSidebarStore.getState().activeMedia).toBe('movies')
  })

  it('getActiveSchema returns the schema for activeMedia', () => {
    const schema = useSidebarStore.getState().getActiveSchema()
    expect(schema.mediaType).toBe('music')
    expect(schema.sections.length).toBeGreaterThan(0)
  })

  it('getActiveSchema returns correct schema after switching', () => {
    useSidebarStore.getState().setActiveMedia('movies')
    const schema = useSidebarStore.getState().getActiveSchema()
    expect(schema.mediaType).toBe('movies')
  })

  it('schemas initialize from ALL_SCHEMAS', () => {
    expect(useSidebarStore.getState().schemas).toEqual(ALL_SCHEMAS)
  })

  it('setSchema updates a single media type schema', () => {
    const customSchema = { ...ALL_SCHEMAS.music, sections: [] }
    useSidebarStore.getState().setSchema('music', customSchema)
    expect(useSidebarStore.getState().schemas.music.sections).toEqual([])
  })

  it('preserves existing items array for backward compat', () => {
    useSidebarStore.getState().setItems([{ id: 'test', type: 'page_link', label: 'Test', icon: 'home', config: {} }])
    expect(useSidebarStore.getState().items).toHaveLength(1)
  })
})
```

**GREEN:** Extend `sidebar-store.ts`:
```typescript
interface SidebarState {
  // Existing (backward compat)
  items: SidebarItemData[]
  isLoading: boolean
  error: string | null
  isEditorOpen: boolean
  // New
  activeMedia: MediaType
  schemas: Record<MediaType, MediaSidebarSchema>
  // Actions
  setItems: (items: SidebarItemData[]) => void
  setLoading: (loading: boolean) => void
  setError: (error: string | null) => void
  setEditorOpen: (open: boolean) => void
  setActiveMedia: (media: MediaType) => void
  setSchema: (media: MediaType, schema: MediaSidebarSchema) => void
  getActiveSchema: () => MediaSidebarSchema
}
```

**REFACTOR:** Remove the `icon` field from `SidebarItemData` if no longer used by new sidebar rendering. Keep for backward compat with SidebarEditor until it's updated.

**Verification:** `npx vitest run tests/features/layout/stores/sidebar-store.test.ts`

---

### Unit 4: Sidebar Component Rewrite — Selector + Sectioned Nav

**Goal:** Rewrite `Sidebar.tsx` into the two-zone layout: selector zone (logo, search, media type tabs) and content zone (sectioned nav items from active schema). Extract sub-components.

**Files:**
- `src/features/layout/components/Sidebar.tsx` (rewrite)
- `src/features/layout/components/SidebarSelector.tsx` (new)
- `src/features/layout/components/SidebarContent.tsx` (new)
- `src/features/layout/components/SidebarSection.tsx` (new)
- `src/features/layout/components/SidebarPinnedFooter.tsx` (new)
- `tests/features/layout/components/sidebar.test.tsx` (new)

**Dependencies:** Unit 1 (media mode store), Unit 2 (schemas), Unit 3 (sidebar store extension).

**RED:**
```typescript
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { Sidebar } from '@/features/layout/components/Sidebar'

// Mock sidebar config hook to return empty items (schemas are used instead)
vi.mock('@/features/layout/hooks/use-sidebar-config', () => ({
  useSidebarConfig: () => ({ items: [], isLoading: false, error: null })
}))

function renderSidebar(initialPath = '/') {
  return render(
    <MemoryRouter initialEntries={[initialPath]}>
      <Sidebar />
    </MemoryRouter>
  )
}

describe('Sidebar', () => {
  it('renders the Bånder logo', () => {
    renderSidebar()
    expect(screen.getByText('Bånder')).toBeVisible()
  })

  it('renders the search input', () => {
    renderSidebar()
    expect(screen.getByPlaceholderText('Search...')).toBeVisible()
  })

  it('renders media type selector with all 6 types', () => {
    renderSidebar()
    expect(screen.getByRole('tab', { name: /music/i })).toBeVisible()
    expect(screen.getByRole('tab', { name: /movies/i })).toBeVisible()
    expect(screen.getByRole('tab', { name: /tv/i })).toBeVisible()
    expect(screen.getByRole('tab', { name: /podcasts/i })).toBeVisible()
    expect(screen.getByRole('tab', { name: /concerts/i })).toBeVisible()
    expect(screen.getByRole('tab', { name: /ebooks/i })).toBeVisible()
  })

  it('marks music as selected by default', () => {
    renderSidebar()
    expect(screen.getByRole('tab', { name: /music/i })).toHaveAttribute('aria-selected', 'true')
  })

  it('renders section headers from active schema', () => {
    renderSidebar()
    expect(screen.getByText('Quick Jump')).toBeVisible()
    expect(screen.getByText('Library')).toBeVisible()
  })

  it('renders nav items as links', () => {
    renderSidebar()
    // Music schema has an "Albums" page_link with route /music/albums
    const albumsLink = screen.getByRole('link', { name: /albums/i })
    expect(albumsLink).toBeVisible()
    expect(albumsLink).toHaveAttribute('href', '/music/albums')
  })

  it('renders pinned footer with global links', () => {
    renderSidebar()
    expect(screen.getByText('Settings')).toBeVisible()
  })

  it('switches media type on tab click', async () => {
    const user = userEvent.setup()
    renderSidebar()
    await user.click(screen.getByRole('tab', { name: /movies/i }))
    expect(screen.getByRole('tab', { name: /movies/i })).toHaveAttribute('aria-selected', 'true')
    // Sidebar should now show movies sections
    expect(screen.getByText('Directors')).toBeVisible()
  })
})
```

**GREEN:** Implement the 5 components:

1. **`Sidebar.tsx`** (rewrite): Assembles Logo → Search → SidebarSelector → SidebarContent → SidebarPinnedFooter
2. **`SidebarSelector.tsx`**: `role="tablist"` with `role="tab"` children, reads `useMediaModeStore` and `useSidebarStore` to display and switch active media type
3. **`SidebarContent.tsx`**: Reads `getActiveSchema()` from sidebar store, renders `SidebarSection` for each section
4. **`SidebarSection.tsx`**: Renders section header (`text-[11px] uppercase tracking-wider font-medium text-muted-foreground`) + nav items as `<NavLink>` or `<button>` depending on type
5. **`SidebarPinnedFooter.tsx`**: Static footer with Settings, Equalizer, Search links separated by `·`

Component tree:
```
<aside> (w-56, flex-col, bg-sidebar)
  <Logo> (h-12, px-4)
  <Search form> (px-3, py-2)
  <SidebarSelector /> (role=tablist, px-3)
  <SidebarContent /> (flex-1, overflow-y-auto, px-2)
    <SidebarSection /> × N
  <SidebarPinnedFooter /> (shrink-0, border-t)
</aside>
```

**REFACTOR:** Clean up dead code from old flat-list rendering. Ensure `handleItemClick` for `panel_action` items still works via the section renderer.

**Verification:** `npx vitest run tests/features/layout/components/sidebar.test.tsx`

---

### Unit 5: Route Migration to Media-Prefixed Structure

**Goal:** Restructure routes from flat paths (`/albums`) to media-prefixed paths (`/music/albums`). Add media type Home pages. Update `HomePage` redirect.

**Files:**
- `src/features/layout/routes.tsx` (modify)
- `src/features/catalog/pages/HomePage.tsx` (modify — becomes media-aware redirect)
- `tests/features/layout/routes.test.tsx` (new)

**Dependencies:** Unit 2 (schemas define route structure).

**RED:**
```typescript
describe('route structure', () => {
  it('/ redirects to /music by default', () => {
    // Test Navigate redirect
  })

  it('music routes are nested under /music', () => {
    // Verify /music, /music/albums, /music/artists, /music/songs, /music/genres, /music/browse, /music/playlists, /music/radio exist
  })

  it('global routes remain at root level', () => {
    // /search, /settings, /equalizer stay global
  })

  it('old routes redirect to new media-prefixed routes', () => {
    // /albums → /music/albums, /artists → /music/artists, etc.
  })
})
```

**GREEN:** Route changes:
```
/ → <Navigate to="/music" replace>
/music → <MusicHomePage />  (existing HomePage, renamed)
/music/albums → <AlbumsPage />
/music/albums/:publicId → <AlbumDetailPage />
/music/artists → <ArtistsPage />
/music/artists/:publicId → <ArtistDetailPage />
/music/songs → <SongsPage />
/music/genres → <GenresPage />
/music/browse → <CatalogShell />
/music/playlists → <PlaylistsPage />
/music/radio → <RadioPage />
/movies → <MoviesHomePage />  (placeholder)
/movies/browse → <MovieBrowserPage />  (placeholder)
/movies/:publicId → <MovieDetailPage />  (placeholder)
/tv → <TVHomePage />  (placeholder)
/podcasts → <PodcastsHomePage />  (placeholder)
/concerts → <ConcertsHomePage />  (placeholder)
/ebooks → <EbooksHomePage />  (placeholder)
/search → <SearchPage />
/equalizer → <EqualizerPage />
/settings → <SettingsPage />
```

Add redirect routes for backward compat:
```
/albums → <Navigate to="/music/albums" replace>
/artists → <Navigate to="/music/artists" replace>
/songs → <Navigate to="/music/songs" replace>
/genres → <Navigate to="/music/genres" replace>
/browse → <Navigate to="/music/browse" replace>
/playlists → <Navigate to="/music/playlists" replace>
/radio → <Navigate to="/music/radio" replace>
```

**REFACTOR:** Remove old flat routes once all internal links are updated. Ensure `NavLink` active states work correctly with nested routes.

**Source-driven check:** Verify React Router v6 nested route patterns against official docs before implementing.

**Verification:** `npx vitest run tests/features/layout/routes.test.tsx`

---

### Unit 6: Media Type Switch Navigation Integration

**Goal:** When the user switches media types in the selector, navigate to the new media type's Home page. Sync sidebar and content area.

**Files:**
- `src/features/layout/components/SidebarSelector.tsx` (modify — add navigation)
- `src/features/layout/hooks/use-media-mode.ts` (new)
- `tests/features/layout/hooks/use-media-mode.test.ts` (new)

**Dependencies:** Unit 1 (media mode store), Unit 4 (selector component), Unit 5 (routes).

**RED:**
```typescript
describe('useMediaMode', () => {
  it('switching media type updates activeMedia in store', () => {
    const { result } = renderHook(() => useMediaMode(), { wrapper: BrowserRouter })
    act(() => result.current.switchMedia('movies'))
    expect(useMediaModeStore.getState().activeMedia).toBe('movies')
  })

  it('switching media type navigates to media home', () => {
    // Verify navigation to /movies when switching to movies
  })

  it('switching to same media type does not navigate', () => {
    // Already on movies, click movies selector → no navigation
  })

  it('keyboard shortcut Cmd+1 switches to music', () => {
    // Test keyboard shortcut registration
  })
})
```

**GREEN:**
- `useMediaMode` hook encapsulates: `setActiveMedia(media)` → `navigate(`/${media}`)` → update sidebar store
- Skip navigation if already on a route under the target media prefix
- Register keyboard shortcuts `Cmd+1` through `Cmd+6` via `useKeyboardShortcuts` or a dedicated `useMediaShortcuts` hook

**REFACTOR:** Ensure the hook is used consistently from both `SidebarSelector` clicks and keyboard shortcuts.

**Verification:** `npx vitest run tests/features/layout/hooks/use-media-mode.test.tsx`

---

### Unit 7: Sidebar Config Hook Update

**Goal:** Update `use-sidebar-config.ts` to fetch per-media-type schemas from the API, falling back to static defaults. Update the SidebarEditor to work with sectioned schemas.

**Files:**
- `src/features/layout/hooks/use-sidebar-config.ts` (modify)
- `src/features/layout/components/SidebarEditor.tsx` (modify)
- `tests/features/layout/hooks/use-sidebar-config.test.ts` (new)

**Dependencies:** Unit 2 (schemas), Unit 3 (store extension).

**RED:**
```typescript
describe('use-sidebar-config (extended)', () => {
  it('fetches schema for active media type from API', async () => {
    // Mock axios to return custom schema for /api/user/sidebar-config/music
    // Verify store is updated
  })

  it('falls back to static default schema on API error', async () => {
    // Mock axios to throw
    // Verify store has static ALL_SCHEMAS.music
  })

  it('re-fetches when activeMedia changes', async () => {
    // Switch activeMedia to movies
    // Verify fetch for /api/user/sidebar-config/movies
  })
})
```

**GREEN:**
- `useSidebarConfig` fetches `GET /api/user/sidebar-config/:mediaType`
- Falls back to `ALL_SCHEMAS[activeMedia]` on error
- Calls `setSchema(activeMedia, data)` on success
- Re-fetches when `activeMedia` changes (use `activeMedia` in useEffect deps)

**SidebarEditor changes:**
- Show sections for the active media type's schema
- Allow reordering items within sections
- Allow hiding/showing individual items (existing feature)
- Save via `PUT /api/user/sidebar-config/:mediaType`

**REFACTOR:** Simplify the hook. The old flat `items[]` path can remain for backward compat.

**Verification:** `npx vitest run tests/features/layout/hooks/use-sidebar-config.test.tsx`

---

### Unit 8: Recent Section Component (Placeholder)

**Goal:** Add the Recent section to the sidebar with placeholder data. The full implementation (with API-backed thumbnails) is deferred to a future phase when the backend `/api/user/recent` endpoint is ready.

**Files:**
- `src/features/layout/components/SidebarRecentItems.tsx` (new)
- `tests/features/layout/components/sidebar-recent-items.test.tsx` (new)

**Dependencies:** Unit 4 (SidebarContent renders this section).

**RED:**
```typescript
describe('SidebarRecentItems', () => {
  it('renders empty state when no recent items', () => {
    render(<SidebarRecentItems items={[]} />)
    expect(screen.getByText(/nothing played yet/i)).toBeVisible()
  })

  it('renders up to 4 recent items with thumbnails', () => {
    const items = [
      { id: '1', title: 'OK Computer', subtitle: 'Radiohead', timestamp: '2h ago', thumbnailUrl: '/cover/1.jpg' },
      { id: '2', title: 'Blade Runner 2049', subtitle: 'Denis Villeneuve', timestamp: 'yesterday', thumbnailUrl: '/cover/2.jpg' },
    ]
    render(<SidebarRecentItems items={items} />)
    expect(screen.getByText('OK Computer')).toBeVisible()
    expect(screen.getByText('Radiohead')).toBeVisible()
    expect(screen.getByText('2h ago')).toBeVisible()
  })

  it('each recent item has accessible label', () => {
    const items = [
      { id: '1', title: 'OK Computer', subtitle: 'Radiohead', timestamp: '2h ago', thumbnailUrl: '/cover/1.jpg' },
    ]
    render(<SidebarRecentItems items={items} />)
    expect(screen.getByLabelText('OK Computer by Radiohead, played 2h ago')).toBeVisible()
  })
})
```

**GREEN:**
- Component accepts `items: RecentItem[]` (or empty array)
- Each item: 32×32 thumbnail + two-line text + timestamp
- Empty state: "Nothing played yet" in muted text
- Data source is props — the parent (SidebarContent) will fetch from API in future phase
- For now, pass `items={[]}` to show the empty state

**REFACTOR:** Ensure thumbnail loading is lazy (below the fold). Use `loading="lazy"` on images.

**Verification:** `npx vitest run tests/features/layout/components/sidebar-recent-items.test.tsx`

---

### Unit 9: Search Scoping Integration

**Goal:** Update the search input and SearchPage to respect active media mode. Search defaults to the active media type's scope; a toggle switches to global.

**Files:**
- `src/features/layout/components/Sidebar.tsx` (modify — search form passes scope)
- `src/features/catalog/pages/SearchPage.tsx` (modify — reads scope from URL + media mode)
- `tests/features/layout/components/sidebar-search.test.tsx` (new)

**Dependencies:** Unit 1 (media mode store), Unit 4 (sidebar rewrite), Unit 5 (routes).

**RED:**
```typescript
describe('sidebar search scoping', () => {
  it('search navigates with active media type scope', async () => {
    const user = userEvent.setup()
    useMediaModeStore.getState().setActiveMedia('movies')
    renderSidebar()
    const input = screen.getByPlaceholderText('Search...')
    await user.type(input, 'blade{Enter}')
    // Should navigate to /search?q=blade&scope=movies
  })

  it('search scope defaults to active media type', () => {
    useMediaModeStore.getState().setActiveMedia('music')
    // Verify scope parameter
  })
})
```

**GREEN:**
- Search form reads `activeMedia` from `useMediaModeStore`
- Navigates to `/search?q=${query}&scope=${activeMedia}`
- SearchPage reads `scope` from URL params
- Toggle button on SearchPage switches between `scope=movies` and `scope=all`
- Toggle state does not persist across searches

**REFACTOR:** Minimal changes to existing SearchPage. The scope toggle is a simple URL param swap.

**Verification:** `npx vitest run tests/features/layout/components/sidebar-search.test.tsx`

---

### Unit 10: Keyboard Shortcuts for Media Switching

**Goal:** Register `Cmd+1` through `Cmd+6` for media type switching, `Cmd+L` for sidebar search focus, `Cmd+Shift+F` for search scope toggle.

**Files:**
- `src/shared/hooks/use-keyboard-shortcuts.ts` (modify — add media shortcuts)
- `tests/shared/hooks/use-keyboard-shortcuts.test.ts` (extend)

**Dependencies:** Unit 1 (media mode store), Unit 6 (useMediaMode hook).

**RED:**
```typescript
describe('media keyboard shortcuts', () => {
  it('Cmd+1 switches to music', async () => {
    // Fire keyboard event, verify store update and navigation
  })

  it('Cmd+2 switches to movies', async () => {
    // ...
  })

  it('Cmd+L focuses sidebar search input', async () => {
    // ...
  })
})
```

**GREEN:**
- Extend existing `useKeyboardShortcuts` map with `onSwitchMedia` callback
- Or create a dedicated `useMediaShortcuts` hook that registers in `AppShell`
- `Cmd+1` through `Cmd+6` → call `switchMedia(MEDIA_TYPES[index - 1])`
- `Cmd+L` → focus the search input element

**REFACTOR:** Keep shortcut registration centralized. Avoid duplicating shortcut logic.

**Verification:** `npx vitest run tests/shared/hooks/use-keyboard-shortcuts.test.ts`

---

### Unit 11: E2E Sidebar Tests

**Goal:** Update and extend Playwright E2E tests for the new sidebar behavior.

**Files:**
- `tests/e2e/layout/layout.spec.ts` (modify)
- `tests/e2e/layout/sidebar-navigation.spec.ts` (new)

**Dependencies:** All previous units complete.

**RED:** Write E2E test scenarios:
1. Sidebar renders with all 6 media type tabs
2. Clicking a media type tab switches sidebar sections
3. Clicking a media type tab navigates to media home page
4. Nav items navigate to correct routes
5. Search submits with correct scope parameter
6. Old routes redirect to new media-prefixed routes

**GREEN:** Implement E2E tests matching the above scenarios.

**Verification:** `npx playwright test tests/e2e/layout/sidebar-navigation.spec.ts`

---

## Execution Order & Parallelization

```
Unit 1: Media Mode Store          ─┐
Unit 2: Sidebar Schema Definitions ─┤  (parallel — no dependencies)
                                    ├─→ Unit 3: Sidebar Store Extension ─→ Unit 4: Sidebar Rewrite
                                    │                                        │
                                    │                                        ├─→ Unit 5: Route Migration
                                    │                                        ├─→ Unit 6: Navigation Integration
                                    │                                        ├─→ Unit 8: Recent Section
                                    │                                        └─→ Unit 9: Search Scoping
                                    │
                                    └─→ Unit 7: Config Hook Update (after 2, 3)
                                         └─→ Unit 10: Keyboard Shortcuts (after 1, 6)
                                              └─→ Unit 11: E2E Tests (after all)
```

**Parallel group 1:** Units 1, 2 (independent)
**Parallel group 2:** Unit 3 (depends on 1, 2)
**Sequential:** Unit 4 (depends on 1, 2, 3)
**Parallel group 3:** Units 5, 6, 8, 9 (depend on 4)
**Sequential:** Unit 7 (after 2, 3), Unit 10 (after 1, 6)
**Final:** Unit 11 (after all)

---

## Out of Scope (Deferred)

1. **Backend API for `/api/user/sidebar-config/:mediaType`** — frontend uses static defaults for now; API will be built in a separate backend phase
2. **Backend API for `/api/user/recent`** — Recent section shows empty state until API is ready
3. **Media type Home page content** (`MoviesHomePage`, `TVHomePage`, etc.) — placeholder pages only
4. **Thumbnail loading via `useImageBlob`** — deferred until Recent section has real data
5. **SidebarEditor section-level customization** — users can hide items but not sections yet
6. **Non-music media type detail pages** (movie detail, show detail, etc.) — placeholder routes only
7. **i18n for new sidebar labels** — English only in this phase

---

## Risk Assessment

| Risk | Mitigation |
|------|-----------|
| Route migration breaks existing bookmarks/links | Add redirect routes for all old paths (`/albums` → `/music/albums`) |
| SidebarEditor breaks with new schema model | Keep backward compat in store, extend editor incrementally |
| Media type selector takes too much vertical space | Compact design (28px height, `text-xs`). Monitor on small screens. |
| Keyboard shortcuts conflict with existing | `Cmd+1`-`Cmd+6` not used currently. Verified against existing shortcut map. |
| Zustand persist migration (new store keys) | Fresh key `baander-media-mode`, no migration needed |

---

## Source Verification Notes

- **React Router v6 nested routes:** Verify `children` route config pattern and `<Outlet>` behavior with nested paths
- **Zustand persist middleware:** Verify API matches existing store patterns (`context-panel-store.ts`)
- **`useImageBlob` hook:** Verify signature when implementing Recent thumbnails (future phase)
- **Tailwind classes:** Verify `bg-accent`, `text-muted-foreground`, etc. match the project's Tailwind config
