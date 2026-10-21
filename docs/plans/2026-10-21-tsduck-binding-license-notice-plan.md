---
title: TSDuck PHP Binding License Notice - Plan
type: fix
date: 2026-10-21
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: code
---

# TSDuck PHP Binding License Notice - Plan

## Goal Capsule

- **Objective:** Redistributors can find the local PHP bindings' copyright attribution and complete BSD-2-Clause terms in the package source.
- **Means:** Issue the author's package notice and bind it to the source review (KTD1, KTD2).
- **Authority:** The Product Contract owns scope; technical decisions own implementation choices within it.
- **Execution:** One bounded local implementation unit, followed by focused packaging verification and review.
- **Stop conditions:** Conflicting authorship evidence or a verification failure that prevents proving notice retention.
- **Delivery:** The lead integrates, reviews and commits locally on the branch named after this plan; pushing requires a new request.

## Product Contract

### Summary

Add the missing copyright and BSD-2-Clause notice to the local `tsduck/php` bindings and resolve its source-review gap with explicit author provenance.

### Problem Frame

The local package declares BSD-2-Clause but has no retained notice or attribution.
The user answered the provenance question with “i authored it”.
The package first appears in commit `1268df23cc255fe414d33940dd303228189343e6`, authored by Martin Christiansen on 2026-08-01.
Repository ownership and the native TSDuck notice alone did not resolve this gap.
The author's confirmation now supports issuing the missing notice for these bindings.

### Requirements

- R1. Add `packages/tsduck-php-ffi/LICENSE` containing the standard BSD-2-Clause text and `Copyright (c) 2026 Martin Christiansen`.
- R2. Preserve the package's declared BSD-2-Clause license, dependencies and native TSDuck licensing boundary.
- R3. Update the local Composer source record with the 2026-10-21 confirmation date, notice path, notice SHA-256 and template provenance while preserving its previously reviewed source reference, tree hash and manifest hash.
- R4. Describe the notice as newly issued by the confirmed author; do not present it as recovered upstream evidence or as a file present in the historical source tree.
- R5. Link the actual package notice from `THIRD_PARTY.md`, record the resolved local source gap in `third_party/inventory/application-review.md`, and record delivery and focused verification in `ROADMAP.md`.
- R6. Preserve historical image inventories, raw gap counts, image identities and their `not-qualified` outcomes; source notice resolution makes no new image qualification claim.
- R7. Prove the actual package notice survives Docker's context matcher and is discovered and retained by the existing inventory collector without adding tests, rebuilding full application images or running broad application suites.

### Scope Boundaries

Other redistribution gaps remain separate release work.
Collector redesign and additional test infrastructure are considered unnecessary: the existing path-repository fixture and bounded packaging checks can establish R7.
No new domain concept is introduced, so `CONCEPTS.md` needs no entry.

## Planning Contract

### Key Technical Decisions

- KTD1. **Use the existing declaration and author confirmation.** R1–R4 issue a missing notice, rather than change the package's license or infer provenance from native TSDuck.
- KTD2. **Bind new evidence separately from the old snapshot.** Retain `local_source` identifiers in `third_party/inventory/composer-notices/source.json`; add explicit current notice and author-confirmation metadata so the old tree is not falsely described as containing it (R3, R4).
- KTD3. **Use a pinned standard template.** Adopt the SPDX BSD-2-Clause text at revision `93b66352608d470dc094a344a110b94d63fabb73`, template SHA-256 `f32fb3b417a194167cfad068223fc975ba96c5960513a10f66a3c28720aec1df`; substitute the copyright placeholders, remove trailing whitespace and retain one final newline, without changing license wording (R1).
- KTD4. **Use packaging smoke evidence.** Exercise Docker's matcher with the real notice bytes and adapt the existing collector fixture transiently to the actual package; report this as source/fixture evidence, separate from historical image evidence (R6, R7).

### Assumptions

The user's authorship confirmation and initial package commit provide sufficient attribution for the missing notice.
No contradictory authorship evidence is present in the supplied provenance investigation.

### Sources and Existing Patterns

The local package manifest is `packages/tsduck-php-ffi/composer.json`.
The reviewed source record pins repository reference `8121bcc45717e0ebc8a160b25eb90561afef7b3a`, tree `b32d561a07db30233388fec45c4688adb19625ad` and manifest SHA-256 `54f2c4bcb91ee0b66518ecb36e2fbeb1375d0be3d55787c87db56741e00f9531`.
`Dockerfile` copies the repository into the application; `.dockerignore` governs that context.
`scripts/test-docker-context.sh` exercises Docker's actual matcher with a scratch export.
`scripts/tests/test_packaged_inventory.py` already defines `test_composer_path_repository_owns_physical_source_and_notice`, including evidence retention and inventory verification.
The collector implementation is `scripts/packaged_inventory.py`.
No matching licensing or inventory solution was found under `docs/solutions/`; no Compound Packs are configured.
The pinned template is available from [SPDX license-list-data](https://raw.githubusercontent.com/spdx/license-list-data/93b66352608d470dc094a344a110b94d63fabb73/text/BSD-2-Clause.txt).

## Implementation Units

### U1. Issue and retain the binding notice

**Goal:** Make the package notice available and close its source-evidence gap.

**Requirements:** R1–R7; KTD1–KTD4.

**Dependencies:** None.

**Files:** Create `packages/tsduck-php-ffi/LICENSE`; update `third_party/inventory/composer-notices/source.json`, `THIRD_PARTY.md`, `third_party/inventory/application-review.md` and `ROADMAP.md`.

**Approach:** Add the notice using KTD3, record new evidence using KTD2, then replace the current local-package gap description with the source resolution and its historical-image limit.
Keep previous measured verification checkpoints intact.

**Test scenarios:** Compare the finished notice with the transformed pinned template byte for byte; verify the manifest bytes are unchanged; export the real notice through Docker's context matcher and compare its bytes; use the existing path-repository fixture with `tsduck/php`, its real manifest/source path and actual notice bytes, then verify collector ownership, evidence hash, retained content and absence of that package's missing-notice gap.

**Verification:** Follow the Verification Contract and record measured results in the roadmap.

**Execution note:** This is a notice and packaging change; use focused smoke verification and existing fixture machinery.
No committed test changes are needed.

## Verification Contract

Compare the notice to the pinned template after exactly the transformation in KTD3.
Check the package manifest hash against the existing source record and check JSON parsing and notice hashes.
Use a temporary scratch context with the repository's `.dockerignore` and the actual notice, following `scripts/test-docker-context.sh`; Docker must retain the exact bytes.
Reuse the existing path-repository test fixture from `scripts/tests/test_packaged_inventory.py` transiently for the actual bindings and run `scripts/packaged_inventory.py` collection and verification through that fixture.
Require the expected Composer owner, matching retained notice SHA-256 and no missing-notice gap for `composer:tsduck/php`.
This fixture run is not a production or worker inventory refresh.
Run `git diff --check`, prose preservation review, phase-boundary simplification and final code review; do not run broad PHP/frontend suites or a full image build for this notice-only change.

## Definition of Done

The five delivery files satisfy R1–R7 and the notice is linked and hash-bound.
Focused verification results and limits appear in `ROADMAP.md` and the application review record.
The existing manifest and historical image records remain intact.
The final review has no unresolved justified finding within scope.
Remove temporary experiments from the tracked diff and land the work as a local commit.
