---
title: Exclude attached cover art from movie video selection
date: 2026-10-24
status: reviewed
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
