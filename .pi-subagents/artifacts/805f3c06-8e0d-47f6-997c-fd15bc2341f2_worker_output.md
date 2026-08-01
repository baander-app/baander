Done. Here's the report:

**(a) File paths modified:**
- `src/Transcode/Infrastructure/Swoole/TranscodeSessionSubscriber.php` — rewired video segment dispatch to use TranscodeStreamManager; deleted `dispatchSegments`, `reorganizeQueue`, `processSegmentResult`; added `dispatchLongStream` + 5 helper methods
- `config/services.yaml` — wired `$streamManager` and `$availability` into TranscodeSessionSubscriber

**(b) How I compute lastProducedSegment for throttle:**
Via `glob()` of the output directory (`getLastProducedSegmentIndex`). The manager (U3) doesn't expose a getter for this. I parse `seg_N.m4s` filenames to find the highest index. This is O(files-in-dir) per poll cycle (100ms), which is cheap for ~100-segment videos.

**(c) Confirm audio/init/loudness/subs paths untouched:**
- Steps 1-8 (probe, in_progress, filters, init segment via pool, loudness via pool, audio init via pool, first audio segment via pool) — UNCHANGED
- `dispatchFirstAudioSegment()` and `dispatchAudioSegments()` — UNCHANGED (still use `scanForCompletedResult` → pool result table)
- Step 10 subtitles — UNCHANGED
- `scanForCompletedResult()` — PRESERVED (needed by audio dispatch)
- `waitForResult()`, `pollResult()`, `findSiblingLoudness()`, `waitForSiblingLoudness()` — PRESERVED

**(d) Test results:**
- `php -l` clean on TranscodeSessionSubscriber.php
- 350 unit + integration tests pass (1050 assertions, no new failures)
- 8 new seek-math tests pass
- Only failures in the full suite are Redis-reachability (environment, not code)

**(e) No getter needed on TranscodeStreamManager** — I approximated `lastProducedSegment` via glob of the output dir, which the manager already exposes via its return value from `startStream()`. No follow-up needed.