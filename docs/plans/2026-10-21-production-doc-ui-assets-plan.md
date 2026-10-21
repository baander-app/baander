---
title: Production documentation UI asset removal - Plan
type: fix
date: 2026-10-21
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: code
---

# Production documentation UI asset removal - Plan

## Goal Capsule

**Objective:** Reduce unused material shipped with the application while preserving its supported API documentation outputs.

**Means:** Omit the bundled documentation UI assets from production dependency installation (KTD1).

**Authority and delivery:** The Product Contract owns behavior; the Planning Contract owns implementation choices within it. Execute on `plan/production-doc-ui-assets`, commit completed units locally, and leave pushing to a separate request. Stop acceptance for unexplained component drift or changed documentation outputs.

## Product Contract

### Problem Frame

The retained production inventory at `var/test-runs/production-composer-cache-20261020/production/inventory.json` contains 14 files totalling 11,515,432 bytes under `vendor/nelmio/api-doc-bundle/public`. These are browser UI resources. `config/routes/nelmio_api_doc.yaml` exposes `/api/doc.json`; its browser UI route is commented out. The application documentation controller requests JSON, and `src/Command/ExportOpenApiSpecCommand.php` supports JSON and YAML exports. The checked application, templates and frontend have no consumer for these UI resources; the bundle's own Twig UI templates reference them.

This is one bounded application redistribution checkpoint. The current [application review](../../third_party/inventory/application-review.md) and [roadmap](../../ROADMAP.md) retain the other unresolved observations.

### Requirements

**Packaging**

- R1. Newly built `production` and inherited `worker` images must contain no entries under `vendor/nelmio/api-doc-bundle/public`.
- R2. Retain the installed bundle's PHP implementation and LICENSE, installed package versions and references, and native components.
- R3. Development dependency installation must retain its existing UI assets.

**Documentation behavior and evidence**

- R4. Preserve the supported `/api/doc.json` response and both JSON and YAML command exports.
- R5. Collect and verify inventories for both rebuilt targets from committed source with `.dockerignore` applied, and record actual identities, measurements and remaining gaps.
- R6. Preserve the disposable isolation of the existing worker smoke checks and protect local user data.

### Acceptance Examples

- AE1. The new asset-absence assertion rejects a retained worker that contains the assets and passes on its replacement. Covers R1.
- AE2. Both replacement inventories omit the exact asset prefix while component comparisons establish R2; the development installation path is unchanged. Covers R1–R3, R5.
- AE3. Runtime checks against the retained and replacement images produce equivalent supported documentation outputs, and the existing replacement worker smoke passes. Covers R4, R6.
- AE4. The review and roadmap distinguish historical observations from this measured checkpoint and leave redistribution qualification open. Covers R5.

### Scope Boundaries

Do not change Composer dependencies, routes, controllers, export formats, application behavior, collector policy, PostgreSQL persistence or Registry storage. Removing other vendor paths, qualifying all embedded dependencies, and adding a new test framework are outside this task. No new project domain term is introduced.

## Planning Contract

- KTD1. Remove only `vendor/nelmio/api-doc-bundle/public` in the existing production Composer `RUN`, after successful installation and autoload generation. This keeps the resources out of the dependency layer itself; `worker` inherits the result. The development stage has its own installation and receives no cleanup. Governs R1–R3.
- KTD2. Extend the existing PHP artifact probe in `scripts/test-worker-production-container.sh` with the exact asset-directory absence assertion. Boot the production kernel in a disposable container, using networking disabled where possible or an isolated disposable Redis network when the configured cache requires it, match `/api/doc.json` through the actual router, and invoke its configured controller with the matched request. Compare that JSON with actual JSON/YAML command exports and compare each output before and after removal. Label this a configured-controller invocation; it does not prove an authenticated HTTP response. Use the existing isolated operator smoke rather than adding fixtures or a test framework. Governs R1, R4, R6.
- KTD3. Follow [the packaged inventory procedure](../../dev-docs/application-packaged-inventory.md) for both actual targets. Compare package versions/references and native identities/hashes, not counts alone, against the retained evidence. Investigate every difference outside the removed assets, this change's source files and explained supplemental notices. Governs R2, R5.
- KTD4. Add a distinct measured checkpoint to `third_party/inventory/application-review.md` and `ROADMAP.md`; preserve the historical JSON review and inventories. State precisely which paths were removed and which licensing observations remain. Governs R5.

### Assumptions

The retained images and their evidence remain available for before/after checks. The 14-file measurement is the baseline observation, not a predicted final image-size reduction. Other build inputs can drift; unexplained differences block acceptance. No external research is needed for this local packaging choice, and no competing mechanism needs a bake-off.

## Implementation Units

### U1. Omit unused production UI resources and verify supported outputs

**Goal:** Deliver R1–R4 and preserve R6.

**Dependencies:** None.

**Files:** `Dockerfile`, `scripts/test-worker-production-container.sh`.

**Approach:** Apply KTD1 and KTD2. Add the probe first and demonstrate its asset-specific failure against the retained worker. Read the existing documentation endpoint and exporter setup to choose the smallest real runtime checks, then compare retained and replacement outputs. Commit the implementation locally before creating source-labelled replacement images.

**Test scenarios:** AE1 and AE3. Distinguish a missing image or infrastructure failure from the intended assertion failure. Parse the configured controller JSON and both actual command exports, compare their represented specification content and before/after outputs, and retain logs. Verify the installed PHP and LICENSE remain. Inspect the independent development installation path for R3.

**Verification:** Shell syntax, the targeted red assertion, both image builds, documentation output comparisons and the existing production worker runner on the immutable replacement image ID.

### U2. Record both image measurements and acceptance limits

**Goal:** Establish R2 and R5 with reviewable evidence.

**Dependencies:** U1 committed and its source revision available.

**Files:** `third_party/inventory/application-review.md`, `ROADMAP.md`.

**Approach:** Apply KTD3 and KTD4. Build from a clean committed directory context, collect both evidence bundles under a fresh ignored `var/test-runs/` directory, and retain full logs there. Reuse the prior build and comparison helpers where suitable. Run prose-fix on changed documentation.

**Test scenarios:** AE2 and AE4. Verify both bundle integrity checks, exact-prefix absence, retained PHP/LICENSE, source labels and installed/native component identity. Explain actual drift rather than assuming totals decrease by exactly 14 files. The checkpoint remains explicitly unqualified.

**Verification:** Both collectors and bundle verifiers, component comparison, documentation preservation audit and `git diff --check`.

## Verification Contract

Run `bash -n scripts/test-worker-production-container.sh`; demonstrate AE1 on the retained worker, then build `production` and `worker` serially from committed source. Reuse the procedure and helpers cited in U2 to collect and verify both bundles. Run `BAANDER_TEST_IMAGE=<replacement-worker-id> bash scripts/test-worker-production-container.sh` and the real documentation checks described in U1. Keep any runtime configuration disposable, mock or isolate external services, and use `baander.app` for test hostnames.

Acceptance requires equivalent supported documentation content under R4, exact asset-prefix absence, retained PHP/LICENSE, and explained installed/native component comparisons under R2. Run the prose-fix audit for revised documentation and `git diff --check`. Broad application unit/static-analysis suites are outside this packaging-only change; record the actual focused checks and their outcomes. Serialize builds and shared-state smoke checks.

## Definition of Done

U1 and U2 satisfy AE1–AE4, justified review findings are applied, and the roadmap records measured results with their verification checkpoint. The review preserves historical observations and remaining gaps. Changes are committed locally on the named branch without a push or publication.
