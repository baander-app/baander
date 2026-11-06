---
title: Two-Command Copy Pacing - Plan
type: fix
date: 2026-11-06
topic: two-command-copy-pacing
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: code
---

# Two-Command Copy Pacing - Plan

## Goal Capsule

- **Objective:** The backend quality gate's two-command browser drills pass on GitHub's hosted runner and on a fast developer host, so the gate proves independent web and worker media ownership again instead of stopping at proofs that the owned stream copy and the redesigned seek control broke.
- **Means:** The worker paces an owned stream copy at the source so its published lead stays inside the throttle band every producer already obeys (KTD1), and the drill drives the movie seek control through its accessible slider attributes instead of an input's attributes (KTD2).
- **Product authority:** The maintainer's instruction on 2026-11-06 to pace the copy and fix what the current CI run fails on. The Product Contract wins on what the drill proves; the Planning Contract on how.
- **Execution profile:** Worker code, unit and media tests, the drill script and the roadmap, on a branch named after this plan, landed as local commits. Verified by the copy argument unit test, the segment encoder media tests in the CI image, and the two-command Chromium drill run locally in the rebuilt worker image. Push when the user has asked, as they have for this work.
- **Stop conditions:** Stop and ask if pacing the copy makes a media test fail for a reason other than the new arguments, or if the drill fails a proof whose expectation is itself a product question.
- **Who finishes:** The implementer runs the gates, records the delivery in ROADMAP.md and pushes; the GitHub run then shows whether later gate steps hold.

## Product Contract

### Summary

An owned stream copy no longer outruns the worker's lead throttle: FFmpeg reads the copy source with an initial burst and then at a bounded multiple of real time, so the throttle engages for copies as it does for encodes. The two-command drill reads the movie seek slider's accessible minimum, maximum and value and drags along its track, so its seek proofs work against the Radix slider the overlay has used since 2026-10-30.

### Problem Frame

The worker pauses a producer when its published end runs 72 seconds ahead of the playhead and resumes it at 36 seconds, checking every 100 ms. An owned stream copy is I/O bound and publishes a whole 180-second movie inside one check, so the throttle never engages. The drill's attached proof, written for producers that publish progressively, requires unpublished source beyond the first window and fails on a fast host; on GitHub run 42235123759 it passed only because the hosted runner had published 150 of 180 seconds when it looked.

That run then failed "Source seeking must span the full source duration, 0 !== 180". The movie overlay's seek control became a Radix slider on 2026-10-30: its `role=slider` element carries `aria-valuemin`, `aria-valuemax` and `aria-valuenow`, not an input's `max` and value, and pointer gestures land on its root, not on the thumb. The drill still reads `max` and the input value and drags the thumb's box. No runner has executed these proofs since the Forgejo runner went offline on 2026-10-26.

### Key Decisions

- **Pace the copy in the worker, not in the drill.** (session-settled: user-directed — chosen over restating the published-window proof for copies: the throttle band is the product's own bound on how far a producer runs ahead, and the copy should obey it like every encode.) Governs R1, R2.
- **The drill adapts to the product's slider.** The overlay keeps its Radix slider; the drill reads its accessible attributes. Governs R4, R5.

### Requirements

**Copy pacing**

- R1. The copy command reads its source with an initial burst and then at a bounded multiple of real time, so the published lead over the playhead stays inside the worker's throttle band.
- R2. The burst and rate are named constants next to the copy command, documented against the throttle thresholds, and covered by the copy argument unit test.
- R3. The segment encoder media tests that run the copy command still pass in the CI image.

**Drill seek control**

- R4. The drill reads the movie seek slider's duration and position from its accessible maximum and value, and drags along the slider's root, through one set of helpers used at every seek site.
- R5. The attached, client-controls and movie-endpoint phases drive the slider through those helpers; no site reads an input attribute.

**Record**

- R6. ROADMAP.md records the delivery, the local drill outcome and the GitHub run that follows.

### Acceptance Examples

- AE1. **Covers R1, R2.** Given a copy selection with audio, when the arguments are built, then the input options carry the burst seconds and the read rate before the source, and the output options are unchanged.
- AE2. **Covers R4.** Given the overlay's slider at duration 180 and position 12, when the drill reads it, then it reports 180 and 12 from the accessible attributes.
- AE3. **Covers R1, R4, R5.** Given the two-command Chromium drill in the rebuilt worker image on this host, when the attached phase runs, then the published window is shorter than the burst plus a few seconds of playback, the seek beyond the window succeeds, and the phase completes.

### Scope Boundaries

- The overlay and the Radix slider are unchanged.
- The throttle thresholds are unchanged; the copy's burst sits below the pause threshold so the throttle takes over.
- Considered and not built: a test-only pacing switch in the worker. A production copy that outruns the throttle is the defect; the fix belongs to the product.
- Later drill proofs that fail for other reasons are recorded, not fixed here, unless they are a direct consequence of these two changes.

## Planning Contract

### Key Technical Decisions

- KTD1. **FFmpeg read-rate pacing with an initial burst.** The copy command passes `-readrate_initial_burst` and `-readrate` as input options before `-i`; the image's FFmpeg 7.1.5 supports both. The burst is 48 seconds, under the 72-second pause threshold so the first window appears at once and the throttle governs from there; the rate is 2.0 so the lead regrows after a resume at 36 seconds. Both are constants on the builder with a comment naming the thresholds in the session subscriber. (session-settled: user-directed, inherited from the Key Decision governing R1 and R2 — chosen over restating the proof for copies.)
- KTD2. **Accessible slider helpers in the drill.** The drill gains helpers that resolve the slider's root element (the nearest ancestor with a text direction attribute, which Radix renders on its root), read `aria-valuemax` and `aria-valuenow`, and translate a target position to a pointer coordinate on the root's box. Every site that read `max`, the input value or the thumb's box uses them. Keyboard Home and End keep working on the thumb.
- KTD3. **Media tests prove the command still copies.** The existing segment encoder media tests exercise copy commands on short sources that stay inside the burst, so they prove the arguments are accepted; the local drill proves the pacing on a long source.

### Sources

- `src/Transcode/Infrastructure/Swoole/TranscodeSessionSubscriber.php`: the lead throttle (pause at 72 s ahead, resume at 36 s, polled every 100 ms).
- `src/Transcode/Infrastructure/FFmpeg/CopySegmentArguments.php` and `tests/Unit/Transcode/Infrastructure/FFmpeg/CopySegmentArgumentsTest.php`: the copy command and its test.
- `ui/web/src/features/catalog/components/MoviePlayerOverlay.tsx` and `ui/web/src/shared/components/ui/slider.tsx`: the seek control, a styled Radix slider since f64ec31a6.
- `ui/web/tests/e2e/two-command/browser.mjs`: the slider sites at the source-seek routine, the seek-outage proof, the movie-endpoint proof and the rapid-gesture proof.
- The GitHub artifact of run 42235123759: media duration 150 of 180 at the window proof, then the seek proof reading 0.
- `docs/solutions/best-practices/pace-held-frame-fixtures-by-generated-frames.md`: the sibling pacing learning for fixtures.

## Implementation Units

### U1. Pace the owned copy at the source

- **Goal:** The copy command carries the burst and read rate as R1 and R2 state.
- **Requirements:** R1, R2, R3. Implements KTD1, KTD3.
- **Dependencies:** none.
- **Files:** `src/Transcode/Infrastructure/FFmpeg/CopySegmentArguments.php`, `tests/Unit/Transcode/Infrastructure/FFmpeg/CopySegmentArgumentsTest.php`.
- **Approach:** Add the two constants and insert the two input options immediately before `-i`, after the boundary seek options, so they apply to the single input. Extend the unit test to assert both values and their placement before the input.
- **Patterns to follow:** The builder's existing option ordering and the test's option-value helper.
- **Test scenarios:**
  - Covers AE1. Copy with audio: `-readrate_initial_burst` is 48 and `-readrate` is 2, both at indexes before `-i`.
  - Copy without audio: the same two options are present once each.
  - The excluded encode options the existing test lists are still absent.
- **Verification:** The copy argument unit test passes, and the segment encoder media tests pass in the CI image.

### U2. Drive the Radix slider in the drill

- **Goal:** The drill's seek proofs work against the overlay's slider.
- **Requirements:** R4, R5. Implements KTD2.
- **Dependencies:** none.
- **Files:** `ui/web/tests/e2e/two-command/browser.mjs`.
- **Approach:**
  1. Add helpers near the source-seek routine: one returns the slider's accessible maximum, one its accessible value, one the root's bounding box.
  2. Replace every `getAttribute('max')` and `inputValue()` read and the thumb-box drag with the helpers.
  3. Keep the pointer-down draft read: Radix updates the value on pointer down, so the helper read before pointer-up still returns the draft.
- **Patterns to follow:** The drill's existing `until` and `waitFor` loops; the Playwright role locator already used for the slider.
- **Test scenarios:**
  - Covers AE2 and AE3. The two-command Chromium drill in the rebuilt worker image passes its attached phase locally, including the seek beyond the window, the paused seeks and the return to the start.
  - The movie-endpoint phase's slider receipt records the accessible maximum and value.
- **Verification:** The local drill passes the attached phase; any later failure is recorded with its proof name.

### U3. Roadmap record

- **Goal:** The delivery and its outcomes are recorded.
- **Requirements:** R6.
- **Dependencies:** U1, U2.
- **Files:** `ROADMAP.md`.
- **Approach:** Add a checkpoint bullet under the 2026-11-06 checkpoint naming the pacing values, the slider helpers, the test counts and the local drill result, and rewrite the open item on the drill proofs to what remains.
- **Test expectation:** none -- documentation only; `git diff --check` and the prose audit cover it.
- **Verification:** The entry names the commits and the counts.

## Verification Contract

| Check | Command or outcome | Applies to |
|---|---|---|
| Copy argument unit test | `BAANDER_TEST_IMAGE=baander-ci-prewarm:local bash scripts/test-unit-container.sh --filter CopySegmentArgumentsTest` passes | U1 |
| Segment encoder media tests | the same runner with `--filter 'SegmentEncoder.*MediaTest'` passes | U1 |
| PHP static gates | PHPStan on `src` in the CI image reports no errors | U1 |
| Two-command drill | `scripts/test-two-command-deployment-container.sh` in `baander-ci-prewarm:local-worker` rebuilt from the branch, with the CI step's environment plus `BAANDER_TEST_DOCKER_SOCKET_PATH`, passes the attached phase | U1, U2 |
| Whitespace and prose | `git diff --check`; the prose audit on the roadmap text | U3 |

## Definition of Done

- The copy command paces its source with the documented burst and rate, pinned by the unit test, and the media tests pass.
- The drill reads and drives the slider through the accessible helpers at every site.
- The local drill passes the attached phase, or the next failing proof is named in the roadmap with its evidence.
- ROADMAP.md records the delivery and passes the prose audit.
- No experimental code remains, and no source, test or comment cites this plan or its identifiers.
