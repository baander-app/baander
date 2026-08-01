# Task for worker

You are a delegated subagent running from a fork of the parent session. Treat the inherited conversation as reference-only context, not a live thread to continue. Do not continue or answer prior messages as if they are waiting for a reply. Your sole job is to execute the task below and return a focused result for that task using your tools.

Task:
Remove the dead separate-audio code path from the Transcode bounded context. This is M4 of the muxed-refactor — audio is now muxed inline in each segment (M1-M3 shipped), so all separate-audio delivery/encoding code is dead.

Repo: /home/martin/dev/baander. Branch: feat/long-process-segmenter. All code compiles, 321 unit tests pass.

DELETE these methods/code:

1. TranscodeProcessPool (src/Transcode/Infrastructure/Swoole/TranscodeProcessPool.php):
   - encodeAudioInitSegment() method (~lines 125-153)
   - encodeAudioSegment() method (~lines 155-193)

2. TranscodePoolWorker (src/Transcode/Infrastructure/Swoole/TranscodePoolWorker.php):
   - Remove 'encode_audio_init_segment' and 'encode_audio_segment' from supportedTypes() array
   - Remove their match arms in handle()
   - Remove the private methods: encodeAudioInitSegment(), encodeAudioSegment(), encodeFmp4AudioSegment()

3. TranscodeStreamingPortInterface (src/Transcode/Application/Port/TranscodeStreamingPortInterface.php):
   - Remove: getAudioManifest, getAudioInitSegment, getAudioSegment, getAudioInitSegmentPath, getAudioSegmentPath, resolveAudioSegmentAvailability

4. TranscodeStreamingService (src/Transcode/Infrastructure/Transcode/TranscodeStreamingService.php):
   - Remove: getAudioManifest, getAudioInitSegment, getAudioInitSegmentPath, getAudioSegment, getAudioSegmentPath, resolveAudioSegmentAvailability
   - Remove extractAudioSegmentsForLanguage() (already unused)
   - Remove scanSubtitleSegments() only if it's only called by audio methods (check first — it may be used by subtitles)

5. CachedTranscodeStreamingService (src/Transcode/Infrastructure/Transcode/CachedTranscodeStreamingService.php):
   - Remove: getAudioManifest, getAudioInitSegment, getAudioSegment, getAudioInitSegmentPath, getAudioSegmentPath, resolveAudioSegmentAvailability (delegating methods)

6. QualityFilteringStreamingDecorator (src/QoL/Infrastructure/Swoole/QualityFilteringStreamingDecorator.php):
   - Remove: same audio methods that delegate to inner

7. ManifestGenerator (src/Transcode/Infrastructure/HLS/ManifestGenerator.php):
   - Remove generateAudioManifest() method

8. StreamManifestController (src/Transcode/Interface/Controller/StreamManifestController.php):
   - Remove the audioManifest() route + method (~line 178: /{jobPublicId}/audio/{language}/media.m3u8)

9. StreamSegmentController (src/Transcode/Interface/Controller/StreamSegmentController.php):
   - Remove audioInitSegment() route + method (~line 167: /audio/{language}/init.mp4)
   - Remove audioSegment() route + method (~line 209: /audio/{language}/seg_{index}.m4s)

10. TranscodeSessionSubscriber (src/Transcode/Infrastructure/Swoole/TranscodeSessionSubscriber.php):
    - Remove dispatchFirstAudioSegment() method (~line 568)
    - Remove dispatchAudioSegments() method (~line 624)
    - The CALLER of these (the if block around lines 290-337 that uses them when streamManager === null) — remove that fallback audio block too, since the stream manager is always active now. Keep only the dispatchLongStream() call for Step 9.
    - Remove the pollResult() and waitForSiblingLoudness() methods if still present (dead code from earlier refactor)

11. Tests: remove any test files that test audio-specific methods:
    - tests/Unit/Transcode/Infrastructure/Transcode/TranscodeStreamingServiceHelperTest.php — remove testExtractAudioSegmentsForLanguage* tests (keep audioCodecToRfc6381 tests if the method stays)

CONSTRAINTS:
- PHP 8.5, strict_types, 2-space indent
- Do NOT touch: the subtitle path (getSubtitleManifest/Segment/Path, extract_subtitles), the video path (getMediaManifest, getSegment, getInitSegment, resolveVideoSegmentAvailability, dispatchLongStream), the DASH manifest generator, or the muxed stream args
- Do NOT run the full test suite — run ONLY: php -l on every changed file, then ./vendor/bin/phpunit tests/Unit/Transcode
- The grep verification: grep -rn 'getAudioManifest\|encode_audio_segment\|encode_audio_init_segment\|generateAudioManifest\|dispatchFirstAudioSegment\|dispatchAudioSegments\|resolveAudioSegmentAvailability' src/ should return ZERO hits after your work

VERIFICATION (done signal):
- php -l clean on all changed files
- ./vendor/bin/phpunit tests/Unit/Transcode passes (321 baseline may drop slightly as you remove audio tests)
- grep returns zero hits for the dead audio methods
- No new PHPStan errors (run ./vendor/bin/phpstan analyse src/Transcode src/QoL/Infrastructure/Swoole/QualityFilteringStreamingDecorator.php --no-progress --memory-limit=512M 2>&1 | tail -5 if you want to check)

REPORT: file paths changed, test result, grep verification. Do NOT commit — leave uncommitted for the orchestrator.

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