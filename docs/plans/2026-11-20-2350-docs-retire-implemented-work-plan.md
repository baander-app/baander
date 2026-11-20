---
title: Retire implemented work from planning documents - Plan
type: docs
date: 2026-11-20
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: knowledge-work
---

# Retire implemented work from planning documents - Plan

## Goal Capsule

Contributors can find remaining work without reading completed delivery narratives.
The lead delivers a documentation-only cleanup, with implementation evidence kept
in Git history. The user has authorized cleanup and the subsequent merge and push;
Git operations remain the lead's responsibility.

## Product Contract

`ROADMAP.md` already identifies itself as the record of future work and unresolved
acceptance. Its runtime opcode-cache and tooling-package sections also contain long
completed-delivery narratives. The plan directory has fourteen existing plans,
including two React Native plans excluded from this cleanup. The review baseline
is master commit `92dfdbec9010974bcf7654aa277077080cd95379`.

### Requirements

- R1. Remove an in-scope plan only when its requirements and acceptance gates are
  supported by implementation, history, and recorded verification evidence.
- R2. Keep partial, open, or uncertain work and all outstanding acceptance gates
  discoverable in surviving plans and `ROADMAP.md`.
- R3. Replace completed roadmap narratives with actionable remaining work and
  inline evidence or links to maintained documentation where context is needed.
- R4. Repair references from surviving tracked documents to deleted plans, using
  current documentation for current contracts and commit-specific links for history.
- R5. Keep React Native and Essentia plans unchanged, and change no application code.
- R6. Preserve completed evidence in Git history without creating an archive;
  retire this cleanup plan after delivery in a separate local commit.

## Planning Contract

- KTD1. Review each plan against its own acceptance criteria before selecting it
  for deletion (R1, R2). A completion label or the absence of callers is insufficient.
- KTD2. Keep historical context through Git and maintained guides (R3, R4, R6).
  This avoids introducing a second planning archive that needs maintenance.
- KTD3. Treat documentation checks as the acceptance gate for this change (R5).
  Existing application test receipts establish delivery evidence; this cleanup
  does not rerun application suites or claim new runtime verification.

## Implementation Units

### U1. Classify existing plans and remaining acceptance

Read the twelve in-scope plans and compare their stated work with source, commit
history, current guides, and verification receipts. Identify deletion candidates
and any remaining work that `ROADMAP.md` must retain. Covers R1 and R2; follows KTD1.
Files: `docs/plans/*.md`, `ROADMAP.md`, and read-only evidence sources.
Verification: every deletion candidate has positive delivery evidence; every
uncertain or incomplete requirement stays represented. No new tests are needed.

### U2. Retire completed documents and repair their references

After U1, delete supported candidates and trim completed roadmap narratives.
Repair tracked documentation links under R4 and keep concise evidence beside
remaining work. Covers R3–R5; follows KTD2.
Files: selected in-scope `docs/plans/*.md`, `ROADMAP.md`, and surviving tracked
documents that reference deleted plans.
Verification: check deletion names against tracked references, confirm replacement
targets exist, and compare the revised roadmap with U1's remaining-work inventory.

### U3. Verify delivery and retire this plan

After U2, review the final documentation diff and its references. Record the cleanup
and its check results in the delivery commit, then remove this plan in a separate
local commit under R6. The user-authorized merge and push are handled by the lead.
Files: this plan and the U2 change set.
Verification: the final tree retains the open-work inventory and has no newly
broken local plan references.

## Verification Contract

Run `git diff --check`. Search tracked documents for every removed basename and
resolve each surviving reference. Confirm local replacement paths exist and
historical links use a commit containing the referenced document. Review the diff
against R1–R6, including unchanged React Native and Essentia plans. Apply the
repository prose audit to rewritten documentation. Preserve exact outstanding
suite counts and gate names when they are relevant to remaining acceptance.

## Definition of Done

Only plans with evidenced completion are removed. Remaining work and acceptance
gates are represented, surviving references resolve, and the documentation diff
passes whitespace and prose checks. Delivery commits retain the evidence and check
results; this plan is absent from the final tree after its retirement commit.
