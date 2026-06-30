---
name: design-language
description: "Baander UI design language. Binding rules for all frontend implementation."
---

# Design Language

**Audience:** Anyone writing frontend code for Baander.
**Status:** Authoritative. When in doubt, this document wins.

---

## Philosophy

Content is the interface. The UI gets out of the way. Progressive disclosure: simple by default, powerful on demand. Interactions are precise and immediate. Motion communicates spatial continuity, never decoration.

Reference era: Apple products 2010-2020. Clarity, restraint, spatial hierarchy. Not Apple Music.

---

## Layout

### App Shell
Three-panel structure: **Sidebar** (56px / 224px) | **Main Content** (flex) | **Context Panel** (0–360px).

The main content area adapts to context — it is not a fixed page. It reshapes based on what the user is doing:
- **Browse mode:** Shows the active view (grid, list, columns, etc.)
- **Preview (click):** Context panel expands with item preview. Browse view stays visible.
- **Detail (Enter):** Full detail page replaces browse view.
- **Back:** Returns to previous browse view with scroll position and selection preserved.

### Surfaces
- Background: `#000000` (`var(--color-background)`)
- Card: `#0a0a0b` (`var(--color-card)`)
- Sidebar: `#080809` (`var(--color-sidebar)`)
- Borders: `#1a1a1f` (`var(--color-border)`) — used sparingly, not between every element
- No gradients. No drop shadows except on context menus and overlays (subtle, `0 4px 24px rgba(0,0,0,0.4)`).

### Border radius
- Cards: `rounded-lg` (12px). Do not use `rounded-xl` (16px) for card surfaces.
- Buttons/inputs: `rounded-lg` (8px) — set by shadcn primitives.
- Overlays (dialogs, popovers): `rounded-xl` (16px) — set by shadcn primitives.
- Context menus: `rounded-md` (6px) — set by shadcn primitives.
- Do not invent new radius values.

### Spacing
- Page padding: `24px` (`px-6`) — applied by each page, not the AppShell.
- Section gap: `32px` (`gap-8`)
- Item gap: `16px` (`gap-4`) for grids, `2px` (`gap-0.5`) for lists
- Compact mode lists: `4px` vertical padding (`py-1`)
- Header height: `48px` (`h-12`)
- Context panel width: `360px` max
- Do not invent gap values. Use `gap-4` (16px), `gap-6` (24px), or `gap-8` (32px).

---

## Typography

- **Font:** Inter (`var(--font-sans)`)
- **Tracking:** `-0.01em` (`tracking-tight` on headings)
- **Body:** `14px` / `0.875rem` (`text-sm`)
- **Labels:** `11px` / `0.6875rem` (`text-[11px]`), uppercase, `tracking-wider`, `font-medium`
- **Muted text:** `var(--color-muted-foreground)` (`#8b8d97`)
- **Headings:** semibold (`font-semibold`), never bold
- **Monospace:** JetBrains Mono for metadata values (bitrate, format, durations, IDs)

---

## Color

### Static palette
Use CSS variables from `app.css`. Never hardcode hex values.

| Token | Use |
|-------|-----|
| `--color-foreground` | Primary text |
| `--color-muted-foreground` | Secondary text, labels |
| `--color-primary` | Interactive accent (buttons, links, active states) |
| `--color-secondary` | Subtle surfaces |
| `--color-accent` | Hover/active backgrounds |
| `--color-destructive` | Errors, delete actions |

### Dynamic accent (blurhash-derived)
On album and artist detail pages, extract the dominant color from the item's `coverImage.blurhash`:
1. Decode blurhash client-side (no image fetch)
2. Extract the most saturated pixel from the 4×3 grid
3. Set as CSS variable `--accent-derived` on the page container
4. Apply as subtle tint: 2px header underline, playing indicator, active selection glow
5. **Fallback:** Use `--color-primary` when blurhash is absent, or when extracted color has saturation < 10% or lightness < 10% or > 90%

### Foreground-on-accent contrast
When using accent-derived color for text or icons, ensure WCAG AA contrast against the background. If the derived color fails contrast, lighten or darken it by adjusting lightness to 60%.

---

## Interaction Model

### Primary actions
| Input | Action |
|-------|--------|
| **Click** | Select — highlight row/card, show preview in context panel |
| **Enter / Space** | Commit — play item or open full detail page |
| **Right-click** | Native context menu (full action vocabulary) |
| **Drag** | Shortcut for common actions (add to playlist, reorder queue) |
| **Keyboard** | Fully customizable, discoverable defaults |

### Selection
Selection is a first-class concept. The selected item is:
- What the context panel previews
- What keyboard shortcuts act on
- What the context menu targets
- Visually distinct (accent-colored left border or background tint, NOT full-row highlight)

### Context menus
Native right-click only. No "..." buttons. No visible affordance for menus.

Menu structure per item type is defined in `docs/brainstorms/2026-05-11-catalog-redesign-requirements.md` → Context Menus section.

### Keyboard shortcuts
Fully customizable (VS Code-style). Default bindings documented in the same brainstorm doc.
Keyboard shortcut help overlay: press `?`.

---

## Motion

Motion communicates *where things went*, not *how fancy the UI is*.

| Transition | Duration | Easing |
|-----------|----------|--------|
| View switch (Grid↔List↔Column) | 80ms | ease-out |
| Enter detail page | 120ms | ease-out |
| Back from detail | 100ms | ease-out |
| Column browser column change | 80ms | ease-out |
| Selection highlight | 0ms | none (instant) |
| Context panel open/close | 120ms | ease-out |
| Hover state | 60ms | ease-out |
| Context menu | Native OS | — |

**Rules:**
- Easing: `ease-out` only. Never `ease-in-out`, `bounce`, `spring`, or custom bezier curves.
- If you can name the animation, it's too slow.
- No bounces. No overshoots. No elastic.
- Selection must feel instant — zero delay between input and visual response.
- CSS: `transition-[opacity,transform] duration-[80ms] ease-out`

---

## Components

### Primitives
Use shadcn/ui components from `@/shared/components/ui/`:
- `Button` for all interactive buttons. Never raw `<button>`.
- `ContextMenu` for right-click menus.
- `Dialog` for modals (playlist picker, metadata editor).
- `Skeleton` for loading placeholders.
- `Tooltip` for icon-only button labels.
- `ScrollArea` for custom scroll containers.
- `Slider` for volume/seek controls.
- `DropdownMenu` for select-like menus (column config, view mode).

### Lists and grids
- Virtualize any collection > 50 items with `@tanstack/react-virtual`.
- Row height: `32px` for compact lists, `48px` for standard lists.
- Grid item: aspect-ratio `1/1` for album art cards.
- Hover on list row: reveal inline action (play button replacing index number).
- Hover on grid card: no visible change — content is the affordance.

### Icons in menu lists
Icons in navigation menus, sidebar lists, and dropdown menus must be **extremely sparing**. Apply icons only to:

1. **Destructive actions** (delete, remove, disconnect) — visual warning
2. **The 3 most frequently used actions** in that menu — scannability anchors

When every item has an icon, none of them stand out. A sea of icons is visual noise. **Default: no icon. Add one only when you can justify why that specific item needs to be noticed faster than its neighbors.**

**Current state:** Sidebar navigation uses text-only items (no icons). This is correct. Do not add icons to sidebar nav items without explicit justification.

### Images (covers)
- Use `useImageBlob` hook (shared) for authenticated cover fetching.
- Blurhash placeholder while loading: decode blurhash → dominant color → set as card background.
- Never use raw `<img src>` for covers that require authentication.
- Always set `loading="lazy"` on cover images.
- Revoke blob URLs on unmount (handled by `useImageBlob`).

### Loading states
- Skeleton placeholders that match the layout of the loaded content.
- Skeleton color: `var(--color-muted)` with `animate-pulse`.
- Never show empty state while loading.

### Error states
- Error message in `var(--color-destructive)`.
- Retry button (shadcn `Button variant="ghost"`).
- Never silently show empty state on error.

### Empty states
- Short message: one line of text in `var(--color-muted-foreground)`.
- No illustrations. No emoji. Just text.
- If action exists to fix the empty state, show it (e.g., "Add your music library").

---

## View Modes

Six view modes, all sharing the same interaction model, context menus, and selection state:

| View | Purpose | Primary content |
|------|---------|----------------|
| Grid | Browse by cover art | Album cards in responsive columns |
| List | Detailed browsing | Sortable table with configurable columns |
| Column Browser | Power-user navigation | Genre → Artist → Album → Songs columns |
| Timeline | Chronological exploration | Albums by year, grouped by decade |
| Activity | Self-awareness | Listening history grouped by time period |
| Discover | Serendipity | Recommendation clusters from API |

View mode preference persists across sessions (Zustand + localStorage).

---

## Detail Pages

### Album Detail
- **Compact fixed header** (one line): 32×32 cover thumbnail, title, artist, year, Play button, Shuffle button, Back button. Accent underline from blurhash.
- **Full-height track list**: The star. Virtualized. #, title, artist (compilations), duration. Playing track shows accent equalizer icon. Hover shows play button.
- **Collapsible metadata**: One click expands. Format, bitrate, sample rate, bit depth, file size, replay gain, MusicBrainz IDs, label, catalog number, etc.

### Artist Detail
- **Compact header**: Avatar (first letter or photo), name, album count, Play/Shuffle buttons.
- **Discography**: Albums in grid or list, respecting user's view mode preference.
- **No `<a href>`** — always use React Router `<Link>` or `useNavigate()`.

---

## Anti-patterns (do not do these)

| Anti-pattern | Instead |
|-------------|---------|
| `as any` type casts | Define proper interfaces. Use generated types. |
| `fetch()` directly | Use shared `AXIOS_INSTANCE` from `@/shared/api-client/axios-instance` |
| Raw `<button>` elements | Use shadcn `Button` component |
| `../../..` cross-feature imports | Use `@/` path aliases |
| `useState` for derived state | Use `useMemo` or derive in the render |
| `getState()` calls in render body | Wrap in `useEffect` |
| `setState` inside `useMemo` | Extract to `useEffect` |
| Two-effect blob URL pattern | Use `useImageBlob` hook (single effect) |
| `any` type | Never. Define the type. |
| Gradients on surfaces | Flat colors only |
| Drop shadows on cards | No shadows except overlays |
| Bounce/spring animations | ease-out only, <120ms |
| "..." menu buttons on items | Native right-click only |
| Full-page reloads for navigation | React Router `<Link>` / `useNavigate()` |
| Client-side filtering on paginated data | Server-side filtering via API params |
| Inline component definitions in pages | Extract to `components/` |
| Inline "Add to playlist"/"Add to queue" buttons on list rows | Play button replacing index number on hover; other actions in context menu |
