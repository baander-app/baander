---
title: Overlapping unicode-range fonts download slices a page never renders
date: "2026-11-04"
category: performance-issues
module: Web typography font loading and website page fonts
problem_type: performance_issue
component: frontend
symptoms:
  - "Firefox requests Noto Sans SC, TC, Thai and Myanmar slices on a page that shows only Latin text"
  - Chromium and WebKit request only the Geist files for the same page
  - "A page-font subset built by intersecting every family's unicode-range with the page's characters exceeds its size budget"
root_cause: async_timing
resolution_type: code_fix
severity: medium
applies_when:
  - Adding or reordering families in a font stack whose later families also cover Latin or each other's characters
  - Loading Fontsource Noto packages, which each declare latin and latin-ext faces, and CJK packages whose Han slices overlap
  - Selecting which font slices a page or bundle should ship
tags: [fonts, unicode-range, firefox, fontsource, noto, font-loading, subsetting]
---

# Overlapping unicode-range fonts download slices a page never renders

## Problem

The web app's `--font-ui` stack is Geist, then Noto Sans, Thai, Myanmar, SC, TC, JP and KR. Every Noto package also declares Latin and Latin Extended faces, and the four CJK packages cover the same Han characters. On a cold load, Firefox downloaded Noto CJK, Thai and Myanmar slices for a page whose every character Geist covers.

## Symptoms

- The three-browser typography harness's Latin-only page failed only in Firefox, requesting `noto-sans-sc-vietnamese`, `noto-sans-sc-119`, `noto-sans-sc-91`, `noto-sans-sc-latin-ext`, `noto-sans-tc-vietnamese`, `noto-sans-thai-latin-ext` and `noto-sans-myanmar-latin-ext` on every run, on the host and in the Playwright image.
- A planned website subsetter that took every face whose `unicode-range` intersected the page's code points would have selected Latin slices from all eight Noto families and every Han slice in all four CJK families: about 3.8 MB in a simulation, against 1.6 MB when selecting along the stack.

## What Didn't Work

- Relying on per-glyph fallback alone. Glyph coverage is correct, but download behaviour differs: while a Geist slice is still loading, Firefox starts loading each later family's face whose `unicode-range` covers the same character. Chromium and WebKit wait for the first family.
- Preloading the Geist slices was considered; it narrows the window but does not close it.

## Solution

At runtime, load the primary faces before the first render. `ui/web/src/shared/typography/load-primary-fonts.ts` awaits `document.fonts.load()` for Geist and Geist Mono with sample text covering their Latin, Latin Extended and Vietnamese slices, in parallel with authentication, and stops waiting after three seconds or on failure so a slow font never blocks the app. `ui/web/src/main.tsx` awaits it just before `createRoot().render()`, and the harness fixtures call it the same way.

When choosing what to ship, select along the stack. `packages/baander-tooling/src/Website/PageFonts.php` walks a rendered page's text nodes, resolves each node's `lang` and whether it uses the mono stack, orders the families as the page CSS does (the Japanese, Korean and Traditional Chinese reorders, Geist or Geist Mono first), and for each character takes only the first family's face that covers it. The typography page ships 36 slices (1,493,344 bytes) this way.

## Why This Works

Once Geist is loaded, the browser resolves Latin text from it and never reaches a later family for those characters, so Firefox has no pending first face to fall past. Stack-ordered selection mirrors the browser's own fallback: a face that a character can never reach first is never declared, so no browser can fetch it.

## Prevention

- Keep a test that a Latin-only page requests no non-Latin font file, and run it in Firefox, not only Chromium (`ui/web/tests/e2e/typography/typography.spec.ts`, Latin-only page). The site suite has the same check for every page except the typography page.
- When adding a family to a stack, check whether its `unicode-range` overlaps the families before it; overlapping faces are where the extra downloads come from.
