---
title: Debian License References - Plan
type: docs
date: 2026-10-20
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: code
---

# Debian License References - Plan

## Goal Capsule

- **Objective:** A redistribution reviewer can trace the six observed missing Debian common-license references to the complete texts authorized by their copyright statements.
- **Means:** Retain three existing license texts with six component-specific provenance records (KTD1).
- **Authority:** The bounded redistribution follow-up, `ROADMAP.md`, and the requirements below govern scope.
- **Execution profile:** Data and documentation changes with direct evidence and packaging checks.
- **Stop conditions:** Stop on conflicting package identities, copyright grants, or body hashes; a similar filename does not establish license correspondence.
- **Delivery:** The lead integrates, verifies, reviews, and commits locally; pushing requires a separate request.

## Product Contract

### Summary

Resolve the six absent common-license references recorded in the accepted application image inventories through supplemental evidence.
Preserve the original observations and the distinction between a referenced component's license and the containing package's licensing.

### Problem Frame

The accepted production and worker inventories retain copyright files that name three absent common-license paths.
The adjacent text explicitly identifies the applicable license versions, and both inventories already retain their complete bodies under different names.
A reviewer needs that correspondence recorded without silently rewriting the source evidence or claiming redistribution qualification.

### Requirements

**Supplemental evidence**

- R1. Resolve exactly the six copyright references in the Source Correspondence table using their explicit component-level grants.
- R2. Preserve the three complete retained license bodies byte-for-byte and bind every resolution to installed package identity, image identity, copyright path and hash, literal missing reference, explanatory source excerpt, and actual license path and hash.
- R3. Retain the supplemental bodies and provenance record through the existing application packaging and inventory collection paths.

**Review boundary**

- R4. Preserve historical image identities, inventory counts, raw gaps, and the `not-qualified` outcome while recording these six supplemental resolutions separately.
- R5. Record the delivered scope and actual verification checkpoints in `ROADMAP.md`.

### Scope Boundaries

The 149 copyright files lacking machine-readable declarations, remaining package notices, embedded dependencies, copied assets, inherited files, Registry qualification, and DSP qualification remain follow-up work.
No dependency changes, image rebuilds, collector changes, general reference resolver, new test suite, or broad product-suite run is planned.
A general resolver and new regression tests are considered and not built because existing packaging retains the evidence without behavior changes; reconsider only if the targeted retention check exposes a concrete limitation.

## Planning Contract

### Key Technical Decisions

- KTD1. **Use explicit grants and retained bodies.** For R1 and R2, create `third_party/inventory/debian-notices/source.json` and three notice-named files: `LICENSE.GFDL-1.3`, `LICENSE.GPL-2`, and `LICENSE.GPL-3`.
  The grant text establishes the version correspondence; common-license filenames alone do not.
  Copy the bodies from the accepted local evidence, with no external substitution or edits to the original copyright files.
- KTD2. **Keep six scoped records.** For R2, record each installed binary package separately even where source package, copyright hash, and license body coincide.
  Preserve the source paragraph's component scope, including ancillary tool and documentation grants; do not classify an entire package as GPL from one paragraph.
  Include source package/version, binary package/version/architecture, both image IDs, original copyright excerpt, retained-blob identity, and the supplemental file path/hash.
- KTD3. **Reuse existing collection.** For R3, `scripts/packaged_inventory.py` recognizes notice-named files and inventory `source.json` files, and the root Dockerfile copies the repository into the application image.
  Verify that path using a disposable Docker context and fixture collection, following the previous package-notice checkpoint.
- KTD4. **Describe a source follow-up.** For R4 and R5, amend `third_party/inventory/application-review.md` and `ROADMAP.md` without changing `third_party/inventory/application-review.json` or the accepted local bundles.
  The new files are supplemental source evidence, not measured contents of the historical images.

### Source Correspondence

The accepted bundles are `var/test-runs/application-inventory-20261020/production/` and `var/test-runs/application-inventory-20261020/worker/`.
Their image IDs and source revision are recorded in `third_party/inventory/application-review.json`.
Research verified the same source and body hashes in both bundles; implementation must confirm those identities when retaining the files.
All listed binary packages are amd64.

| Binary package | Source package and installed version | Copyright path | Literal absent common-license name | Explicit grant and retained body |
|---|---|---|---|---|
| gzip | gzip, 1.12-1 | `/usr/share/doc/gzip/copyright` | `GFDL-3` | GNU Free Documentation License version 1.3 or later, no invariant sections or cover texts; `/usr/share/common-licenses/GFDL-1.3` |
| libpango-1.0-0 | pango1.0, 1.50.12+ds-1 | `/usr/share/doc/libpango-1.0-0/copyright` | `GPL-2.0` | GNU GPL version 2 or later; `/usr/share/common-licenses/GPL-2` |
| libpangocairo-1.0-0 | pango1.0, 1.50.12+ds-1 | `/usr/share/doc/libpangocairo-1.0-0/copyright` | `GPL-2.0` | GNU GPL version 2 or later; `/usr/share/common-licenses/GPL-2` |
| libpangoft2-1.0-0 | pango1.0, 1.50.12+ds-1 | `/usr/share/doc/libpangoft2-1.0-0/copyright` | `GPL-2.0` | GNU GPL version 2 or later; `/usr/share/common-licenses/GPL-2` |
| libdecor-0-0 | libdecor-0, 0.1.1-2 | `/usr/share/doc/libdecor-0-0/copyright` | `GPL-3.0` | GNU GPL version 3 or later; `/usr/share/common-licenses/GPL-3` |
| libdecor-0-plugin-1-cairo | libdecor-0, 0.1.1-2 | `/usr/share/doc/libdecor-0-plugin-1-cairo/copyright` | `GPL-3.0` | GNU GPL version 3 or later; `/usr/share/common-licenses/GPL-3` |

Copyright SHA-256 values are `1ca5dd5098fe2e1c0f0d05196f5b3da8b414a807702e6ca8b536eb5fd3059130` for gzip, `3a29d0e487eae553ec47f260be1570a0d96e4f28c21f29ef58a14cd3d53147ff` for the Pango entries, and `c8b0394900f44bcb9e07a48fa878d3629957d84ba19a992ddfcec6d96e639899` for the libdecor entries.
The explanatory paragraphs are at lines 42–50, 210–222, and 45–57 respectively; retain their actual text and relevant component headings rather than relying on line numbers alone.

Body SHA-256 values are `110535522396708cea37c72a802c5e7e81391139f5f7985631c93ef242b206a4` for GFDL-1.3, `8177f97513213526df2cf6184d8ff986c675afb514d4e68a404010521b880643` for GPL-2, and `3972dc9744f6499f0f9b2dbf76696f2ae7ad8af9b23dde66d6af86c9dfb36986` for GPL-3.
Each body's heading explicitly names the corresponding version.

### Assumptions

The accepted local bundles remain available for the bounded evidence check.
The new provenance record follows the existing supplemental notice pattern in `third_party/inventory/composer-notices/source.json`; it introduces no new application domain term.

## Implementation Units

### U1. Retain the scoped Debian reference evidence

- **Goal:** Supply auditable correspondence for the six references.
- **Requirements:** R1–R3; KTD1–KTD3.
- **Dependencies:** None.
- **Files:** Create `third_party/inventory/debian-notices/source.json`, `third_party/inventory/debian-notices/LICENSE.GFDL-1.3`, `third_party/inventory/debian-notices/LICENSE.GPL-2`, and `third_party/inventory/debian-notices/LICENSE.GPL-3`.
- **Approach:** Confirm package entries, copyright excerpts, and complete body identities in both accepted bundles before retaining the three bodies and six provenance records.
- **Test expectation:** None; these are static evidence additions using existing collection behavior.
- **Verification:** Directly check six exact source references, three body hashes, record completeness, Docker context inclusion, and collector retention.
- **Execution note:** Prefer bounded artifact checks over product suites or image rebuilds.

### U2. Record the supplemental resolution and remaining gate

- **Goal:** Make the recovered scope and its verification visible to redistribution reviewers.
- **Requirements:** R4, R5; KTD4.
- **Dependencies:** U1.
- **Files:** Modify `third_party/inventory/application-review.md` and `ROADMAP.md`.
- **Approach:** Add the new evidence and actual verification checkpoint; narrow the remaining Debian work to the unresolved declaration review without erasing the historical six-reference observation.
- **Test expectation:** None; documentation changes only.
- **Verification:** Review the resulting claims against U1's evidence and confirm the historical JSON and bundle measurements remain unchanged.

## Verification Contract

Use direct data and packaging checks, following `dev-docs/application-packaged-inventory.md` and the previous supplemental-notice checkpoint in `third_party/inventory/application-review.md`.

- Confirm all six package/version/architecture identities and copyright hashes against both accepted inventories.
- Confirm each literal missing reference and explicit grant in the unchanged copyright bytes, including the component scope and gzip's GFDL qualifiers.
- Confirm the three retained bodies' full bytes and SHA-256 values against both bundles and the new record.
- Export a disposable Docker context with the repository's `.dockerignore` and verify all four new files survive.
- Generate and verify a scratch collector fixture containing all four new image paths; confirm byte-for-byte retention and `not-qualified` without rewriting accepted bundles.
- Parse the new JSON, check documentation links, apply `prose-fix`, and run `git diff --check`.
- Record actual counts and results in the review and roadmap; do not repeat the earlier 96-test checkpoint as a new run.

## Definition of Done

The six references have complete component-specific provenance and three unchanged license bodies.
The existing packaging and collection retain all four new files, with direct checks recorded.
The review and roadmap describe the supplemental resolution under R4, and all work remains in local commits.
No unresolved blocking question remains; a mismatch in the evidence invokes the stop condition rather than an inferred replacement.
