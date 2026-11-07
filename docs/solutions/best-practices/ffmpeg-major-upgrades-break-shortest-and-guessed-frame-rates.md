---
title: End movie audio at a stated video end and read the average frame rate across FFmpeg upgrades
date: "2026-11-04"
last_updated: "2026-11-07"
category: best-practices
module: Transcode segment encoding and source probing
problem_type: best_practice
component: background_job
severity: high
applies_when:
  - Upgrading the FFmpeg or ffprobe version in the worker or CI images
  - Changing how the segment encoder ends audio relative to the movie
  - Reading a source frame rate or duration from ffprobe stream fields
  - Media tests fail on audio track ends, publication timing or probed frame rates after an image change
  - Comparing probe results for the same source across two FFmpeg versions
symptoms:
  - Segment encoder media tests fail with audio ending short of the video end or missing its final frame
  - The first progressive segment is published seconds later than before
  - Probe results report the wrong frame rate for sub-1 fps sources
root_cause: wrong_api
resolution_type: code_fix
tags: [ffmpeg, ffprobe, shortest, apad, frame-rate, segment-encoder, upgrade, start-time]
---

# End movie audio at a stated video end and read the average frame rate across FFmpeg upgrades

## Context

The worker images moved from Debian 12 (FFmpeg 5.1) to Debian 13 (FFmpeg 7.1).
After the move, 61 backend tests failed, by the count of the session that made
the fix. Segment encoder media tests found audio ending short of the video,
a missing final audio frame and late progressive publication. Probe tests found
wrong frame rates. That session traced the failures to FFmpeg's new behaviour,
not to a change in Bånder's code.

Two things the encoder relied on had shifted under it:

- **`-shortest`.** The encoder padded audio with `apad` and let `-shortest` stop
  the output at the end of the video. On FFmpeg 7.1, the investigation recorded
  in commit `eefd89fc6` found that `-shortest` holds up to ten seconds of frames
  before the first DASH segment is published, and drops the last AAC frame that
  crosses the video end. In that commit's paced production command (30 s movie,
  4 s audio, seek to segment 1), the first video segment appeared at 17.43 s
  with `-shortest` and at 7.47 s with the audio ended at the video end. For a
  14 s movie with 4 s of audio, the AAC track ended at 13.994667 s instead of
  14.000000 s. Opus came out exact both ways.
- **`r_frame_rate`.** The probe took the source frame rate from ffprobe's
  `r_frame_rate`, which is a guessed base rate. On FFmpeg 7 it overstates sub-1
  fps Matroska tracks: commit `083c9128c` records 1/5 for a 0.1 fps H.264 track
  and 7/2 for a 0.7 fps HEVC track. MPEG-TS overstated such rates on 5.1 and 7.1
  alike (1/2 for 0.1 fps). The encoder treats a source below 1 fps as sparse and
  adds an `fps` filter
  (`src/Transcode/Infrastructure/FFmpeg/SegmentEncoder.php:159-162`), so a 0.7 fps
  source reported as 3.5 fps loses that handling.

Two intermediate steps fell short:

- **Removing `-shortest` alone.** With `whole_dur` padding, the padded audio is
  cheap to produce and runs ahead of the movie. Without strict interleaving, the
  DASH muxer flushed audio before the first video packet. Commit `eefd89fc6`
  records empty and fragmentary leading audio segments in about one in ten
  sparse delayed-audio encodes. With `-max_interleave_delta 0`, 0 of 40 runs
  failed.
- **Passing the probed duration as the video end.** The first version passed
  `VideoProbeResult::$duration`. That value falls back to the container duration
  when the video stream does not state its own end, and a container estimate is
  not a verified end for the selected video (see
  [Selected video endpoint](../../../CONCEPTS.md#selected-video-endpoint)).
  Commit `756699118` narrowed the input to a new `videoEnd` field that is set
  only when the video stream itself states its end. A source with only the
  container estimate keeps `-shortest`.

These commits were made on `feat/configurable-media-capacity` and reached
`master` with the hashes below; their subjects find them if history is rewritten
again:

- `083c9128c` fix(transcode): take the source frame rate from ffprobe's average rate
- `879f51701` test(transcode): make media fixtures independent of the FFmpeg version
- `eefd89fc6` fix(transcode): end movie audio at the probed video end instead of -shortest
- `756699118` fix(transcode): end audio at the video end only when the stream states it

## Guidance

**End audio at an explicit video end; do not lean on `-shortest`.** When the
selected video's end is known, pad the audio to that end and cut it there in the
filter graph. `D` is the video end minus the seek start of the generation:

```text
aresample=first_pts=0,apad=packet_size=1024:whole_dur=D,atrim=end=D
```

Omit `-shortest` in that case. Every command with audio also sets
`-max_interleave_delta 0`, so padded audio cannot reach the muxer ahead of the
first video packet. See `SegmentEncoder.php:167-175` for the filter and
`SegmentEncoder.php:300-307` for the output options.

**Take the end only from the video stream.** `VideoProbeResult::$videoEnd`
(`src/Transcode/Domain/ValueObject/VideoProbeResult.php:44`) is null unless the
stream states its end: MOV or MP4 stream timing, or the Matroska track
`DURATION` tag (`selectedVideoEndpoint`, lines 254-291). Callers pass
`$probe->videoEnd ?? 0.0`, and 0 means unknown. Do not substitute the container
duration.

**Read frame rates from `avg_frame_rate`.** `streamFramerate`
(`VideoProbeResult.php:352-357`) uses `avg_frame_rate` and falls back to
`r_frame_rate` only when the average is missing, unknown or not finite. The two
agree for 24, 29.97 and 60 fps, for interlaced 25i and 29.97i, and for B-frame
sources. For variable-rate sources the average differs from the base rate (22.5
for a 30/15 fps mix), which is the intended trade.

**Compare probe output field by field across the upgrade.** Generate a small set
of sources (MP4, MOV, Matroska, WebM, MPEG-TS, FLAC, MP3; with and without audio
priming), probe each with both versions using the application's own
`FFprobeArguments`, and diff what `VideoProbeResult` produces, not the raw
JSON. A changed field there changes presentation identities and filter graphs,
so it re-encodes cached presentations of re-probed videos once.

**Treat an FFmpeg major upgrade as a behaviour change.** Run the media suites on
the new image before relying on it, and read each failure as a possible FFmpeg
change before changing the expectation. The fixture fixes in commit `879f51701`
list other FFmpeg 7 changes the tests hit: encoder-side `-color_primaries` and
`-color_trc` are ignored, ffprobe's frame `pkt_duration_time` became
`duration_time`, muxers interleave strictly by timestamp, a MOV container's
duration is reported from its start time, and a terminal AAC packet that holds
only padding may decode to no samples.

## Why This Matters

The encoder treated `-shortest` and ffprobe's guessed frame rate as stable
behaviour. Between 5.1 and 7.1 both changed for some inputs, without an error or
a warning. The encoder kept producing valid output, but that output published
later and ended audio early. Only media tests that measure publication times and
track ends caught it.

An explicit end in the filter graph does not depend on how the muxer decides
which stream is shortest. The average frame rate comes from the stream's frames
and duration rather than from a guessed base rate.

**FFmpeg 7.1 to 8.1.** The images moved to the project's static FFmpeg 8.1
build on branch `feat/baac-ffmpeg-transcoder` (2026-11-07). Its probe-drift
check, run that day on generated sources, found packet timestamps identical
between the versions and one parsed change. For a Matroska or WebM source whose
audio carries encoder priming, ffprobe 7.1 reported the container `start_time`
as a small negative value (-0.021 s for AAC, -0.007 s for Opus in that check),
and 8.1 reports 0. The parser accepts only a non-negative container start as the
timestamp origin (`VideoProbeResult.php:259-260` and `nonnegativeTimestamp`,
line 329), so on 7.1 such a source had no `videoEnd` even when its track stated
a `DURATION`, and its audio fell back to `-shortest`. On 8.1 the same source gets
a `videoEnd` and takes the explicit `whole_dur`/`atrim` end; its probed duration
also drops by the priming. MP4, MOV, MPEG-TS and FLAC results were unchanged.
Whether `-shortest` on 8.1 still holds back the first segment was not measured.

**Residual risk.** Callers without a stated video end still use `-shortest` and
still get FFmpeg 7's behaviour: a later first publication and an AAC track that
can end short of the video by part of a frame. This applies to formats other
than MOV, MP4 and Matroska (MPEG-TS and AVI, for example), to MOV files that fall
back to the format duration, to Matroska files without a track `DURATION` tag,
and to a seek that starts at or past the stated end. On FFmpeg 7.1 it also
covered Matroska and WebM sources with primed audio; 8.1 moved those to the
explicit end, as described above. A future FFmpeg change to
`-shortest` would land on these sources first.

## When to Apply

- Before bumping the FFmpeg version in `docker/ffmpeg/Dockerfile` or a base
  image that brings a new FFmpeg.
- When adding an encode path that must end audio with the movie.
- When adding a decision that depends on the source frame rate or duration.

## Examples

A 14 s movie with 4 s of audio, encoded from the start, as built by
`SegmentEncoder::buildStreamArgs` with no profile audio filters (excerpt):

```text
# Video end stated by the stream (sourceDuration = 14.0)
-af aresample=first_pts=0,apad=packet_size=1024:whole_dur=14.000000,atrim=end=14.000000
-max_interleave_delta 0

# No stated end (sourceDuration = 0.0)
-af aresample=first_pts=0,apad=packet_size=1024
-max_interleave_delta 0 -shortest
```

The stream-arguments test covers both forms:
`tests/Unit/Transcode/Infrastructure/FFmpeg/SegmentEncoderStreamArgsTest.php`.

After the fix, the native lane ran 911 tests OK on the Debian 13 images on
2026-11-04, by the session's record.

## Related

- [Selected video endpoint](../../../CONCEPTS.md#selected-video-endpoint)
- [Verify AAC stream copy after normalizing transport framing](verify-aac-copy-after-normalizing-transport-framing.md)
- [Backend and media verification](../../../docs-book/part-2-developer-guide/testing.md)
