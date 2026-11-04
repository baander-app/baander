---
title: Browser font and clipping checks that pass without proving anything
date: "2026-11-04"
category: best-practices
module: Web and website typography browser tests
problem_type: best_practice
component: testing_framework
severity: medium
applies_when:
  - Writing a browser test that a glyph is not clipped by a fixed-height or overflow-hidden box
  - Writing a browser test that text renders from a bundled web font rather than a system font
  - Reading Chromium's DevTools platform-font report for a variable font
tags: [playwright, fonts, document-fonts, cdp, clipping, ink-overflow, test-fidelity]
---

# Browser font and clipping checks that pass without proving anything

## Context

The typography harness (`ui/web/tests/e2e/typography/`) and the site suite (`ui/site/tests/website.spec.ts`) prove that Thai, Burmese and CJK text renders from bundled fonts and is not clipped in 32px rows. Three obvious checks turned out unable to fail on the defect they target; reviewers caught them before they shipped.

## Guidance

**Clipping: measure glyph ink, not scroll height.** Stacked Burmese marks cut off by a row's `overflow: hidden` leave `scrollHeight === clientHeight`, because ink overflow does not count as scrollable overflow. Noto Sans Myanmar's tallest marks reach about 0.3px above a 30px line box at 15px. `ink-bounds.ts` measures the string with canvas `measureText` (`actualBoundingBoxAscent` / `actualBoundingBoxDescent`) at the element's computed font, places that box at the baseline found with a zero-size inline-block probe, and asserts it lies inside the nearest clipping ancestor's client rect.

**Bundled face: find the covering face first, then check it.** `document.fonts.check(font, text)` returns `true` when no declared face matches the text, so a character whose slice is missing passes while it renders in a system font. A plain `check()` across a whole stack also fails falsely in Firefox, which counts every unloaded fallback face. The harness first finds the first family in the element's computed `font-family` whose declared face's `unicode-range` covers the character, fails if none is a bundled family, and only then calls `check()` for that family and character.

**Chromium's font report names variable fonts by their default instance.** CDP `CSS.getPlatformFontsForNode` reports a Fontsource variable font as, for example, `Noto Sans SC Thin`, not `Noto Sans SC Variable`, so a family-name match must allow the instance suffix. Its `isCustomFont: false` entry is how a system font that slipped in shows up (it caught the host's own Noto Sans Thai once the bundled Thai face was removed). The API exists only in Chromium; Firefox and WebKit rely on the two-step check above.

## Applicability

Before trusting any of these checks, break the thing it guards (remove a face, a reorder rule or a script rule from the built CSS) and confirm the test fails; the site suite's checks were validated this way.
