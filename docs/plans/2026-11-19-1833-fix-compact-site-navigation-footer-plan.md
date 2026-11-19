---
title: "fix: Compact site navigation and align the footer"
date: 2026-11-19
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: code
---

## Goal Capsule

Visitors can scan the website navigation without scrolling through long feature descriptions, and find footer links in a clear layout. Use grouped feature links and a deliberate footer grid. This is one local change set; the lead owns implementation, verification and local commits. Push only on request. No new domain concepts are introduced.

## Product Contract

The user reports that Product → Features occupies too much scrollable space and that the footer looks awkward. `ui/site/templates/header.php` displays every feature summary in a single column. `ui/site/assets/site.css` caps that panel at 30rem. The footer repeats the nested hierarchy in wrapping flex rows.

### Requirements

- R1. At desktop sizes (1024×768 and 1440×900), every Product link is visible without scrolling the panel. Feature links retain their Listening, Operating and Developing groups.
- R2. The footer has aligned link columns, a distinct brand area and a separate attribution/source row, retaining every existing destination.
- R3. Small and short screens retain access to every link without horizontal overflow. Keyboard dismissal, current-page markers, themes and navigation without JavaScript keep working.

Only site navigation, footer presentation and their tests are in scope. Feature content, menu destinations and application UI stay outside this change.

## Planning Contract

- KTD1. Keep `SiteMenu` as the shared source. In the header template, render multi-group columns as a grid: the overview spans the grid and audience groups use compact text links without their page summaries. Keep descriptions on overview and resource entries. This addresses the height at its source rather than shrinking the scroll box.
- KTD2. Flatten each footer panel's entries into an aligned list, with Product using two columns on desktop. Keep its Product and Resources headings; audience headings remain in the header. Move Source beside attribution below the grid.
- KTD3. Preserve native disclosures and `site-menu.js`. Keep viewport-constrained scrolling for small screens and stack groups when needed. Use existing theme tokens and typography.

Evidence: `packages/baander-tooling/src/Website/SiteMenu.php` supplies ordered columns/groups; `ui/site/features/index.php` supplies nine feature pages. `ui/site/tests/menu.spec.ts` currently requires desktop scrolling and checks footer grouping, so those assertions must change. Commit `ca22fd7b6` introduced the height cap; `914bce1fd` introduced shared menu data. No directly applicable solution note was found under `docs/solutions/`.

## Implementation Units

### U1. Compact navigation and align the footer

Change `ui/site/templates/header.php`, `ui/site/templates/footer.php` and `ui/site/assets/site.css` together. Update `ui/site/tests/menu.spec.ts` to check desktop fit, shared destinations and responsive footer alignment, and `ui/site/tests/website.spec.ts` to find Source in the footer's new bottom row. Retain keyboard, current-page, short-screen and no-JavaScript coverage. Verify both themes and desktop/mobile screenshots. This is presentation work, so browser checks replace test-first development.

### U2. Record verification

After U1, record the delivered behavior and actual check counts in `ROADMAP.md`. Update the static website section of `ui/DESIGN.md`, whose existing requirement for a description on every link conflicts with compact feature links. Review the implementation for simplicity and regressions before the local commit. No other unit depends on this documentation.

## Verification Contract

Run `make website-build`, then the dedicated site browser suite with `corepack yarn test:browser` in `ui/site`. Its disposable `website.baander.app` server is documented in `ui/site/README.md`; it does not require an application preview. Run the site's TypeScript check and `git diff --check`. Inspect the Product panel and footer at desktop and phone widths in dark and light themes. Resolve any failure caused by this change; report unrelated or unavailable checks explicitly.

## Definition of Done

All three requirements are met, existing destinations remain reachable, the site builds, browser checks pass, and the roadmap records the evidence. Changes are reviewed and committed locally; nothing is published.
