---
title: Respect source default audio in movie playback
date: 2026-10-24
status: reviewed
execution: code
---

# Respect source default audio in movie playback

## Selected failure

Master `aa2777d4` always maps the first source audio stream for movie playback.
If commentary precedes dialogue marked default, the commentary plays. The probe
also takes filter attributes from that first stream, and both HLS and DASH
language metadata describe it. The source's default disposition is already
retained in `AudioStreamInfo`, but these consumers do not use it.

The roadmap retains host FFmpeg calibration and source inspection under
`var/test-runs/ordinary-movie-audio-delay-gates/default-audio-follow-up/`.
Before implementation, reproduce the mismatch in the CI image through the actual
stream manager and encoder, retaining the source probe, command, published
presentation and decoded HLS tone. This is an existing web movie consumer; the
change does not introduce interactive source-track selection.

The CI reproduction at `aa2777d4` confirms the failure through the production
manager and encoder. Commentary is audio ordinal zero/global index one,
220 Hz, mono/44.1 kHz/English; default dialogue is ordinal one/global index two,
880 Hz, 5.1(side)/48 kHz/Danish. Fresh and seek-six commands both map `0:a:0`,
retain commentary filter attributes and encode approximately 220 Hz into complete
fourteen-/eight-second HLS audio. The native MPD labels it English. Receipts under
`var/test-runs/source-default-audio-audit/` retain the CI image, commands, probes,
decoded media and cleanup. This proves the manager/encoder path; subscriber
recovery and browser delivery remain acceptance work below.

## Selection and boundaries

Use one deterministic source audio selection rule: choose the first audio stream
marked default in discovery order; if none is marked, choose the first audio
stream. No audio means no selection or audio map. Keep the discovered stream list
in its original order, including all nonselected tracks. Multiple defaults choose
the first marked stream. Do not sort or mutate the source list to force index zero.

`VideoProbeResult` owns derived `selectedAudioStreamOrdinal()` and
`selectedAudioStream()` accessors.
The ordinal is the position among audio streams, matching FFmpeg `0:a:N`, not the
global FFprobe stream index. Do not persist an independent selection field. Raw
probe and serialized reconstruction must agree on selection and selected codec,
channels, sample rate and channel layout. Existing public scalar attributes must
describe that selection so downmix, dialogue enhancement and resampling use the
same source that FFmpeg maps.
Direct construction with a full source list must enforce the same scalar
consistency. Empty source lists must not acquire a selection from stale scalar
metadata. Audit existing constructors rather than adding a compatibility path.

Pass the selected ordinal from the session subscriber through the stream manager
to the encoder's existing mapping argument. Initial playback, seek replacement
and recovered execution share the same launch rule. Derive HLS and DASH language
from the same selection in retained probe data. Preserve the primary job's single
canonical published audio track, output audio profile, signed URLs, execution
generation and ownership checks. No endpoint, schema or persistence migration is
needed. Add source audio selection to the shared vocabulary in `CONCEPTS.md`.

Keep source timestamp origin qualification based on the selected video stream.
The chosen audio stream retains its own native timestamps and receives the
existing leading-silence normalization. A different audio default must not change
the movie's common origin or source-relative duration, or borrow the first audio
track's delay. Preserve video-only handling and source video/artwork selection.
The audio-only transcoder and interactive alternate-track controls are outside
this change.

## Implementation and verification

Continue the authorized equivalent reviewed-plan workflow while the
compound-engineering skills remain unavailable. Obtain independent plan review
before implementation; work on the branch named after this plan and commit units
locally. Workers own non-overlapping files and do not stage or commit. The lead
owns integration, shared runner/CI registration, authoritative gates and commits.
Serialize native media and browser runs; leave user media and existing services
untouched.

One worker owns production selection and propagation plus focused unit tests.
A second owns native media regressions through the real probe, stream manager,
encoder, native presentation reader and HLS renderer. A third owns the disposable
browser fixture and observer. Agree the selected ordinal/info accessors before
parallel edits. Keep production frontend code unchanged.

Unit regressions must cover a later default, no defaults, multiple defaults,
video-only input and raw/serialized parity with distinct source codecs, channels,
layouts and sample rates. Verify stale first-track scalar values cannot survive
reconstruction when the full source list selects another track. Preserve that
list's order and confirm audio ordinals differ from global stream indices.
Worker execution tests must capture selected mapping and filter attributes for
initial, seek and recovered launches. Manifest tests must prove selected language
for both HLS and DASH without changing canonical audio or video-only behavior.

Native tests use generated movie video with distinguishable source audio tracks:
220 Hz commentary first, mono/44.1 kHz/English, and 880 Hz dialogue second,
48 kHz/surround/Danish and marked default. Independently decoded fragments and HLS
PCM must contain the selected tone, retain complete fresh and seek-six tails, and
use the selected track's filter attributes. Include no-default and multiple-default
controls. Cover AAC and Opus output without adding an unrelated profile matrix.
With commentary at zero and default dialogue delayed eight seconds, require
actual silence followed by 880 Hz at local eight/two seconds. Reverse the delays
to ensure commentary timing cannot delay selected dialogue. Existing video-only,
sparse, delayed-audio and source-origin tests remain required.

Add explicit default-audio fresh/seek browser scenarios using the existing
thirty-second 25 fps colour source, two distinguishable audio tracks and no
subtitles. The marked default dialogue begins at source second eight; commentary
begins at zero. Fixture preflight must verify dispositions, audio ordinal versus
global stream index, selected metadata and the selected track's first packet.
Use the shared selector when constructing fixture commands. The production
streaming service must advertise Danish, and the actual browser analyser must
measure the dialogue tone, not merely positive audio energy. Require
dominant-frequency measurements with explicit tolerance
at several post-onset and late-hold windows. Preserve complete
thirty-/24-second playback, eight-/two-second silence, native frame/colour clocks,
signed fresh delivery, growing publication, stable targets, one attachment and
bounded capture cleanup. Keep existing subtitle and adaptive assertions intact.

Retain the actual prior-code manager/encoder failure and repaired native receipts.
For the browser negative control, restore only the prior producer's ordinal-zero
mapping and first-track filter chain in a disposable fixture copy. Keep source
preflight, current metadata and browser assertions unchanged; retain the exact
override and commands. This verifies the browser oracle against the demonstrated
old producer behavior, not against an entire old application stack. Do not add a
permanent compatibility method or testing switch. Independent review approved
this distinction because the old probe API cannot run the new fixture unchanged.
Run focused
selection/worker/manifest tests, then the full Transcode selection in the CI image,
full PHPStan and Deptrac, both new browser scenarios, an ordinary delayed-audio
control and the existing Matroska subtitle control. Run the ordinary adaptive
control if its shared observer or fixture behavior changes. Run relevant syntax,
configuration and runner-preflight checks. Complete independent code and
simplification review, apply justified findings, and record exact revisions,
counts, tone/onset observations, cleanup and limits in `ROADMAP.md` and the testing
guide through prose-fix. Close the plan only when these gates pass. Merge and push
are separate requests.

Acceptance covers the selected software movie path, native AAC/Opus output,
manifest language and Chromium HLS playback. It does not qualify interactive
audio switching, arbitrary discontinuities, additional source-origin handling,
hardware, other browsers, DASH browser playback or formal CMAF conformance.

Independent plan review approved the scope and shared accessors before
implementation, including constructor consistency, absent selection for no audio
and measured browser tone identity across the audible interval.
