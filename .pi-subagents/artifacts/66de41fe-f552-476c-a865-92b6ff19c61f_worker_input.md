# Task for worker

You are a delegated subagent running from a fork of the parent session. Treat the inherited conversation as reference-only context, not a live thread to continue. Do not continue or answer prior messages as if they are waiting for a reply. Your sole job is to execute the task below and return a focused result for that task using your tools.

Task:
Implement U3 (TranscodeStreamManager — Swoole Process direct) for the long-process-segmenter refactor.

Plan: /home/martin/dev/baander/docs/plans/2026-07-16-001-refactor-long-process-segmenter-plan.md — read ONLY the "### U3." section, KTD-1 (FINAL, revised), KTD-3 (RESOLVED), and KTD-2. Do NOT read other units.

You are on branch feat/long-process-segmenter (commits U1, U2 already shipped). Repo root: /home/martin/dev/baander.

GOAL: Create a new TranscodeStreamManager that spawns ONE long-lived FFmpeg process via \Swoole\Process directly (OUTSIDE the CpuProcessPool). The process produces video + all audio languages continuously via the HLS muxer with fMP4 segments. The manager reads FFmpeg stdout line-by-line, and on each segment filename writes a SegmentAvailabilityTable row. The manager owns the full process lifecycle.

CRITICAL CONTEXT (the benchmark U1 measured, inside the baander-app container, FFmpeg 5.1.9):
- The CORRECT fMP4 invocation uses the HLS muxer: `-f hls -hls_segment_type fmp4 -hls_time <dur> -hls_playlist_type vod -hls_segment_filename <out>/seg_%d.m4s <out>/stream.m3u8`. `-segment_format fmp4` does NOT work on this FFmpeg ("Muxer not found"). U1 benchmarked this exact invocation successfully.
- FFmpeg writes the playlist to stdout when you add `-hls_segment_list_type csv -hls_segment_list pipe:1` — NO wait, the working pattern is: the HLS muxer writes the .m3u8 to the file path you give it, AND it writes segment filenames. To get per-segment availability events, the manager should WATCH the output directory for new `seg_*.m4s` files appearing (inotify-style polling via glob + filesize check), OR parse the .m3u8 as it grows. The cleanest approach given PHP/Swoole: poll the output dir every 100ms for new non-zero-size segment files, and mark each ready. (The toy project used `-f segment -segment_list pipe:1 -segment_list_type csv` which streams CSV to stdout — but that segment muxer does NOT do fMP4 cleanly on this FFmpeg version. So: HLS muxer to files + directory polling for availability. This is pragmatic and matches what the HTTP layer already does in StreamSegmentController::waitForFilePath.)

THE MANAGER (src/Transcode/Infrastructure/Swoole/TranscodeStreamManager.php):

Constructor deps (inject these):
- SegmentAvailabilityInterface (U2)
- LoggerInterface
- A configurable int $maxConcurrentStreams (default 4)

Public methods:
- startStream(TranscodeJob $job, string $sourcePath, QualityTier $tier, array $audioLanguages, string $videoFilters, string $audioFilters, ?int $startSegment = null): string
  - Resolves the output directory via TranscodeStoragePortInterface (inject it — see how TranscodeSessionSubscriber uses $this->storage->resolveSegmentPath / resolveJobDirectory)
  - Builds the FFmpeg arg array via SegmentEncoder::buildStreamArgs() (you add this method — see below)
  - Spawns \Swoole\Process with redirect=false, pipe=true. The process runs FFmpeg; the MANAGER (in the parent, the HTTP/event-loop process) runs a coroutine that polls the output dir every 100ms for new seg_*.m4s and init.mp4 files, and calls $this->availability->markReady($job->getId(), <tierOrLang>, <index>, <path>) for each. Use \Swoole\Coroutine::create() or Async::sleep() (App\Shared\Infrastructure\Swoole\Async) for the polling loop so it never blocks the event loop.
  - Video segments: key tier = $tier->name. Audio segments: key tier = the language code. The segment filename pattern from FFmpeg will be `seg_%d.m4s` for video; for audio the pattern needs to encode the language — use `-hls_segment_filename` per audio variant OR put each language in its own subdir. SIMPLEST: one FFmpeg invocation per (video + language) is NOT what we want — we want ONE process. So: use `-map 0:v:0` + `-map 0:a:0` + `-map 0:a:1` ... in a single invocation, and write each to its own subdir via per-stream output. BUT the HLS muxer doesn't trivially do multi-output in one process. THEREFORE: the pragmatic design that actually works on FFmpeg 5.1.9 is to spawn ONE process for VIDEO (with `-an`), and accept that audio is folded in by spawning the audio portion through the EXISTING pool path (encode_audio_segment jobs) OR a separate manager process per language. 
  - DESIGN DECISION TO MAKE: given the FFmpeg constraint that one HLS-muxer process = one rendition (video OR one audio track), "fold audio into the long process" as literally one process is not achievable with the HLS muxer. The cleanest faithful implementation: the manager spawns ONE long video process via \Swoole\Process, and audio continues to use the EXISTING per-segment pool dispatch (encode_audio_segment) that already works. This preserves the "one long video process" benefit (which is where the seek/init tax is highest) while not fighting FFmpeg's muxer model. RECORD THIS in a code comment and in your final report. Audio folding into a truly-single process would require the DASH muxer or multiple HLS outputs (ffmpeg -f hls per output via tee/split_map) which is a separate larger effort.
  - Stores the \Swoole\Process handle keyed by jobId so stop/pause/resume/restart can find it.
  - Returns the output directory path.
- stopStream(Uuid $jobId): void — kills the process (SIGKILL), clears the jobId entry.
- pauseStream(Uuid $jobId): void — SIGSTOP the process. No-op if not running.
- resumeStream(Uuid $jobId): void — SIGCONT the process. No-op if not running.
- isStreaming(Uuid $jobId): bool
- A private enforceConcurrency() that rejects/queues if at capacity.

SegmentEncoder modification (src/Transcode/Infrastructure/FFmpeg/SegmentEncoder.php):
- Add public function buildStreamArgs(string $sourcePath, QualityTier $tier, string $videoFilters, ?int $startSegment = null): array
  - Returns the FFmpeg arg array for the long video stream: input seek (-ss if startSegment), -i source, -map 0:v:0 -an, codec flags via VideoProcessingRules::codecFlags($this->encoderProfile->encoder), video bitrate/maxrate/bufsize from $tier, $videoFilters as -vf, then `-f hls -hls_segment_type fmp4 -hls_time <getSegmentDuration()> -hls_playlist_type vod -hls_segment_filename <dir>/seg_%d.m4s -hls_start_number <startSegment or 0> <dir>/stream.m3u8`. Look at TranscodePoolWorker::encodeFmp4VideoSegment() (the existing working HLS-muxer invocation) and mirror its flag structure EXACTLY — it's the proven pattern.
  - Look at TranscodePoolWorker::encodeFmp4VideoSegment lines ~316-393 for the canonical arg structure (hwaccel_flags, decoder_flags, encoder flags, bitrate, -f hls -hls_segment_type fmp4 -hls_time -hls_playlist_type vod -hls_segment_filename). buildStreamArgs produces the same shape as a RETURNED ARRAY (not a shell string — the manager uses \Swoole\Process which takes an array, avoiding shell injection).

PATTERNS TO FOLLOW (read these first):
- src/Transcode/Infrastructure/Swoole/TranscodePoolWorker.php — especially encodeFmp4VideoSegment() (the canonical HLS-muxer fMP4 arg structure) and exec() (non-blocking stdout/stderr read via stream_select + Async::sleep)
- src/Transcode/Infrastructure/Swoole/TranscodeSessionSubscriber.php — how it uses $this->storage, $this->segmentEncoder, Async::sleep; the polling pattern in dispatchSegments/dispatchAudioSegments
- src/Transcode/Infrastructure/Swoole/SegmentAvailabilityTable.php (U2) — the interface you write to
- src/Shared/Infrastructure/Swoole/Async.php — the Async::sleep() helper
- \Swoole\Process — PHP Swoole docs; the constructor signature is `function(callable, bool $redirect_stdin_and_stdout = false, int $pipe_type = SWOOLE_PIPE_TCP, bool $enable_coroutine = false)`. Use SWOOLE_PIPE_TCP and read via $proc->read() inside a coroutine, OR use proc_open-style with the process and poll the dir (pragmatic). Pick whichever is cleaner with Async::sleep.

CONSTRAINTS:
- PHP 8.5, strict_types, 2-space indent, never use `mixed`/`any` without justification
- Follow existing namespace conventions (App\Transcode\Infrastructure\Swoole\...)
- Uuid = App\Shared\Domain\Model\Uuid; QualityTier = App\Transcode\Domain\ValueObject\QualityTier; TranscodeJob = App\Transcode\Domain\Model\TranscodeJob
- Inject TranscodeStoragePortInterface (App\Transcode\Application\Port\TranscodeStoragePortInterface) to resolve paths

DO NOT:
- Touch CpuProcessPool, TranscodeProcessPool, TranscodePoolWorker, or any Recommendation/Scheduler files — they stay
- Modify TranscodeSessionSubscriber (U4 owns that)
- Modify StreamSegmentController (U5 owns that)
- Remove the per-segment encode_segment pool path (U4/U6 own that — leave it for now)
- Run the full test suite. Run ONLY: php -l on new files, and your new unit test.

TESTS (tests/Unit/Transcode/Infrastructure/Swoole/TranscodeStreamManagerTest.php):
- buildStreamArgs produces expected flags for a single tier (video map, -an, -f hls, fmp4, hls_time = segment duration, hls_segment_filename with %d)
- buildStreamArgs includes -hls_start_number when startSegment provided
- buildStreamArgs carries hwaccel/decoder flags through (parametrize libx265, h264_nvenc)
- Manager startStream/stopStream lifecycle: use a mock/stub process — verify isStreaming true after start, false after stop. (You may need to make the \Swoole\Process spawn injectable/mockable — an injectable ProcessFactory is the clean pattern. Add a private factory callable or a ProcessFactoryInterface if needed for testability.)
- Manager pause/resume call SIGSTOP/SIGCONT (verify via the spawned stub)
- enforceConcurrency rejects when at capacity

VERIFICATION (your done signal):
- php -l clean on all new/modified files
- ./vendor/bin/phpunit tests/Unit/Transcode/Infrastructure/Swoole/TranscodeStreamManagerTest.php passes
- Manager + interface registered in config/services.yaml

REPORT in your final message: (a) all file paths created/modified, (b) the design decision you made re: audio folding (single video process via manager + audio stays on pool, OR true multi-output), with the FFmpeg constraint that drove it, (c) test run result, (d) services.yaml wiring confirmation. Leave changes UNCOMMITTED — the orchestrator commits.

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