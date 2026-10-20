---
title: Production Composer cache removal - Plan
type: fix
date: 2026-10-20
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: code
---

# Production Composer cache removal - Plan

## Goal Capsule

**Objective:** Reduce the application's unresolved redistribution contents by excluding downloaded dependency archives that its runtime does not use.

**Means:** Remove Composer's download cache during the production dependency layer and verify the inherited worker image.

**Boundary:** This change addresses one observed packaging gap. Redistribution qualification remains open for the other recorded observations.

## Product Contract

### Problem and scope

The accepted application inventories contain 173 regular files totalling 15,057,283 bytes under `/home/www-data/.cache/composer`: 148 archives containing 14,951,128 bytes, 13 scripts/tools and 12 other files. These downloaded inputs remain separate from installed `vendor/` dependencies. The production Dockerfile runs Composer as `www-data`; `worker` extends `production`. The runtime has no consumer for these cached downloads.

[ROADMAP.md](../../ROADMAP.md) retains application redistribution acceptance as open. The [application review](../../third_party/inventory/application-review.md) records historical images built from `1066d203`; subsequent supplemental notice checks do not replace those image observations.

### Packaging requirements

- R1. Newly built `production` and `worker` images must contain no Composer cache at `/home/www-data/.cache/composer`.
- R2. Preserve installed Debian and Composer package membership and the native/tool components when removing the cache.

### Verification and evidence requirements

- R3. Demonstrate the cache regression against the retained accepted worker image before accepting its rebuilt replacement.
- R4. Collect and verify inventories for both rebuilt targets from clean committed source using a Docker directory context with `.dockerignore` applied.
- R5. Record actual image identities, measured contents, verification results and remaining gaps without overwriting historical observations or claiming redistribution qualification.
- R6. Protect local user data by retaining the existing worker smoke runner's disposable isolation.

### Acceptance examples

- AE1. The retained worker fails the new cache assertion; the rebuilt worker passes it and the existing production worker smoke. Covers R1, R3, R6.
- AE2. Both new inventories show no path under the Composer cache and retain installed component coverage. Covers R1, R2, R4.
- AE3. A reviewer can distinguish the historical images from the new cache-removal checkpoint and inspect all remaining gaps. Covers R5.

## Planning Contract

- KTD1. Append deletion of the exact cache directory to the existing production Composer `RUN`, after successful `dump-autoload`. This prevents the cache entering that image layer and gives `worker` the same cleanup. Governs R1.
- KTD2. Extend the PHP artifact probe in `scripts/test-worker-production-container.sh` with a focused cache-absence assertion. The existing image checks and operator smoke supply runtime verification; no new test suite or broad application suite is warranted. Governs R1, R3, R6.
- KTD3. Follow [the collection procedure](../../dev-docs/application-packaged-inventory.md) using a fresh evidence directory for each rebuilt target. Compare installed package versions/references and native identities/hashes with the retained accepted inventory; investigate and identify every difference outside the cache, current supplemental notices, and this change's own source files. Dynamic upstream build inputs can produce unrelated changes, so do not infer component preservation solely from equal counts. Governs R2, R4, R5.
- KTD4. Preserve `third_party/inventory/application-review.json` as historical evidence. Add a distinct current checkpoint to the Markdown review and ROADMAP, naming new evidence bundles and identities. Governs R5.

No application behavior, PostgreSQL schema, Registry storage, dependency resolution policy, collector classification or CI lane changes are in scope. Existing supplementary notices remain applicable evidence, not a claim that all embedded dependencies have been qualified. No new shared domain term is introduced.

## Implementation Units

### U1. Remove the cache and demonstrate the regression

**Files:** `Dockerfile`, `scripts/test-worker-production-container.sh`.

**Approach:** Follow KTD1 and KTD2. Add the assertion first and run the existing runner against the retained accepted worker, confirming a cache-specific failure. Apply cleanup only to the production Composer layer; development and CI dependency installation are outside this unit. Commit the implementation locally before building the source-labelled replacement images.

**Test scenarios:** AE1; confirm failure is caused by the cache rather than a missing image or infrastructure error. On the replacement image the same artifact probe, Composer platform check, restricted coroutine file probe and existing operator smoke must pass.

### U2. Measure replacement images and record acceptance

**Files:** `third_party/inventory/application-review.md`, `ROADMAP.md`.

**Dependencies:** U1 committed and the new source revision available.

**Approach:** Build both real targets from an extracted clean committed directory context. Follow KTD3 for collection and comparisons, and KTD4 for the checkpoint. Run prose-fix on changed documentation. Retain logs and full bundles under ignored `var/test-runs/`; report observed figures rather than predicting that totals differ only by 173 files.

**Test scenarios:** AE2 and AE3; verify source labels match the committed build context, both bundle integrity checks succeed, the exact cache prefix has no filesystem entries or review gaps, and remaining differences have concrete explanations. The new review remains explicitly unqualified.

## Verification Contract

The authoritative checks are shell syntax for the changed runner, the cache-specific red result on the retained image, both production and worker builds, generation and verification of both new inventory bundles, explicit installed-component and filesystem comparisons, and the existing worker production container runner on the rebuilt immutable image ID. Inspect relevant failures from retained logs. Run `git diff --check` and prose-fix's preservation audit on changed documentation. Application unit/static-analysis suites are outside this packaging-only change.

Builds and shared-state smoke checks run serially. Keep the existing runner's isolation and do not reset, mount or migrate local user data. If a rebuild changes package/native contents, record and investigate the observed drift before asserting R2; unexplained drift prevents acceptance. Existing raw licensing observations remain visible.

## Definition of Done

U1 and U2 satisfy AE1–AE3, justified review findings are applied, and the roadmap checkpoint contains the actual verification results and measured evidence. Changes are committed locally on `plan/production-composer-cache`; no merge, push or publication is authorized by this plan.
