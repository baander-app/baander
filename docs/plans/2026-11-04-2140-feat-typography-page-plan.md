---
title: Website Typography Page - Plan
type: feat
date: 2026-11-04
topic: website-typography-page
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: code
---

# Website Typography Page - Plan

## Goal Capsule

- **Objective:** A visitor to the public website can see, in live text, that Bånder renders titles in every supported script correctly: the scripts, their glyph forms, the rules that keep them legible, and the type scale.
- **Means:** A new `/typography/` page beside bAAC that loads the Noto fonts for its own text only (KTD1, KTD2).
- **Product authority:** This Product Contract. `ui/DESIGN.md` (Typography) is the source of the facts the page states; where the page and DESIGN.md differ, DESIGN.md wins and the page is corrected.
- **Execution profile:** Website-only change: PHP site tooling, a PHP template, site CSS and site tests. No application code changes.
- **Stop conditions:** Stop and ask if the page's font slices would exceed 2 MB in total, or if a script cannot be shown with the bundled Noto families.
- **Open blockers:** None.

---

## Product Contract

### Summary

The website gains a Typography page, linked from the header and footer next to bAAC.
It shows live specimens of every script Bånder's interface supports, the same Han characters in Japanese, Simplified and Traditional forms, the script rules with before-and-after examples, and the ten-role type scale.
Only this page loads Noto fonts, and only the slices its own text needs.

### Problem Frame

The application now renders Latin, Vietnamese, Cyrillic, Thai, Burmese, Korean, Chinese and Japanese titles with bundled fonts and script-aware spacing, but the public site ships only Latin Geist and says nothing about it.
A visitor cannot see that support, and the site cannot even display those scripts reliably, because every non-Latin glyph falls back to whatever the visitor's system has.

### Key Decisions

- **Live text, not screenshots.** The page proves the fonts work by rendering them, and visitors can select and zoom the text. Governs R2, R7. (session-settled: user-directed — chosen over app screenshots and over both: live text demonstrates the fonts themselves.)
- **All four content sections.** Governs R3–R6. (session-settled: user-directed — chosen between the four sections as a set; the user selected every one.)
- **Header and footer links next to bAAC.** Governs R1. (session-settled: user-directed — chosen over footer-only and homepage-only links.)
- **Noto only on this page.** The rest of the site stays Latin-only Geist, which keeps every other page as light as it is today. Governs R7, R8. (session-settled: user-directed — chosen as part of the live-text option.)

### Requirements

**Page and navigation**

- R1. A page at `/typography/` titled "Typography" is linked as "Typography" from the site header and footer, next to bAAC, and the header marks it current on that page.
- R2. The page describes itself to search engines and link previews like other pages, and appears in the sitemap.

**Content**

- R3. Script specimens: for each supported script (Latin with Vietnamese, Cyrillic, Thai, Burmese, Korean, Simplified Chinese, Traditional Chinese, Japanese) the page shows its name and sample album, song or screen text set in the type roles.
- R4. Glyph forms: the same Han characters (直, 骨, 与) appear side by side as Japanese, Simplified Chinese and Traditional Chinese, each showing its own form, with one sentence on why the form follows the item's language.
- R5. Script rules: zero tracking for Thai, Burmese, Hangul and CJK; the taller line-heights for CJK, Thai and Burmese; the +2px size for wholly Burmese text; and tabular numerals for timecodes. Each rule has a labelled "without the rule" and "with the rule" example.
- R6. Type scale: the ten roles with their desktop and phone sizes, weights and use, each rendered as a live sample.

**Fonts and rendering**

- R7. Every character on the page renders from the self-hosted family the app uses for it: Geist or Geist Mono for Latin, Latin Extended, Vietnamese and Cyrillic, and Noto for the other scripts. No page requests a third-party font service.
- R8. Pages other than `/typography/` request no Noto font file.
- R9. The page ships only the font slices that its own characters reach first in their font stack, under content-hashed names, with the OFL license, and the existing immutable cache rule covers them.
- R10. The facts the page states (scripts, rules, sizes, weights) match `ui/DESIGN.md`.

### Acceptance Examples

- AE1. **Covers R4.** **Given** the glyph-forms section, **then** `直` in the Japanese column computes a font stack whose first CJK family is Noto Sans JP, and in the Simplified column Noto Sans SC.
- AE2. **Covers R5.** **Given** the tracking example, **then** the "with the rule" Thai label has zero letter-spacing and the "without" one has 0.12em.
- AE3. **Covers R8.** **Given** a visitor opening the homepage, **then** no Noto font file is requested.

### Scope Boundaries

- App screenshots of real screens; the preview instance that would capture them is unavailable.
- Interactive controls (switching languages, editing sample text).
- Changing any other page's fonts or the site's sub-12px labels.

---

## Planning Contract

### Key Technical Decisions

- KTD1. **Subset by the rendered page, along the font stack.** After the pages are rendered, the publication step reads `typography/index.html`. For each text node it resolves the nearest `lang` and whether the element uses the mono stack, orders the families as the page CSS does (the `:lang()` reorders for ja, ko and zh-Hant/TW/HK/MO, otherwise the default order, with Geist or Geist Mono first), and for each code point selects only the first family's Fontsource face whose `unicode-range` covers it. Geist's Latin slice, which every page already declares, counts as covering its range. Selecting along the stack matters because every Noto package also declares Latin faces, and the CJK packages overlap each other: an intersect-everything rule would ship faces the page never uses, and Firefox fetches later families that cover the same characters while an earlier face loads. The selected `.woff2` files are copied under content-hashed names with each family's license, and their rules go into the page stylesheet. Text edits update the set without a hand-kept list (R9). The site pins the eight `@fontsource-variable/noto-sans*` packages at the versions the web app uses; the Geist packages it already pins supply the Latin Extended, Vietnamese and Cyrillic slices.
- KTD2. **One page stylesheet carries the fonts and the rules.** The generated face rules and the page's own CSS form the page's `stylesheet`, so no other page links them (R8). The page CSS reads the shared `--font-ui` and `--font-mono` stacks and `--text-*` role tokens from `theme.css`, and restates the app's `:lang()` reorders and `data-script` rules from `ui/web/src/shared/theme/GlobalStyles.tsx`, because the site has no React text component (R4, R5).
- KTD3. **Markup carries language and script markers directly.** The template writes `lang` and `data-script` on each sample element, as `ContentText` does in the app. "Without the rule" examples opt out through a page-local class that restores the rule's absence, inside a labelled panel.
- KTD4. **Content lives in the template's data.** The page's strings and role table come from a data array passed by `Command.php`, like the bAAC page's evidence. Role sizes and weights come from `ui/design-tokens/tokens/base/type.json` at build time, so the scale section cannot drift from the tokens (R6, R10).

---

## Implementation Units

### U1. Page font subsetting

**Goal:** The build copies only the Noto slices the typography page needs and writes their face rules.

**Requirements:** R7, R8, R9; KTD1, KTD2.

**Dependencies:** None.

**Files:**
- Modify `ui/site/package.json`, `ui/site/yarn.lock` (pin the eight Noto packages at 5.3.x)
- Modify `packages/baander-tooling/src/Website/Publication.php`, `packages/baander-tooling/src/Website/Command.php`
- Modify `scripts/tests/php/Website/PublicationTest.php`

**Approach:** Parse each package's `index.css` into faces (family, `unicode-range`, file). Walk the rendered page's text nodes and select faces by the stack-ordered rule in KTD1. Copy each selected file and each family's license through the existing hashed copy, and prepend the rules to the page stylesheet in staging. The site-wide `theme.css` and its Latin Geist face are unchanged. The 2 MB stop condition applies to the selected set.

**Test scenarios:**
- A page containing `直` and `ไทย` selects a Noto SC slice covering U+76F4 and the Noto Thai slice, and no Myanmar slice.
- A page with only Latin text selects no Noto slice.
- A Japanese-tagged `直` selects a Noto Sans JP slice and no SC, TC or KR slice.
- A page containing Cyrillic selects the Geist Cyrillic slice and no Noto Sans Cyrillic slice; Vietnamese selects the Geist Vietnamese slice.
- Selected files are published under content-hashed names in the fonts directory, with each family's license.
- The generated rules name the families exactly as the shared stacks do (`Noto Sans SC Variable` and so on).

**Verification:** tooling PHPUnit and PHPStan pass.

### U2. Typography page and navigation

**Goal:** The page exists with all four sections, linked from the header and footer.

**Requirements:** R1–R6, R10; AE1, AE2; KTD2–KTD4.

**Dependencies:** U1.

**Files:**
- Create `ui/site/templates/typography.php`, `ui/site/assets/typography.css`
- Modify `packages/baander-tooling/src/Website/Command.php` (register the page and its data)
- Modify `ui/site/templates/header.php`, `ui/site/templates/footer.php`

**Approach:** Mirror the bAAC page's structure (intro, on-page navigation, sections). Take the specimens, the Han characters 直, 骨 and 与, and the Thai, Burmese and Hangul rule examples from the test strings in the Appendix (section 8) of `docs/plans/2026-11-04-1910-feat-geist-noto-typography-plan.md`. At phone width the role table renders each role as a stacked block (name and metrics above its live sample), and the glyph-form and with/without panels stack vertically with their labels above each sample, so no section needs horizontal scrolling. Set samples in role classes that read the `--text-*` tokens. Keep labels and copy factual and consistent with `ui/DESIGN.md`.

**Test expectation:** covered by U3's browser scenarios.

**Verification:** the site builds and the page renders in light and dark themes at phone and desktop widths.

### U3. Browser checks

**Goal:** The site browser suite proves the page renders every script with bundled fonts and the rules.

**Requirements:** R1, R2, R7, R8; AE1–AE3.

**Dependencies:** U2.

**Files:**
- Modify `ui/site/tests/website.spec.ts`

**Test scenarios:**
- Covers AE1. Japanese and Simplified `直` compute Noto Sans JP and Noto Sans SC first.
- Covers AE2. The with-rule Thai label has zero letter-spacing; the without-rule one has 0.12em.
- Covers AE3. Opening the homepage requests no Noto font file.
- For each specimen character, the first family in the element's computed stack whose declared face covers it is a bundled family, and `document.fonts.check` passes for that family and character, as in `ui/web/tests/e2e/typography/typography.spec.ts`.
- The header link reaches the page and is marked current there; the sitemap lists `https://baander.app/typography/`.
- At 390px and 1280px in both themes the page stays within the viewport and no section scrolls horizontally.

**Verification:** `yarn --cwd ui/site test:browser` passes after a fresh build.

### U4. Documentation

**Goal:** The design language and site README describe the page's font exception.

**Requirements:** R10.

**Dependencies:** U2.

**Files:**
- Modify `ui/DESIGN.md` (Static public website), `ui/site/README.md`

**Test expectation:** none -- documentation only.

**Verification:** the text matches the shipped behaviour.

---

## Verification Contract

| Gate | Command | Proves |
|---|---|---|
| Tooling | `php vendor/bin/phpunit -c phpunit-tooling.xml.dist scripts/tests/php/Website` and PHPStan with `phpstan-tooling.neon` on the changed files | U1 |
| Site | `yarn --cwd ui/site typecheck`, `node --test ui/site/scripts/*.test.mjs`, `make website-build`, `yarn --cwd ui/site test:browser` | U2, U3 |
| Preview | `make website-preview`, reviewed by the user before publishing | Objective |

## Definition of Done

- R1–R10 hold, AE1–AE3 pass in the browser suite, and the user has previewed the page.
- `ROADMAP.md` records the delivery and its checkpoint.
- Nothing is published or pushed until the user asks.
