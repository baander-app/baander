---
title: Completed Plan Cleanup - Plan
type: docs
date: 2026-10-21
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: code
---

# Completed Plan Cleanup - Plan

## Goal Capsule

- **Objective:** Contributors can find unfinished work in `docs/plans/` without browsing completed plans.
- **Means:** Remove the audited completed plans (KTD1).
- **Authority:** The request to clean up completed plans governs scope; `ROADMAP.md`, delivered changes and verification records establish completion.
- **Execution profile:** Documentation cleanup with focused reference checks; the lead integrates, verifies and commits locally.
- **Stop condition:** Preserve any plan whose remaining commitments or completion evidence are uncertain.

## Product Contract

### Summary

Remove completed planning documents while retaining plans with unfinished commitments.

### Problem Frame

The directory contains 39 existing plans, including 33 whose work has been delivered.
Completed plans obscure the six plans that still contain unfinished work.

### Requirements

**Selection**

- R1. Remove only the 33 existing plans identified by KTD1, using delivery evidence rather than age or a status label.
- R2. Preserve the six July plans identified by KTD2, including partially superseded work with remaining commitments.

**Documentation integrity**

- R3. Keep operating guidance and unresolved commitments discoverable in surviving documentation or plans; repair surviving references affected by deletion.
- R4. Record the cleanup scope and verification checkpoint in `ROADMAP.md`.
- R5. Retire this cleanup plan after delivery while preserving its reviewed contents in Git history and a scratch copy for final review.

### Scope Boundaries

Application code, tests, configuration, backlog implementation and documentation redesign are outside this cleanup.
An archive directory and an automated plan lifecycle are unnecessary for this request; Git already retains removed documents.

## Planning Contract

### Key Technical Decisions

- KTD1. Delete every pre-existing Markdown file directly under `docs/plans/` except the six `2026-07-*` files: 20 dated October 19–21, nine dated October 17–18 and four undated plans (`atomic-rate-limits.md`, `provider-destination-policy.md`, `public-api-identities.md`, `websocket-handshake-tickets.md`). The completion audit found delivered changes and verification evidence for all 33, with no unique operating material requiring migration.
- KTD2. Retain `2026-07-08-001-perf-flac-dsp-cpu-plan.md`, `2026-07-15-001-refactor-rn-styling-pattern-plan.md`, `2026-07-15-002-chore-rn-tv-dx-tooling-plan.md`, `2026-07-16-001-refactor-long-process-segmenter-plan.md`, `2026-07-16-002-feat-transcode-cache-sweep-e2e-plan.md` and `2026-07-17-001-feat-messenger-enterprise-hardening-plan.md`. Each has incomplete or partially superseded commitments.
- KTD3. Preserve the reviewed artifact before its final removal (R5), so the cleanup leaves no redundant completed plan in the directory.

### Assumptions

Completion evidence is the audited state of this checkout; the lead rechecks the deletion set before applying it.
The external reference in `dev-docs/worker-runtime-inventory.md` points to the retained Messenger plan and requires no edit.
The retained FLAC, segmenter and cache-sweep plans each link to the completed shared-media qualification plan; replace those three targets with `../../docs-book/part-2-developer-guide/testing.md#shared-media-control-qualification` while preserving labels and commitments.

## Implementation Units

### U1. Remove the audited completed plans

- **Requirements:** R1–R3; KTD1–KTD2.
- **Files:** The 33 pre-existing files selected by KTD1 under `docs/plans/`; surviving documents only where a reference repair proves necessary.
- **Approach:** Confirm the manifest against completion evidence, remove that manifest, and update the three identified link targets without altering retained commitments.
- **Test expectation:** None — document deletion changes no application behavior.
- **Verification:** The six retained plans preserve their commitments, with only the three identified link targets changed; tracked surviving references resolve or explicitly identify historical Git context.

### U2. Record and close the cleanup

- **Requirements:** R4–R5; KTD3. Depends on U1.
- **Files:** `ROADMAP.md` and `docs/plans/2026-10-21-completed-plan-cleanup-plan.md`.
- **Approach:** Add the cleanup checkpoint near the roadmap resume guidance, preserve the reviewed plan, and retire it in the final cleanup commit.
- **Test expectation:** None — this unit changes documentation only.
- **Verification:** Final inventory contains exactly the six retained plans, and the roadmap records 33 completed plans removed with the focused checks performed.

## Verification Contract

- Compare the final deletion manifest with the audited 39-file baseline: 33 completed plans removed and six unfinished plans preserved.
- Scan tracked surviving files for removed basenames and `docs/plans/` references, including relative links and same-directory references.
- Review the diff for accidental edits and run `git diff --check`.
- Review changed prose with `prose-fix`; run simplification and independent final review as required by the repository workflow.
- Application suites and static code gates are not applicable because no executable files change; record that scope in the roadmap checkpoint.

## Definition of Done

U1 and U2 satisfy their verification criteria, justified review findings are resolved, and the local commits preserve the reviewed plan before its removal.
No push is part of this cleanup.
