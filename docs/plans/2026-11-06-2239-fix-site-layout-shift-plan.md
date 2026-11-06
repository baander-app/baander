---
title: Website Layout Shift - Plan
type: fix
date: 2026-11-06
topic: site-layout-shift
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: code
---

# Website Layout Shift - Plan

## Goal Capsule

- **Objective:** A reader following a link on the public website sees the new page paint once and stay put: the header, the manual's chapter list and the article text do not move between first paint and load, on a cold visit as well as a cached one.
- **Means:** Reserve the theme toggle's and the search form's space in the page markup, preload the Latin Geist faces with a metric-matched fallback, keep a stable scrollbar gutter, and guard the result with a throttled layout-shift browser check (KTD1 to KTD6).
- **Product authority:** The maintainer's choice on 2026-11-06 to fix all three measured causes. The Product Contract wins on what the pages must do; the Planning Contract on how.
- **Execution profile:** Site templates, stylesheet and search script, the website publication step in the tooling package and its PHPUnit tests, the site's Chromium suite and the roadmap, on a branch named after this plan, landed as local commits. Code, comments and tests describe the behaviour in plain words and never cite this plan or its ids. Verified by the tooling PHPUnit, PHPStan and format gates, the site typecheck, a fresh website build and the site's Chromium suite, including the new check failed once against a deliberately broken build. Push only when asked.
- **Stop conditions:** Stop and ask if the metric-matched fallback cannot bring the operator guide's paragraph back to its loaded line count, or if the layout-shift check cannot reach zero on the two measured pages without a tolerance.
- **Who finishes:** The implementer runs the gates, records the delivery and its checkpoint in ROADMAP.md, and leaves the branch unpushed until asked.

## Product Contract

### Summary

Every page of the public website holds its layout from first paint to load. The header renders the theme toggle's box before the appearance script reveals it. Manual pages render the search form in the HTML and the search script wires it up. The build preloads the Latin Geist faces and declares a fallback face whose metrics match Geist, so text that paints before the font keeps its width and line count. The document keeps a stable scrollbar gutter. A browser check in the site suite measures layout shift under throttled loading and fails on any shift.

### Problem Frame

Measured on the built site in headless Chromium at 1440 by 900, three causes move content on every navigation. The theme toggle in the header starts hidden and the appearance script reveals it after the document parses, so the header grows from 23 to 44 pixels, the page drops 4 pixels and the nav links slide 68 pixels left on every page, cached or not. On manual pages the search script appends a label, a field and a status line into an empty mount, pushing the chapter list down 144 pixels once the module runs. Geist and Geist Mono load with `font-display: swap` and no preload, so text reflows by 2 to 7 pixels, the header nav widens from 390 to 468 pixels and, on the operator guide, a paragraph gains a line that moves the list below it 26 pixels. The 2026-11-04 typography plan left font preloading and metric-matched fallbacks unbuilt on the condition that visible layout shift on headers would change the call. This measurement is that evidence.

### Key Decisions

- **Fix all three causes in one change.** (session-settled: user-directed — chosen over the script-caused jumps only and over the fonts only: the toggle reveal is the jump on every click, and the font reflow is the largest single shift.) Governs R1 to R6.
- **Preload the Latin faces and metric-match the fallback, reversing the typography plan's "considered and not built" item.** (session-settled: user-directed — chosen over keeping that decision: the header width change and the one-line reflow on the operator guide are the evidence that plan asked for.) Governs R4, R5.
- **Keep `font-display: swap`.** (session-settled: user-approved — chosen over `optional`: a slow first visit still gets Geist rather than sticking with the fallback.) Governs R4.
- **A stable scrollbar gutter on the document.** (session-settled: user-approved — accepted with the empty gutter strip on pages shorter than the viewport on desktops with classic scrollbars.) Governs R7.
- **A throttled layout-shift browser check with a break-it-first validation.** (session-settled: user-approved — chosen over relying on review: a template or script change can reintroduce a jump that nobody notices on a fast local build.) Governs R8, R9.

### Requirements

**Header and search**

- R1. The theme toggle occupies its final box from the first paint; the appearance script makes it visible without changing the header's size or the nav links' position.
- R2. Visitors without JavaScript see no usable toggle, and the header keeps the same size as with JavaScript.
- R3. Manual pages render the search label, field, status line and results list in the HTML; the search script enables and wires them without adding or moving elements. Without JavaScript the field is disabled and the existing "Search needs JavaScript" message shows inside the form.

**Fonts**

- R4. Every page preloads the Latin Geist face; a page that sets text in the mono stack also preloads the Latin Geist Mono face. The faces keep `font-display: swap`.
- R5. The published font stacks place a metric-matched fallback face after each Geist family, so text painted before Geist loads keeps the width and line count it has once Geist renders. Only the website's published theme changes; the shared design tokens and the per-page Noto slice selection are unchanged.
- R6. The font preload links and fallback rules the build emits are covered by the tooling PHPUnit suite.

**Viewport**

- R7. The document reserves a stable scrollbar gutter, so centred content does not shift when a page's height crosses the viewport on desktops with classic scrollbars.

**Regression check**

- R8. The site's Chromium suite loads the homepage and the operator guide page at a desktop and a phone width with network and CPU throttled, and fails when any layout shift not caused by input is recorded between first paint and settled load.
- R9. Before the check is trusted, it is run once against a deliberately broken build and fails; the roadmap records that run.

**Record**

- R10. ROADMAP.md records the delivery and its verification checkpoint. The site README and the design language's website section describe the preload and fallback behaviour.

### Acceptance Examples

- AE1. **Covers R1, R2.** Given the homepage with JavaScript enabled, when the appearance script runs, then the header's height and the nav links' positions are the same as before it ran, and the toggle becomes visible. Given the same page without JavaScript, then the toggle is hidden and the header has the same height.
- AE2. **Covers R3.** Given a manual page without JavaScript, then the search field is present and disabled and the "Search needs JavaScript" message is visible inside the form. Given the same page with JavaScript, then typing a query shows results as today.
- AE3. **Covers R4.** Given the built homepage, then its head carries one font preload link for the Latin Geist face and none for Geist Mono. Given a built manual page with a code block, then its head carries both.
- AE4. **Covers R5.** Given the operator guide page with the Geist font files blocked, then its reading-path paragraph has the same line count as with the fonts loaded.
- AE5. **Covers R8.** Given the homepage and the operator guide page under the throttled profile, then the summed layout shift is zero at both widths. Given the toggle's reveal rule switched from visibility to display, so its box collapses until the script runs, or the fallback faces removed while the fonts are served slowly, then the check fails.

### Scope Boundaries

- The web app and Electron font loading stay as the typography plan left them.
- The typography page's samples and the per-page Noto slice selection are unchanged.
- Considered and not built: `font-display: optional`. A slow first visit would keep the fallback for the whole page; the user chose swap with a matched fallback.
- Considered and not built: reserving the search form's height with a CSS minimum. A number drifts from the mounted form's real height; rendering the form in the HTML gives the slot its intrinsic height.
- Considered and not built: adding the fallback family to the shared design tokens. The app would then declare a face it does not ship; the website composes its own theme and adds the family there.

#### Deferred to Follow-Up Work

- Measuring diagram pages, where the diagram script swaps an image for an inline SVG after load. The two pages in the check carry no diagram; a shift there is its own fix.

### Sources

- `ui/site/templates/header.php` and `ui/site/assets/appearance.js`: the toggle starts `hidden` and `connect()` reveals it on `DOMContentLoaded`; the script itself runs synchronously in the head and sets `data-theme` on the root element before the body parses.
- `ui/site/templates/page.php` and `ui/site/src/search.ts`: the empty `#manual-search` mount and `mountSearch()`, which creates the label, field, status and results list.
- `ui/site/assets/site.css`: `.header-actions`, `.theme-toggle[hidden]`, the phone-width header grid, and `.chapter-sidebar`'s existing `scrollbar-gutter: stable`.
- `packages/baander-tooling/src/Website/Publication.php`: `assets()` composes the published `theme.css` from the Latin face rules and the design tokens; `pageFonts()` rewrites each page's head ahead of the theme link; `faceRule()` emits `font-display: swap`.
- `packages/baander-tooling/src/Website/PageFonts.php`: reads the stacks from the design tokens' build, not the published theme, and skips stack families it has no faces for.
- `scripts/tests/php/Website/PublicationTest.php`: the font publication tests, including the head link order assertion.
- `ui/site/tests/website.spec.ts` and `ui/site/tests/experience.spec.ts`: the no-JavaScript manual and homepage tests that assert the search message and the hidden toggle.
- `docs/solutions/best-practices/browser-font-checks-that-pass-without-proving-anything.md`: break the guarded thing before trusting a browser check.
- `docs/plans/2026-11-04-1910-feat-geist-noto-typography-plan.md`, Scope Boundaries: the condition under which preloading and metric-matched fallbacks were to be revisited.
- Measurement script from the brainstorm: a Playwright run registering a `layout-shift` `PerformanceObserver` with `buffered: true` before navigation, with Chrome DevTools Protocol network and CPU throttling for the slow profile.

## Planning Contract

### Key Technical Decisions

- KTD1. **The toggle is visibility-hidden until the root carries a theme, not display-hidden until the script connects.** The button loses its `hidden` attribute. The stylesheet hides it with `visibility: hidden` and shows it under `:root[data-theme]`, which the appearance script sets synchronously in the head before the body paints. The button keeps its 44 pixel box in both the flex header and the phone-width grid, so nothing moves; without JavaScript the root never gains a theme and the box stays invisible. The script's `button.hidden = false` goes. (Inherits the user-directed Key Decision governing R1, R2.)
- KTD2. **The manual template renders the search form; the script wires it.** `page.php` emits the label, the `search` input with `disabled`, the status paragraph with its initial text and the hidden results list, with the same ids and attributes the script creates today. The `noscript` message moves inside the form, after the field, and the stylesheet hides the status line while the root carries no theme, so a visitor without JavaScript sees the disabled field and the message and never the invitation to search beside it. `mountSearch()` looks the elements up, removes `disabled`, and attaches its listeners. The build's search mount anchor, the opening `<section id="manual-search"` tag, is unchanged, so the result-fonts attribute still lands. (Inherits the user-directed Key Decision governing R3.)
- KTD3. **Preload links are emitted by the publication step, which owns the hashed face paths.** `assets()` already copies the two Latin faces under content-hashed names; it hands those paths to `pageFonts()`, which inserts `<link rel="preload" as="font" type="font/woff2" crossorigin>` links ahead of the page-font and theme links. Geist is preloaded on every page. Geist Mono is preloaded when the page's text reaches the mono stack, which the page-font selection already evaluates for `code`, `kbd`, `samp`, `pre` and the page's mono selector; the homepage saves the 23 KB file it never draws. Same-origin font preloads need `crossorigin` or the browser fetches the file twice. (Inherits the user-directed Key Decision governing R4.)
- KTD4. **Metric-matched fallback faces live in the published theme, and the published stacks name them.** `Publication` emits two `local()` faces: Geist Fallback with the source list `local("Arial"), local("Liberation Sans")`, and Geist Mono Fallback with `local("Courier New"), local("Liberation Mono")`. Chromium matches `local()` against installed font names only and does not follow fontconfig's Arial alias, so the Liberation names are listed explicitly; each pair shares its metrics, so one set of `size-adjust`, `ascent-override`, `descent-override` and `line-gap-override` values per face, derived against Arial and Courier New, serves every platform. The values are constants beside the Latin face names, with a comment naming the Geist release and the tool used to derive them. Each fallback face carries the unicode range of the Fontsource Latin Geist slice, so it stands in only for characters Geist's Latin face will render; a character outside that range, such as the header's arrow, still falls through to its Noto slice instead of being drawn from the system font. `theme()` inserts each fallback family after its Geist family in every `--font-ui*` and `--font-mono*` declaration of the published copy. `PageFonts` reads the design tokens' build and skips families it has no faces for, so slice selection is unaffected; the design tokens themselves are untouched. (Inherits the user-directed Key Decision governing R5.)
- KTD5. **The layout-shift check uses the Layout Instability API under Chrome DevTools Protocol throttling.** A new spec registers a buffered `layout-shift` observer from an init script, applies a slow network profile and a CPU slowdown through a CDP session, navigates, waits for load, `document.fonts.ready` and a short settle, and asserts the sum of entries without recent input is zero. The check allows no tolerance, so the fallback faces must match Geist closely enough that Chromium records no shift at all. The spec runs the homepage and the operator guide page at 1440 by 900 and 390 by 844. The suite already runs Chromium only, so the CDP session is available. (Inherits the user-approved Key Decision governing R8.)
- KTD6. **`scrollbar-gutter: stable` goes on the root element in the site stylesheet,** next to the existing `scroll-padding-top`. Overlay-scrollbar platforms, including headless Chromium, render no gutter, so the browser check cannot prove this one; the chapter sidebar already uses the same property. (Inherits the user-approved Key Decision governing R7.)

### Sequencing

U1 and U2 are independent. U3 depends on neither. U4 depends on U1 to U3, because the check is validated against the finished pages. U5 closes.

## Implementation Units

### U1. Reserve the toggle's box and the scrollbar gutter

- **Goal:** The header keeps its size and the nav links their position whether or not the appearance script has run, and the document reserves its scrollbar gutter.
- **Requirements:** R1, R2, R7 (KTD1, KTD6).
- **Dependencies:** none.
- **Files:** `ui/site/templates/header.php`, `ui/site/assets/appearance.js`, `ui/site/assets/site.css`, `ui/site/tests/experience.spec.ts`.
- **Approach:**
  1. Remove the `hidden` attribute from the toggle and the `button.hidden = false` line from the script's `connect()`.
  2. Replace the `.theme-toggle[hidden]` rule with a visibility rule keyed on `:root[data-theme]`; keep the phone-width grid placement.
  3. Add `scrollbar-gutter: stable` to the `html` rule.
- **Patterns to follow:** the existing `:root[data-theme="balanced"]` selectors in `site.css` that switch the sun and moon icons.
- **Test scenarios:**
  - Covers AE1. Homepage with JavaScript: the header's bounding height and the nav's left edge measured before `DOMContentLoaded` equal those after load, and the toggle is visible and clickable.
  - Covers AE1. Homepage without JavaScript: the toggle is hidden (the existing no-JavaScript test still passes with `visibility: hidden`) and the header's height equals the JavaScript case.
  - Phone width with JavaScript: the toggle sits in the header's first row and the nav row does not move when the script runs.
- **Verification:** the existing experience and website specs pass, and the header measurements above hold.

### U2. Render the search form in the manual template

- **Goal:** Manual pages carry the search form in their HTML; the script enables it without adding or moving elements.
- **Requirements:** R3 (KTD2).
- **Dependencies:** none.
- **Files:** `ui/site/templates/page.php`, `ui/site/src/search.ts`, `ui/site/assets/search.css`, `ui/site/tests/website.spec.ts`, `scripts/tests/php/Website/PublicationTest.php`.
- **Approach:**
  1. Emit the label, disabled `search` input, status paragraph and hidden results list in the template with the ids, `aria-describedby`, `aria-controls`, placeholder and initial status text the script sets today; place the `noscript` message inside the form, after the field.
  2. Change `mountSearch()` to look the elements up by id, remove `disabled`, and attach its listeners; drop the element creation.
  3. Style the disabled field so it reads as unavailable next to the message, and hide the status line under `:root:not([data-theme])` so it never contradicts the message.
  4. Keep the search mount anchor the build matches; the publication test's search fixture may gain the rendered children to mirror the template.
- **Patterns to follow:** the template's existing `aria-current` and `data-pagefind-meta` markup; the script's existing element attributes.
- **Test scenarios:**
  - Covers AE2. Manual page without JavaScript: the field is present and disabled, the "Search needs JavaScript" message is visible inside the form, and the status line is hidden.
  - Covers AE2. Manual page with JavaScript: the field is enabled, typing a query shows results, and the existing search tests pass unchanged.
  - Manual page with JavaScript: the chapter list's top edge before `DOMContentLoaded` equals its top edge after load.
  - Publication: a page whose mount contains the rendered form still receives the result-fonts attribute on the section.
- **Verification:** the website spec's search and no-JavaScript tests pass, the site typecheck passes, and the publication test passes.

### U3. Preload the Latin faces and declare metric-matched fallbacks

- **Goal:** Pages preload the Geist faces they use and paint the fallback at Geist's metrics, so font arrival moves no text.
- **Requirements:** R4, R5, R6 (KTD3, KTD4).
- **Dependencies:** none.
- **Files:** `packages/baander-tooling/src/Website/Publication.php`, `scripts/tests/php/Website/PublicationTest.php`, `ui/site/tests/website.spec.ts`, `ui/site/README.md`, `ui/DESIGN.md`.
- **Approach:**
  1. Let `assets()` expose the published Latin face paths and pass them into `pageFonts()`, which inserts the preload links ahead of the page-font link; emit the mono preload only when the page's text reaches the mono stack.
  2. Add the two fallback face rules to the fonts block of the published theme, each with its source list, the Latin Geist unicode range, the override constants and their derivation comment (KTD4).
  3. Rewrite the `--font-ui*` and `--font-mono*` declarations in `theme()` to name each fallback family after its Geist family.
  4. Derive the override values once from the installed Geist and Geist Mono releases' metrics against Arial and Courier New, with an off-the-shelf font metrics tool, and record tool and version in the comment.
  5. Update the README's font paragraph and the design language's website font paragraph; run `prose-fix` on both.
- **Execution note:** Prove the fallback on the operator guide page with the font files blocked before polishing the rules; the line count of the reading-path paragraph is the signal.
- **Patterns to follow:** `faceRule()` and `copyHashed()` in `Publication.php`; the head link order assertion in `PublicationTest.php`.
- **Test scenarios:**
  - Covers AE3. A published page without mono text carries one preload link, for Geist, before the page-font and theme links; a page with a `code` element carries both.
  - The published `theme.css` contains the two fallback faces with their source lists, the Latin Geist unicode range, `size-adjust` and the three overrides, and every `--font-ui*` and `--font-mono*` declaration names the fallback family directly after its Geist family.
  - In the browser, the existing search-results and typography font coverage checks pass unchanged with the fallback faces present, and the header's arrow still renders from its Noto slice.
  - The design tokens' build file is unchanged after publication, and the typography page still ships the same slice set as before.
  - Covers AE4. In the browser, the operator guide page with Geist blocked has the same reading-path paragraph line count as with Geist loaded.
- **Verification:** the Website tooling PHPUnit tests, PHPStan and the format check pass; a fresh website build succeeds; the browser scenario above holds.

### U4. Layout-shift browser check

- **Goal:** The site suite fails when a page shifts between first paint and settled load.
- **Requirements:** R8, R9 (KTD5).
- **Dependencies:** U1, U2, U3.
- **Files:** `ui/site/tests/stability.spec.ts`, `ui/site/tests/website.spec.ts` (shared same-origin fixture, if extracted).
- **Approach:**
  1. Register a buffered `layout-shift` observer from an init script that records value, time and source rectangles.
  2. Open a CDP session, apply a slow network profile and a CPU slowdown, navigate, wait for load, `document.fonts.ready` and a short settle.
  3. Assert the summed value of entries without recent input is zero for the homepage and the operator guide page at 1440 by 900 and 390 by 844; print the recorded sources on failure so the culprit is named.
  4. Validate once against deliberately broken builds: collapse the toggle's box until the script runs by switching its reveal rule from visibility to display, and separately remove the fallback faces while the fonts are served slowly; confirm each failure names the moved element, and do not commit the broken state.
- **Patterns to follow:** the same-origin route fixture and viewport loop in `website.spec.ts`; the init-script measurement from the brainstorm.
- **Test scenarios:**
  - Covers AE5. Homepage and operator guide at both widths under throttling: zero summed shift.
  - Covers AE5. With the toggle's box collapsed until the script runs: the check fails and its message names the header actions as a source.
  - Covers AE5. With the fallback faces removed and fonts served slowly: the check fails on the operator guide page.
- **Verification:** the site suite passes with the check included, and the roadmap records the break-it-first run.

### U5. Record the delivery

- **Goal:** The roadmap carries the delivery and its checkpoint.
- **Requirements:** R10.
- **Dependencies:** U1 to U4.
- **Files:** `ROADMAP.md`.
- **Approach:** Add a website layout stability entry beside the website font stack entry: the three causes, the measured values before, the fix, and the verification checkpoint with suite counts and the break-it-first result.
- **Test expectation:** none; a documentation change.
- **Verification:** the entry's links resolve and `prose-fix` has run on it.

## Verification Contract

| Check | Command or outcome | Proves |
|---|---|---|
| Tooling tests | `composer run-script --timeout=7200 test:tooling` from the repo root, or its Website filter while iterating | R6, U2, U3 |
| Tooling static gates | `composer analyse:tooling` and `composer check:tooling-format` | changed PHP is clean |
| Site typecheck | `corepack yarn typecheck` in `ui/site` | U2 |
| Site node tests | `node --test scripts/*.test.mjs` in `ui/site` | the render helpers still pass |
| Website build | `make website-build` | the published head, theme and fonts |
| Browser suite | `corepack yarn test:browser` in `ui/site`, which starts its own disposable server | R1 to R5, R8 |
| Break-it-first | the new check fails once against a broken build | R9 |

## Definition of Done

- Every unit's verification holds and the gates above pass.
- The new browser check has failed once against a broken build, and the roadmap records it.
- No code, comment or test cites this plan or its ids.
- No abandoned experiment remains in the diff.
- ROADMAP.md records the delivery and checkpoint; the README and design language are updated and have passed `prose-fix`.
