---
title: Remove TypePHP integration - Plan
type: feat
date: 2026-11-20
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: code
---

# Remove TypePHP integration - Plan

## Goal Capsule

Remove the TypePHP build and runtime integration after the measured comparison, while retaining its historical results only in the repository. Implement on `feat/remove-typephp`, commit locally and do not push. The user has authorized implementation. The lead owns integration and `ROADMAP.md`.

## Product Contract

### Summary

Ordinary PHP remains the application runtime. The repository stops supplying the TypePHP toolchain, executable adapters, patches, qualification fixtures and benchmark runner. The repository retains the comparison as historical evidence; the website removes it.

### Problem Frame

The measured TypePHP package did not improve median performance in the three recorded workloads: console discovery took 3.191 times as long, operator help 1.042 times and the sustained loop 1.066 times. These observations belong to the single run documented in `tools/typephp/benchmarks/README.md`, not a general performance claim. The user has now requested its removal.

### Requirements

- R1. Remove every tracked file under `tools/typephp/` and `docker/typephp/`, plus active build targets, runtime hooks and package documentation advertising that integration.
- R2. Preserve ordinary PHP behavior and the generic correctness fixes discovered during qualification. Do not revert application fixes merely because their history mentions TypePHP.
- R3. Retain the measured report and all five raw evidence files under `docs/benchmarks/2026-11-20-typephp/`, with unchanged evidence bytes and the same raw basenames beneath `results/`. Remove the `/runtime-performance/` site page and every navigation, documentation and test reference that exposes it.
- R4. Preserve unrelated worktrees, Docker images and caches, ignored build output and local user data. Record delivered scope and verification in `ROADMAP.md`.

### Scope Boundaries

Remove repository support, not local artifacts or historical records. Prior plans, solution records and roadmap checkpoints remain historical evidence; the new roadmap entry records retirement. No application optimization, ordinary runtime redesign, dependency refresh, database operation, full backend requalification, `ui/rn` or Essentia work belongs to this removal.

## Planning Contract

### Key Technical Decisions

- KTD1. Delete the dedicated integration rather than introduce a disabled mode. Source and history inspection shows the Makefile TypePHP block and its isolated compiler dependencies belong to this experiment. The root Composer dependency graph is unchanged. Verify any non-keyword runtime hook before removing it; keep ordinary-PHP application behavior and its tests.
- KTD2. Move the report to `docs/benchmarks/2026-11-20-typephp/README.md` and the five files currently in `tools/typephp/benchmarks/results/2026-11-20/` directly beneath the archive's `results/`. Preserve raw JSON and compressed pilot bytes, hashes, measurements and qualifiers. Replace current executable/reproduction instructions with historical source links to the committed harness, and adjust report-relative evidence links. No live runner remains.
- KTD3. Remove `ui/site/reference/runtime-performance.php`, the runtime-performance menu entry, its site README reference, dedicated browser checks and the exact menu expectation added for this page. The archive remains repository-only. The user explicitly corrected the earlier historical-page proposal: no TypePHP page remains on `ui/site`.

### Source and History Evidence

`tools/typephp/README.md` describes the embedded-bytecode ZendVM path and its runtime qualification. The benchmark implementation and record landed in `861ba672f`, `694d9235e` and the untracked-input correction in `170a61182`. Inspection of active references finds the Makefile TypePHP targets, four local-package README links and website report paths. The removal audit confirms that loader/runtime dispatch and prepend hooks live entirely inside the dedicated trees; there is no root Composer, CI or application runtime hook to remove.

Preserve ordinary-PHP fixes in `d11e841d7` (storage-path resolution), `bf1a0c91c` (stream/coroutine cancellation), `1b595b256` (WebSocket bounds and OAuth behavior), and fixture corrections in `b3bfcfd8c`, `07e38b019` and `68f576dd5`. Their histories establish application or fixture correctness beyond this toolchain.

The evidence archive records retirement while continuing to explain what the original measurement did. Removing the reference descriptor also removes the page from the existing builder, search and sitemap; remove its menu entry in the same change to avoid a dangling route.

## Implementation Units

### U1. Remove dedicated integration and active references

**Covers:** R1, R2, R4; KTD1. **Dependencies:** establish the evidence move in U2 before deleting its source files.

**Files:** all tracked `tools/typephp/` and `docker/typephp/` files; the TypePHP block in `Makefile`; TypePHP embed claims in `packages/baander-http-client/README.md`, `packages/baander-redis-rate-limiter/README.md`, `packages/baander-image-processing/README.md` and `packages/baander-media-metadata/README.md`; any proven dedicated runtime hooks found by the audit. Lead owns shared build configuration and `ROADMAP.md`.

Delete compiler manifests/lock, patches, adapters, qualification code, benchmark harness and dedicated tests together. Do not run recursive deletion over ignored directories: remove tracked paths and preserve local artifacts. Check before editing `.gitignore` or root Composer files; current inspection finds no TypePHP-specific entries there. Retain the storage-boundary canonical-resolution retry, post-yield audio cancellation check and their application tests if present; these fixed ordinary PHP behavior as well.

**Verification:** confirm no tracked files remain beneath the two removed trees; inspect `make help` for removed targets; search active code/config/docs for dangling TypePHP build paths and classify remaining historical references. Verify the ordinary application source and root Composer files are unchanged. No new deletion-only unit tests are needed. If a runtime hook requires an application change, verify it with its existing focused tests before acceptance.

### U2. Archive evidence and remove the website comparison

**Covers:** R3, R4; KTD2, KTD3. **Dependencies:** move evidence before U1 deletion; verify after integration.

**Files:** new `docs/benchmarks/2026-11-20-typephp/README.md` and `results/{manifest.json,measurements.json,pilot-manifest.json,pilot-measurements.json.gz,source-validation.json}`; `ui/site/reference/runtime-performance.php`, `ui/site/menu.php`, `ui/site/README.md`, `ui/site/tests/website.spec.ts`, `scripts/tests/php/Website/SiteMenuTest.php`; affected existing PHP website tests and `ROADMAP.md`.

Keep the measured results and methodological limitations, add retirement context, remove instructions that imply executable support still exists, and point original collector/driver references to their committed source history. Delete the site descriptor, menu entry, site README reference and dedicated browser comparison checks. Remove the exact menu-inventory expectation for the retired page; keep coverage of remaining pages. Apply prose-fix to changed prose. Record archive locations, byte-preservation evidence and exact check counts in the roadmap.

**Verification:** compare all five moved evidence hashes with their pre-move values, including the compressed pilot. Build the site and run affected PHP tooling tests, rendering fixtures and the dedicated website browser suite. Check that the page, menu, search and sitemap no longer expose the comparison, and that the repository archive links still resolve to retained evidence or historical source. Run simplification and code review, apply justified findings, then commit locally. A documentation-only path move needs no benchmark rerun.

## Verification Contract

Use the website runners documented in `ui/site/README.md`, applicable formatting/static checks for changed PHP, raw-file hash comparison and a targeted reference audit. `git diff --check` must pass. Record suite counts and any pre-existing failures inline in `ROADMAP.md`. Repeat backend tests only if the audited removal changes application runtime behavior.

## Definition of Done

No tracked TypePHP integration remains in its dedicated trees or active build/runtime paths. Ordinary PHP behavior and generic correctness fixes remain intact. All original evidence bytes survive in the archive, the website no longer publishes the comparison, and the roadmap records the removal and checks. No local artifact cleanup is implied. There is no planning-time blocker.

## Sources

- User direction: remove TypePHP after reviewing the recorded comparison; remove it from `ui/site` as explicitly corrected, retaining measured evidence only in the repository.
- `docs/plans/2026-11-19-1159-feat-typephp-setup-plan.md` and `docs/plans/2026-11-20-1632-feat-typephp-performance-comparison-plan.md`: historical scope and qualification decisions.
- `tools/typephp/README.md`, `tools/typephp/benchmarks/README.md`, `Makefile`, four package README references: inspected integration and measured result.
- `ui/site/README.md`, `ui/site/reference/runtime-performance.php`, `ui/site/tests/website.spec.ts`: existing publication contract and checks.
