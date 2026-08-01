---
artifact_contract: ce-unified-plan/v1
artifact_readiness: implementation-ready
product_contract_source: ce-plan-bootstrap
execution: code
title: "feat: transcode cache-sweep scheduler job + e2e reliability"
plan_type: feat
date: 2026-07-16
depth: standard
---

# Transcode Cache-Sweep Scheduler Job + E2E Reliability

## Goal Capsule

Two deliverables that close out the muxed-refactor:

1. **Cache-sweep scheduler job** — segments are now a persistent cache (seek-back hits cached files, no re-transcode). Without cleanup, the cache grows unbounded. Add a schedulable command that sweeps old segment files by LRU/age, keeping the cache within a configured size budget.

2. **E2E reliability** — the muxed e2e tests are flaky because the encoding loop needs ~2-5s to produce the first segment after the manifest request, but the test's `manifestParsed` assertion fires before segments exist. Fix the e2e harness to wait for segment availability, not just manifest parse.

---

## Problem Frame

### Cache sweep

Segments live in `storage/conversions/{videoId}/{tier}/` as `v{vIdx}_a{aIdx}_{tier}_{seg}.m4s` + `init.mp4`. Each 10-min video at 3 tiers produces ~318 files (~50-200MB). Without cleanup, this grows linearly with watched content. The identity-encoded filenames make each file self-describing (video index, audio index, tier, segment), so a sweep job can identify provenance and apply policy without parsing the DB.

There's already a `CleanupOrphanedJobsCommand` (cleans up abandoned job/session records) and a full scheduler infrastructure (`SchedulableCommandInterface`, `SchedulerRegistry`, `ScheduledJob`). The cache-sweep job follows the same pattern.

### E2E reliability

The e2e tests (`tests/e2e/transcode.spec.ts` etc.) wait for `manifestParsed` then `playback > 3s`. Under the muxed model, the manifest is served immediately (it's generated from ffprobe data), but the stream manager needs 2-5s to spawn FFmpeg and produce the first segment. hls.js parses the manifest, requests segment 0, gets a 503 (not ready yet), and the test's `manifestParsed` event fires but playback never starts because hls.js has no segment to buffer. The test times out at 60s.

The fix: the e2e harness should wait for the first segment to be available before asserting playback. This can be done by polling the segment endpoint until it returns 200, or by extending the hls.js error tolerance.

---

## Requirements

- **R1 — Cache-sweep command.** A schedulable console command `app:transcode:cache-sweep` that deletes segment files older than a configurable TTL (default 24h) or when the total cache exceeds a size budget (default 50GB), whichever triggers first. *(Cleanup)*
- **R2 — LRU eviction.** When the size budget is exceeded, evict the oldest-accessed video directories first (not individual segments — a video's segments are an atomic unit). Access time = file mtime. *(Cleanup)*
- **R3 — Scheduler registration.** The command implements `SchedulableConsoleCommandInterface` so it can be scheduled via the existing scheduler. Default schedule: daily at 03:00. *(Integration)*
- **R4 — Safe deletion.** Never delete segment files that are in active use. "In use" means: (a) the transcode job is in_progress (still encoding), OR (b) a viewer has requested a segment from this video within a recent window (e.g. last 30 min — playback in progress). Detection: check session `updated_at` timestamps (a session touched recently means someone is watching), not just job status. A completed job with an idle session is safe to sweep; a completed job with a recently-active session is not. *(Correctness)*
- **R5 — E2e harness fix.** The e2e tests reliably pass against a cold server (fresh DB, empty cache) by waiting for segment availability before asserting playback. *(Reliability)*

---

## Key Technical Decisions

### KTD-1: Sweep granularity = video directory, not individual segment files

A video's segments across tiers form an atomic cache unit — partial deletion (some tiers gone, others kept) produces a confusing player experience (quality switch fails). The sweep evicts entire `{videoId}/` directories, not individual `*.m4s` files. This simplifies the eviction logic and guarantees cache consistency.

### KTD-2: E2e fix = increase waitForFilePath timeout + segment-ready poll

The real issue is timing: the stream manager's FFmpeg needs 2-5s to produce segment 0 after the manifest request triggers session creation. Two fixes:
- The controller's `waitForSegment` timeout is 60s (already generous) — the issue is the e2e test asserts `manifestParsed` which fires before segments exist, then checks playback which hasn't buffered.
- The e2e harness should poll the `/segment?index=0` endpoint until it returns 200 (segment ready) before asserting playback advances. This decouples the test from the encoding latency.

---

## Implementation Units

### U1. Cache-sweep command

**Goal:** Add `app:transcode:cache-sweep` schedulable command.

**Requirements:** R1, R2, R3, R4.

**Files:**
- `src/Transcode/Application/Command/SweepTranscodeCacheCommand.php` (new)
- `src/Transcode/Application/CommandHandler/SweepTranscodeCacheHandler.php` (new)
- `src/Transcode/Application/Port/TranscodeStoragePortInterface.php` (modify — add `getDirectorySize`, `getVideoDirectories`, already has `deleteDirectory`)
- `src/Transcode/Infrastructure/Storage/TranscodeFileStorage.php` (modify — implement new methods)
- `src/Transcode/Infrastructure/Storage/SegmentFileResolver.php` (modify — add `getBasePath` accessor)
- `config/services.yaml` (modify — register command + handler, auto-register as schedulable)

**Approach:**
- The command takes `--ttl-hours` (default 24) and `--max-gb` (default 50) options.
- The handler walks `{basePath}/*/` (each subdir = a videoId), checks: (a) is there an active job for this video? (skip if in_progress — R4), (b) is there a session with `updated_at` within the last 30 minutes? (skip — viewer is actively watching — R4), (c) is the directory's newest file older than TTL? (delete if yes — R1), (d) if total cache size exceeds budget, evict oldest-accessed directories until under budget — R2.
- Implements `SchedulableConsoleCommandInterface` for scheduler registration.
- Active-job check: query `TranscodeJobRepository::findActiveByVideo(videoId)` — if any job is in_progress, skip. Also query `TranscodeSessionRepository::findByJob(jobId)` — if any session has `updated_at` within the last 30 minutes, skip (viewer is actively watching).

**Test scenarios:**
- Deletes a video directory whose newest file is older than TTL.
- Does NOT delete a video directory with an active (in_progress) job.
- Does NOT delete a video directory with a session updated within the last 30 min (viewer actively watching).
- Does NOT delete a video directory whose newest file is within TTL.
- When total size exceeds budget, evicts oldest directories first until under budget.
- Dry-run mode (`--dry-run`) reports what would be deleted without deleting.

**Verification:** Unit test passes; command is registered in the scheduler registry.

---

### U2. E2e reliability fix

**Goal:** The e2e tests pass reliably against a cold server.

**Requirements:** R5.

**Files:**
- `tests/e2e/transcode.spec.ts` (modify)
- `tests/e2e/transcode-dash.spec.ts` (modify)
- `tests/e2e/transcode-concurrent.spec.ts` (modify)
- `tests/e2e/transcode-seek.spec.ts` (modify)

**Approach:**
- Before asserting `playback > 3s`, poll the first segment endpoint until it returns 200. This ensures the stream manager has produced at least one segment before the player tries to buffer.
- The poll: `fetch('/api/transcode/{jobPublicId}/segment?index=0&sig=...')` in a loop (every 500ms, up to 30s). Extract the signed URL from the manifest.
- This replaces the implicit assumption that segments are ready when the manifest parses.

**Test scenarios:**
- Cold server (no cached segments): test waits for segment 0 availability, then playback advances past 3s.
- Warm server (segments cached): test still works (segment is immediately available).

**Verification:** All 4 e2e specs pass against a cold server (fresh DB, empty cache) and a warm server.

---

## Verification Contract

1. `./vendor/bin/phpunit tests/Unit/Transcode` — green, including new sweep tests.
2. `cd tests/e2e && SERVER_URL=http://localhost:9501 npx playwright test` — all 4 specs pass against a cold server.
3. `app:transcode:cache-sweep --dry-run` reports directories without deleting.
4. The sweep command appears in the scheduler registry.

## Definition of Done

- U1 and U2 implemented and verified.
- Cache sweep is schedulable and safe (never deletes active jobs).
- E2e tests pass reliably on cold and warm server states.
