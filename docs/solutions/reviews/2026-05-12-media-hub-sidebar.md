# Code Review: Media Hub Sidebar

**Date:** 2026-05-12  
**Scope:** `ui/web/src/features/layout/` — 7 modified + 26 new files  
**Diff:** +309 / -323 tracked, ~500+ lines new  
**Tests:** 63/63 pass (11 files), 0 TypeScript errors  
**Pipeline:** `03-work` → `04-review`

---

## Summary

The media hub sidebar redesign transforms a flat 10-link sidebar into a two-zone layout with a media type selector (Music, Movies, TV, Podcasts, Concerts, Ebooks) and sectioned per-media navigation. The implementation spans stores, schemas, hooks, 5 new components, route migration, search scoping, and keyboard shortcuts.

Overall: **Solid implementation.** Architecture is clean, components are well-decomposed, schemas provide good extensibility. Found 9 issues — 3 bugs (2 high, 1 medium), 4 code quality, 2 trivial. **All fixed and verified.**

---

## Findings

### 🔴 #1 (Fixed) — Duplicate `activeMedia` state in two stores
- **Severity:** High (correctness)
- **Files:** `sidebar-store.ts`, `media-mode-store.ts`, `use-media-mode.ts`
- **Problem:** `activeMedia` stored in both `useMediaModeStore` (persisted) and `useSidebarStore` (not persisted). Every switch must update both — guaranteed desync surface area.
- **Fix:** Removed `activeMedia` from `sidebar-store`. `getActiveSchema()` now reads from `useMediaModeStore.getState().activeMedia`. Single source of truth.

### 🔴 #2 (Fixed) — Backward-compat redirects don't forward route params
- **Severity:** High (correctness)
- **File:** `routes.tsx`
- **Problem:** `<Navigate to="/music/albums/:publicId">` treats `:publicId` as a literal string. Bookmark to `/albums/abc123` redirects to literal `/music/albums/:publicId`.
- **Fix:** Introduced `ParamRedirect` component that reads `useParams()` and substitutes param values into target URL.

### 🟡 #3 (Fixed) — SearchPage scope toggle hardcoded to `'music'`
- **Severity:** Medium (correctness)
- **File:** `SearchPage.tsx`
- **Problem:** `toggleScope` always switches to `'music'` regardless of active media mode.
- **Fix:** Reads `activeMedia` from `useMediaModeStore` and uses it as the non-all scope.

### 🟡 #4 (Fixed) — SidebarContent selector returns new object each call
- **Severity:** Medium (performance)
- **File:** `SidebarContent.tsx`
- **Problem:** `useSidebarStore((s) => s.getActiveSchema())` calls a method that creates new references each time, causing unnecessary re-renders on any store change.
- **Fix:** Split into two selectors: `useMediaModeStore((s) => s.activeMedia)` + `useSidebarStore((s) => s.schemas[activeMedia])`. Stable references.

### 🟡 #5 (Fixed) — Keyboard shortcuts don't navigate to media home
- **Severity:** Low (UX)
- **File:** `use-media-shortcuts.ts`
- **Problem:** Shortcuts updated stores but didn't navigate. Per brainstorm: "Switching modes always takes you to that media type's Home page."
- **Fix:** Hook now uses `useNavigate`/`useLocation` from react-router-dom to navigate when not already on the target media route.

### 🟡 #6 (Fixed) — Dead `useSidebarStore` import in SidebarSelector
- **Severity:** Trivial (dead code)
- **File:** `SidebarSelector.tsx`
- **Problem:** `setActiveMediaStore` subscribed but never used — `switchMedia` already handles both stores.
- **Fix:** Removed dead import and subscription.

### 🟡 #7 (Fixed) — Unused `NavLink` import in Sidebar.tsx
- **Severity:** Trivial (dead import)
- **File:** `Sidebar.tsx`
- **Problem:** `NavLink` import no longer used after component decomposition.
- **Fix:** Removed import, changed logo link to plain `<a>`.

### 🟡 #8 (Fixed) — Dead `DEFAULT_ITEMS` in use-sidebar-config.ts
- **Severity:** Trivial (dead code)
- **File:** `use-sidebar-config.ts`
- **Problem:** `DEFAULT_ITEMS` array defined but never referenced — fallback uses `ALL_SCHEMAS`.
- **Fix:** Removed `DEFAULT_ITEMS` and unused destructured values (`items`, `error`, `setItems`).

### ℹ️ #9 (Noted) — `NavLink end` prop vestigial for media routes
- **Severity:** Info
- **File:** `SidebarSection.tsx`
- **Detail:** `end={route === '/'}` is always `false` since no media route equals `'/'`. Not a bug — current behavior is correct (parent routes highlight when children are active). Just flagging for awareness.

---

## Architecture Assessment

**Strengths:**
- Clean decomposition: 5 focused components (Selector, Content, Section, RecentItems, PinnedFooter)
- Schema-driven sidebar: 6 media schemas + types provide good extensibility
- Proper Zustand persist middleware for media mode preference
- Keyboard shortcuts with input-focus guard
- Backward-compat redirects preserve old bookmarks

**Design patterns observed:**
- Single Responsibility: Each component handles one concern
- Schema pattern: Data-driven navigation instead of hardcoded JSX
- Store composition: `media-mode-store` (persisted preference) + `sidebar-store` (runtime schemas)

**Deferred items:**
- `SidebarEditor.tsx` needs update to work with sectioned schemas (Phase 2)
- E2E tests (Playwright) deferred — need running dev server
- API integration (`/api/user/sidebar-config/:mediaType`) not yet available — graceful fallback works

---

## Verification

| Check | Result |
|-------|--------|
| `npx vitest run tests/features/layout/` | ✅ 63/63 pass, 11 files |
| `npx tsc --noEmit` | ✅ 0 errors |
| Pre-existing failures | 9 in `settings/`, `player/`, `catalog/` — unrelated |

---

## Review Verdict: ✅ APPROVED

All bugs fixed, dead code removed, tests green. Ready for manual smoke test and optional E2E run.
