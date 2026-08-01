# Task for worker

You are a delegated subagent running from a fork of the parent session. Treat the inherited conversation as reference-only context, not a live thread to continue. Do not continue or answer prior messages as if they are waiting for a reply. Your sole job is to execute the task below and return a focused result for that task using your tools.

Task:
Implement U4 (seek + throttle integration) for the long-process-segmenter refactor.

Plan: /home/martin/dev/baander/docs/plans/2026-07-16-001-refactor-long-process-segmenter-plan.md — read ONLY the "### U4." section, KTD-1 (FINAL), KTD-3 (RESOLVED, hybrid). Do NOT read other units.

You are on branch feat/long-process-segmenter. Commits U1, U2, U3, U5 shipped. Repo root: /home/martin/dev/baander.

GOAL: Rewire TranscodeSessionSubscriber to use the NEW TranscodeStreamManager (U3) for the video segment path, replacing the per-segment dispatchSegments() loop. Implement seek/throttle per KTD-3 (SIGSTOP/SIGCONT for throttle; kill+restart-with-headroom for seek).

CRITICAL — what changes and what stays:

CHANGES (the video path only):
1. Step 9 in runEncodingLoop() (around line 302-303): replace `$this->dispatchSegments(...)` with a NEW method `dispatchLongStream(...)` that:
   - Calls `$this->streamManager->startStream($job, $sourcePath, $tier, $videoFilters)` ONCE
   - Enters a poll loop: while ($this->streamManager->pollOnce($job->getId())) { handle seek/throttle signals }
   - On each poll iteration, also check SeekSignalBroker via `$this->seekSignalBroker->waitForSignal($job->getId(), 0.1)`:
     - signal['action'] === 'pause' → `$this->streamManager->pauseStream($job->getId())`, mark session paused, persist
     - signal['action'] === 'resume' (or seek) → `$this->streamManager->resumeStream($job->getId())`, mark resumed
     - signal['action'] === 'seek' with position P → KTD-3 seek path: `$this->streamManager->stopStream($job->getId())`, `$this->availability->clearJob($job->getId())`, compute `$startSegment = max(0, (int)floor(P / SegmentEncoder::getSegmentDuration()) - 3)` (headroom=3, matches toy project), `$this->streamManager->startStream($job, $sourcePath, $tier, $videoFilters, $startSegment)`, mark session resumed, persist
   - Buffer-depth throttle: track the session's current playback position (from SeekSignalBroker / PlaybackPositionChanged — the subscriber already receives these via seekSignalBroker). Compute `$aheadSegments = $lastProducedSegment - $playbackSegment`. When `$aheadSegments >= $throttleHigh` (default 12 segments), call pauseStream. When `$aheadSegments <= $throttleLow` (default 6), call resumeStream. Track $lastProducedSegment from pollOnce's scanning (you may need TranscodeStreamManager to expose a getLastProducedSegmentIndex(jobId) method — if it doesn't, approximate via the job's completedSegments count or the marked segments; or add a getter to the manager).
   - After the loop (stream complete), mark all produced video segments complete on the job via `$job->markSegmentCompleted(...)` — read each segment file's size from the output dir (glob seg_*.m4s) and mark them. Mirror how the OLD dispatchSegments' processSegmentResult did it.

2. Add `TranscodeStreamManager` as a constructor dependency (nullable to preserve the existing optional-dep pattern: `?TranscodeStreamManager $streamManager = null`). Also add `?SegmentAvailabilityInterface $availability = null` for the clearJob call. Wire BOTH in config/services.yaml.

3. The OLD `dispatchSegments()` private method (around line 699) becomes dead for the video path. REPLACE its body with a thin compatibility shim that delegates to dispatchLongStream, OR delete it and update the one caller. Prefer deletion — cleaner. Keep dispatchFirstAudioSegment() and dispatchAudioSegments() UNCHANGED (audio stays on the pool per U3's design decision).

4. The `finally` block in runEncodingLoop must call `$this->streamManager->stopStream($job->getId())` (defensive — ensures no leaked FFmpeg process if the loop exits via exception). Add this to the existing finally that already does seekSignalBroker->close() etc.

STAYS UNCHANGED:
- Steps 1-8 (probe, in_progress, filters, init segment, loudness, audio init, first audio segment) — all still use the pool via processPool
- Step 10 (dispatchAudioSegments) — audio stays on the pool
- Step 10/subtitles — unchanged
- Step 11 (mark completed) — keep, but it depends on segments being marked complete; ensure dispatchLongStream marks them before Step 11 runs
- The `processPool` constructor dependency stays (init/loudness/audio/subs still use it)
- The loop lock renewal (startLockRenewal/stopLockRenewal) stays
- booking table calls — LEAVE THEM for now (U6 removes them; don't touch in U4 to keep the diff focused). They're nullable and will no-op appropriately since the video path no longer books.

PATTERNS TO FOLLOW (read these first):
- src/Transcode/Infrastructure/Swoole/TranscodeSessionSubscriber.php — the WHOLE file. Pay attention to: runEncodingLoop structure, the existing dispatchSegments (line ~699) for how it polls + handles seek/pause (you're replacing video dispatch with the manager but the SIGNAL HANDLING logic is reusable), processSegmentResult, persistState/forcePersistState, the finally block.
- src/Transcode/Infrastructure/Swoole/TranscodeStreamManager.php (U3) — startStream/stopStream/pauseStream/resumeStream/pollOnce signatures
- src/Transcode/Infrastructure/Swoole/SeekSignalBroker.php — waitForSignal signature and the signal shape ['action' => 'pause'|'seek'|'resume', 'position' => float]
- src/Transcode/Infrastructure/FFmpeg/SegmentEncoder.php — getSegmentDuration()

CONSTRAINTS:
- PHP 8.5, strict_types, 2-space indent
- Do NOT modify any file other than: TranscodeSessionSubscriber.php, config/services.yaml
- Do NOT touch TranscodeStreamManager.php (if it needs a getter for last-produced-segment, note it in your report — do NOT edit it; instead approximate via glob in the subscriber)
- Do NOT run the full test suite. Run: php -l on TranscodeSessionSubscriber.php, and the existing subscriber unit tests if any (./vendor/bin/phpunit tests/Unit/Transcode — confirm no new failures vs the 342-test baseline; the only tests that may fail are Redis integration which need the docker network, ignore those)

TESTING:
- There are no direct unit tests for TranscodeSessionSubscriber (it needs heavy infrastructure). Your verification is: php -l clean, the existing transcode unit suite stays green (no NEW failures beyond Redis-reachability), and the code reads correctly.
- If you can add a focused unit test for dispatchLongStream's seek-position math (computing startSegment from position), do so in tests/Unit/Transcode/Infrastructure/Swoole/ — but only if it's cleanly testable without mocking the whole subscriber.

VERIFICATION (your done signal):
- php -l clean on TranscodeSessionSubscriber.php
- ./vendor/bin/phpunit tests/Unit/Transcode passes (no NEW failures vs baseline; Redis failures are pre-existing/environment)
- TranscodeStreamManager + SegmentAvailabilityInterface wired into the subscriber via services.yaml
- The video dispatch path goes through the stream manager; audio/init/loudness/subs still go through the pool

REPORT: (a) file paths modified, (b) how you compute lastProducedSegment for throttle (manager getter vs glob), (c) confirm audio/init/loudness/subs paths untouched, (d) test results, (e) any getter you needed on TranscodeStreamManager that you couldn't add (so I can follow up). Leave UNCOMMITTED.

## Acceptance Contract
Acceptance level: reviewed
Completion is not accepted from prose alone. End with a structured acceptance report.

Criteria:
- criterion-1: Implement the requested change without widening scope
- criterion-2: Return evidence sufficient for an independent acceptance review

Required evidence: changed-files, tests-added, commands-run, validation-output, residual-risks, no-staged-files

Review gate: required by reviewer.

Finish with a fenced JSON block tagged `acceptance-report` in this shape:
Use empty arrays when no items apply; array fields contain strings unless object entries are shown.
```acceptance-report
{
  "criteriaSatisfied": [
    {
      "id": "criterion-1",
      "status": "satisfied",
      "evidence": "specific proof"
    }
  ],
  "changedFiles": [
    "src/file.ts"
  ],
  "testsAddedOrUpdated": [
    "test/file.test.ts"
  ],
  "commandsRun": [
    {
      "command": "command",
      "result": "passed",
      "summary": "short result"
    }
  ],
  "validationOutput": [
    "validation output or concise summary"
  ],
  "residualRisks": [
    "none"
  ],
  "noStagedFiles": true,
  "diffSummary": "short description of the diff",
  "reviewFindings": [
    "blocker: file.ts:12 - issue found, or no blockers"
  ],
  "manualNotes": "anything else the parent should know"
}
```