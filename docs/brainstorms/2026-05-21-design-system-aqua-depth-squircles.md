# Design System: Aqua Depth + Squircles

**Status:** Requirements
**Date:** 2026-05-21
**Context:** Update `ui/DESIGN.md` and component primitives to bring Aqua's deep 3D effect to Baander's dark-first UI

---

## Problem

Baander's current design direction follows Apple's 2010-2015 HIG: restrained, minimal shadows, flat surfaces. This lacks the spatial depth and tactile quality of classic Aqua — the layered 3D effect that made UI elements feel physically present and manipulable. Users perceive the interface as flat and less responsive than it could be.

The current border-radius implementation uses standard CSS curves, not Apple's signature squircle (superellipse) geometry. This is a visible deviation from the Apple design language being referenced.

---

## Vision

Bring Aqua's pronounced depth and layered 3D effect to Baander while keeping the cleaner 2015 HIG layout foundation. The interface should feel spatial and tactile — elevated elements float, shadows communicate hierarchy, and squircle geometry matches Apple's distinctive rounded rectangles.

**Reference era:** Apple 2015 HIG layout × Aqua depth philosophy

---

## Core Requirements

### 1. Squircle Geometry (Superellipse)

**Scope:** All interactive elements use SVG-based squircle masks, not CSS `border-radius`.

**Elements affected:**
- Buttons (all variants: default, outline, ghost, destructive, link)
- Cards and card-like surfaces
- Input fields and textareas
- Dialogs and modals
- Sheets and side panels
- Context menus and dropdowns
- Tabs, switches, and form controls

**Implementation approach:**
- SVG masks with superellipse path data
- Single reusable mask definition applied via CSS
- Mask scales to element size (no fixed dimensions)
- Fallback to CSS `border-radius` for environments without SVG mask support

**Do not:**
- Use CSS `border-radius` as primary implementation
- Create per-component SVGs (use shared mask definition)
- Approximate with percentage-based radius hacks

### 2. Elevation System (7 Levels)

Seven distinct elevation levels provide full hierarchy control. Shadows apply selectively to elevated elements only — not a shadow-on-everything approach.

| Level | Name | Use Case | Shadow Intensity |
|-------|------|----------|------------------|
| 0 | flat | Background, inline content | None |
| 1 | raised | Cards, list items | Subtle |
| 2 | lift | Buttons, inputs | Low-medium |
| 3 | float | Dropdowns, popovers | Medium |
| 4 | overlay | Context menus | Medium-high |
| 5 | modal | Dialogs, sheets | High |
| 6 | toplevel | Highest elevation (alerts) | Maximum |

**Rules:**
- Level 0 (flat): No shadows
- Levels 1-2: Single or dual-layer shadows
- Levels 3-6: Multi-layer shadows (3-4 layers)
- Inner shadows/glows on Levels 3+ for edge definition

### 3. Aqua-Style Multi-Layer Shadows

Each elevation level uses 3-4 layered shadows with ambient light contribution. This creates the characteristic Aqua depth — not a single blurred drop shadow.

**Shadow layer structure:**
1. **Ambient shadow** — soft, large spread, low opacity
2. **Directional shadow** — sharper, indicates light source
3. **Contact shadow** — tight to element base, anchors elevation
4. **Inner highlight (optional)** — top inner glow, Levels 3+

**Dark-background tuning:**
- Shadows on black use lighter opacity than light-mode equivalents
- Ambient shadow adds slight blue tint for depth perception
- No muddy blacks — shadows lift, not darken

**Clever CSS implementation (not just stacked box-shadow):**
- **CSS filters:** `drop-shadow()` for alpha-aware shadows, `blur()` layered elements for ambient glow
- **Pseudo-elements:** `::before`/`::after` as separate shadow layers for independent animation and blur radii
- **Perceived color:** Subtle hue shifts in shadow layers (blue/cool ambient) create depth perception without heavy opacity
- **Layered DOM elements:** When needed, nested spans with individual blur filters for true multi-layer depth
- **Backdrop filters:** `backdrop-filter: blur()` + `brightness()` for elevated glass surfaces

**Platform-specific implementations:**
- **Web (CSS):** Filters, pseudo-elements, box-shadow stacking
- **React Native:** Platform modules for shadow rendering (Android elevation API, iOS shadowPath)

Example approaches:
```css
/* Web: Clever layered approach using pseudo-elements */
.elevated-4 {
  position: relative;
}
.elevated-4::before {
  content: '';
  position: absolute;
  inset: -4px;
  filter: blur(12px);
  background: rgba(255, 255, 255, 0.03);
  z-index: -1;
}
.elevated-4::after {
  content: '';
  position: absolute;
  inset: 0;
  filter: blur(4px) drop-shadow(0 8px 16px rgba(0, 0, 0, 0.4));
  z-index: -1;
}
```

### 4. Selective Elevation

Shadows appear only on elements that are meaningfully elevated from the base plane:

**Gets shadows:**
- Floating elements: dropdowns, context menus, popovers, tooltips
- Elevated surfaces: dialogs, sheets, modals
- Lifted controls: buttons, inputs, cards (when styled as elevated)

**No shadows:**
- Background surfaces
- Inline content and list items (unless elevated variant)
- Borders-only separation

**Principle:** If an element doesn't float above the base plane, it doesn't cast a shadow.

### 5. Cross-Platform Support

The design system must work across web and React Native:

**Web (ui/web/):**
- CSS filters, pseudo-elements, box-shadow stacking
- SVG masks for squircle geometry
- Tailwind v4 `@theme` for elevation tokens

**React Native (future):**
- Platform-specific native modules for shadow rendering
- iOS: `shadowPath`, `shadowOpacity`, `shadowRadius` with custom squircle paths
- Android: `elevation` API with custom outlines for squircle shapes
- Shared elevation tokens map to platform APIs

**Shared tokens:**
- Elevation levels defined as platform-agnostic values
- Web: CSS custom properties
- React Native: StyleSheet constants or platform module lookups

### 6. Component Updates

All shadcn-ui components in `ui/web/src/shared/components/ui/` must be updated:

**Priority 1 (Core primitives):**
- `button.tsx` — Apply squircle mask, elevation-based shadows
- `input.tsx` — Squircle mask, Level 2 shadow on focus
- `card.tsx` — Squircle mask, Level 1 shadow

**Priority 2 (Overlays):**
- `dialog.tsx` — Squircle mask, Level 5 shadow
- `dropdown-menu.tsx` — Squircle mask, Level 4 shadow
- `context-menu.tsx` — Squircle mask, Level 4 shadow
- `sheet.tsx` — Squircle mask, Level 5 shadow

**Priority 3 (Controls):**
- `select.tsx` — Squircle mask, Level 2 shadow
- `slider.tsx` — Squircle mask on thumb
- `switch.tsx` — Squircle mask on track/thumb

Each component:
- Applies SVG mask via CSS class (web)
- Uses elevation token for shadow value
- Maintains existing variants and sizes
- Platform-aware rendering (web today, React Native future)

---

## DESIGN.md Changes

Update `ui/DESIGN.md` to reflect new shadow philosophy:

**Remove:**
> "No drop shadows except on context menus and overlays (subtle, `0 4px 24px rgba(0,0,0,0.4)`)."

**Replace with:**
```
### Shadows and Elevation

Shadows communicate spatial hierarchy. Use the 7-level elevation scale:
- Level 0 (flat): Background, inline content — no shadow
- Level 1-2 (raised/lift): Cards, buttons, inputs — subtle depth
- Level 3-4 (float/overlay): Dropdowns, popovers, menus — medium depth
- Level 5-6 (modal/toplevel): Dialogs, sheets — maximum depth

Each level uses multi-layer Aqua-style shadows (ambient + directional + contact).
Shadows apply selectively to elevated elements only. Not every element casts a shadow.

Use elevation tokens: `--elevation-{0-6}`. Do not invent shadow values.
```

**Add squircle section:**
```
### Geometry (Squircles)

All interactive elements use superellipse (squircle) geometry via SVG masks.
Do not use CSS `border-radius` as primary implementation.

Squircle mask is shared across all components via CSS class.
```

---

## Non-Goals

- Light mode support (dark-first only)
- Animation system for shadow transitions (beyond CSS transitions)
- Design documentation site (DESIGN.md is authoritative)
- Per-component custom squircle shapes (single shared mask)
- React Native implementation now (design system should support it, but implementation is future work)

---

## Dependencies

- Existing `ui/DESIGN.md` as foundation
- Current shadcn/ui component set (web)
- Tailwind v4 `@theme` for shadow token definitions (web)
- React Native for future mobile implementation

---

## Risks and Unknowns

| Risk | Mitigation |
|------|------------|
| SVG mask performance at scale | Test with 100+ components, benchmark render time |
| Shadow layers causing visual clutter on dense screens | Test with real data, tune opacity per level |
| Squircle mask clipping content (text, icons) | Ensure mask applies to container, padding reserves space |
| Multi-layer shadows not visible on pure black backgrounds | Use ambient light tint, test on #000000 |
| SVG mask support in Electron/renderer context | Verify early, have CSS fallback ready |
| CSS filter/blur performance impact | Profile, prefer pseudo-elements over backdrop-filter where possible |
| React Native shadow API limitations | Design allows platform-specific modules; iOS shadowPath can do squircles, Android may need approximate |
| Clever CSS techniques causing unexpected side effects | Each technique isolated to elevation utility classes, test thoroughly |

---

## Success Criteria

1. Squircles are visually distinct from standard CSS `border-radius` curves
2. Shadow hierarchy is clear — users perceive elevation differences between levels
3. Dark backgrounds (#000000) show depth without muddiness or loss of contrast
4. All interactive elements share consistent squircle geometry
5. No performance regression from multi-layer shadows or SVG masks
6. `ui/DESIGN.md` accurately describes the new shadow and elevation system
