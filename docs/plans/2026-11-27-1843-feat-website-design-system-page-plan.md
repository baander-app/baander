---
title: Website Design System Section - Plan
type: feat
date: 2026-11-27
topic: website-design-system
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: code
---

# Website Design System Section - Plan

## Goal Capsule

- **Objective:** A visitor to `baander.app` can read Bånder's design system on the website: its principles, color roles, accents, type roles, layout, motion, icons, writing rules, logo and where the website itself departs from the application. Every value matches what the application builds.
- **Means:** A section of ten pages under `/design-system/`, built from the generated design tokens and from `ui/DESIGN.md` and `ui/ICON-GUIDE.md` at build time (KTD1, KTD2, KTD3).
- **Product authority:** This Product Contract. `ui/DESIGN.md` governs the presentation, and its "Static public website" section governs the site's layout rules.
- **Execution profile:** Website tooling under `packages/baander-dev-website/src/`, templates, styles and data under `ui/site/`, and documentation. No application code, token input, generated token output, schema or API change. `ui/rn` stays untouched.
- **Finish:** Units land as local commits on the session branch `claude/sweet-darwin-8a894r`, the branch this environment requires. Push and publication happen only when the user asks.
- **Stop conditions:** Stop and ask when a page would need a rule that no tracked document states (R12), when publishing two borrowed sections would show contradicting rules (R11), or when a value can only be shown by copying it by hand.
- **Open blockers:** None. The user answered Q1, Q2 and Q5 on 2026-11-27; Q3, Q4 and Q6 take the defaults recorded under Resolved Questions.

---

## Product Contract

### Summary

The site gains a design system section: an overview at `/design-system/` and nine pages below it for color, accents, type, layout, motion, iconography, writing, the logo and the website's own departures. The pages read the design tokens and the design documents when the site builds, show live swatches, type samples and logos, compute contrast ratios, and share one in-section navigation. The top menu gains one Resources entry.

### Problem Frame

Bånder's design language lives in `ui/DESIGN.md`, `ui/ICON-GUIDE.md` and the token files under `ui/design-tokens/`. A contributor reads them in the repository; a visitor to the website cannot read them at all. The only published part is `/typography/`, which covers fonts, scripts and the type scale.

The values are spread over generated output: the 32 mood and accent themes exist only in `ui/design-tokens/build/web/theme.css`, and the contrast each accent reaches in each mood is computed by `ui/design-tokens/web.mjs` and recorded nowhere. A page that copied these values by hand would drift the next time the tokens change.

The typography page already reads its type roles from `ui/design-tokens/tokens/base/type.json`, but `packages/baander-dev-website/src/Command.php` (`TYPE_ROLES`) restates each role's use as a hand-written copy of the Use column in `ui/DESIGN.md`. A change to that column does not reach the site.

### Key Decisions

- **One section of pages, not one long page.** The user asked for a section. Governs R1.
- **Values come from the build's inputs, never from page text.** Tokens, generated themes, documents and the site's own stylesheets are read at build time, and the build fails when a value is missing. Governs R4 to R10.
- **Rules are published as their documents state them.** Use text and rule lists are parsed or borrowed from `ui/DESIGN.md` and `ui/ICON-GUIDE.md`; only openings and descriptions are written for the website. Governs R11, R12.
- **`/typography/` stays where it is.** The user confirmed this. The type page links to it, and the section navigation lists it as a related page. Governs R6, R14.

### Requirements

**Section**

- R1. The site publishes an overview at `/design-system/` and nine pages: `/design-system/color/`, `/design-system/accents/`, `/design-system/type/`, `/design-system/layout/`, `/design-system/motion/`, `/design-system/iconography/`, `/design-system/writing/`, `/design-system/logo/` and `/design-system/website/`.
- R2. The overview states the design principles (content is the interface, the 2008–2014 Human Interface Guidelines as the reference, flat surfaces with one material cue, the well) and lists every page of the section with a one-line description.
- R3. Every page carries the same in-section navigation, marks itself as the current entry, and lists `/typography/` as a related page outside the section.

**Content**

- R4. The color page shows every color role `ui/DESIGN.md` documents, with its use and its value in each of the four moods at the default accent, each value as a live swatch beside its hex code. It states that the swatches show the application's values and gives the website's softer dark foreground beside them.
- R5. The accents page shows the eight accents and, for each mood and accent, the generated primary, accent text and focus ring, with WCAG 2 contrast ratios computed at build time against the targets the token generator enforces.
- R6. The type page shows the ten type roles with desktop and phone size, weight, tracking, line-height, use and a live sample, the role rules from `ui/DESIGN.md`, and a link to `/typography/` for scripts, glyph forms and script rules.
- R7. The layout page shows the spacing and radius scales with the uses `ui/DESIGN.md` gives them, the app shell measurements, the surface rules including the one permitted shadow, and the well.
- R8. The motion page shows the motion table and the easing rules, and the hover duration it states equals the `--duration-hover` token.
- R9. The logo page shows the three cassette marks (`ui/web/public/logo.svg`, `icon.svg`, `favicon.svg`) at the sizes each file states it is drawn for, without a second copy of any file in the repository.
- R10. The website page states where the site departs from the application: body text size and line-height, eyebrow labels, the two moods behind the light and dark toggle, the softer dark foreground, flat surfaces and picture sizing.
- R11. Rule text is published as `ui/DESIGN.md` and `ui/ICON-GUIDE.md` state it. Where two borrowed sections contradict each other, the documents are corrected first.
- R12. A page states no rule that no tracked document states.

**Site integration**

- R13. The Resources menu holds one "Design system" entry pointing to the overview; the footer follows the same menu data, and the entry is marked on every page of the section.
- R14. Every page has a canonical URL, a title of the form `<Page> — Design system — Bånder` within 60 characters, a description within 155, the breadcrumb Bånder › Design system › page, structured data, a sitemap entry dated by its sources, and the font slices its text reaches.
- R15. Pagefind indexes each page's prose under the label "Design system"; swatches, codes and type samples stay out of the index.
- R16. The pages keep the site's guarantees: semantic HTML and static CSS, readable without JavaScript, no horizontal page overflow at 390 px and 1440 px, correct in both site themes, visible focus, no gradients or shadows, no layout shift after first paint, every character from a bundled face, no request to another origin.
- R17. The build fails with a message naming the file and the missing part when a token, theme block, documented role, borrowed heading, table column or stylesheet declaration a page reads is absent, or when a computed contrast falls below its target.

### Acceptance Examples

- AE1. **Covers R4, R17.** **Given** `ui/DESIGN.md` documents `--color-ring`, **when** a theme block in `theme.css` lacks it, **then** the build fails and names the role and the mood and accent of that block.
- AE2. **Covers R5.** **Given** the warm mood with the violet accent, **when** the accents page is built, **then** its accent text row shows `#6e5ca5` and the lowest ratio that color reaches on the warm mood's seven surfaces, and that ratio is at least 4.5:1.
- AE3. **Covers R8, R17.** **Given** the motion table says hover takes 60ms, **when** `--duration-hover` changes to 80ms, **then** the build fails and names both values.
- AE4. **Covers R6.** **Given** the Use column of the Roles table in `ui/DESIGN.md` changes for `caption`, **when** the site is built, **then** both `/design-system/type/` and `/typography/` show the new text.
- AE5. **Covers R11, R17.** **Given** a page borrows "Layout › Border radius" from `ui/DESIGN.md`, **when** that heading is renamed, **then** the build fails and names the data file, the document and the heading.

### Success Criteria

- A contributor can answer "which color, size or duration does this use, and why" from the website alone.
- After a token change and a rebuild, no page of the section shows the old value.

### Scope Boundaries

**Outside this work**

- Any change to token inputs, generated token outputs, the application or `ui/rn`.
- Moving `/typography/` under `/design-system/` (user decision; see KTD12).
- Live samples of the well or the overlay shadow. `ui/DESIGN.md` keeps the material inside real application screenshots on the website; the layout page links to the player feature page instead (KTD9).
- Drawing icon glyphs. The site bundles no icon library; the iconography page names the Lucide components (Q4, default taken).
- A search box on the design system pages. Search stays on the manual pages, and its results include the design system pages (KTD6).
- Publishing or deploying the site, and pushing the branch.

**Considered and not built**

- One long page. The user asked for a section.
- Reusing `templates/page.php`. Its sidebar is fixed to the manual search box and the two guides' chapter trees; the section reuses the manual layout's CSS with its own navigation instead (KTD5).
- Parsing free prose from `ui/DESIGN.md` into new sentences. Borrowed sections are rendered as written; only tables and the role and surface lists are parsed into data (KTD3).
- Removing the `normal` and `slow` duration tokens. No web code uses them, but removing a token needs the user's consent and is separate work (Q3).

### Sources / Research

- Builder: `packages/baander-dev-website/src/Command.php` (`SITE_SOURCES`, `MONO_SELECTOR`, `TYPE_ROLES`, the typography descriptor, `typography()`), `WebsiteBuilder.php` (public page contract, URL check, trail and `SearchMetadata::page`, `sitemap()`, `template()`), `Content/SiteMenu.php` (`SECTIONS`, `forPage()`), `Content/FeatureFile.php` and `FeatureField.php` (data file validation), `Content/FeaturePages.php` (index-driven sections), `Assets/AssetPublication.php` (`assets()` copies only `logo.svg`; `theme()` reads the balanced block as the light fallback and appends `ui/site/theme-tone.css`), `Assets/PageFonts.php` (`select()` per page, `selectIndexed()` over `[data-pagefind-body]`).
- Templates: `ui/site/templates/head.php` (one `stylesheet` per page; search script only under `/docs/`), `page.php` (manual sidebar and `data-pagefind-body`), `reference.php` (indexed public page with `data-pagefind-meta="label"`), `feature.php` (breadcrumb from `meta->breadcrumbs`), `typography.php`, `screenshot.php`.
- Styles: `ui/site/assets/site.css` (`body` sets `font: 400 16px/1.65`; `.eyebrow` sets 11px, 600, `.12em`, uppercase; `:focus-visible` sets a 2px `--color-ring` outline; `.manual-layout`, `.chapter-sidebar`, `.preset-table` scroll region), `typography.css` (`.type` and `.type-*` role classes), `ui/site/theme-tone.css` (dark foreground `#e6e6e9`).
- Tokens: `ui/design-tokens/tokens/base/*.json`, `tokens/mood/moods.json`, `tokens/accent/palette.json`, `tokens/web/semantic-colors.json` (mood labels; targets 4.5 text, 4.7 action, 3 indicator), `web.mjs` (the derivation and the WCAG `contrast()`), `build/web/theme.css` (32 blocks; the default accent's block also matches `:not([data-accent])`).
- Documents: `ui/DESIGN.md` (Philosophy; Layout › App Shell, Footer player, Notification pane, Surfaces, Border radius, Spacing; Typography › Roles; Color › Token authority, Dynamic accent, Foreground-on-accent contrast; Motion; Components › Icons in menu lists, Loading states, Error states, Empty states; Static public website), `ui/ICON-GUIDE.md` (When to Use Icons, Rules, Canonical Concept to Icon Assignments).
- Branding: `ui/web/public/logo.svg` ("full detail, 256px+"), `icon.svg` ("medium detail, 64-128px"), `favicon.svg` ("simplified for 16-32px"). The site uses `logo.svg` for the header (40px), the footer (32px) and the favicon; `favicon.svg` is referenced nowhere.
- Tests that pin the site: `ui/site/tests/website.spec.ts` (typography page metadata and fonts), `menu.spec.ts` (Resources columns and heights), `stability.spec.ts` and `features.spec.ts` (page lists read from the built sitemap), `packages/baander-dev-website/tests/SiteMenuTest.php`.
- Learnings: `docs/solutions/best-practices/browser-font-checks-that-pass-without-proving-anything.md`, `docs/solutions/performance-issues/overlapping-unicode-range-fonts-download-unused-slices.md`.

---

## Planning Contract

### Key Technical Decisions

- KTD1. **One data file for the section.** `ui/site/design-system/index.php` returns the overview's title, description and opening and, in order, each page's slug, title, one-line summary, search description, opening and the document sections it borrows. It is read with `FeatureFile` and a declared shape, as `ui/site/features/index.php` is, so every error in the file is reported at once. The overview index, the section navigation, the sitemap and the browser specs all follow this order. Governs R1 to R3, R14.
- KTD2. **Token values come from the generated output and the token inputs.** A `DesignTokens` reader takes the 32 color blocks, the spacing and radius scales and `--duration-hover` from `ui/design-tokens/build/web/theme.css`, the type roles from `tokens/base/type.json`, the accent identities from `tokens/accent/palette.json`, and the mood labels and contrast targets from `tokens/web/semantic-colors.json`. The default accent is the one whose block also carries the `:not([data-accent])` selector, so the page does not hard-code violet. `AssetPublication::theme()` already reads `theme.css` the same way. Governs R4 to R8, R17.
- KTD3. **Document text comes from the documents.** A `DesignDocument` reader parses `ui/DESIGN.md` and `ui/ICON-GUIDE.md` with CommonMark and its table extension. It returns a table under a heading path as rows keyed by column name (the color Token and Use table, the type Roles table, the Motion table), the Surfaces list as role and variable pairs, and a section under a heading path rendered to HTML, up to the next heading of the same or a higher level. A borrowed section may link to `https:` addresses only; a relative repository link fails the build, because the reference-page link rewriting does not cover these documents. Governs R6 to R8, R11, R17.
- KTD4. **One type-role reader for both pages.** The role values and the Use column move into `DesignTokens::typeRoles()`, which joins `type.json` with the Roles table of `ui/DESIGN.md`. `Command::TYPE_ROLES` keeps only each role's sample text, so `/typography/` and `/design-system/type/` show the same roles from the same sources. This removes the hand-copied Use text. Governs R6, AE4.
- KTD5. **The manual layout's CSS with the section's own navigation.** One template, `ui/site/templates/design-system.php`, renders the head, header, breadcrumb, a `.manual-layout` grid whose `.chapter-sidebar` holds a `<details class="chapter-navigation" open>` with `<nav aria-label="Design system">`, and an `<article class="manual-article" data-pagefind-body>`. It includes the page's body from `ui/site/templates/design-system/<slug>.php`. The navigation lists the overview, the nine pages, and under a "Related" label the typography page. `page.php` is not reused: its sidebar is the manual search box and the guides' chapter trees. On phones the existing `.manual-layout` rules stack the navigation above the article. Governs R3, R16.
- KTD6. **Indexed prose, ignored values.** The article carries `data-pagefind-body` and a hidden `data-pagefind-meta="label"` of "Design system", as `reference.php` does. Swatch cells, hex codes, ratios, logo figures and type samples carry `data-pagefind-ignore`, so results show sentences rather than value lists, and the search fonts stylesheet gains no slices for them. The manual search box's status line and the site README name the design system pages among the searched pages. Governs R15.
- KTD7. **Swatches set the value inline.** Each swatch is a `<span class="ds-swatch" style="--swatch: #hex" aria-hidden="true">` beside `<code>#hex</code>`, so the code, not the color, carries the value. `feature-figure.php` already sets a custom property inline. A swatch has a 1px `--color-border` edge so near-background values stay visible. Value tables sit in a `role="region"` scroll container with `tabindex="0"` and an accessible name, following `.preset-table`, so a four-mood table scrolls inside itself at 390 px. Governs R4, R5, R16.
- KTD8. **The color page shows the application's values.** Its swatches are the values `theme.css` generates. The website's own dark theme lightens six foreground roles in `ui/site/theme-tone.css`; the page reads that file's value and states it beside the dark column, and the website page repeats it with the reason. Governs R4, R10.
- KTD9. **Contrast is computed in PHP and checked.** A `Contrast` helper implements WCAG 2 relative luminance as `web.mjs` does. Per mood and accent the accents page shows: primary-foreground on primary; accent text and focus ring as the lowest ratio over the seven surfaces the generator checks (background, card, popover, sidebar, secondary, muted, highlight). Ratios are truncated to two decimals, never rounded up. The build fails when a ratio is below its target: 4.5 for accent text and for the label on the fill (`normalTextContrast`), 3 for the ring (`indicatorContrast`). The generator's 4.7 action target applies to the fill against the surfaces, which the page states but does not tabulate. On the current tokens the lowest ratios over all 32 themes are 5.86 for the label on the fill, 4.50 for accent text and 3.00 for the ring, computed with `web.mjs`'s `contrast()`. Governs R5, R17.
- KTD10. **Scale uses are matched, not restated.** The layout page lists every spacing and radius token with its value in rem and px. A token's "documented use" is each item of the Spacing or Border radius list in `ui/DESIGN.md` that names its px value; a token no item names reads "No documented use". The two lists themselves are borrowed beside the table. Governs R7.
- KTD11. **Logos are published by the build.** `AssetPublication::assets()` copies `icon.svg` and `favicon.svg` beside `logo.svg` into `/assets/site/`. The logo page reads each file's size comment ("full detail, 256px+") at build time and shows the mark at the end points of its range: `logo.svg` at 256 px, `icon.svg` at 128 and 64 px, `favicon.svg` at 32 and 16 px. The build fails when a comment is missing or names no size. Governs R9.
- KTD12. **`/typography/` stays.** Moving it would add a `MOVED_PAGES` redirect, change its sitemap URL, its canonical and Open Graph URLs, its menu entry and the specs that open it, for no reader benefit the user asked for. The type page links to `/typography/#scripts`, `#glyph-forms` and `#script-rules`, and `/typography/` keeps its type scale section, rendered from the shared reader of KTD4. Governs R6.
- KTD13. **"Color", following the documents.** `ui/DESIGN.md`, the token names and most of the site spell it "color", so the page is `/design-system/color/` titled "Color". The brief's "colour" changes accordingly.
- KTD14. **Shared role classes move to `site.css`.** `head.php` links one page stylesheet. The `.type` and `.type-*` role rules move from `typography.css` to `site.css`, so the typography page and the type page both use them; the script-rule overrides stay in `typography.css`. The section's own layout and swatch styles go in `ui/site/assets/design-system.css`. Governs R6, R16.
- KTD15. **The website page reads the site's own sources.** Its departures table reads the `body` font shorthand, the `.eyebrow` declarations and the `:focus-visible` outline from `ui/site/assets/site.css`, and the softer foreground from `theme-tone.css`. The light theme's mood becomes a constant on `AssetPublication`, which `theme()` uses for its light fallback and the page states; a browser test pins `appearance.js` to the same two moods. The "Static public website" section of `ui/DESIGN.md` is borrowed below the table. Governs R10.
- KTD16. **Menu and section marking.** `ui/site/menu.php` adds "Design system" to the "Build on it" column before "Typography". `SiteMenu::SECTIONS` gains `/design-system/`, so the entry is marked on every page of the section, the way the guides are.
- KTD17. **Sitemap dates follow every input.** `Command::SITE_SOURCES` gains `ui/site/design-system`, `ui/DESIGN.md`, `ui/ICON-GUIDE.md`, `ui/design-tokens/build/web/theme.css`, `ui/design-tokens/tokens`, `ui/site/theme-tone.css`, `ui/site/assets/site.css`, `ui/site/assets/design-system.css` and the three SVG files. Each page's `sources` list names the data file, `design-system.php`, its own body template, the stylesheet and exactly the inputs it reads. Governs R14.

### Page contents

| Page | Read at build time | Borrowed sections |
|---|---|---|
| Overview | section data file | DESIGN.md › Philosophy |
| Color | Token and Use table and Surfaces list; four default-accent blocks; `theme-tone.css` | DESIGN.md › Color › Token authority (prose only, without its table, which the page renders with values) |
| Accents | `palette.json`; 32 blocks; contrast targets | DESIGN.md › Color › Dynamic accent; Foreground-on-accent contrast |
| Type | `type.json`; Roles table | DESIGN.md › Typography › Roles (the bullet list after the table) |
| Layout | spacing and radius scales; Spacing and Border radius lists | DESIGN.md › Layout › App Shell; Surfaces; Border radius; Spacing; Footer player; Notification pane |
| Motion | Motion table; `--duration-hover` | DESIGN.md › Motion (rules list) |
| Iconography | — | ICON-GUIDE.md › When to Use Icons; Canonical Concept to Icon Assignments; DESIGN.md › Components › Icons in menu lists |
| Writing | — | DESIGN.md › Components › Loading states; Error states; Empty states; Typography › Content language (last paragraph), pending Q1 |
| Logo | the three SVG size comments | — |
| Website | `site.css` and `theme-tone.css` declarations; light mood constant | DESIGN.md › Static public website |

A borrowed heading path that matches several sections, or a table part the page does not render, is named in the data file so a reader of the file sees what the page shows.

### Assumptions

- Ten pages is the right first cut; the order is the order of R1.
- Page openings and descriptions are written in the Chen register: what the page holds, then how its values arise.
- The default accent shown on the color page is the generator's default (violet today).
- The section stays out of the homepage; the menu, footer and manual search reach it.

### Sequencing

Phase 1 (U1, U2) builds the readers and their tests. Phase 2 (U3, U4) adds the section machinery, navigation, menu and the overview. Phase 3 (U5 to U8) writes the content pages. Phase 4 (U9, U10) adds the whole-section browser checks and the documentation. U2's document corrections wait for Q1 and Q2 only where those questions apply.

### Risks

- **The Resources panel grows a row.** `menu.spec.ts` requires the desktop Resources panel to fit without scrolling at 1440 × 900 and the phone menu's last entry to scroll into view at 390 × 500. Run both after U4; shorten the entry's description rather than change the panel CSS.
- **Fallback fonts rewrap headings.** `stability.spec.ts` compares line counts between the fallback faces and Geist. Reword a heading that changes; do not change the CSS.
- **SVG color scheme inside `<img>`.** `logo.svg` and `icon.svg` switch their fills with `prefers-color-scheme`. Inside an image that query may follow the operating system rather than the site's toggle. U8's test checks it in both directions; if the image ignores the toggle, the page says which preference the marks follow, and the header's existing logo shares the finding, recorded as an open item rather than worked around.
- **Borrowed prose written for implementers.** Sections such as Surfaces name CSS variables and shared primitives. That is the design system as maintained; the openings say who the rules are written for.
- **A document heading rename breaks the build.** This is intended (AE5); the message names the data file, so the fix is a one-line edit.

---

## Implementation Units

| Unit | Title | Key files | Depends on |
|---|---|---|---|
| U1 | Token, document and contrast readers | `packages/baander-dev-website/src/Content/` | — |
| U2 | Shared type roles and document corrections | `Command.php`, `typography.css`, `site.css`, `ui/DESIGN.md`, `ui/ICON-GUIDE.md` | U1; Q1, Q2 |
| U3 | Section data, loader and builder registration | `ui/site/design-system/index.php`, `DesignSystemPages.php`, `Command.php` | U1 |
| U4 | Section template, navigation, menu and overview | `ui/site/templates/design-system*.php`, `design-system.css`, `menu.php`, `SiteMenu.php` | U3 |
| U5 | Color and accents pages | `templates/design-system/color.php`, `accents.php` | U4 |
| U6 | Type, layout and motion pages | `templates/design-system/type.php`, `layout.php`, `motion.php` | U2, U4 |
| U7 | Iconography and writing pages | `templates/design-system/iconography.php`, `writing.php` | U2, U4 |
| U8 | Logo and website pages | `templates/design-system/logo.php`, `website.php`, `AssetPublication.php` | U4 |
| U9 | Search, sitemap and section-wide browser checks | `ui/site/tests/`, `page.php` | U5 to U8 |
| U10 | Documentation and records | `ui/site/README.md`, `ui/DESIGN.md`, `CONCEPTS.md`, `ROADMAP.md` | U9 |

### U1. Token, document and contrast readers

- **Goal:** The tooling reads every value and text the section shows and fails with a named cause when one is missing.
- **Requirements:** R4 to R10, R17, AE1, AE3, AE5.
- **Dependencies:** None.
- **Files:** Create `packages/baander-dev-website/src/Content/DesignTokens.php`, `DesignDocument.php`, `Contrast.php`, `packages/baander-dev-website/tests/DesignTokensTest.php`, `DesignDocumentTest.php`, `ContrastTest.php`, fixtures under `packages/baander-dev-website/tests/Fixtures/design-system/`.
- **Approach:**
  1. `DesignTokens::load($root)` parses `theme.css` blocks by selector into colors per mood and accent, finds the default accent from the `:not([data-accent])` selector, and reads the `:root` block's `--space-*`, `--radius-*` and `--duration-hover`. Moods and their labels come from `semantic-colors.json`, accents from `palette.json`; a block missing for any pair fails (KTD2).
  2. `DesignTokens::typeRoles(DesignDocument $design)` joins `type.json` with the Roles table, keyed by the role in its first column (KTD4).
  3. `DesignDocument::load($root, $path)` parses with CommonMark and `TableExtension`. `table(array $headings, list<string> $columns)`, `list(array $headings)` and `section(array $headings): string` resolve a heading path such as `['Layout', 'Border radius']`; each failure names the file and the path (KTD3). `section()` refuses links other than `https:` and raw HTML.
  4. `Contrast::ratio(string $first, string $second): float` and `Contrast::lowest(string $color, list<string> $surfaces)` follow `web.mjs` (KTD9).
  5. A small `cssDeclaration(string $css, string $selector, string $property)` on `DesignTokens` reads one declaration of an exact selector for KTD15.
  6. Run `.php-cs-fixer.tooling.php` on the touched files.
- **Patterns to follow:** `AssetPublication::theme()` for block parsing; `FeaturePages` for error messages that name the file; `WebsiteBuilder`'s CommonMark environment settings (`html_input` escape, no unsafe links).
- **Test scenarios:**
  - The repository's tokens yield 4 moods, 8 accents, 32 color maps and violet as the default accent; a fixture whose `:not([data-accent])` selector moves to blue yields blue.
  - Covers AE1. A fixture block without `--color-ring` fails naming the role, the mood and the accent.
  - The repository's Roles table yields ten roles whose Use text equals the table's cells; a fixture without the `label` row fails naming the role.
  - Covers AE3. A fixture `--duration-hover: 80ms` against a 60ms hover row fails naming both values.
  - Covers AE5. A missing heading fails naming file and path; a section with a relative link fails naming the link; an `https:` link renders.
  - `section(['Layout', 'Spacing'])` stops before `## Typography`.
  - `Contrast::ratio('#000000', '#ffffff')` is 21; the ratio is symmetric; known pairs from `web.mjs` (for example the dark mood's `#a78bfa` on `#000000`) match a Node computation recorded in the test.
  - Every mood and accent in the repository meets its three targets; a fixture accent text below 4.5 against one surface fails naming the surface.
- **Verification:** The three test files pass under `phpunit-tooling.xml.dist`; `composer analyse:tooling` and `composer check:tooling-format` are clean.

### U2. Shared type roles and document corrections

- **Goal:** Both type presentations read one source, and the documents the section borrows do not contradict each other.
- **Requirements:** R6, R11, R12, AE4.
- **Dependencies:** U1. The icon correction waits for Q2 and the writing rules for Q1.
- **Files:** Modify `packages/baander-dev-website/src/Command.php`, `ui/site/assets/typography.css`, `ui/site/assets/site.css`, `ui/DESIGN.md`, `ui/ICON-GUIDE.md`. Existing coverage: `ui/site/tests/website.spec.ts` (typography page).
- **Approach:**
  1. `Command::typography()` takes its roles from `DesignTokens::typeRoles()`; `TYPE_ROLES` keeps the samples only (KTD4).
  2. Move `.type`, the ten `.type-*` rules and the mono rule from `typography.css` to `site.css` unchanged (KTD14).
  3. `ui/DESIGN.md` anti-pattern table: "ease-out only, <120ms" contradicts the Motion table's 120ms rows; change it to "ease-out only, 120ms or less".
  4. Q2 answer: a menu holds at most three icons, and a destructive action's icon counts toward the three. Correct `ui/DESIGN.md` › Icons in menu lists to say so; `ui/ICON-GUIDE.md` › Rules already states the cap and gains the sentence that destructive actions take one of the three.
  5. Q1 answer: add a `## Writing` section to `ui/DESIGN.md`, before Motion, stating the interface voice and case rules the application's English strings already follow (`ui/web/src/shared/i18n/locales/en/translation.json`): plain and specific; the reader is *you*, the product says *we* only for what it sends; sentence case for titles, buttons and labels, uppercase only through the `label` role; errors say what happened and what to do next; progress text ends with the ellipsis character; no emoji and no exclamation marks; the product name is written Bånder. Quote two or three real strings as examples. The existing loading, error and empty state rules stay where they are.
  6. Run prose-fix on the changed document passages.
- **Test scenarios:**
  - Covers AE4. The browser spec reads the Roles table from `ui/DESIGN.md` and finds each Use cell as the matching role's description on `/typography/` and on `/design-system/type/`.
  - Every `.type-*` sample on `/typography/` keeps its computed size, weight and line-height after the move (existing typography checks).
- **Verification:** The typography specs in `website.spec.ts` pass unchanged.

### U3. Section data, loader and builder registration

- **Goal:** The build produces ten public page descriptors for the section from one data file.
- **Requirements:** R1, R2, R14, R17.
- **Dependencies:** U1.
- **Files:** Create `ui/site/design-system/index.php`, `packages/baander-dev-website/src/Content/DesignSystemPages.php`, `packages/baander-dev-website/tests/DesignSystemPagesTest.php`. Modify `packages/baander-dev-website/src/Command.php`.
- **Approach:**
  1. The data file shape: overview `title`, `description`, `opening`; `pages` in order, each with `slug`, `title`, `summary`, `description`, `opening` and `borrow` (a list of document path and heading path pairs). Read with `FeatureFile::read` and `FeatureField` types (KTD1).
  2. `DesignSystemPages::load($root, DesignTokens, DesignDocument ...)` returns descriptors: template `design-system`, URL `/design-system/` or `/design-system/<slug>/`, trail `[['name' => 'Design system', 'url' => '/design-system/']]` for the nine pages, `stylesheet` `design-system.css`, the page's `sources` (KTD17), and data holding the navigation, the borrowed HTML and the page's values.
  3. Checks: a body template exists for every slug, the composed title is at most 60 characters, a description at most 155 and none repeated, no unknown key, every borrowed section resolves.
  4. `Command` adds the descriptors to the public pages and the paths of KTD17 to `SITE_SOURCES`.
- **Test scenarios:**
  - The repository's data file yields ten descriptors in R1's order with the URLs of R1.
  - A subpage's metadata title is `Color — Design system — Bånder` and its breadcrumbs are Bånder, Design system.
  - A slug without a body template fails naming the slug; an unknown key fails naming it; a 156-character description fails.
  - Covers AE5. A borrowed heading that does not resolve fails naming the data file, document and heading.
  - The sitemap lists each page dated by the newest commit among its own sources, using a `SourceHistory` fixture.
- **Verification:** As U1.

### U4. Section template, navigation, menu and overview

- **Goal:** The overview renders with the section navigation, and the menu leads to it.
- **Requirements:** R1 to R3, R13, R14, R16.
- **Dependencies:** U3.
- **Files:** Create `ui/site/templates/design-system.php`, `ui/site/templates/design-system/overview.php`, `ui/site/assets/design-system.css`, `ui/site/tests/design-system.spec.ts`. Modify `ui/site/menu.php`, `packages/baander-dev-website/src/Content/SiteMenu.php`, `packages/baander-dev-website/tests/SiteMenuTest.php`.
- **Approach:**
  1. `design-system.php` per KTD5: breadcrumb from `meta->breadcrumbs`, the section navigation with `aria-current="page"` on the current entry and the "Related" typography link, the article with its Pagefind label (KTD6), the page's `<h1>` and opening, then the body template.
  2. `overview.php`: the opening, the borrowed Philosophy section and an ordered list of the nine pages with title links and summaries.
  3. `design-system.css`: swatches, value-table regions and the logo grid; no gradients or shadows; every interactive element keeps the site's `:focus-visible` outline.
  4. Menu entry and section marking per KTD16.
- **Test scenarios:**
  - Resources › Design system opens `/design-system/`, and the entry is marked `page` there and `true` on `/design-system/color/`, with the Resources panel marked.
  - The overview links to exactly the section pages the built sitemap lists under `/design-system/`, in navigation order.
  - On every section page the navigation marks only that page as current and lists Typography under Related with `href="/typography/"`.
  - Canonical URL, Open Graph URL, title and the breadcrumb trail match each page's URL and title.
  - The desktop Resources panel still fits at 1440 × 900 and the phone menu's last entry still scrolls into view at 390 × 500 (existing `menu.spec.ts`).
  - `SiteMenuTest` lists Developer guide, Concepts, Design system, Typography under "Build on it".
- **Verification:** `make website-build` succeeds; `design-system.spec.ts` and `menu.spec.ts` pass.

### U5. Color and accents pages

- **Goal:** Every documented color role and every accent derivation is visible with its value and contrast.
- **Requirements:** R4, R5, R15, R16, AE1, AE2.
- **Dependencies:** U4.
- **Files:** Create `ui/site/templates/design-system/color.php`, `accents.php`. Modify `ui/site/design-system/index.php`, `ui/site/tests/design-system.spec.ts`.
- **Approach:**
  1. Color: one row per variable named in the Token and Use table and the Surfaces list (a row such as `--color-primary` / `--color-primary-foreground` becomes two variables sharing one use), columns for the four moods in `semantic-colors.json` order, each cell a swatch and code (KTD7). A note beside the table gives the site's softer dark foreground and says the swatches show the application's values (KTD8).
  2. Accents: the eight identities as swatches, then one table per mood with a row per accent: identity, primary with the label-on-fill ratio, accent text with its lowest ratio, ring with its lowest ratio, and the targets stated once above the tables (KTD9). The default accent's row is labelled default.
  3. Value cells carry `data-pagefind-ignore` (KTD6).
- **Test scenarios:**
  - Every swatch's computed background equals the hex code beside it, in both site themes.
  - The color table has one row per documented variable and four mood columns; the dark foreground cell shows the application's `#f0f0f2`, and the note shows `#e6e6e9`, which is the page's own computed text color in the site's dark theme.
  - Covers AE2. The warm violet accent-text row shows `#6e5ca5`; a ratio the spec recomputes in the browser from the row's hex codes equals the printed one, for every row.
  - Each value region scrolls inside itself at 390 px and the page does not scroll sideways; the region is reachable and visibly focused by keyboard.
- **Verification:** As U4.

### U6. Type, layout and motion pages

- **Goal:** The role, scale and motion values are visible with their documented uses.
- **Requirements:** R6 to R8, R15, R16, AE3, AE4.
- **Dependencies:** U2, U4.
- **Files:** Create `ui/site/templates/design-system/type.php`, `layout.php`, `motion.php`. Modify `ui/site/design-system/index.php`, `ui/site/tests/design-system.spec.ts`.
- **Approach:**
  1. Type: a table of the ten roles (role, desktop, phone, weight, tracking, line-height, use) and a live sample per role using the `.type-*` classes; the borrowed role rules; links to the three typography sections (KTD12).
  2. Layout: spacing and radius tables with matched uses (KTD10), then the borrowed sections, and a link to the player feature page for the well as it appears in the application.
  3. Motion: the Motion table rendered from the parsed rows and the borrowed rules list; the build has already checked the hover row against the token. Below them, a table of the four `--duration-*` tokens read from `theme.css`, each with where it is used, found at build time by searching the site and application stylesheets: `hover` in the application's hover states, `fast` (150ms) in the website's disclosure and chart transitions (`site.css`, `bac.css`), `normal` and `slow` in no stylesheet. The page states the 120ms limit applies to application interface motion, as `ui/DESIGN.md` says.
- **Test scenarios:**
  - Each type sample's computed font size equals the row's desktop value at 1440 px and its phone value at 390 px; its weight, letter-spacing and line-height match the row.
  - The `timecode` and `mono-sm` samples render in Geist Mono with tabular numerals.
  - The radius table names "Cards" beside `--radius-lg` (0.75rem, 12px) and "No documented use" beside each radius no list item names.
  - The motion table's rows equal the Motion table of `ui/DESIGN.md` in order.
  - Each type link to `/typography/#…` resolves to a heading (the build's fragment check).
- **Verification:** As U4.

### U7. Iconography and writing pages

- **Goal:** The icon and content rules are published as the documents state them.
- **Requirements:** R11, R12, R15.
- **Dependencies:** U2 (Q1, Q2), U4.
- **Files:** Create `ui/site/templates/design-system/iconography.php`, `writing.php`. Modify `ui/site/design-system/index.php`, `ui/site/tests/design-system.spec.ts`.
- **Approach:**
  1. Iconography: the borrowed sections of the page table, in that order. Component names stay as code; no glyphs (Q4).
  2. Writing: the borrowed `## Writing` section that U2 adds, then the loading, error and empty state rules and the one-item-per-element rule.
- **Test scenarios:**
  - Every table borrowed from `ui/ICON-GUIDE.md` renders with its header row and as many rows as the document has.
  - The writing page's text contains each rule of the borrowed sections, and no other list items.
- **Verification:** As U4.

### U8. Logo and website pages

- **Goal:** The marks and the site's departures are shown from their files.
- **Requirements:** R9, R10, R16.
- **Dependencies:** U4.
- **Files:** Create `ui/site/templates/design-system/logo.php`, `website.php`. Modify `packages/baander-dev-website/src/Assets/AssetPublication.php`, `packages/baander-dev-website/tests/PublicationTest.php`, `ui/site/design-system/index.php`, `ui/site/tests/design-system.spec.ts`.
- **Approach:**
  1. `AssetPublication::assets()` copies the three SVG files; a `LIGHT_MOOD` constant replaces the literal `balanced` in `theme()` (KTD11, KTD15). Q5 answer: `ui/site/templates/head.php` links `/assets/site/favicon.svg` as the site icon instead of `logo.svg`.
  2. Logo: a figure per file at each size of KTD11, with its size comment as caption and where the file is used (the application sidebar and sign-in page use `logo.svg`; the Electron application icon is rendered from `icon.svg`, as `docs-book/part-2-developer-guide/development/frontend-development.md` states).
  3. Website: the departures table of KTD15 and the borrowed "Static public website" section.
- **Test scenarios:**
  - The published artifact holds `assets/site/icon.svg` and `favicon.svg` byte-identical to `ui/web/public/` (`PublicationTest`).
  - Every built page's `<link rel="icon">` points to `/assets/site/favicon.svg`.
  - Every logo image loads and renders at its stated size.
  - With the operating system preferring dark and the site toggled to light, and the reverse, the spec records which variant `logo.svg` paints (see Risks) and the page's statement matches it.
  - The website page's body-text row equals the computed `font-size` and `line-height` of a paragraph on `/docs/operator-guide/`; the eyebrow row equals a computed `.eyebrow`; the two toggle states set `data-theme` to `dark` and to the constant's mood.
- **Verification:** As U4.

### U9. Search, sitemap and section-wide browser checks

- **Goal:** The section holds the site's guarantees and is found by search.
- **Requirements:** R14 to R16.
- **Dependencies:** U5 to U8.
- **Files:** Modify `ui/site/templates/page.php` (search status line), `ui/site/tests/design-system.spec.ts`, `ui/site/tests/stability.spec.ts`, `ui/site/tests/website.spec.ts`.
- **Approach:**
  1. The search status line names the design system pages beside the guides and the reference pages.
  2. Read the section's URLs from the built sitemap, as `features.spec.ts` does, and run every check below over all ten pages.
- **Test scenarios:**
  - A manual-page search for a term only the layout page's prose holds, such as "Context panel width", returns the layout page labelled "Design system"; a search for a hex code from the color table returns no design system result.
  - At 390 px and 1440 px in both site themes no page scrolls sideways and nothing overflows its section.
  - With JavaScript disabled every page's tables, samples and navigation are readable.
  - Every character on every page renders from a bundled Geist or Noto face, and no request leaves the origin.
  - Every page passes the font-arrival and slow first-paint checks of `stability.spec.ts`.
  - The sitemap lists the ten URLs and no other `/design-system/` URL.
- **Verification:** The full site browser suite passes.

### U10. Documentation and records

- **Goal:** A maintainer knows where the section's text and values come from, and the delivery is recorded.
- **Requirements:** R11, R12.
- **Dependencies:** U9.
- **Files:** Modify `ui/site/README.md`, `ui/DESIGN.md`, `CONCEPTS.md`, `ROADMAP.md`.
- **Approach:**
  1. `ui/site/README.md`: a "Design system pages" section: the data file, the body templates, which inputs each page reads, that a renamed heading in a borrowed document fails the build, that the pages are indexed for search, and the sitemap sources. Update the search sentence that lists the indexed pages.
  2. `ui/DESIGN.md` › Static public website: one paragraph that the design system section publishes this document's rules and the generated tokens, reads both at build time and shows the application's color values.
  3. `CONCEPTS.md`: "Mood" and "Accent" under a new "Interface color" heading beside "Interface material", and "Design system page" under "Public website".
  4. `ROADMAP.md`: a "Website design system section" entry with the delivered pages, suite counts, static gates, the commits and the open items left by Q3 and the logo color-scheme finding.
  5. Run prose-fix on every passage written or changed.
- **Test expectation:** none. Documentation only.
- **Verification:** Every roadmap figure is stated with its command and commit.

---

## Verification Contract

| Gate | Command | Applies to | In this container |
|---|---|---|---|
| Design tokens current | `(cd ui/design-tokens && node build.mjs --check && node --test tests/*.test.mjs)` | All (no token change expected) | Runs: "Design tokens are current", 76 of 76 pass |
| Site dependencies | `(cd ui/site && corepack yarn install --immutable)` | All | Runs with `COREPACK_NPM_REGISTRY=https://registry.npmjs.org NODE_USE_ENV_PROXY=1`; `repo.yarnpkg.com` is blocked by the proxy |
| Site script tests | `(cd ui/site && node --test scripts/*.test.mjs)` | Regression | Runs with `PLAYWRIGHT_BROWSERS_PATH` set as below |
| Tooling tests | `php vendor/bin/phpunit --configuration phpunit-tooling.xml.dist packages/baander-dev-website/tests` | U1, U3, U4, U8 | Runs: PHP 8.5.13 with Swoole from the ondrej/php archive |
| Tooling static analysis | `composer analyse:tooling` | U1 to U4, U8 | Runs: PHP 8.5.13 |
| Tooling format | `composer check:tooling-format` | U1 to U4, U8 | Runs: PHP 8.5.13 |
| Website build | `PLAYWRIGHT_BROWSERS_PATH=<dir> make website-build` | U2 to U10 | Runs: 297 manual pages built on 2026-11-27 before any change |
| Site browser suite | `(cd ui/site && PLAYWRIGHT_BROWSERS_PATH=<dir> corepack yarn test:browser)` | U2, U4 to U9 | Runs against Chromium 1243 through the mapping below; the CI image's Chromium 1248 is authoritative |
| Preservation audit | `php .agents/skills/prose-fix/scripts/audit_rewrite.php <baseline> <edited>` | U2, U10 | Runs: PHP 8.5.13 |
| Whitespace | `git diff --check` | All | Runs |

`<dir>` is a scratch directory whose `chromium-1248`, `chromium_headless_shell-1248` and `ffmpeg-1011` entries are symlinks to the `-1243` and `ffmpeg-1011` builds in `/opt/pw-browsers`; nothing is installed or changed under `/opt`. A browser check that fails only against 1243 is reported, not fixed around.

The authoritative gates run in the CI image `baander-runner:f46c6148ad63d4b84a401383457dad6e84b3ea87`. The Docker daemon starts here, but pulling that image failed: the proxy refuses `pkg-containers.githubusercontent.com`, and Docker Hub, `mirror.gcr.io` and `public.ecr.aws` answered with rate limits on 2026-11-27. A later pull of a PHP 8.5 image with the host's Node and Chromium mounted could run the build and the tooling tests; the browser suite would still need Chromium 1248.

## Definition of Done

- Every unit's verification holds and the gates above pass in the CI image; a gate that could not run is reported with the reason.
- The Q1, Q2 and Q5 answers are applied.
- A code review of the whole diff has run and its justified findings are applied. The `ce-*` skills are not installed in this environment; the review follows `ce-code-review`'s intent by hand.
- `ROADMAP.md` records the delivery with its checkpoint and open items.
- No source, test, comment or page text cites a plan identifier.

## Resolved Questions

The user answered Q1, Q2 and Q5 on 2026-11-27. Q3, Q4 and Q6 take the plan's defaults; the user can still overrule them.

- Q1. **Writing rules.** Add a Writing section to `ui/DESIGN.md` with the voice and case rules the application's English strings follow; the writing page borrows it (U2, U7).
- Q2. **Icons per menu.** At most three icons per menu, a destructive action's icon included; `ui/DESIGN.md` is corrected to match `ui/ICON-GUIDE.md` (U2).
- Q3. **Duration tokens.** Default: keep all four. `--duration-fast` is in use on the website (`site.css`, `bac.css`); `normal` and `slow` are unused, and their removal is separate work only with the user's consent. The motion page lists all four with where each is used (U6).
- Q4. **Icon glyphs.** Default: names only; no icon package is added to `ui/site`.
- Q5. **Favicon.** Switch the site's favicon to `favicon.svg` (U8).
- Q6. **Two type scales.** Default: keep both; both read the shared reader of KTD4.
