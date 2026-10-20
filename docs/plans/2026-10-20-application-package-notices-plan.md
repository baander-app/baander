---
title: Application Package Notices - Plan
type: docs
date: 2026-10-20
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: code
---

# Application Package Notices - Plan

## Goal Capsule

- **Objective:** A redistribution reviewer can trace the six installed OpenTelemetry packages to authoritative license text and see the remaining evidence gap for the local PHP bindings.
- **Means:** Retain upstream notices with exact source correspondence in the existing Composer notice bundle (KTD1, KTD2).
- **Authority:** The requested bounded follow-up, `third_party/inventory/application-review.md`, and the requirements below govern scope.
- **Execution profile:** Evidence and documentation changes; use targeted provenance and packaging checks.
- **Stop conditions:** Do not mark a package recovered without verified source correspondence or assign copyright ownership without evidence.
- **Delivery:** The lead completes integration, local commits, review, and `ROADMAP.md`; pushing requires a separate request.

## Product Contract

### Summary

Recover notice evidence for the six OpenTelemetry split packages identified in the application inventory review.
Record the local `tsduck/php` notice investigation and its unresolved result unless authoritative evidence becomes available.

### Problem Frame

The installed OpenTelemetry split repositories omit notice files at the recorded revisions.
The current notice record therefore cannot connect their declared licenses to retained upstream text.
The local bindings declare BSD-2-Clause but provide no retained top-level notice or established copyright provenance.

### Requirements

**Source evidence**

- R1. Resolve each of the six recorded OpenTelemetry revisions to an authoritative upstream source containing the applicable notices, with verifiable source correspondence.
- R2. Preserve each retrieved license or notice byte-for-byte, recording immutable upstream references, paths, Git object identities, and SHA-256 hashes.
- R3. Preserve the local `tsduck/php` declared BSD-2-Clause license and record its investigated provenance gap; do not fabricate a license text or copyright attribution.

**Packaging and review**

- R4. Make the supplemental notices and provenance record available through the existing image packaging and inventory evidence collection.
- R5. Distinguish supplemental resolutions from historical raw observations and keep the application outcome `not-qualified` while other redistribution work remains.
- R6. Record delivered scope and actual verification checkpoints in `ROADMAP.md`.

### Scope Boundaries

Debian notice references, embedded dependencies, copied assets, inherited files, Registry qualification, and DSP qualification remain outside this item.
No product behavior, package version, dependency lock, collector interface, or image build instruction change is planned.
A general notice resolver and new speculative tests are considered and not built: this is a bounded evidence recovery using existing packaging; reconsider only if that existing path cannot retain the evidence.

## Planning Contract

### Key Technical Decisions

- KTD1. **Establish correspondence through identical Git trees.** For R1, compare each installed split commit's root tree with the matching monorepo subtree at an immutable revision; version similarity or release dates alone are insufficient.
- KTD2. **Extend the existing Composer notice record.** For R2 and R4, retain package-specific `LICENSE` files under `third_party/inventory/composer-notices/open-telemetry/` and extend `source.json` with the monorepo correspondence and notice provenance. Existing recovered entries provide the record pattern; add fields rather than remove their installed-source evidence.
- KTD3. **Keep unresolved evidence explicit.** For R3 and R5, add the local package investigation to the record without changing its license declaration, and describe the missing authorship or source proof in the review. Repository ownership and native TSDuck's license do not establish the PHP bindings' notice.
- KTD4. **Reuse the established packaging path.** For R4, the root Dockerfile copies the repository into the application image, and `scripts/packaged_inventory.py` retains notice-named files and inventory `source.json` files. Verify those existing rules against the new paths instead of adding collection machinery.

### Source Correspondence

The upstream repository is `open-telemetry/opentelemetry-php` on GitHub.
Read-only research found the following exact root-tree/subtree matches; verify the identities again when retaining the evidence.
The installed split references remain owned by `third_party/inventory/composer-notices/source.json`.

| Package | Monorepo reference | Subtree |
|---|---|---|
| `open-telemetry/api` | `e5a33a4cabe9486c71c240af6afc586d19c8e64a` | `src/API` |
| `open-telemetry/context` | `ac49b46ffe3b264834808ae48e64e10cdef92bf1` | `src/Context` |
| `open-telemetry/exporter-otlp` | `26a2a4ed076a3e7b4c51204ecac52a38ac17f25e` | `src/Contrib/Otlp` |
| `open-telemetry/gen-otlp-protobuf` | `ac49b46ffe3b264834808ae48e64e10cdef92bf1` | `proto/otel` |
| `open-telemetry/sdk` | `2a3aa614ed75e27d22e2336f250316b572524ebd` | `src/SDK` |
| `open-telemetry/sem-conv` | `695724168681238d1e811a60fec1939bb4a3179e` | `src/SemConv` |

Each complete recursive monorepo tree in this research contains root `LICENSE` and no separately named `NOTICE`.
All six root licenses have Git blob `261eeb9e9f8b2b4b0d119366dda99c6fd7d35c64`, SHA-256 `c71d239df91726fc519c6eb72d318ec65820627232b2f796219e87dcf35d0ab4`, and size 11,357 bytes.
These observations establish the planned notice retrieval; they do not settle embedded dependency obligations.

### Assumptions and Open Questions

No launch-blocking question remains.
The parent research found no notice, copyright attribution, or upstream provenance for the local bindings in their source and initial repository history; R3 defines the bounded result if that finding stands.
Exact provenance field names are implementation details within KTD2.
No new project domain vocabulary is introduced.

## Implementation Units

### U1. Retain the six OpenTelemetry licenses and correspondence

- **Goal:** Deliver R1 and R2 through KTD1 and KTD2.
- **Dependencies:** None.
- **Files:** `third_party/inventory/composer-notices/source.json`; new `LICENSE` files under each of `open-telemetry/api`, `context`, `exporter-otlp`, `gen-otlp-protobuf`, `sdk`, and `sem-conv` within that directory.
- **Approach:** Retrieve the pinned upstream bytes; record split root-tree and monorepo subtree identity, complete tree query provenance, and immutable license URL and hashes. Mark only the six verified entries recovered.
- **Test scenarios:** Each split root-tree equals its recorded monorepo subtree; each retained notice matches both the upstream Git blob and SHA-256; recursive tree responses are complete; the two previously recovered Composer entries retain their evidence.
- **Verification:** Targeted JSON, tree-identity, byte/hash, and upstream-response checks. Test expectation: none beyond direct artifact checks; this unit changes evidence data.

### U2. Record the local bindings investigation

- **Goal:** Deliver R3 through KTD3.
- **Dependencies:** U1 establishes the extended record shape.
- **Files:** `third_party/inventory/composer-notices/source.json`; read-only `packages/tsduck-php-ffi/composer.json`, package sources and repository history, and `THIRD_PARTY.md`.
- **Approach:** Record the package's actual local path/source identity and missing notice evidence; retain unresolved status if no authoritative notice is established.
- **Test scenarios:** The declared BSD-2-Clause value remains unchanged; the unresolved record identifies the concrete missing evidence without inventing an author, year, or upstream attribution.
- **Verification:** Compare record statements with manifest, source and history observations. Test expectation: none; this unit records an investigation.

### U3. Update the review and delivery checkpoint

- **Goal:** Deliver R4, R5 and R6 through KTD4.
- **Dependencies:** U1 and U2.
- **Files:** `third_party/inventory/application-review.md`; `ROADMAP.md`; read-only `Dockerfile`, `.dockerignore`, `scripts/packaged_inventory.py`, and `scripts/tests/test_packaged_inventory.py`.
- **Approach:** Describe the supplemental resolutions and remaining local gap; preserve the historical image IDs, counts and raw observations. Check the new paths against Docker context inclusion and collector evidence rules. Document only packaging results actually observed.
- **Test scenarios:** Each added notice and updated source record survives the existing context rules and is eligible for retained evidence; the review keeps the historical source boundary and `not-qualified` outcome; unrelated remaining gates remain listed.
- **Verification:** Direct packaging-rule inspection, targeted existing context checks if applicable, JSON/path/hash checks and documentation checks. Test expectation: none for unchanged collector logic; use existing tests only if verification exposes a needed implementation change.

## Verification Contract

- Validate `source.json` parsing and every retained notice path, immutable reference, Git blob identity, and SHA-256; record the number of packages resolved and remaining unresolved.
- Check complete upstream tree evidence and exact subtree identities for all six packages.
- Verify context inclusion and the collector's notice/source-record retention rules for the actual new paths. A source-level eligibility check does not prove a new image was collected.
- A fresh image build and inventory collection are conditional: perform them only if claiming new measured image contents, and follow `dev-docs/application-packaged-inventory.md` using a filtered directory context. Otherwise preserve the earlier image observations and state the narrower verification boundary.
- Apply `prose-fix` to changed documentation, run `git diff --check`, and complete the required simplification and code review checkpoints.
- Backend, frontend, Registry and DSP suites are outside this evidence-only change. Do not add speculative tests or rerun broad suites.

## Definition of Done

U1 has six verified source mappings and byte-preserved upstream licenses.
U2 has an evidence-based local-package disposition consistent with R3.
U3 reports the resulting supplemental coverage and actual checks without implying redistribution qualification or remeasuring historical images.
All R1–R6 requirements have recorded evidence, local commits are complete, and abandoned scratch or experimental repository changes are absent.
