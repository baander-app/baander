I have completed a thorough review with verified evidence. Here are my findings.

## Review

The refactor is well-structured and the happy paths (init/audio/loudness/subtitle pool path, single-stream spawn/poll, seek kill+restart+headroom, availability-table fast-path shape, DI wiring, booking-table removal consistency) are sound. All e2e + 70 unit tests pass — but they pass because they use short clips that stay under the throttle watermark, which masks a serious latent deadlock. Findings below are prioritized.

---

### P0 — Buffer-depth throttle deadlocks the stream permanently (never un-pauses)

**File:** `src/Transcode/Infrastructure/Swoole/TranscodeSessionSubscriber.php`
- Throttle block: `dispatchLongStream()`, lines ~833–855
- `estimatePlaybackSegment()`, lines 967–969

**Issue:** The throttle's "playback position" proxy is `estimatePlaybackSegment()` → `$job->getCompletedSegments()`. But `completedSegments` is **only** incremented in `markProducedSegmentsComplete()`, which runs *after* the `while (pollOnce)` loop exits. So during the loop the playback cursor is a **constant**.

Therefore `aheadSegments = lastProducedSegment - playbackSegment` grows monotonically. Once it reaches `THROTTLE_HIGH_SEGMENTS` (12), `pauseStream()` (SIGSTOP) fires. The un-throttle condition is `aheadSegments <= THROTTLE_LOW_SEGMENTS` (6) — but while paused FFmpeg produces nothing, so `lastProducedSegment` is frozen too; `aheadSegments` stays pinned at ~12 and can never drop to 6. A stopped process still reports `running=true` from `isRunning()`, so `pollOnce()` keeps returning `true`, the coroutine spins forever, and the loop-lock renewal timer keeps the lock alive (no takeover rescue). **Any content that produces more than 12 segments ahead of the baseline never finishes transcoding.**

Why tests miss it: short e2e/unit clips produce < ~12 segments, so the high watermark never trips. The author's own comment ("fires early rather than late, the safe direction") shows the intent was understood, but the feedback loop never closes.

**Fix (pick one):**
1. Drive the throttle from a real playback cursor — `PlaybackPositionChangedListener` already pushes positions via `SeekSignalBroker`; track the latest position per job and convert to a segment index in `estimatePlaybackSegment()`.
2. Mark segments complete incrementally inside `pollOnce`/`scanForNewSegments` so `completedSegments` advances during the loop.
3. Until a real playback feed exists, drop the playback proxy and instead gate on produced-vs-`totalSegments` (encode the whole file; throttle only against buffer *capacity*, not playback).

Add an integration test with >12 segments asserting the stream SIGSTOPs **and** SIGCONTs and reaches completion.

---

### P1 — FFmpeg stdout/stderr pipes are never drained → pipe-buffer deadlock on long encodes

**File:** `src/Transcode/Infrastructure/Swoole/TranscodeStreamManager.php` (`startStream` stores `$entry['pipes']` but no method ever reads them) + `src/Transcode/Infrastructure/Swoole/ProcOpenSpawner.php` (lines 39–40).

**Issue:** FFmpeg writes progress/stats to stderr continuously (default `info` log level + stats; no `-nostats`, no `-hide_banner`). The pipes are set non-blocking but **never read**, so once the stderr pipe buffer (~64 KB) fills — roughly a few minutes into a real encode — FFmpeg blocks on its next stderr write and the encode stalls. Nothing in `pollOnce`, `scanForNewSegments`, or `stopStream` drains the pipes (verified: no `fread`/`stream_get_contents`/`fgets` against the manager). Long videos deadlock even after the P0 is fixed. Short e2e clips don't fill the buffer.

**Fix:** Non-blocking-read and discard `$entry['pipes'][1]` and `[2]` each `pollOnce()` (e.g. `stream_get_contents` in a loop until empty); **and** pass `-nostats -loglevel error -hide_banner` in `buildStreamArgs` to minimize stderr volume. Either alone is partial; do both.

---

### P1 — Availability table can mark a segment ready while FFmpeg is still writing it

**Files:** `TranscodeStreamManager::scanForNewSegments()` (the `filesize($file) > 0` gate) and the controller fast-path `StreamSegmentController::waitForSegment()` (~lines 290–295).

**Issue:** `markReady` fires as soon as `filesize($file) > 0`. The HLS fMP4 muxer opens each segment file and writes incrementally; non-zero size means writing has *started*, not finished. The controller's fast path trusts the row and only re-checks `is_file && filesize > 0` (also true mid-write), then `streamFile()` does `fopen`+`fpassthru`, which serves only bytes up to the *current* EOF — i.e. a truncated segment. The file-stat fallback (`waitForFilePath`) requires 2 consecutive stable-size reads (~0.5 s) for exactly this reason, but the table fast-path has no stability check.

**Fix:** Apply the same stable-size validation in the fast path before serving, or have the manager mark ready only after detecting a stable size (or after the *next* segment index appears, signalling the previous one is closed).

---

### P2 — `SegmentAvailabilityTable` silently drops writes when full

**File:** `src/Transcode/Infrastructure/Swoole/SegmentAvailabilityTable.php` (`markReady`, ignored `set()` return) + `config/services.yaml` (`$tableSize: 8192`).

**Issue:** `Swoole\Table::set()` returns `false` when full; the return is ignored and nothing is logged. Rows are only evicted by `clearJob` (completion/seek). Long videos / multiple concurrent (job × tier) streams can exceed 8192 (e.g. 2 h @ 6 s ≈ 1200 seg/tier × several tiers + jobs). Impact is degraded performance (controller falls back to file-stat polling), not correctness — but the failure is invisible.

**Fix:** Check `set()`'s return and `logger->warning` on failure; size to `maxConcurrentStreams × maxSegmentsPerJob × tiers` with headroom; or evict consumed rows.

---

### P2 — `buildStreamArgs` returns a shell string, not an arg array (contradicts "array form, no shell")

**Files:** `src/Transcode/Infrastructure/FFmpeg/SegmentEncoder.php::buildStreamArgs()` (returns `string`) → `ProcOpenSpawner::spawn(string)` → `proc_open($command, …)`.

**Issue:** The task asserts the command is built "in array form, no shell", but the implementation returns one shell-interpolated string and `proc_open` is called with a string (→ `sh -c` on Unix). The variable path/filter inputs are `escapeshellarg`'d (good), but `$encoderFlags`, `$hwAccelFlags`, `$decoderFlags` are interpolated **raw**. They currently come from trusted config/env, so live risk is low — but the method name (`buildStreamArgs`) and the stated security model are misleading, and the surface is wider than believed. There is also no command-injection test for this path (the existing injection suite covers the per-pool worker path only).

**Fix:** Move `spawn()` + `proc_open` to array form (`proc_open(['/usr/bin/ffmpeg', …], …)`, which bypasses the shell entirely) and build a real arg array; mirror the existing pool-path injection test for the stream path.

---

### P2 — `streamFile()` has no error handling and serves without Content-Length

**File:** `src/Transcode/Interface/Controller/StreamSegmentController.php::streamFile()` (~lines 355–370).

**Issue:** `fopen($path, 'rb')` can return `false` (file removed between stat and open, or permission error); the result is passed straight to `fpassthru(false)` → PHP warning + empty 200 body. No `Content-Length`/`Content-Range`, so players can't size buffers or seek within a segment.

**Fix:** Guard `fopen`, return 404/503 on failure; emit `Content-Length` for completed segments.

---

### P3 — Stale pre-seek segment files accumulate; redundant globbing

**Files:** `TranscodeSessionSubscriber.php` (`markProducedSegmentsComplete` re-globs the whole dir post-seek) and `TranscodeStreamManager.php` vs the subscriber.

**Issue:** On seek, FFmpeg restarts with `-hls_start_number N` and only overwrites `seg_N..`; `seg_0..seg_{N-1}` remain on disk and are re-globbed by `markProducedSegmentsComplete` (count stays correct because already-mapped indices are skipped, but orphaned files accumulate). `clearJob` clears the table, not the filesystem. Separately, the directory is globbed up to 3× per poll iteration (manager `scanForNewSegments` + subscriber `getLastProducedSegmentIndex`/`countProducedSegments`); the manager already tracks `marked[]` — expose a getter instead of re-globbing.

**Fix:** Sweep the output dir for files outside the produced range before marking complete; replace subscriber globs with a manager getter.

---

### Correct (with evidence)
- DI wiring is correct: both decorators (`QualityFilteringStreamingDecorator`, `CachedTranscodeStreamingService`) implement and delegate `resolveVideoSegmentAvailability`/`resolveAudioSegmentAvailability` (U5 followup complete); no other interface implementor was missed.
- Booking-table removal is consistent: interface + Redis/InMemory impls + their tests + `SeekAwarePrefetcher` ctor + `services.yaml` aliases all removed together (verified via diff scope).
- `startStream` idempotent; `stopStream` no-op-when-absent; `enforceConcurrency` cap wired (`maxConcurrentStreams: 4`).
- Seek sequence (`stopStream → clearJob → startStream(headroom)`, `$lastProducedSegment = startSegment-1`) is sound; `-hls_start_number` keeps filename indices absolute so the manager's `markReady(jobId, tier, index, path)` matches the controller's request index.
- `clearJob` Swoole Table keying/prefix scan is correct.
- Concurrency model (many viewers → one stream via table signaling) is coherent.
- No files staged in scope (`git diff --cached` = 0); `bin/reli-mcp-bridge` mod and `.pi-subagents/` are unrelated/untracked, outside this refactor.

This was a **review-only** task — no files were edited.