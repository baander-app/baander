---
title: Remove Standalone Android App - Plan
type: chore
date: 2026-10-26
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: code
---

# Remove Standalone Android App - Plan

## Goal Capsule

- **Objective:** Contributors no longer maintain the separate Android application.
- **Means:** Remove the standalone package and its remaining references in active configuration (KTD1).
- **Authority:** The user's request, “i want to remove ui/android,” governs this scope; repository rules govern delivery.
- **Execution profile:** One local implementation commit on `2026-10-26-remove-android-app-plan`, with static verification.
- **Stop conditions:** Investigate unexpected local files or a surviving executable consumer before deleting or widening scope.
- **Delivery:** The lead completes review, verification and the local commit; pushing requires a separate request.

---

## Product Contract

### Summary

Remove `ui/android` and reconcile its CI and documentation references.

### Problem Frame

The repository contains a separate `@baander/android` application alongside `ui/rn`.
The user has requested removal of the separate application.
The remaining mobile work is deferred in `ROADMAP.md`.

### Requirements

**Application removal**

- R1. Remove the complete `ui/android` directory, including its package manifest, lockfile, source and app-specific configuration.

**Repository integration**

- R2. Remove the `ui/android/**` entry from both frontend CI event path lists.
- R3. Reconcile documentation that treats the removed application as a future consumer, and record the delivered change with verification evidence in `ROADMAP.md`.

### Scope Boundaries

The request targets the standalone application directory.
It does not select React Native feature reconciliation, shared-package changes, API changes or changes to Android and TV targets inside `ui/rn`.
The existing React Native deferral remains in force.

No compatibility package, replacement application or shared-theme extraction is planned.
The dependency audit found no remaining consumer requiring one; a newly identified executable consumer would require reassessment.

### Sources

- User request: “i want to remove ui/android.”
- `ui/android/package.json`: standalone private `@baander/android`, linking to `../shared`.
- `.forgejo/workflows/frontend.yaml`: push and pull-request path filters.
- `docs/plans/2026-07-15-001-refactor-rn-styling-pattern-plan.md`: historical app boundary and prospective shared-theme extraction.
- `ROADMAP.md`: deferred mobile scope and delivered-work record.

---

## Planning Contract

### Key Technical Decisions

- KTD1. **Delete the package without replacement.** The tracked inventory contains 52 files, and the dependency audit found no workspace membership or downstream consumers outside the two CI filters. Keeping a stub would preserve maintenance for an application the user asked to remove. Governs R1–R2.
- KTD2. **Use static verification.** The surviving executable paths are unchanged, so deletion inventory, reference checks and a parsed workflow comparison establish the relevant contracts. Add a targeted check only if the final audit discovers an affected executable consumer. Governs verification of R1–R3.
- KTD3. **Clarify historical scope without resuming it.** Amend the old styling plan's two app-specific references to explain that the second application was removed and its cross-app extraction rationale is superseded. Keep the historical styling decisions and deferred mobile work intact. Governs R3.

### Assumptions

The requested path identifies the standalone package rather than Android as a platform.
The existing `ui/rn` Android and Android TV scripts support this interpretation.
No user data is stored in the removal target: the audit found only tracked app files and ignored `.yarn/install-state.gz`; refresh that inventory before removal.

### Sequencing

Capture removal and retained-tree inventories before U1.
Remove the package and reconcile its references as one atomic change.
Review the resulting diff before recording the final verification checkpoint.

---

## Implementation Units

### U1. Remove the standalone Android app

- **Goal:** Complete R1–R3 as one repository change.
- **Dependencies:** None.
- **Files:** Delete `ui/android/`; modify `.forgejo/workflows/frontend.yaml`, `docs/plans/2026-07-15-001-refactor-rn-styling-pattern-plan.md` and `ROADMAP.md`.
- **Approach:** Follow KTD1 and KTD3. Remove the two obsolete event-filter entries without changing other workflow content. Preserve the old styling plan's historical meaning while removing its future dependency on the deleted application. Record the exact verification results in the roadmap.
- **Execution note:** Use KTD2's static checks; this removal adds no executable behavior or permanent test files.
- **Test expectation:** None for app unit or build suites, because no surviving executable consumer changes.
- **Verification scenarios:** The directory is absent after removal, including the known ignored install state. Both event lists lose exactly one entry. Retained mobile/shared inventories are identical. Remaining app-name and path references describe removal or history. The roadmap retains mobile deferral and records the completed checkpoint.

---

## Verification Contract

- **Deletion inventory:** Compare the captured 52 tracked paths with the final deletion set, confirm `ui/android` is absent, and investigate unexpected local files before removal.
- **Retained trees:** Compare path, content and file-mode inventories for `ui/rn` and `ui/shared` before and after implementation, including their Android/TV targets.
- **Workflow structure:** Parse the complete original and edited `.forgejo/workflows/frontend.yaml` using a loader that preserves the `on` key; require structural equality after removing only the two `ui/android/**` path entries from the original document.
- **Reference audit:** Search tracked files for `ui/android` and `@baander/android`; classify every surviving match and require no active configuration, workspace or executable dependency on the removed package.
- **Documentation:** Check edited Markdown links, preserve the styling plan's unrelated commitments and verify the roadmap's remaining mobile deferral. Run `git diff --check`.

---

## Definition of Done

U1 satisfies its verification scenarios, the final diff contains only the declared removal and supporting edits, and the roadmap records the results.
Documentation prose follows the house style, simplification and code review leave no justified unresolved findings, and the changes are committed locally.
No application test or build result is claimed when those suites were not run.
