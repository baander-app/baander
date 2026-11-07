---
title: Pace a stream copy with a read-rate burst counted from the seek boundary
date: "2026-11-06"
category: performance-issues
module: Transcode owned stream copy (FFmpeg copy command and lead throttle)
problem_type: performance_issue
component: background_job
related_components: [testing_framework]
severity: medium
symptoms:
  - "The two-command drill's attached phase fails: The fixture must leave source media beyond the currently published native window"
  - "An owned stream copy of a 180 s movie publishes nearly the whole presentation within a second of playback, so the published window ends far past the throttle's pause mark"
  - "With input-side pacing and an input -itsoffset, a copy restarted at a seek publishes its remainder in one burst while a copy from the start is paced"
root_cause: wrong_api
resolution_type: code_fix
tags: [transcode, ffmpeg, stream-copy, readrate, copyts, seek-boundary, lead-throttle, pacing]
---

# Pace a stream copy with a read-rate burst counted from the seek boundary

## Problem

An owned stream copy is bound by disk, not by an encoder, so FFmpeg copied a whole movie into the DASH output before the session subscriber's lead throttle looked at it. The published window ran to the end of the source within a second of playback, which defeated the throttle's purpose (a producer stays at most 72 seconds ahead of the playhead) and failed the two-command drill's proof that source media remains beyond the published window.

## Symptoms

- The two-command drill's attached phase stops at "The fixture must leave source media beyond the currently published native window": the player's seekable end already reached about 180 seconds of a 180-second movie.
- The copy's `ffmpeg` process finishes seconds after it starts; the throttle's pause mark (`TranscodeSessionSubscriber::PAUSE_LEAD_SECONDS`, 72 seconds) is never reached while the process runs.
- After the first pacing attempt, a copy started from the beginning was paced, but a copy restarted at a seek (the worker kills and restarts the copy at a boundary before the target on every source seek) published its whole remainder at once.

## What Didn't Work

- **Restating the drill's window proof for copies** was considered and rejected: a production copy that outruns the throttle is the defect, and the throttle band is the product's own bound on how far any producer runs ahead. The fix belongs in the worker.
- **Input-side pacing with the boundary normalised on the input side.** The first version added `-readrate_initial_burst 48 -readrate 2.0` before `-i` and kept the existing `-itsoffset -<boundary>` as an input option. Under `-copyts` with `-seek_timestamp 1 -ss <boundary>`, FFmpeg 7.1.5 measures the initial burst from the seek boundary's timestamp, and the input offset shifted the packet timestamps so that the burst window was pushed out by the boundary: a copy restarted at 90 seconds treated everything up to about 90 + 48 seconds as burst and published the rest of the movie unpaced. The reviewer who measured it saw the drill's window proof pass for the attached phase and fail after the first seek.

## Solution

The copy command passes the burst and rate as input options immediately before `-i`, and moves the boundary normalisation to the output side as `-output_ts_offset -<boundary>` placed before the manifest path. The constants live next to the command and are documented against the throttle marks (`src/Transcode/Infrastructure/FFmpeg/CopySegmentArguments.php`):

```php
public const int INITIAL_BURST_SECONDS = 48;
public const float READ_RATE = 2.0;
// ...
array_push($arguments, '-seek_timestamp', '1', '-ss', sprintf('%.6f', $sourceBoundary));
// Under -copyts FFmpeg counts the burst from the seek boundary, and an input
// offset would push the burst out by that boundary, so the boundary is
// normalised on the output side instead.
array_push($arguments, '-readrate_initial_burst', (string) self::INITIAL_BURST_SECONDS, '-readrate', sprintf('%.1f', self::READ_RATE), '-i', $sourcePath, /* ... */);
// ... DASH output options ...
array_push($arguments, '-output_ts_offset', sprintf('%.6f', -$sourceBoundary), $outputDirectory . '/manifest.mpd');
```

The resulting command shape, for a copy restarted at 6.17 seconds:

```text
ffmpeg -copyts -seek_timestamp 1 -ss 6.170000 -readrate_initial_burst 48 -readrate 2.0 -i source.mkv \
  -map 0:0 -map 0:1 -c copy -f dash ... -output_ts_offset -6.170000 /output/manifest.mpd
```

The throttle marks are named constants on the subscriber (`TranscodeSessionSubscriber::PAUSE_LEAD_SECONDS` 72 and `RESUME_LEAD_SECONDS` 36), and `CopySegmentArgumentsTest` asserts the burst sits between them, that both pacing options precede `-i`, that `-output_ts_offset` follows it, and that no `-itsoffset` remains.

## Why This Works

- `-readrate` limits how fast FFmpeg reads the input relative to its timestamps, and `-readrate_initial_burst` lets the first N seconds of input through at full speed. With a 48-second burst the player gets its first window at once; the burst stays under the 72-second pause mark so the throttle is what stops the copy, and a 2x rate lets the lead regrow after the throttle resumes the producer at 36 seconds.
- As observed on FFmpeg 7.1.5: under `-copyts` the output keeps the source timestamps, and the burst accounting starts at the first timestamp read, which after `-seek_timestamp 1 -ss` is the seek boundary. An input-side `-itsoffset` changes the timestamps the demuxer reports, so the burst window moved with it. An output-side `-output_ts_offset` only shifts the timestamps the muxer writes, after pacing has been applied, so the copy is counted from the boundary while its output is what the earlier `-itsoffset` produced: the video copy presentation integration test's packet-data hash proof is unchanged and still passes, and the drill's manifests read as before.
- The copy's packets are the unit the muxer publishes, so input-side pacing bounds the published lead for ordinary sources. This differs from the held-frame encode fixtures, where one input packet carries seconds of generated output and pacing has to happen on generated frames instead (see the related learning).

## Prevention

- Treat any producer that is not bound by an encoder (copies, remuxes, cached sources) as something the throttle must govern, and prove it with the drill rather than a unit test alone. The attached phase asserts only that the fixture leaves source media beyond the published window once playback starts; a stricter check that the published end stays under the pause mark plus one segment while the process runs does not exist yet and would be the direct proof.
- When adding FFmpeg timing options to a command that uses `-copyts` with a seek, check whether each option counts from the stream's first timestamp or from zero, and keep timestamp normalisation on the output side so pacing and seeking see the source's own timestamps. Pin the argument order in `CopySegmentArgumentsTest` (input options before `-i`, output options after) so a reordering cannot silently change the semantics.
- Bind pacing constants to the throttle's named marks in the test, as `CopySegmentArgumentsTest` does, so a change to the throttle fails the test instead of silently leaving the burst inside the pause band.
- Still unmeasured, as of this writing: FFmpeg's read-rate clock keeps running while the producer is stopped by the throttle, so a copy that resumes after a long pause may publish its accrued allowance in one burst before the throttle stops it again. The drill samples only the first window. Measure the published end across a pause and resume cycle before changing the rate or relying on the band for long pauses.

## Related Issues

- [Pace held-frame transcode fixtures by generated frames, not input packets](../best-practices/pace-held-frame-fixtures-by-generated-frames.md): the complementary case, where input-side pacing does not bound a held-frame source because one packet carries a whole hold; that learning covers filtered encodes, this one packet copies.
- [Verify AAC stream copy after normalizing transport framing](../best-practices/verify-aac-copy-after-normalizing-transport-framing.md): copy integrity verification, which this change leaves intact; it defers copy execution timing to this learning.
- [End movie audio at a stated video end and read the average frame rate across FFmpeg upgrades](../best-practices/ffmpeg-major-upgrades-break-shortest-and-guessed-frame-rates.md): FFmpeg major upgrades change timing behaviour; the burst accounting described here was observed on FFmpeg 7.1.5 and needs re-verification on the next major upgrade.
- Plan: retired; read it with `git show b9a67726f:docs/plans/2026-11-06-1659-fix-two-command-copy-pacing-plan.md`. The roadmap checkpoint "two-command copy pacing" dated 2026-11-06 is in `git show b9a67726f:ROADMAP.md`.
