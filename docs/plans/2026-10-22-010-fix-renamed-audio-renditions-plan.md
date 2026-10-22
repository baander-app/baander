---
title: Renamed Audio Renditions - Plan
type: fix
date: 2026-10-22
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: code
---

# Renamed Audio Renditions - Plan

## Goal Capsule

- **Objective:** A listener can request transcoded audio after ingestion repairs a source rename.
- **Means:** Include the resolved source path in the shared rendition signature (KTD1).
- **Authority:** The Product Contract governs behavior; the Planning Contract governs implementation within it.
- **Execution profile:** Lightweight PHP fix with unit coverage and a real worker regression.
- **Stop conditions:** Stop for evidence that the change needs a schema, public transport contract or unrelated media redesign.
- **Completion owner:** The lead integrates, runs authoritative checks, updates the roadmap and commits locally; push only when requested.

## Product Contract

### Summary

Make a source rename select an independent audio rendition while retaining the repaired track's identity.
Keep existing cache reuse and worker ownership behavior.

### Problem Frame

Ingestion preserves a track's identity when it repairs a source rename.
A physical rename can preserve modification time and size, which currently leaves the rendition signature unchanged.
The durable runtime then receives the old rendition key with a different immutable source path and rejects admission, preventing transcoded playback.
The follow-up is recorded under “Transcoding after a source rename” in `ROADMAP.md`.

### Requirements

- R1. A transcoded request after a repaired source rename succeeds when the track ID, modification time, size, format and bitrate are unchanged.
- R2. The renamed source receives a distinct rendition identity and output directory, leaving an earlier completed rendition's files intact.
- R3. Repeated requests for the unchanged current source reuse its completed rendition.
- R4. Existing source-change rejection, generation fencing, lease-loss handling and rebuild after eviction remain valid.

### Acceptance Examples

- AE1. **Covers R1, R2:** Complete a rendition, rename its source on disk without changing its modification time or size, and request the same track and encoding again; a real worker completes a second durable rendition in a separate directory.
- AE2. **Covers R3:** Request the renamed source again after completion; the completed bytes and durable identity are reused without another generation.
- AE3. **Covers R4:** A source whose relevant metadata changes after admission is rejected by execution, and existing lease and eviction regressions continue passing.

### Scope Boundaries

This change covers the internal PHP rendition signature and its callers, focused regressions, and the affected audio-rendition documentation.
It excludes playlist work, ingestion matching changes, schema changes, compatibility fallbacks and content hashing for an in-place replacement that preserves the same path and metadata.

## Planning Contract

### Key Technical Decisions

- KTD1. **Use one path-aware source signature.** Require the resolved absolute source path in `AudioRenditionFormat::sourceSignature()` alongside its current inputs, and use that signature in both cache admission and executor validation to satisfy R1–R4.
- KTD2. **Separate storage through the existing resolver.** Retain the runtime key and segment resolver designs; KTD1 must change both the derived rendition key and the signature-based output directory, because a key-only change would allow two first-generation jobs to share output files.
- KTD3. **Prove the durable worker path.** Extend `AudioRenditionWorkerHandoffTest` using its existing PostgreSQL harness, native execution fixture and real ffmpeg worker for AE1 and AE2; keep persistence and worker production code unchanged unless the regression demonstrates a necessary defect in the planned signature fix.

### Assumptions

The stream path resolver supplies the resolved absolute path already used as immutable rendition input.
A rename may leave old cache files until the existing sweep removes them.
No new domain term is introduced.

### Grounding

`src/Transcode/Application/Port/AudioRenditionFormat.php` owns the shared signature.
`src/Transcode/Infrastructure/Transcode/AudioRenditionCache.php` derives admission and output location from it, while `src/Transcode/Infrastructure/Transcode/AudioRenditionExecutor.php` validates it before work.
`src/Transcode/Infrastructure/Doctrine/AudioRenditionRuntime.php` enforces immutable admitted input.
The local `docs/solutions/` titles contain no matching rendition or rename solution.

## Implementation Units

### U1. Isolate renditions after a source rename

- **Goal:** Deliver R1–R4 and AE1–AE3 with the shared signature fix and focused regression coverage.
- **Dependencies:** None.
- **Files:** `src/Transcode/Application/Port/AudioRenditionFormat.php`; `src/Transcode/Infrastructure/Transcode/AudioRenditionCache.php`; `src/Transcode/Infrastructure/Transcode/AudioRenditionExecutor.php`; `tests/Unit/Transcode/Application/Port/AudioRenditionFormatTest.php`; `tests/Unit/Media/AudioRenditionCacheTest.php`; `tests/Unit/Media/AudioRenditionExecutorTest.php`; `tests/Fixtures/WebRuntime/HeldRendition.php`; `tests/Integration/AudioRenditionWorkerHandoffTest.php`.
- **Approach:** Add KTD3's regression and observe the admission failure first, then implement KTD1 and KTD2 and update every signature caller and the executor test's manually reconstructed hash.
- **Test scenarios:** Unit coverage checks path sensitivity and unchanged-input stability; the worker regression asserts equal metadata across the physical rename, successful nonempty encoded output, two completed runtime rows, distinct keys and first-generation output locations, intact earlier output, and repeated reuse of the new rendition.
- **Verification:** Run the focused unit and durable worker/runtime tests, followed by the backend gates in the Verification Contract.

### U2. Record the delivered behavior and checks

- **Goal:** Document the rendition lifecycle and record the completed follow-up with its verification evidence.
- **Dependencies:** U1.
- **Files:** `ROADMAP.md`; `docs-book/part-2-developer-guide/contexts/transcode.md`.
- **Approach:** Replace the roadmap's rename follow-up with delivered behavior and exact check counts. Correct only the guide's Audio Renditions subsection to describe durable admission, worker execution, path-aware identity and the existing sweep lifecycle; verify those statements against source and preserve configuration and HTTP behavior.
- **Verification:** Apply `prose-fix`, inspect the documentation diff against source and run `git diff --check`.

## Verification Contract

Use the CI-target PHP image and disposable backend coordinator documented in `docs-book/part-2-developer-guide/testing.md`; serialize shared runtime tests.
Do not use the development database or production services.

- Run focused checks with `bash scripts/test-backend-container.sh --testsuite Unit,Integration --filter 'AudioRendition(Format|Cache|Executor|WorkerHandoff|RuntimeLease)Test'`, selecting available qualified CI-target images through the documented runner environment variables.
- Run the affected Transcode/Media Unit coverage and durable media runtime Integration coverage through that coordinator after focused checks pass; expand only for changed behavior or unresolved failures.
- Run the affected audio-stream Functional coverage through `scripts/test-functional-container.sh` and the WebRuntime held-rendition fixture coverage after its signature update.
- Run PHPStan and Deptrac in the same CI image using the repository's configured analysis commands; keep logs outside the tracked tree and record the final counts or failures.
- Run `ce-simplify-code` at the phase boundary and `ce-code-review` before completion, applying justified findings and repeating affected checks only when changes require it.
- Record actual suite counts and static-gate results in `ROADMAP.md`; document any environment blocker instead of claiming an unrun check passed.

## Definition of Done

R1–R4 hold with the real worker rename regression and existing ownership/rebuild coverage passing.
Every signature caller and manual test reconstruction uses the new input contract.
U2 matches implemented behavior and contains the verification checkpoint.
The final diff remains within this scope, required reviews are resolved, and local commits are on the plan's branch without a push.
