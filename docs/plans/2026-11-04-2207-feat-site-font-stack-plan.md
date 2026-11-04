---
title: Website Font Stack - Plan
type: feat
date: 2026-11-04
topic: website-font-stack
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: code
---

# Website Font Stack - Plan

## Goal Capsule

- **Objective:** Every page of the public website renders its text with the same font stack as the application, so any script a page contains renders in Geist or the matching Noto family rather than the visitor's system fonts.
- **Means:** Run the stack-ordered page-font subsetter on every rendered page, not only the typography page (KTD1).
- **Product authority:** This Product Contract; `ui/DESIGN.md` (Typography and Static public website) describes the stack.
- **Execution profile:** Website tooling, site templates and site tests only.
- **Stop conditions:** Stop and ask if a page other than `/typography/` would declare more than 500 KB of font slices.
- **Open blockers:** None.

---

## Product Contract

### Summary

Every website page declares the Geist and Noto slices its own text reaches first in the shared stack, so the site uses the application's font stack everywhere while each page downloads only what it renders.

### Problem Frame

The site declares only Latin Geist on every page except `/typography/`, so Vietnamese diacritics outside Geist's Latin slice, Cyrillic, symbols such as `↗`, and any non-Latin text in the manuals fall back to system fonts. The application, by contrast, renders all of them from Geist or Noto.

### Key Decisions

- **Same stack on every page.** The user asked for the site to use the application's font stack. (session-settled: user-directed — chosen over keeping Noto on the typography page only, the earlier decision this replaces.)
- **Per-page subsets, not a site-wide Noto declaration.** Declaring every Noto face on every page makes Firefox fetch unused Noto slices while Geist loads (`docs/solutions/performance-issues/overlapping-unicode-range-fonts-download-unused-slices.md`). Governs R2, R3.

### Requirements

- R1. Every character on every page renders from the family the shared `--font-ui` or `--font-mono` stack reaches first for it: Geist or Geist Mono for Latin, Latin Extended, Vietnamese and Cyrillic, Noto for other scripts and for symbols Geist lacks.
- R2. Each page declares only the slices its own text reaches first; a page whose text Geist's Latin slice fully covers declares no other face.
- R3. Pages with the same selected set share one font stylesheet, published under a content-hashed name in the immutable fonts directory, with each family's license.
- R4. `/typography/` keeps its current rendering and browser checks.
- R6. Search results, which show other pages' titles and excerpts, render their characters from bundled faces too; text the visitor types is excluded.
- R7. `code`, `pre`, `kbd` and `samp` use the mono stack on every page, not only in the manual.
- R5. `ui/DESIGN.md`, `ui/site/README.md` and the previous plan's docs no longer state that Noto is limited to the typography page.

### Acceptance Examples

- AE1. **Covers R1, R2.** **Given** a manual page whose only non-Latin-slice character is the header's `↗`, **then** that page declares the one Noto slice covering U+2197 and nothing else beyond the shared Latin Geist faces.
- AE2. **Covers R2.** **Given** a page with only basic Latin text, **then** it declares no extra font stylesheet.

---

## Planning Contract

### Key Technical Decisions

- KTD1. **Generalise `PageFonts` to every page.** After rendering, select faces per page with the existing stack-ordered rule (mono detection by the site's mono selectors: `code`, `pre`, `kbd`, `samp` and the classes that set `var(--font-mono)`), skip faces `theme.css` already declares, write each distinct set once as `fonts/page-<hash>.css`, and insert its `<link>` into that page's `<head>`. The typography page's generated rules move into the same mechanism, so `typography.css` keeps only page styles.
- KTD3. **One search stylesheet.** The build also selects faces for the union of all indexed manual-article text, writes them once as `fonts/search-<hash>.css` (excluding faces `theme.css` declares), and `ui/site/src/search.ts` links it when it first renders results, so pages pay for it only after a search.
- KTD4. **Site-wide mono rule.** `site.css` sets `code, kbd, samp, pre { font-family: var(--font-mono); }`, matching the subsetter's mono detection; the manual's `code`/`pre` rule keeps only its size.
- KTD2. **Post-render injection.** The link is added to the rendered HTML before packaging and before Pagefind indexes it, so templates need no knowledge of the selection.

## Implementation Units

### U1. Per-page font stylesheets

**Goal:** Every page links the font stylesheet for its own text, and the typography page uses the same mechanism.

**Requirements:** R1–R4, R6, R7; AE1, AE2; KTD1–KTD4.

**Files:** `packages/baander-tooling/src/Website/PageFonts.php`, `Publication.php`, `Command.php`, `ui/site/assets/typography.css`, `ui/site/assets/site.css`, `ui/site/src/search.ts`, `scripts/tests/php/Website/PublicationTest.php`, `ui/site/tests/website.spec.ts`.

**Test scenarios:**
- Covers AE1. A page containing `↗` gets a stylesheet declaring the Noto slice covering U+2197.
- Covers AE2. A basic-Latin page gets no font stylesheet link.
- Two pages with identical selected sets link the same hashed stylesheet.
- A search result excerpt containing `→`, shown on a page whose own text has no `→`, renders from a bundled face once results appear.
- bAAC's `code` elements compute the mono stack.
- In the browser suite, the homepage, a manual page and bAAC render every character from a bundled face (the two-step check), request no slice their text does not reach, and `/typography/` checks still pass.

**Verification:** tooling PHPUnit, PHPStan and php-cs-fixer on changed PHP; site typecheck; `make website-build`; `yarn --cwd ui/site test:browser`.

### U2. Documentation

**Goal:** The docs describe one stack site-wide with per-page subsets.

**Requirements:** R5.

**Files:** `ui/DESIGN.md`, `ui/site/README.md`, `ROADMAP.md`.

**Test expectation:** none -- documentation only.

## Verification Contract

| Gate | Command |
|---|---|
| Tooling | `php vendor/bin/phpunit -c phpunit-tooling.xml.dist scripts/tests/php/Website`, PHPStan `phpstan-tooling.neon`, php-cs-fixer `.php-cs-fixer.tooling.php` dry run |
| Site | `yarn --cwd ui/site typecheck`, `node --test ui/site/scripts/*.test.mjs`, `make website-build`, `yarn --cwd ui/site test:browser` |

## Definition of Done

- R1–R5 hold and AE1–AE2 pass; the user previews the rebuilt site before anything is published.
