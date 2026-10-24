---
title: Preserve movie playback after source audio ends
date: 2026-10-24
status: reviewed
execution: code
---

# Preserve movie playback after source audio ends

## Demonstrated failure

The current movie path cannot play or seek reliably through a silent video tail.
At `c86bfd71`, a generated fourteen-second movie with four seconds of audio drives
the actual stream manager and encoder in the CI image. Fresh encoding succeeds,
but the resulting audio ends at about four seconds and the publication rejects
coverage at second twelve. A generation starting at second twelve encodes the
remaining two seconds of video without any audio fragments. The presentation
reader returns no playable presentation and the stream manager reports failure
despite FFmpeg exiting successfully. PCM and AAC sources reproduce the failure.
Evidence is retained under `var/test-runs/short-audio-tail-audit/`.

This is an active web movie workflow. The worker requires published video and
selected audio at the requested source position before acknowledging readiness;
the streaming service uses the same coverage rule. Existing tests allow a small
codec tail discrepancy, but do not cover a soundtrack ending seconds before its
video. Keep the coverage checks: a manifest claiming missing audio is not a repair.

## Intended behavior and boundaries

After the selected source audio ends, movie output must contain actual silence
through the remaining video. Initial playback, sought generations and recovery
must retain the complete video, its source coordinates and existing leading audio
delay. A restart after the final source audio packet must still publish valid
audio initialization and media fragments. The encoding process must finish; it
must not pad indefinitely or extend the movie beyond its video tail.

Keep Source audio selection, source video selection, Source timestamp origin,
canonical audio ownership, signed delivery and generation fencing unchanged.
Video-only input must stay video-only and omit audio padding and `-shortest`.
Subtitle helpers remain separate and must
not determine encoding duration. No API, schema, source-track control or frontend
production behavior is added.

The repair appends FFmpeg's `apad` after existing audio normalization
and uses output `-shortest`, making the video the finite output stream. The
[FFmpeg filter documentation](https://ffmpeg.org/ffmpeg-filters.html#apad) describes
this combination. Documentation alone does not qualify the installed FFmpeg:
before implementation, the CI calibration must establish complete video frames,
bounded audio tails and finite termination for fresh and empty-audio seek output.
The disposable CI calibration at `c86bfd71` confirms finite completion for AAC
and Opus output, fresh and starting at second twelve. Video remains exactly
fourteen/two seconds with 350/50 decoded frames; independently decoded audio
extends by less than 38 milliseconds for codec padding. The fresh audible region
is preserved, its tail is silent and sought audio is entirely silent. Both tracks
cover the final cursor. These command variants are diagnostic evidence, not a
production change. Broader regressions below must still qualify the repair.

## Work and verification

Continue the previously authorized equivalent reviewed-plan workflow while the
compound-engineering skills remain unavailable. Obtain independent plan review
before implementation. Work on the branch named after this plan, land local unit
commits, perform independent simplification/code review and update `ROADMAP.md`
with exact evidence. Merge and push remain separate requests. Apply prose-fix to
documentation. Preserve existing services and user media; serialize expensive
native, browser and static checks in disposable containers.

One worker owns the encoder change and command tests, plus a focused worker
regression establishing readiness at a cursor in the silent tail. A second owns
native media tests through the real probe, stream manager, encoder and publication
reader. A third owns the disposable browser fixture and observer. The lead owns
runner/CI registration, integration, authoritative gates and commits. Workers do
not stage or commit, and edits must stay within their assigned files.

Native regression must independently decode video fragments and HLS audio. Use
distinguishable video content and an audible tone before the source audio ends,
then require measured silence through the full tail. Cover AAC and Opus output,
fresh playback and seeks with no remaining source audio packets. Check real source
packet bounds, selected mapping, native publication coverage, decoded frame count,
audio sample duration and bounded process termination. Include combined leading
delay and trailing silence. Include a selected nonzero audio ordinal that ends
early while an unselected track continues, and controls whose source audio spans
or exceeds the video. Padding must follow the selected source, and longer audio
must not truncate video or prevent finite termination. These producer controls do
not change how source duration is inferred for audio longer than video.
Do not relax existing coverage, duration, timing or decode assertions.

Add explicit short-audio fresh and seek browser scenarios using the existing
thirty-second, 25 fps colour source. Audio ends early enough that the six-second
restart has no remaining source audio. Fresh playback must contain the tone before
the boundary, then measured silence; sought playback must remain silent throughout.
Fixture metadata must distinguish the selected audio's delay/end from video
duration. Existing audible-per-hold checks stay unchanged for other scenarios.
Both must reach their complete thirty-/24-second tails with the expected decoded
frames and native colours. Retain signed fresh delivery, growing publication,
stable targets, one attachment and bounded capture cleanup. Run the unchanged
producer against the new observer and retain its actual failure before repair.

Run focused command/worker/media regressions, the full Transcode selection in the
CI image, full PHPStan and Deptrac, syntax/configuration and runner preflight.
Retain paced publication coverage to detect buffering or readiness regressions.
Run both new browser cases plus the selected-default-audio, ordinary delayed-audio,
Matroska subtitle and adaptive-switching controls affected by shared encoding.
Record failures separately from final acceptance. Close the plan only when code
review is resolved and the required gates pass.

Acceptance covers the selected software movie encoder path, native AAC/Opus and
Chromium HLS playback. It does not qualify arbitrary timestamp discontinuities,
additional source origins, hardware, other browsers, DASH browser playback or
formal CMAF conformance.

Independent plan review approved this scope before implementation, including
selected-track padding, longer-audio and video-only controls, measured tail silence,
actual publication readiness and bounded process completion.
