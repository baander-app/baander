---
artifact_contract: ce-unified-plan/v1
artifact_readiness: implementation-ready
product_contract_source: ce-plan-bootstrap
execution: code
title: "refactor: long-lived FFmpeg segmenter process for on-the-fly transcode"
plan_type: refactor
date: 2026-07-16
depth: deep
---

# Long-lived FFmpeg Segmenter Process

## Goal Capsule

Shift the on-the-fly transcoder from **N independent per-segment FFmpeg invocations** (each re-opening the input, re-seeking, re-initializing the encoder) to **one long-lived FFmpeg process per (job, tier)** that produces video + audio segments continuously via `-f segment -segment_list pipe:1`, emitting segment-available events as FFmpeg completes each fragment.

**Inspiration:** `baander-transcoder` toy project (`src/workers/segmenter.ts`, `src/services/transcoder.ts`) — same two themes baander already implements (playlists from ffprobe, segments on the fly) but with a cleaner production model: one producer, event-driven availability, buffer-depth throttle.

**Why now:** the per-segment model pays a seek + encoder-init tax on every fragment and required building a Redis `SegmentBookingTable` + 100-500ms result-table polling specifically to coordinate independent jobs. A single long process amortizes seek/init to once, makes availability instant (stdout event), and eliminates double-dispatch entirely — letting us delete the booking table.

**Non-goal:** change manifest generation, ffprobe parsing, storage layout, signed-URL scheme, the quality-ladder domain, or any client-side player behavior. The existing e2e contracts (HLS 3-level switch, DASH, concurrent viewers) remain the acceptance bar.

---

## Problem Frame

baander's transcoder works — all e2e tests pass against the live Swoole server. But the production model has three structural costs:

1. **Per-segment overhead.** `TranscodePoolWorker::encodeFmp4VideoSegment()` spawns a fresh FFmpeg per segment with `-ss <start> -t <dur>`. Every fragment re-opens the source, seeks, and re-initializes the encoder. For a 10-minute video at 6s segments that's ~100 separate FFmpeg invocations per tier, each paying 100-300ms of fixed overhead.

2. **Polling-based availability.** `TranscodeSessionSubscriber::scanForCompletedResult()` polls the `CpuProcessPool` result table every 100ms (audio) / 500ms (seek wait) to detect completion. The toy project gets this for free: FFmpeg's `-segment_list pipe:1 -segment_list_type csv` writes each segment filename to stdout the instant it's closed — a push, not a poll.

3. **Coordination machinery that exists only because of #1.** The Redis `SegmentBookingTable` (`SegmentBookingInterface`) was introduced to stop two pool workers from double-encoding the same segment — a race that only exists *because* segments are independent dispatchable units. Under a single long process, there is one producer per job and the table is dead code.

The toy project solves all three with one design: spawn FFmpeg once with `-f segment`, read its stdout for availability events, and SIGSTOP/SIGCONT (or kill+restart) to control pace. This plan adopts that model inside baander's DDD/Swoole architecture without adopting the toy project's weaker structure.

### Origin

Direct user request: *"use ../baander-transcoder as inspiration … The main theme is: generate playlists from ffprobe data, generate segments on the fly."* Direction chosen: **long-process segmenter (full shift)**, with audio folded into the long process and the `SegmentBookingTable` removed.

---

## Requirements

- **R1 — Single long FFmpeg process per (job, tier).** One process produces all video segments for a tier continuously via `-f segment -segment_list_type fmp4`. No per-segment FFmpeg invocations on the steady-state path. *(Advances: problem cost #1)*
- **R2 — Audio folded into the long process.** The same FFmpeg invocation maps all requested audio languages (`-map 0:a:0 -map 0:a:1 …`) and emits per-language segments through the same stdout availability channel. Removes the separate audio-segment pool path. *(Advances: problem cost #1; user decision: fold audio in)*
- **R3 — Event-driven segment availability.** Segment completion is signaled by FFmpeg writing the filename to stdout (parsed by the worker), pushed to a Swoole `Table`/`Channel` the HTTP layer awaits — replacing 100-500ms result-table polling. *(Advances: problem cost #2)*
- **R4 — Optimal seek experience.** Seek latency is minimized by a mechanism chosen via a research task (benchmark SIGSTOP/SIGCONT resume latency vs kill+restart-with-headroom startup cost against the real seek-latency budget). Whatever is chosen must integrate with the existing `SeekSignalBroker`. *(Advances: problem cost #1 for seeks; user decision: determine optimal)*
- **R5 — Remove SegmentBookingTable.** Single-producer model makes it dead. Delete the interface, both impls (Redis + InMemory), the DI wiring, and the unit test. Keep `TranscodeLoopLock` — it still guards the "only one worker starts the loop" race in `CreateTranscodeSessionHandler`. *(Advances: problem cost #3; user decision: remove booking, keep lock)*
- **R6 — Preserve all existing contracts.** Init-segment delivery, HLS v6 master/media manifests, DASH manifest, signed URLs, hardware acceleration, HDR tone-mapping, subtitle extraction, and the three e2e scenarios (HLS level switch, DASH playback, concurrent viewers) remain green. *(Acceptance gate)*
- **R7 — Never block the Swoole event loop.** The long FFmpeg process runs inside a CPU-pool worker (or a dedicated process manager with the same isolation guarantees), not a raw `proc_open` in the HTTP worker. The HTTP layer awaits availability via async sleep on a shared-memory signal. *(Architectural invariant — see CLAUDE.md "Workers")*

---

## Key Technical Decisions

### KTD-1: Stream via \Swoole\Process directly; CpuProcessPool kept for short jobs (FINAL)

**The long-lived streaming FFmpeg is spawned and managed by a new `TranscodeStreamManager` using `\Swoole\Process` directly — outside the `CpuProcessPool`. The pool is KEPT for all short jobs.**

**Why not a pool job type:** the pool worker loop is a one-shot `handle($data) → string → writeResult` contract. A long-lived stream emitting N availability events over minutes does not fit it. Spawning via `\Swoole\Process` directly lets the manager run FFmpeg for the stream's lifetime, read its stdout line-by-line for segment-available events, write `SegmentAvailabilityTable` rows, and own SIGSTOP/SIGCONT + kill lifecycle — all without contorting the pool's one-shot model.

**Why not drop the pool entirely:** `CpuProcessPool` is a SHARED subsystem. It serves Transcode (init segment, loudness, subtitles), Recommendation (`RecommendationPoolWorker`), and Scheduler (`SchedulerConsolePoolWorker`) — all working features. Deleting it would force rewrites of three bounded contexts + shared shutdown/stats infra (`SwooleWorkerEventSubscriber`, `WorkerStatsController`, `CpuProcessPoolShutdownHandler`), far beyond the streaming problem. The pool is correct for short one-shot work; only the long-lived stream exits it.

**`TranscodeStreamManager` owns:**
- Spawning the streaming FFmpeg via `\Swoole\Process` (redirect stdout to a pipe the manager reads)
- Reading stdout line-by-line (`-f hls -hls_segment_type fmp4 -hls_segment_filename ...`); on each segment filename, writing a `SegmentAvailabilityTable` row
- Lifecycle: start, stop (kill), SIGSTOP/SIGCONT (throttle — KTD-3), restart-with-headroom (seek — KTD-3)
- Max-concurrency enforcement (configurable; excess spawns queue)
- Cleanup on job completion / worker death / server shutdown

**What stays in the pool (unchanged):** init segment (`encode_init_segment`), loudness (`analyze_loudness`), subtitle extraction (`extract_subtitles`). These remain one-shot jobs dispatched via `TranscodeProcessPool` → `CpuProcessPool::dispatch`, writing result-table rows the subscriber polls as it does today. The per-segment video/audio jobs (`encode_segment`, `encode_audio_segment`, `encode_audio_init_segment`) are REMOVED — the stream subsumes them.

**Capacity:** under the manager, stream concurrency is bounded by a configurable max (the manager enforces it); the pool's worker count governs only short-job concurrency. The subscriber's sliding-window logic is no longer needed (one process = one producer, not N dispatches).

### KTD-2: Availability signaling via a Swoole Table, awaited with async sleep

A new Swoole `Table` (`segment_availability`) maps `"{jobId}:{tier}:{segmentIndex}"` → `{ready: bool, path: string}`. The pool worker writes a row the instant FFmpeg emits the filename on stdout. The HTTP segment controller's existing `waitForFilePath()` gains a faster first check: if the table says `ready`, skip the file-stat polling. If not, fall back to the current stat loop (kept as a safety net for the case where FFmpeg wrote the file but the stdout event was lost).

**Rationale:** Swoole Table is shared memory across workers — zero-copy, no IPC round-trip. The stat-based `waitForFilePath()` stays as the fallback because file-existence is the ground truth; the table is an optimization that makes the common case instant.

**Rejected alternative:** Swoole `Channel` (FIFO). A segment can complete in any order; a channel forces FIFO consumption and would reintroduce the ordering constraint the current `scanForCompletedResult` loop was written to avoid.

### KTD-3: Seek/throttle mechanism — RESOLVED (hybrid, per U1 benchmark)

**Decision: SIGSTOP/SIGCONT for throttle; kill + restart-with-headroom for seek.**

U1 benchmarked both mechanisms against the Big Buck Bunny source (634s, 1080p/30fps, libx264 ultrafast, 6s fMP4 segments) inside the `baander-app` Docker container (FFmpeg 5.1.9), producing:

| Mechanism | Measurement |
|-----------|-------------|
| Cold-start seek, `-ss 0s` (near) | **0.486s** to seg_0 ready |
| Cold-start seek, `-ss 60s` (mid) | **0.644s** to seg_0 ready |
| Cold-start seek, `-ss 300s` (far) | **0.608s** to seg_0 ready |
| SIGSTOP halts output | ✅ confirmed (0 new segments over 5s paused) |
| SIGCONT resume → next new segment | **0.381s** |

**Reasoning:**
- **Throttle (player buffered ahead):** SIGSTOP/SIGCONT is unambiguously optimal — 0.38s resume, holds the process and its encoder state in memory, no re-init. Killing to throttle would waste the amortized init the long-process model exists to preserve.
- **Seek:** SIGSTOP/SIGCONT *cannot* reposition FFmpeg's read head — a paused streaming process resumes from where it stopped, not from the requested seek position. So seek requires a new process regardless. Cold-start at ~0.6s (input-seeking makes distance irrelevant) is cheap enough that kill+restart-with-headroom (start at `floor(position/segDur) - headroom`, matching the toy project's `RESTART_HEADROOM = 3`) delivers the requested segments immediately without a perceptible penalty. Hybrid matches the toy project's `planSessionRestart` design.
- Both numbers are well under the 2s "instant seek" industry budget, so the choice optimizes correctness (seek must restart; throttle must not) rather than latency (both are fast).

U3's worker branch must support SIGSTOP/SIGCONT signals to its FFmpeg child. U4 implements throttle as SIGSTOP/SIGCONT and seek as kill + `clearJob` + new `startStream` with `-segment_start_number`.

### KTD-4: Keep TranscodeLoopLock; remove SegmentBookingTable

`TranscodeLoopLockInterface` (Redis) stays — `CreateTranscodeSessionHandler` still needs it to resolve the "two HTTP workers both try to start the loop for the same job" race, which the single-producer model does not eliminate. `SegmentBookingInterface` is removed entirely: under one long process there is exactly one producer per (job, tier) and no segment is ever double-dispatched.

**Rationale (settled scope):** the booking table's entire reason for existence was coordinating independent per-segment dispatches. That coordination need vanishes. The loop lock's reason (start-race) persists.

### KTD-5: Subtitles stay on the existing pool path

Subtitle extraction (`extract_subtitles` job type, WebVTT) is unchanged. It's a one-shot full-file extract, not a per-segment stream, and the toy project treats it as a side-map in the same command — which baander's multi-language + WebVTT-segmented model doesn't cleanly support. Keeping it separate is lower risk and the subtitle path is not a latency bottleneck.

---

## High-Level Technical Design

```mermaid
sequenceDiagram
    participant Player
    participant HTTP as StreamSegmentController
    participant Loop as TranscodeSessionSubscriber
    participant Pool as CpuProcessPool
    participant Worker as TranscodePoolWorker (long)
    participant FF as FFmpeg (single process)
    participant Table as segment_availability (Swoole Table)

    Note over Loop,Worker: Startup (once per job+tier)
    Loop->>Pool: dispatch({type: encode_stream, job, tier, languages, startSegment})
    Pool->>Worker: write(payload)
    Worker->>FF: spawn ffmpeg -f segment -segment_list pipe:1 -map 0:v -map 0:a:0 ...
    Worker-->>Loop: stream started (table row)

    Note over Player,Table: Steady state (per segment request)
    Player->>HTTP: GET seg_42.m4s
    HTTP->>Table: read "{jobId}:{tier}:42"
    alt ready
        Table-->>HTTP: ready=true, path
    else not ready
        HTTP->>HTTP: waitForFilePath() stat-loop (fallback)
        Worker->>FF: (writing seg_42...)
        FF-->>Worker: stdout: "seg_42.m4s"
        Worker->>Table: write ready=true, path
        Table-->>HTTP: (polled) ready
    end
    HTTP-->>Player: 200 video/mp4 (zero-copy fpassthru)

    Note over Loop,Worker: Seek (mechanism TBD by U2 research)
    Player->>Loop: PlaybackPositionChanged (seek to 180s)
    Loop->>Worker: seek signal (via existing SeekSignalBroker)
    alt SIGSTOP/SIGCONT viable (U2 decides)
        Worker->>FF: resume from adjusted position
    else kill+restart (U2 decides)
        Loop->>Worker: stop current stream
        Loop->>Pool: dispatch({type: encode_stream, startSegment: 30 - headroom})
    end
```

**Component responsibilities after the shift:**

| Component | Before | After |
|-----------|--------|-------|
| `TranscodeSessionSubscriber::dispatchSegments()` | Loop dispatching N per-segment jobs, polling result table | Dispatches ONE `encode_stream` job, then idles until completion/seek |
| `TranscodePoolWorker` | Stateless `handle()` per segment | New long-lived `encode_stream` branch: spawns FFmpeg once, reads stdout, writes availability table |
| `StreamSegmentController::waitForFilePath()` | Stat-loop only | Checks availability table first (instant), falls back to stat-loop |
| `SegmentBookingInterface` | Coordinates double-dispatch | **Removed** |
| `SeekSignalBroker` | Signals loop to reorganize queue | Signals worker to SIGSTOP/SIGCONT or triggers kill+restart |

---

## Scope Boundaries

### In scope
- New `encode_stream` job type + worker branch (video + audio in one FFmpeg process)
- Swoole Table availability signaling + HTTP-side fast-path read
- Seek/throttle mechanism (decided by research task U2, implemented in U3)
- Removal of `SegmentBookingInterface`, `RedisSegmentBookingTable`, `InMemorySegmentBookingTable`, their tests, and DI wiring
- Adaptation of `TranscodeSessionSubscriber` to the single-dispatch + idle model
- Adaptation of `StreamSegmentController` availability wait to table-first
- Updated/new unit tests for the worker branch and the availability read
- All three e2e scenarios remain green (acceptance gate)

### Deferred to Follow-Up Work
- Client-side `/config` endpoint (hls.js ABR tuning) — the toy project's `hlsRouter.get('/config/:id')`; separate feature, not part of this architectural shift
- I-frame / trickplay streams — baander has none today; adding them is a feature
- Removing the now-unused `pollResult()` / `waitForSiblingLoudness()` / `extractAudioSegmentsForLanguage()` dead code — already-decided separate cleanup, tracked independently
- Removing `TranscodeLoopLock` entirely — kept for now (KTD-4); revisit if the start-race proves handleable by the subscriber's `runningJobs` guard alone
- Migrating subtitle extraction into the long process — kept separate (KTD-5)

### Outside this product's identity
- Rewriting `CpuProcessPool` internals (`boot`, round-robin, health-check) — the long-process worker flows through existing dispatch
- Changing the manifest generators, `VideoProbeResult`, storage resolver, or signed-URL scheme
- Any RN/web client changes

---

## System-Wide Impact

- **End users (viewers):** lower time-to-first-frame and smoother seeking (R1, R3, R4). No API contract change.
- **Operators:** lower CPU per stream (one FFmpeg init amortized), simpler observability (one process per job instead of N). Must monitor for long-lived process leaks (the subscriber's `finally` block must kill the process on job end/worker death).
- **Developers:** the transcode hot path becomes simpler to reason about (one producer) but the worker branch becomes stateful and long-lived — a new access pattern for the pool that needs clear lifecycle docs.

### Risks & Dependencies

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Long-lived worker blocks a pool slot if a job is abandoned | Medium | High (pool starvation) | Worker health-check + subscriber `finally` kill + idle-timeout in the worker branch (kill FFmpeg if no segment requested in N minutes) |
| FFmpeg stdout parsing breaks on an FFmpeg version bump (segment-list CSV format change) | Low | High (no availability signal) | Keep `waitForFilePath()` stat-fallback (KTD-2); pin FFmpeg version in Docker |
| SIGSTOP'd process holds memory while paused (if U2 picks SIGSTOP) | Medium | Medium | Worker reports paused-process memory; kill+restart fallback if paused too long |
| Kill+restart seek tax reintroduces the latency we're removing (if U2 picks kill+restart) | Medium | Medium | The research task U2 exists to prevent this — measure before committing |
| Audio segment availability couples to video pace (R2 decision) | Medium | Low | Acceptable per user decision; audio init is still primed up-front (existing Step 8) so first audio segment is fast |
| Regression in hardware-accel path (NVENC/QSV/VAAPI) under long-process model | Medium | High | HW-accel is carried through the same `VideoProcessingRules::codecFlags()` the worker already uses; e2e uses software encode so add a HW-smoke unit test |

**Dependencies / prerequisites:** FFmpeg in the Docker image must support `-f segment -segment_list_type fmp4 -segment_list pipe:1 -segment_list_type csv` (verified present in standard FFmpeg builds; the toy project uses it on the same FFmpeg). Swoole `Table` extension (already used by `CpuProcessPool::getResultTable()`).

---

## Implementation Units

### U1. Research: seek/throttle mechanism benchmark

**Goal:** Decide between SIGSTOP/SIGCONT and kill+restart-with-headroom (KTD-3) by measuring real latency, so U3+ implement against the right mechanism. This is a first-class implementation unit chartered to produce the decision KTD-3 currently defers — not a prerequisite investigation. It runs first because U3's worker branch and U4's seek integration both branch on its output, but it can proceed in parallel with U2 (the availability table), which does not branch on the decision.

**Requirements:** R4.

**Dependencies:** none (parallelizable with U2).

**Files:**
- `docs/plans/2026-07-16-001-refactor-long-process-segmenter-plan.md` (append decision to this plan as an `### Open Questions` resolution or a KTD-3 update note)

**Approach:**
- Measure cold-start latency of `ffmpeg -f segment ... -ss <seek>` for a representative source (the Big Buck Bunny e2e fixture) at 3 seek distances (near: 6s, mid: 60s, far: 300s).
- Measure SIGSTOP/SIGCONT resume latency on the same process (time from `SIGCONT` to next segment-available event).
- Compare both against the perceived-seek-latency budget (industry rule of thumb: < 2s to first frame after seek is "instant", 2-5s acceptable, > 5s poor).
- Check whether a SIGSTOP'd FFmpeg continues writing buffered segments to stdout on SIGCONT (if it flushes a burst, that may be desirable or may overwhelm the player).
- Verify SIGSTOP/SIGCONT works inside baander's Docker container (`baander-app`) — the toy project notes Windows incompatibility; confirm Linux/Docker parity.

**Test scenarios:** `Test expectation: none -- research task produces a decision recorded in the plan, not code.`

**Verification:** Decision is recorded with measured numbers, names the chosen mechanism, and KTD-3 is updated to reflect it. If the decision is hybrid (SIGSTOP for throttle, kill+restart for seek), state both explicitly.

---

### U2. Swoole Table availability signaling

**Goal:** Add the `segment_availability` Swoole Table and the worker-side writer + HTTP-side reader (KTD-2) so U3/U4 have a signaling substrate.

**Requirements:** R3.

**Dependencies:** none (foundational; U3, U4, U5 build on it).

**Files:**
- `src/Transcode/Infrastructure/Swoole/SegmentAvailabilityTable.php` (new) — thin wrapper over `\Swoole\Table`, keyed `"{jobId}:{tier}:{index}"`, columns `ready` (int bool), `path` (string)
- `src/Transcode/Application/Port/SegmentAvailabilityInterface.php` (new) — `markReady(jobId, tier, index, path)`, `isReady(jobId, tier, index): ?string`, `clearJob(jobId)`
- `config/services.yaml` (modify) — register the table + interface alias
- `src/Shared/Infrastructure/Swoole/SwooleTableFactory.php` (new or extend) — centralize `Table` schema definitions (the codebase already uses ad-hoc tables; a factory avoids schema drift)
- `tests/Unit/Transcode/Infrastructure/Swoole/SegmentAvailabilityTableTest.php` (new)

**Approach:**
- Schema: column `ready` INT, column `path` STRING(512). Size: scale to expected concurrent segments (jobs × tiers × lookahead; e.g. 8192 rows matches the existing result table size).
- The interface lives in `Application/Port` (not Infrastructure) so the HTTP controller depends on the port, not the Swoole impl — matching the codebase's port/adapter discipline.
- `clearJob(jobId)` is called by the subscriber on job completion and before a kill+restart seek (if U1 picks that) to avoid stale-ready rows.
- Thread safety: Swoole Table is process-shared and atomic for single-key writes; the worker writes, the HTTP worker reads — no lock needed.

**Test scenarios:**
- Happy path: `markReady` then `isReady` returns the path.
- `isReady` on an un-marked segment returns null.
- `clearJob` removes all rows for a job; subsequent `isReady` returns null.
- Concurrent `markReady` for different segments does not collide (write two keys, read both).
- Overwriting a ready row with a new path (segment re-encoded after a seek) returns the new path.

**Verification:** Unit tests green; table wired in DI and injectable.

---

### U3. Long-lived FFmpeg worker branch (`encode_stream`)

**Goal:** The core unit. Add the `encode_stream` job type to `TranscodePoolWorker` that spawns one FFmpeg producing video + all audio languages continuously, reads stdout for availability, and writes to the U2 table.

**Requirements:** R1, R2, R3, R7.

**Dependencies:** U2 (availability table).

**Files:**
- `src/Transcode/Infrastructure/Swoole/TranscodePoolWorker.php` (modify) — add `encode_stream` to `supportedTypes()` and `handle()`; new private `runLongStream(array $job)` method
- `src/Transcode/Infrastructure/Swoole/TranscodeProcessPool.php` (modify) — add `startStream(job, sourcePath, tier, languages, audioProfile, videoFilters, audioFilters, startSegment, outputPath)` method that dispatches the new job type
- `src/Transcode/Infrastructure/FFmpeg/SegmentEncoder.php` (modify) — add `buildStreamArgs(...)` returning the FFmpeg arg array for the long process (the `-f segment -segment_list pipe:1 -segment_list_type csv -segment_format fmp4 ... -map 0:v -map 0:a:0 ...` invocation, reusing `VideoProcessingRules::codecFlags()` + `AudioProcessingRules::codecOptions()` so HW accel + loudness filters carry through)
- `tests/Unit/Transcode/Infrastructure/Swoole/TranscodePoolWorkerLongStreamTest.php` (new)
- `tests/Unit/Transcode/Infrastructure/FFmpeg/SegmentEncoderStreamArgsTest.php` (new)

**Approach:**
- The worker spawns FFmpeg via `proc_open` (same pattern as the existing `exec()` helper, but **non-blocking stdout read in a loop** until the process exits or a stop signal arrives).
- `-segment_list pipe:1 -segment_list_type csv` → FFmpeg writes one CSV line per segment (`filename,duration,...`). The worker parses `filename`, extracts the segment index from the naming convention, and calls `SegmentAvailabilityTable::markReady()`.
- Init segment: `-segment_header_filename` (fMP4) writes `init.mp4` automatically — same mechanism the current `encodeFmp4VideoSegment` uses with `keepInitOnly`, now produced once at stream start.
- Audio: `-map 0:a:0 -map 0:a:1 ...` per requested language; FFmpeg writes each audio segment to its own filename pattern, and the worker marks the audio-availability row keyed by `"{jobId}:{language}:{index}"`.
- Stop signal: the pool's existing `write('')` empty-payload convention signals worker exit; the worker additionally needs a per-job "stop this stream" channel (a Swoole atomic flag or a dedicated table column) so a seek (U4) can kill the stream without killing the worker.
- The worker must `finally { fclose(stdout); proc_close(); }` to avoid FD leaks on every exit path.

**Technical design (directional, not implementation spec):**
```
worker loop (per encode_stream job):
  spawn ffmpeg with buildStreamArgs(...)
  while (!feof(stdout) && !stopRequested(jobId)):
    line = fgets(stdout)
    if line matches "<filename>,<duration>,...":
      index = parseIndex(filename)
      lang  = parseLang(filename) or null
      table.markReady(jobId, tierOrLang, index, realpath(filename))
  wait for proc exit, capture code
  if code != 0 && !stopRequested: throw
```

**Patterns to follow:**
- `TranscodePoolWorker::exec()` — the non-blocking stdout/stderr read pattern (already uses `stream_select` + `Async::sleep`)
- `TranscodePoolWorker::encodeFmp4VideoSegment()` — the fMP4 + `-segment_header_filename` + `atomicWriteWithLock` patterns (the atomic write still applies; FFmpeg writes to the segment dir, the availability row is set after)
- `baander-transcoder/src/workers/segmenter.ts::monitorHlsOutput()` — the stdout-line-parsing reference (CSV from `-segment_list pipe:1`)

**Test scenarios:**
- `buildStreamArgs` produces the expected flag sequence for a single-language, single-tier job (video map + audio map + `-f segment` + fMP4 format options).
- `buildStreamArgs` includes all requested audio languages as separate `-map` entries.
- `buildStreamArgs` carries HW-accel flags through from `VideoProcessingRules::codecFlags()` (parametrize over `libx265`, `h264_nvenc`, `h264_qsv`).
- `buildStreamArgs` includes `-segment_start_number` when a start segment is provided (seek support for U4).
- Worker marks the availability table for each segment filename FFmpeg emits (use a fixture ffmpeg stub that writes known lines to stdout).
- Worker writes audio availability rows keyed by language, distinct from video rows.
- Worker stops cleanly when the stop flag is set mid-stream (no FD leak, process reaped).
- Worker throws on non-zero FFmpeg exit when stop was not requested.

**Verification:** Unit tests green; the `encode_stream` job type is dispatchable through `TranscodeProcessPool::startStream()`.

---

### U4. Seek + throttle integration (mechanism from U1)

**Goal:** Wire the chosen seek/throttle mechanism (U1's decision) into `TranscodeSessionSubscriber` + the worker, integrating with the existing `SeekSignalBroker`.

**Requirements:** R4, R7.

**Dependencies:** U1 (decision), U2 (table `clearJob` for restart path), U3 (worker stop channel).

**Files:**
- `src/Transcode/Infrastructure/Swoole/TranscodeSessionSubscriber.php` (modify) — replace `dispatchSegments()` + `dispatchAudioSegments()` + `dispatchFirstAudioSegment()` with a single `dispatchLongStream()` that starts the stream (U3) and then waits on `SeekSignalBroker` for pause/seek/resume
- `src/Transcode/Infrastructure/Swoole/TranscodePoolWorker.php` (modify, if SIGSTOP chosen) — add `pauseStream(jobId)` / `resumeStream(jobId)` that send SIGSTOP/SIGCONT to the FFmpeg child process
- `src/Transcode/Application/Port/SegmentAvailabilityInterface.php` (modify) — ensure `clearJob` is callable before a restart-seek

**Approach:**
- The new `dispatchLongStream()` calls `processPool->startStream(...)` once, then enters a wait loop on `seekSignalBroker->waitForSignal()`.
- On `pause` signal → SIGSTOP the process (if U1 chose SIGSTOP) or kill it (if U1 chose kill-on-pause). The subscriber sets session state and persists.
- On `seek` signal → if U1 chose kill+restart: stop the stream, `table->clearJob(jobId)`, compute `startSegment = floor(position/segDur) - headroom`, dispatch a new `startStream` with `-segment_start_number`. If U1 chose SIGSTOP/SIGCONT-for-seek: pause, wait for FFmpeg to drain its internal buffer to the new position (this is the case U1 must validate is even possible), resume.
- The subscriber's `finally` block must stop the stream on job completion/failure/worker-shutdown (the process must not outlive the loop).
- Buffer-depth throttle: the subscriber tracks `maxSegment - currentPlaybackSegment` (from `SeekAwarePrefetcher` / `PlaybackPositionChanged` events); when it exceeds a threshold (configurable, default ~2× segment count), SIGSTOP or stop; when it drops below, resume.

**Test scenarios:**
- On `pause` signal, the session is marked paused and (if SIGSTOP) the process receives SIGSTOP; in-flight segments finish (no corruption).
- On `seek` to position P with kill+restart, `startStream` is called with `-segment_start_number = floor(P/segDur) - headroom` and the table is cleared.
- On `seek` with SIGSTOP/SIGCONT, the process is paused and resumed without a new dispatch (only if U1 validated this path).
- On buffer-depth exceed (e.g. 12 segments ahead), the process is paused; on drain (e.g. ≤ 6 ahead), resumed.
- On job completion, the stream process is killed (no leak).
- On worker death / subscriber `finally`, the stream process is killed.
- Concurrent: two seek signals in quick succession result in the latest position winning (drain-all-then-act, matching the current `waitForSignal` loop semantics).

**Verification:** Seek/pause/resume unit tests green; manual seek test in the e2e harness (extend `tests/e2e/` — see U7).

---

### U5. HTTP availability fast-path

**Goal:** `StreamSegmentController` checks the U2 table before falling back to the stat loop, making the common case instant.

**Requirements:** R3.

**Dependencies:** U2.

**Files:**
- `src/Transcode/Interface/Controller/StreamSegmentController.php` (modify) — inject `SegmentAvailabilityInterface`; `waitForFilePath()` becomes `waitForSegment(jobId, tier, index, path)` which checks the table first, then stats.
- `src/Transcode/Interface/Controller/StreamSegmentController.php` (modify) — same for `audioSegment()` / `audioInitSegment()` paths.

**Approach:**
- The controller resolves `(jobId, tier, index)` from the route (it already has `jobPublicId` + `index`), checks `table->isReady(jobId, tier, index)`. If ready, `streamFile()` immediately.
- If not ready, fall back to the existing stat-loop (`waitForFilePath`) — the table is an optimization, file-existence is ground truth. This also covers the case where FFmpeg wrote the file but the stdout event was lost (KTD-2 safety net).
- Audio path: `table->isReady(jobId, $language, index)` — same shape, different key.

**Test scenarios:**
- When the table says ready, the file is streamed with no stat-loop delay.
- When the table says not-ready but the file appears later, the stat-loop fallback serves it.
- When the table has no row (job unknown), behavior matches today (stat-loop or 503).
- Signature validation still gates access before any table read (security regression check).

**Verification:** Existing e2e `transcode.spec.ts` still passes (manifest parsed, playback > 3s) with lower latency (assertable via timing logs, not a hard e2e assertion).

---

### U6. Remove SegmentBookingTable

**Goal:** Delete the now-dead coordination machinery (KTD-4, R5).

**Requirements:** R5.

**Dependencies:** U3 (long process is the single producer; booking must stay until U3 ships to avoid a double-dispatch regression window).

**Files:**
- `src/Transcode/Application/Port/SegmentBookingInterface.php` (delete)
- `src/Transcode/Infrastructure/Swoole/InMemorySegmentBookingTable.php` (delete)
- `src/Transcode/Infrastructure/Redis/RedisSegmentBookingTable.php` (delete)
- `tests/Unit/Transcode/Infrastructure/Swoole/InMemorySegmentBookingTableTest.php` (delete)
- `tests/Integration/Transcode/Infrastructure/Redis/RedisSegmentBookingTableIntegrationTest.php` (delete)
- `src/Transcode/Infrastructure/Swoole/TranscodeSessionSubscriber.php` (modify) — remove the `$bookingTable` constructor param and all `?->book()` / `?->release()` / `?->isBooked()` calls
- `src/Transcode/Infrastructure/Swoole/SeekAwarePrefetcher.php` (modify) — remove `$bookingTable` dependency (the prefetcher coordinated via bookings; under single-producer it either becomes a no-op or is simplified — determine during implementation whether the prefetcher is still needed at all)
- `config/services.yaml` (modify) — remove the `SegmentBookingInterface` alias + both service definitions

**Approach:**
- Straight deletion once U3 proves the single-producer model. The `SegmentBookingInterface` constructor param on `TranscodeSessionSubscriber` is nullable (`?SegmentBookingInterface $bookingTable = null`), so removal is non-breaking for the DI graph.
- `SeekAwarePrefetcher` review: if its only job was booking segments ahead of the encoder, and the encoder is now one process, the prefetcher may be fully redundant. If so, note it under Deferred; if it still serves a purpose (e.g. warming the availability table for ahead-segments), trim the booking calls and keep it.

**Test scenarios:**
- `tests/Unit/Transcode/Infrastructure/Swoole/InMemorySegmentBookingTableTest.php` is deleted (no longer needed).
- `tests/Integration/Transcode/...RedisSegmentBookingTableIntegrationTest.php` is deleted.
- `TranscodeSessionSubscriber` constructs and runs the encoding loop with no booking-table dependency (existing subscriber tests stay green after removing the constructor arg).
- No remaining reference to `SegmentBookingInterface` / `SegmentBookingTable` anywhere in `src/` or `config/` (grep-verified).

**Verification:** `grep -rn 'SegmentBooking' src/ config/ tests/` returns only `SegmentAvailability` (U2) hits, nothing from the old booking namespace. Full unit + integration suite green.

---

### U7. E2E + regression validation

**Goal:** Confirm the rewrite keeps every existing contract green and adds seek coverage.

**Requirements:** R6.

**Dependencies:** U3, U4, U5 (the shift is complete enough to run end-to-end).

**Files:**
- `tests/e2e/transcode.spec.ts` (modify) — existing, must stay green unchanged
- `tests/e2e/transcode-dash.spec.ts` (modify) — existing, must stay green unchanged
- `tests/e2e/transcode-concurrent.spec.ts` (modify) — existing, must stay green unchanged
- `tests/e2e/transcode-seek.spec.ts` (new) — seek scenario

**Approach:**
- Run all three existing e2e specs against the live server after each unit ships; they are the regression gate.
- Add a seek e2e: load the HLS player, let it play to ~5s, seek to 60s, assert playback resumes past 63s within the seek-latency budget determined by U1. This is the first e2e that exercises seek end-to-end.
- The duplicate `ui/web/tests/e2e/transcode/transcode.spec.ts` should be reconciled (delete or align) as part of this unit — it's a pre-existing duplication that becomes confusing during this change.

**Test scenarios:**
- Existing: HLS manifest parses ≥ 3 levels, playback > 3s, level switch succeeds, no fatal errors.
- Existing: DASH manifest ≥ 3 video + ≥ 1 audio reps, playback > 3s.
- Existing: 3 concurrent viewers all play past 3s, no fatal errors.
- New: seek from 5s to 60s, playback resumes and passes 63s; total seek-to-resume time within U1's budget.

**Verification:** `cd tests/e2e && SERVER_URL=http://localhost:9501 npx playwright test --reporter=line` — all green, including the new seek spec.

---

## Verification Contract

The plan is complete when **all** of the following pass against the live Swoole server (Docker stack up):

1. `./vendor/bin/phpunit tests/Unit/Transcode tests/Integration/Transcode` — all green, including new `SegmentAvailabilityTableTest`, `TranscodePoolWorkerLongStreamTest`, `SegmentEncoderStreamArgsTest`.
2. `cd tests/e2e && SERVER_URL=http://localhost:9501 npx playwright test` — `transcode.spec.ts`, `transcode-dash.spec.ts`, `transcode-concurrent.spec.ts`, and new `transcode-seek.spec.ts` all green.
3. `grep -rn 'SegmentBooking' src/ config/ tests/` — zero hits in the old booking namespace (only `SegmentAvailability` may appear).
4. `./vendor/bin/phpstan analyse src/Transcode --no-progress` — no new errors beyond the pre-existing baseline (the dead-code findings for `pollResult` etc. are out of scope and tracked separately).
5. U1's seek/throttle decision is recorded in this plan (KTD-3 updated) with measured latency numbers.

## Definition of Done

- Units U1 through U7 are implemented and their per-unit verification passes.
- The full Verification Contract (all 5 gates) is green.
- No long-lived FFmpeg process leaks on job completion, worker death, or server shutdown (verified by `ps aux | grep ffmpeg` showing zero stray processes after a full e2e run).
- The plan's KTD-3 reflects the measured seek decision, not a placeholder.

---

## Open Questions

- **U1's output (blocking):** the seek/throttle mechanism decision. Everything in U3/U4 that branches on "SIGSTOP vs kill+restart" waits on this. U1 is sequenced first and is pure investigation, so it does not block U2/U6 scaffolding — only U3's worker branch and U4's seek integration.
- **SeekAwarePrefetcher fate (non-blocking):** whether the prefetcher has any remaining role after U6 removes bookings, or whether it becomes dead code (then deferred to the follow-up dead-code cleanup). Resolve during U6 implementation.
