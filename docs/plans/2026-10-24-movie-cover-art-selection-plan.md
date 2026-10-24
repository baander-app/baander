---
title: Exclude attached cover art from movie video selection
date: 2026-10-24
status: implemented
execution: code
---

# Exclude attached cover art from movie video selection

## Reproduced failure and active consumer

The movie worker builds its filters from VideoProbeResult and starts the
SegmentEncoder command through TranscodeStreamManager. Both currently select the
first video stream, including attached pictures. A valid generated MP4 with its
cover metadata before its movie track exposes a 32-by-32 MJPEG attached picture
at video ordinal zero and a 64-by-64 H.264 movie at ordinal one. The parser reports
the cover's 90,000-fps rate and dimensions. The actual command exits successfully
but leaves a zero-byte initialization file and no media segments. Selecting the
movie instead produces a valid initialization file and a published segment.

FFmpeg distinguishes video streams from video streams that exclude attached
pictures through its `v` and `V` stream specifiers. The
[stream-specifier documentation](https://www.ffmpeg.org/ffmpeg.html#Stream-specifiers)
and the deployed
[FFmpeg 5.1.9 implementation](https://github.com/FFmpeg/FFmpeg/blob/n5.1.9/libavformat/avformat.c)
provide the contract. Use the first non-attached video for probing and encoding.
This retains first-video ordering among ordinary movie tracks; it introduces no
preference for a default-disposition track or new video-track selection feature.

## Implementation boundary

Exclude streams with the attached-picture disposition when collecting movie
metadata. Ensure the existing positional probe fallback cannot select explicitly
typed attached artwork or audio as movie video. Preserve the established empty
probe defaults and serialized metadata shape. Keep actual audio and subtitle
stream discovery unchanged.

Use FFmpeg's non-attached-video selector in the movie command builder. The
existing video index argument then denotes an ordinal within that eligible set;
document and test that meaning. The active worker uses ordinal zero, so no new
index field, database schema, runtime state or control API is needed. Keep audio
mapping, filters, codecs, segment timing and delivery contracts unchanged.

Cover-only input must not be treated as a movie: its probe has no movie dimensions
or codec, and the required FFmpeg video mapping must fail instead of publishing
cover frames. This does not add a new user-facing validation API. Hardware,
thumbnail dispositions without attached-picture flags and explicit alternate-video
selection remain outside the reproduced repair.

## Verification

Add failing probe and command regressions before changing production code. Cover
art before the movie, artwork after the movie, audio before video, multiple
ordinary videos, explicit non-attached ordinal selection, attached-picture-only
input and ordinary sources without disposition metadata. Check serialization of
the selected movie metadata and preserve audio/subtitle identity.

Generate a tiny real movie with visually distinct attached artwork. Reorder only
the existing MP4 metadata children, preserving box lengths and media offsets, to
make the attachment precede the movie in the native probe. Assert that precondition
from FFprobe rather than assuming stream order. Run the real probe, production
filter builder and command builder, read the published presentation, join the
initialization and media fragments, and independently decode the output. Require
movie dimensions, frame rate, duration and multiple changing movie frames; a zero
process exit or nonempty manifest alone is insufficient. Check fresh output and
a nonzero source start so the attachment cannot substitute for the movie after
seeking. Verify ordinary cover-last ordering and cover-only refusal as well.

Retain commands, probes, publication files, decoded observations and failed/passing
receipts in the phase artifact directory. Use the CI image's FFmpeg 5.1.9 and PHP
8.5.2 for authoritative acceptance. Run affected probe, filter, command, native
media, publication and worker tests, then full PHPStan and Deptrac. These tests
qualify native probe-to-fragment behavior. Frontend, signed delivery and manifests
are unchanged; earlier browser receipts are not new acceptance for this phase.

## Workflow and record

Use the previously authorized equivalent workflow while the compound-engineering
skills remain unavailable. Obtain independent plan review before implementation.
The lead owns production changes, focused unit regressions, authoritative checks
and commits. A worker owns the native media regression and its temporary fixtures;
workers do not stage or commit and must preserve others' changes. Review
simplification and the final code before recording acceptance.

Record the repair, exact gate counts and limits in ROADMAP.md and update the
testing guide. Apply prose-fix to changed documentation. Existing application
services and the original videos remain untouched. Commit locally on the branch
named after this plan; no merge or push is included.

Independent review approved the plan before implementation. The deployed FFmpeg
selector excludes attached-picture flags specifically; the probe must use the
same boundary, and native assertions must distinguish movie motion from artwork.

## Delivered checkpoint

Source revision `767156b7` aligns probe metadata and the movie encoder on the first
video stream without the attached-picture disposition. The existing first-video
ordering among ordinary tracks and serialized metadata shape remain unchanged.
The positional probe fallback no longer treats explicitly typed audio or artwork
as movie video.

The corrected focused regressions failed against the original source: 48 tests,
628 assertions and 11 failures, including both cover-first native cases and
cover-only acceptance. After the repair, all 48 tests passed with 987 assertions.
The complete Transcode unit selection then passed 905 tests with 11,667 assertions
across 76 files: 888 ordinary cases with 11,604 assertions and 17 isolated native
cases with 63 assertions. The focused 48 cases are included in that total.

For both cover-first and cover-last inputs, independent fragment decoding produced
140 distinct movie frames over 14 seconds. Starting at source second six produced
80 distinct frames over eight seconds. Every output remained 64 by 64 at ten
frames per second. Cover-only input now exits with a failed required video mapping
and publishes no initialization or media fragments. These checks reject successful
empty publication, static artwork and an ignored seek.

Receipts and retained media are under `var/test-runs/movie-cover-art-gates/`.
The authoritative suite run is `backend/20261024-141127-968e97d5`, and
`native-evidence.json` records independently checked frame counts, durations and
output metadata. The CI image uses PHP 8.5.2 and FFmpeg 5.1.9-0+deb12u1.
Full PHPStan passed without errors. Deptrac reported zero violations, warnings
and errors, with 484 existing baseline violations skipped. Independent code and
simplification review found no further changes needed.

The testing guide and roadmap describe native source selection through fragment
publication. Browser lifecycles were not rerun; this phase does not establish new
browser playback acceptance, hardware qualification or CMAF conformance. Existing
application data and the user's original videos were untouched. The changes are
committed locally without a feature merge or push.
