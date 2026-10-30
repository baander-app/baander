---
name: design-language
description: "Baander UI design language. Binding rules for all frontend implementation."
---

# Design Language

**Audience:** Anyone writing frontend code for Baander.
**Status:** Authoritative. When in doubt, this document wins.

The product requirements below govern the web implementation. Implement them
with styled-components, shared UI primitives, and theme tokens as described in
`.agents/rules/frontend.md`; existing code deviations are not design exceptions.

---

## Philosophy

Content is the interface. The UI gets out of the way. Progressive disclosure: simple by default, powerful on demand. Interactions are precise and immediate. Motion communicates spatial continuity, never decoration.

The reference era is Apple's 2008–2014 Human Interface Guidelines: clear hierarchy,
restrained controls, and precise interaction, adapted to Baander's responsive web
interface and current accessibility requirements. The supplied
[2018 macOS HIG archive](https://martin-juul.github.io/aslice/library/apple-macos-hig-2018/index.html#470c81114d39cd61d4ec2d2af0c4aa565d70ee758c5b4b6dff3be78c84700e2c.html)
is supplementary Mojave-era material, not a 2014 source. Apple Music is not the
visual reference.

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
- Background: `var(--color-background)`
- Card: `var(--color-card)`
- Sidebar: `var(--color-sidebar)`
- Borders: `var(--color-border)` — used sparingly, not between every element
- Surface colors follow the selected mood; dark-mode values are not universal defaults.
- No gradients. No drop shadows except on context menus and overlays (subtle, `0 4px 24px rgba(0,0,0,0.4)`).

### Border radius
- Cards: 12px radius. Do not use 16px for card surfaces.
- Buttons/inputs: 8px radius — set by shared primitives.
- Overlays (dialogs, popovers): 16px radius — set by shared primitives.
- Context menus: 6px radius — set by shared primitives.
- Do not invent new radius values.

### Spacing
- Page padding: `24px` — applied by each page, not the AppShell.
- Section gap: `32px`
- Item gap: `16px` for grids, `2px` for lists
- Compact mode lists: `4px` vertical padding
- Header height: `48px`
- Context panel width: `360px` max
- Do not invent gap values. Use 16px, 24px, or 32px.

---

## Typography

- **Font:** Inter (`var(--font-sans)`)
- **Tracking:** `-0.01em` on headings
- **Body:** `14px` / `0.875rem`
- **Labels:** `11px` / `0.6875rem`, uppercase, wider tracking, medium weight
- **Muted text:** `var(--color-muted-foreground)`
- **Headings:** semibold, never bold
- **Monospace:** JetBrains Mono for metadata values (bitrate, format, durations, IDs)

---

## Color

### Token authority

Web semantic colors are defined in
`ui/design-tokens/tokens/web/semantic-colors.json`, together with the shared inputs
under `ui/design-tokens/`: `tokens/base/`, `tokens/mood/moods.json`, and
`tokens/accent/palette.json`.
Run `yarn --cwd ui/design-tokens build` after changing those inputs. The generator
resolves one table of 32 mood/accent combinations and emits both
`ui/design-tokens/build/web/theme.css` and `theme.ts`. Do not edit generated files.
The existing native theme outputs are unchanged.

`ui/web/src/index.css` imports the generated CSS; the root's `data-theme` and
`data-accent` select its colors. `resolveTheme` looks up the same generated table
for styled-components. `GlobalStyles` supplies global element rules, not another
set of theme-variable definitions. Use theme properties or their CSS variables;
never hardcode palette values in components.

| Token | Use |
|-------|-----|
| `--color-foreground` | Primary text |
| `--color-muted-foreground` | Secondary text, labels |
| `--color-primary` / `--color-primary-foreground` | Filled actions and their content |
| `--color-primary-hover` / `--color-primary-pressed` | Filled-action interaction states |
| `--color-accent-text` | Links, active text, and icons on ordinary surfaces |
| `--color-ring` | Focus indicators |
| `--color-input-boundary` | Visible input edges |
| `--color-secondary` / `--color-muted` | Subtle surfaces |
| `--color-highlight` | Hover and selection backgrounds |
| `--color-destructive` / `--color-destructive-foreground` | Destructive actions and errors |
| `--color-success` / `--color-success-foreground` | Success status and paired content |
| `--color-warning` / `--color-warning-foreground` | Warning status and paired content |
| `--color-info` / `--color-info-foreground` | Informational status and paired content |

Filled actions use their paired foreground at rest, hover, and press. Text on an
ordinary surface uses `accentText` or a suitable semantic text color; a fill's
foreground belongs on that fill. The former `accent` and `accentForeground`
aliases are removed. Status colors describe meaning independently of the user's
accent choice.

### Dynamic accent (blurhash-derived)
On album and artist detail pages, extract the dominant color from the item's `coverImage.blurhash`:
1. Decode blurhash client-side (no image fetch)
2. Extract the most saturated pixel from the 4×3 grid
3. Set `--accent-derived` on the page container for decorative color, with
   `--accent-derived-foreground` carrying its contrast-adjusted text color.
4. Apply the decorative color as a subtle tint or 2px header underline; use the
   foreground color for playing-state text and icons.
5. **Fallback:** When blurhash is absent, or the extracted color has saturation
   < 10% or lightness < 10% or > 90%, use `--color-primary` for decoration and
   `--color-accent-text` for text and icons.

### Foreground-on-accent contrast
Measure accent-derived text against the surfaces where it appears. Keep the
artwork color when it reaches 4.5:1; otherwise blend toward white or black until
it reaches that ratio on every supplied surface, falling back to the theme's
`accentText` if necessary. A fixed lightness percentage cannot establish contrast.
Theme generation likewise checks normal text at 4.5:1 and focus/input boundaries
at 3:1; action fills and their paired content must remain readable through hover
and press.

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

Menu structure per item type is defined in `docs/brainstorms/2026-05-14-catalog-redesign-requirements.md` → Context Menus section.

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
- CSS: `transition-property: opacity, transform; transition-duration: 80ms; transition-timing-function: ease-out`

---

## Components

### Primitives
Use shared UI components from `@/shared/components/ui/`; their styling uses
styled-components and their accessible interactions retain Radix primitives:
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
- Skeleton color: `var(--color-muted)` with the shared Skeleton's pulse animation.
- Never show empty state while loading.

### Error states
- Error message in `var(--color-destructive)`.
- Retry button (shared `Button variant="ghost"`).
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
| Backend JSON API calls made with `fetch()` | Use shared `AXIOS_INSTANCE` from `@/shared/api-client/axios-instance` or generated hooks |
| Raw `<button>` elements | Use shared `Button` component |
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

Native fetch exceptions are limited to service-worker request forwarding and
streaming interception, and binary/static asset loading such as WASM. They do
not relax origin, client, credential, redirect, or authentication requirements.
See `.agents/rules/frontend.md` for the implementation boundary.
