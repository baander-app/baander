---
title: Late Native Snapshot Capture - Plan
type: fix
date: 2026-11-06
topic: late-native-snapshot-capture
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: code
---

# Late Native Snapshot Capture - Plan

## Goal Capsule

- **Objective:** The backend quality gate on GitHub's hosted runners passes the ordinary movie playback scenarios whenever playback is correct, so a red gate there points at a playback or encoding defect and not at a frame snapshot the browser took one frame late.
- **Means:** The adaptive browser frame observer records a native snapshot that is one frame later than its callback as a lagged observation and keeps running, while earlier or further-off snapshots stay fatal (KTD1).
- **Product authority:** The maintainer's choice on 2026-11-06 between tolerating a one-frame-late snapshot, recording it under its own timestamp, and leaving the gate red. The Product Contract wins on what the gate proves; the Planning Contract on how the observer records it.
- **Execution profile:** Test-harness change on a branch named after this plan, landed as local commits, verified by the frame observer unit tests and the ordinary and held-frame scenarios run locally in the prewarm CI image. Push only when asked.
- **Stop conditions:** Stop and ask if a local scenario run with the change still fails a playback assertion, or if a lagged or timestamp diagnostic from a real browser reports no frame duration, because the tolerance rule depends on it. The GitHub failure diagnostics already report 40000 microseconds for the ordinary sources, and a passing receipt carries no native duration.
- **Who finishes:** The implementer runs the gates, records the delivery in ROADMAP.md, offers the solutions note at the completion checkpoint and hands the branch to review. Pushing and watching the GitHub gate is the user's call.

## Product Contract

### Summary

The frame observer that samples movie frames during the adaptive browser scenarios stops treating a snapshot taken one frame after its callback as a fatal error. It closes that frame, counts it as a lagged observation, publishes no colour sample for it and continues. A snapshot that is earlier than its callback, more than one frame later, or whose frame duration is unknown stays fatal with today's diagnostics. The lagged count and a bounded sample of its diagnostics reach the scenario receipt. Every playback assertion, the encoder and the fixture commands stay as they are.

### Problem Frame

Every CI run on master since 6f76024cb fails the backend quality gate on GitHub at the step that plays the ordinary delayed-audio movie. The evidence from runs 42121036684 and 42120445948 shows the browser script aborting with one observer error: the native snapshot's timestamp was 13.40 against a callback media time of 13.36 in one run, and 15.40 against 15.36 in the other. Both are exactly one 40 ms frame apart. The callback ran about 17 ms after the frame's expected display time, and by then Chromium had presented the next frame, so constructing a native frame from the video element captured that next frame.

Playback was correct in both runs: the producers were running, no media or transport error was logged, the 25 fps cadence held, and the same scenario passes locally at HEAD in the prewarm image with 743 frames observed. The failure is the harness losing its chance to inspect one frame, which the harness treats as fatal by design: the unit test "timestamp mismatch stays fatal" pins it with a fixture that is also one frame later. On the slower hosted runner that race happens often enough to fail every run, so the GitHub gate has never been green on master and the roadmap's dual-forge acceptance item cannot close.

### Key Decisions

- **A one-frame-late snapshot is a missed observation, not a failure.** (session-settled: user-directed — chosen over recording the lagged snapshot under its own timestamp and over leaving the gate red until dual-forge acceptance resumes: the playback assertions already tolerate missed callbacks, so tolerating the compositor swap keeps every proof as strict as today.) Governs R1, R2, R3.
- **The change is confined to the test harness.** (session-settled: user-approved — the user asked whether the change would break the encoding; the encoder, the fixture commands and the playback assertions are untouched.) Governs R4.

### Requirements

**Observation handling**

- R1. When the native snapshot's timestamp is later than the callback's media time by more than the comparison tolerance and by at most one frame duration, the observer closes the frame, counts a lagged observation, publishes no colour sample for that sequence and keeps observing.
- R2. When the snapshot is earlier than the callback, later by more than one frame duration, or the frame reports no finite positive duration, the observer fails in the `timestamp` phase with the diagnostics it records today and stops.
- R3. A lagged sequence never blocks in-order publication of the colour samples that follow it.

**Scope**

- R4. The segment encoder, the fixture bootstrap commands, the CI workflows and every playback assertion in the sparse browser script are unchanged.

**Visibility**

- R5. The scenario receipt carries the lagged count and a bounded sample of lagged diagnostics, in the observer state the script already copies into its snapshots and its cleanup record.
- R6. The ordinary-scenario frame cadence receipt names the lagged count beside the callback and unique-frame counts.

**Record**

- R7. ROADMAP.md records the delivery with its verification checkpoint under the dual-forge acceptance work. At the completion checkpoint the implementer offers a note under `docs/solutions/` on why the compositor can swap the frame before the snapshot, as the project's compound rule requires, and writes it only if the offer is accepted.

### Acceptance Examples

- AE1. **Covers R1, R3.** Given a callback at media time 24.6 and a snapshot with timestamp 24.64 and duration 40 ms, when the observer handles it, then the frame is closed, the lagged count is 1, no colour sample is published, the observer has no errors, a further callback is requested, and a later matching frame at 24.68 publishes as the next sample.
- AE2. **Covers R2.** Given a callback at 24.6 and a snapshot at 24.56, when the observer handles it, then it records one `timestamp` error carrying the callback, native and stage diagnostics in today's shape, closes the frame once and stops.
- AE3. **Covers R2.** Given a callback at 24.6 and a snapshot at 24.68 with duration 40 ms, then the observer fails as in AE2.
- AE4. **Covers R2.** Given a callback at 24.6 and a snapshot at 24.64 with no duration, then the observer fails as in AE2.
- AE5. **Covers R5, R6.** Given the ordinary delayed-audio scenario run in the prewarm image, when it completes, then its receipt's final observer state and cleanup record carry the lagged count, and the frame cadence receipt names it.

### Scope Boundaries

- The ten-second late publication of frames generated after a held-frame source's last packet, recorded as an open item in ROADMAP.md, is not addressed.
- The 60 percent coverage thresholds and the cadence assertions in the sparse browser script are not relaxed.
- Considered and not built: tolerating a snapshot more than one frame late. A two-frame gap would also hide a stalled compositor, and the evidence shows exactly one frame; a run that produces a larger gap should fail and show it.
- Considered and not built: publishing the lagged snapshot under its own timestamp. The sample would carry a callback's clock and stage timings for a different frame, and the cadence assertions would then measure the observer's lag instead of the presentation.

## Planning Contract

### Key Technical Decisions

- KTD1. **The tolerance is one frame, measured by the snapshot's own duration.** A snapshot counts as lagged when its timestamp is later than the callback's media time by at most one native frame duration. The comparison keeps the existing microsecond allowance at both ends: a gap within that allowance of zero is a match, and a gap within it of one frame duration is still one frame. The duration comes from the native frame, which the evidence reports as 40000 microseconds for the ordinary sources and which is one second for the held-frame sources, so the same rule covers both without a fixed constant. A snapshot without a finite positive duration cannot be classified and stays fatal. A fixed 40 ms window was rejected because it would misclassify the held-frame scenarios. (session-settled: user-directed, inherited from the Key Decision governing R1 and R2 — the one-frame tolerance was chosen over recording the lagged snapshot under its own timestamp and over leaving the gate red.)
- KTD2. **Lagged observations live on the observer state the scripts already copy.** The observer gains a lagged counter and a bounded list of lagged diagnostics in the shape of today's error diagnostics, capped at sixteen entries with an overflow counter, so the scenario's snapshots and its cleanup record surface them with no new plumbing. The sparse browser script adds the count to the ordinary frame cadence receipt and asserts nothing new about it.
- KTD3. **A lagged sequence is consumed, not completed.** The observer publishes colour samples in callback order by waiting for each sequence number in turn, so a sequence that never completes would hold every later sample back. A lagged sequence is marked as consumed so the publication cursor steps over it.
- KTD4. **Fatal paths keep their shape.** The earlier-than-callback, further-than-one-frame and unknown-duration cases raise in the `timestamp` phase with the same message form and diagnostics as today, so the existing unit assertions about diagnostics move to those cases rather than being rewritten.

### Sources

- `ui/web/tests/e2e/transcode-adaptive/frame-observer.mjs`: the observer, its `timestamp` phase and the in-order publication loop.
- `ui/web/tests/e2e/transcode-adaptive/frame-observer.test.mjs`: the harness whose native frame stub takes a per-frame timestamp and clock, and the pinned fatal mismatch test.
- `ui/web/tests/e2e/transcode-adaptive/sparse-browser.mjs`: the scenario's observer assertions, the frame cadence receipt and the cleanup check.
- The `backend-verification` artifacts of GitHub runs 42121036684 and 42120445948, under `var/test-runs/transcode-ordinary-delayed-fresh/browser/result.json`: the two failures, each one frame late with a 40000 microsecond frame duration.
- `docs/solutions/best-practices/pace-held-frame-fixtures-by-generated-frames.md`: the sibling note on the held-frame fixtures and the shape of a solutions entry.

## Implementation Units

### U1. Lagged observation path in the frame observer

- **Goal:** The observer tolerates a one-frame-late snapshot as R1 and R3 state, keeps the fatal cases of R2, and the unit tests pin both.
- **Requirements:** R1, R2, R3, R5. Implements KTD1, KTD2, KTD3, KTD4.
- **Dependencies:** none.
- **Files:** `ui/web/tests/e2e/transcode-adaptive/frame-observer.mjs`, `ui/web/tests/e2e/transcode-adaptive/frame-observer.test.mjs`.
- **Approach:**
  1. In the `timestamp` phase, compute the gap between the snapshot time and the callback's media time and classify it by KTD1 before the existing throw.
  2. On the lagged branch, record the diagnostic per KTD2, mark the sequence consumed per KTD3, close the frame through the owned-frame path so constructed and closed stay equal, and return without touching the pending copy bookkeeping.
  3. Keep the throw for every other mismatch, with the message naming both timestamps as today.
  4. Extend the test harness so a frame specification can override the native duration, then replace the pinned fatal one-frame-later test with the lagged case and add the fatal cases.
- **Patterns to follow:** The bounded `errors` list with its overflow counter in the observer; the diagnostic object built per callback; the matching-frames test that drives several callbacks and checks publication order.
- **Test scenarios:**
  - Covers AE1. One-frame-late snapshot: lagged count 1, lagged diagnostic retains the callback, native and stage fields, frame closed once, no colour sample, errors empty, observer not stopped, next callback requested.
  - Covers AE1. Lagged sequence followed by two matching frames: the matching frames publish as samples with sequences 1 and 2 in order.
  - Covers AE2. Earlier snapshot: one `timestamp` error with the diagnostic assertions the existing test makes today, frame closed once, observer stopped.
  - Covers AE3. Two-frames-late snapshot: fatal as AE2.
  - Covers AE4. One-frame-late snapshot with no duration: fatal as AE2.
  - Seventeen lagged snapshots: the diagnostics list holds sixteen and the overflow counter reads one.
  - A lagged snapshot while a deferred copy is pending, followed by a matching frame: the pending count is unchanged, no sample publishes until the deferred copy resolves, then sequences 0 and 2 publish in order and cleanup reports constructed equal to closed.
- **Verification:** The frame observer and video cancellation unit tests pass under the Node test runner from `ui/web`.

### U2. Lagged count in the scenario receipt

- **Goal:** The scenario receipt names the lagged count where a reader looks for frame coverage.
- **Requirements:** R4, R6. Implements KTD2.
- **Dependencies:** U1.
- **Files:** `ui/web/tests/e2e/transcode-adaptive/sparse-browser.mjs`.
- **Approach:** Add the lagged count from the observer state to the ordinary frame cadence receipt. Change no assertion.
- **Patterns to follow:** The existing frame cadence receipt fields.
- **Test scenarios:**
  - Covers AE5. The ordinary delayed-audio fresh scenario in the prewarm image passes and its receipt carries the lagged count in the final observer state, the cleanup record and the frame cadence entry.
  - The ordinary delayed-audio seek scenario passes.
  - The sparse fresh scenario passes, showing the held-frame path is unchanged.
- **Verification:** The three scenarios pass locally with their receipts written under the scratch directory.

### U3. Roadmap entry and the solutions-note offer

- **Goal:** The delivery and its checkpoint are recorded where the project keeps them, and the reasoning is offered for a solutions note.
- **Requirements:** R7.
- **Dependencies:** U1, U2.
- **Files:** `ROADMAP.md`; a new note under `docs/solutions/best-practices/` only if the offer is accepted.
- **Approach:**
  1. Add a dated checkpoint to the dual-forge acceptance section in ROADMAP.md naming the GitHub failures, the change and the gate counts, and amend the "Dual-forge acceptance is partial" open item to say the ordinary-scenario failure is fixed and the GitHub gate still needs its green run.
  2. Run `prose-fix` on the roadmap text.
  3. At the completion checkpoint, offer the solutions note once: the symptom, the compositor swap before the snapshot, the one-frame rule, what stays fatal, and how to read the lagged count in a receipt. If accepted, write it with the frontmatter the sibling notes use and run `prose-fix` on it.
- **Patterns to follow:** The checkpoint bullets and open items of the sibling roadmap sections; the frontmatter and section order of the pacing note.
- **Test expectation:** none -- documentation only; `git diff --check` and the prose audit cover it.
- **Verification:** The roadmap entry names the commit and the counts, and any note's audit shows no lost facts.

## Verification Contract

| Check | Command or outcome | Applies to |
|---|---|---|
| Frame capture unit tests | `node --test tests/e2e/transcode-adaptive/frame-observer.test.mjs tests/e2e/transcode-adaptive/video-cancellation.test.mjs` from `ui/web`, the same files the CI step "Browser frame capture and video replacement evidence" runs | U1 |
| Ordinary scenarios | `scripts/test-transcode-adaptive-browser.sh` with `BAANDER_TEST_IMAGE=baander-ci-prewarm:local`, `BAANDER_TEST_VIDEO_SCENARIO` set to `ordinary-delayed-fresh` and then `ordinary-delayed-seek`, and `BAANDER_TEST_ARTIFACTS` under the scratch directory; both print `passed: true` | U2 |
| Held-frame regression | the same script with `sparse-fresh`; prints `passed: true` | U2 |
| Lint | `eslint .` in `ui/web` covers only TypeScript files, so no lint applies to these modules; state that in the roadmap checkpoint | U1, U2 |
| Whitespace and prose | `git diff --check`; the `prose-fix` audit on the roadmap text and the note | U3 |

## Definition of Done

- The one-frame-late snapshot is recorded as lagged and the three fatal cases remain fatal, each pinned by a unit test.
- The unit tests, the two ordinary scenarios and the sparse fresh scenario pass locally with the counts recorded in ROADMAP.md.
- The receipts of the local runs carry the lagged count.
- No encoder, fixture, workflow or playback assertion changed.
- ROADMAP.md is written and passes the prose audit, and the solutions note has been offered.
- No experimental or abandoned code remains in the diff, and no source, test or comment cites this plan or its identifiers.
