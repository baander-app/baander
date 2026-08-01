# Task for reviewer

Review the long-process-segmenter refactor on branch feat/long-process-segmenter.

Repo: /home/martin/dev/baander. You are on branch feat/long-process-segmenter.

SCOPE: Review the 9 refactor commits (exclude the baseline commit e9d68d63 which carries pre-existing uncommitted work). Get them with: git log --oneline master..HEAD -- src/Transcode config/services.yaml src/QoL tests  (the baseline commit touched many unrelated files too; focus on these paths).

The refactor shifts the on-the-fly video transcoder from N independent per-segment FFmpeg invocations to ONE long-lived FFmpeg process per (job, tier) managed by a new TranscodeStreamManager (\Swoole\Process direct, outside the CpuProcessPool which is kept for short jobs + shared with Recommendation/Scheduler).

Key files to review:
- src/Transcode/Infrastructure/Swoole/TranscodeStreamManager.php (NEW — the core: spawn/poll/stop/pause/resume/restart lifecycle)
- src/Transcode/Infrastructure/Swoole/TranscodeSessionSubscriber.php (REWRITTEN — dispatchLongStream replaces dispatchSegments; seek=kill+restart+headroom, throttle=SIGSTOP/SIGCONT)
- src/Transcode/Infrastructure/Swoole/SegmentAvailabilityTable.php (NEW — Swoole Table signaling)
- src/Transcode/Interface/Controller/StreamSegmentController.php (U5 — table-first fast-path)
- src/Transcode/Infrastructure/Transcode/TranscodeStreamingService.php (new resolve*Availability methods)
- SegmentBookingTable DELETED (U6)
- config/services.yaml wiring changes

WHAT TO LOOK FOR:
1. Correctness bugs: race conditions in the manager's poll loop, FD/process leaks on error paths, SIGSTOP/SIGCONT safety, the seek clearJob+restart sequence
2. The dispatchLongStream throttle logic — is lastProducedSegment (via glob) computed correctly vs playback position? Are the high/low watermarks (12/6) sane?
3. Resource cleanup: does the finally block reliably stop the stream on every exit path? What if startStream throws?
4. The availability table fast-path in the controller — does the fallback (waitForFilePath) correctly handle table misses/stale rows?
5. DI wiring correctness (the QualityFilteringStreamingDecorator already needed a followup fix — are there other interface implementors I missed?)
6. Security: the manager builds FFmpeg args via SegmentEncoder::buildStreamArgs (array form, no shell) — confirm no injection surface
7. Anything that would break under concurrency (multiple viewers = multiple segment requests against one stream)

All e2e tests pass (HLS, DASH, concurrent, seek) and 345 unit/integration tests are green. The review is for latent bugs and quality, not whether it works (it does).

Output: a prioritized findings list (P0/P1/P2/P3). For each: file, line, the issue, and the fix. Do NOT edit files — review only.

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