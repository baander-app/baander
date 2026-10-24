---
title: Preserve movie playback after source audio ends
date: 2026-10-24
status: implemented
execution: code
---

# Preserve movie playback after source audio ends

## Demonstrated failure

The current movie path cannot acknowledge seeks reliably through a silent video tail.
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
must not pad indefinitely or extend the movie beyond its video tail when selected
source audio ends no later than video. A longer soundtrack's duration contract is
a separate demonstrated gap, recorded below.

Keep Source audio selection, source video selection, Source timestamp origin,
canonical audio ownership, signed delivery and generation fencing unchanged.
Video-only input must stay video-only and omit audio padding and `-shortest`.
Subtitle helpers remain separate and must
not determine encoding duration. No API, schema, source-track control or frontend
production behavior is added.

The repair gives audio its own input within the existing FFmpeg process, appends
`apad=packet_size=1024` after existing audio normalization and uses output
`-shortest`. The video remains finite while selected audio can generate silence
independently. The
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
early while an unselected track continues, and a matched-duration control. Padding
must follow the selected source. A paired prior/candidate experiment separately
checks that a longer soundtrack introduces no regression; it does not qualify
that source's already-failing duration contract.
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

## Reviewed boundary amendment

The first twelve-case native run exposed two boundary problems. With default
padding packets, a matched fourteen-second AAC soundtrack reached 14.08 seconds.
Using 1,024-sample padding packets reduced the native end to 14.016 seconds and
decoded duration to 14.037333 seconds. This preserves the existing strict bounds;
the matched-duration regression remains required.

With fourteen seconds of video and eighteen seconds of audio, FFmpeg 5.1's
`-shortest` still publishes eighteen seconds of audio. Disabling B-frames did not
change that result. An explicit fourteen-second output limit works, but the probe
currently records the eighteen-second container duration. A reliable selected-video
endpoint requires its own probe, serialization, coordinate and consumer review;
passing container duration as a video limit would not repair this case.

Paired unchanged `c86bfd71` and initial shared-input candidate commands confirm
this longer-audio gap predates the repair. Both AAC and Opus preserve all 350 video
frames with identical decoded RGB and retain the source tone through second 17.5.
Both reject exact video
end coverage. AAC decoded duration is unchanged; Opus gains one twenty-millisecond
packet. All processes finish in under half a second. These receipts remain under
`var/test-runs/short-audio-tail-gates/paired-long-audio/`, alongside the failed native
run and calibration. Longer-audio acceptance is removed from this phase's native
suite and retained as a separate demonstrated roadmap follow-up. No short-audio,
matched-audio, silence, frame, duration or coverage threshold is relaxed.

The prior-encoder fresh browser control passed complete thirty-second playback
with 750 decoded frames. Chromium can continue silently after audio EOF. This is
not failure evidence; native readiness and the exhausted-audio seek are the repair
targets. Retain the fresh control and run the prior-encoder seek separately.

## Progressive publication amendment

The first implementation passes complete native decoding but fails the unchanged
browser startup requirement for a seek after audio EOF. Both renditions must first
be jointly playable while their publications are still growing. With one shared
input, audio EOF reaches the padding filter only when the video input finishes.
The lower rendition is already complete when valid audio appears. Preserve this
failure under `browser-final-short-audio-seek`; do not relax its startup assertion.

A paced thirty-second source with four seconds of audio and a six-second restart
confirms the mechanism. The shared input first publishes video at 17.994 seconds
and audio at 24.091 seconds; the first readable joint presentation is already
complete. With separate video and audio inputs in one process, dynamic joint
prefixes appear at 8.085, 14.075 and 20.063 seconds, before completion at 24.188
seconds. Both variants retain all 600 video frames and entirely silent audio
lasting 24.021333 seconds. CPU time changes from 0.19 to 0.24 seconds, peak RSS
from 69.54 to 70.76 MB, and logical read bytes from 274,169 to 357,309 in this
bounded fixture. These measurements establish the fixture's behavior, not a
whole-host or arbitrary-media resource guarantee.

Keep input zero for video, with its existing decoder/hardware options and source
selection. Add input one for the same source path, with explicit bounded decoder
threads and video/subtitle/data decoding disabled. Map the selected audio ordinal
from input one. Apply the same source seek and verified-origin translation to both
inputs. Both readers preserve the established sparse preroll and early-seek guard;
audio retains the existing trim/leading-silence filter sequence. Ordinary input
seeking remains accurate. Hardware video options and
fixture input pacing belong only to the video input. Video-only commands retain
one input. No second encoder process, execution owner, muxer or publication fence
is introduced; cancellation still terminates the one process and both reads.

Independent audio input can read ahead and performs additional source I/O. Keep
decoder threads explicit and verify bounded buffering during paced publication.
Add native regressions that observe two increasing, independently decodable joint
prefixes while the presentation is dynamic and the producer remains active.
Decode the initial audio prefix as actual silence, retain complete final video
and audio tails, and record publication times, audio/video lead and process memory.
Check cancellation of the same producer. Existing native default-track,
delay/origin, video-only and paced sparse tests must pass with the new input maps;
update only command identity expectations, not their media or timing criteria.

Re-run the full affected backend selection, PHPStan, Deptrac and all seven browser
scenarios after this architectural change. Earlier backend/static passes and the
fresh browser pass remain evidence for their recorded revisions, not acceptance
of the revised producer. Review this amendment before implementation.

Clock qualification corrected the initial proposal that audio should always seek
accurately. The combined native run passed the new progressive/cancellation and
default-track cases but failed twelve existing anchored Matroska seek cases
(45 tests, 5,844 assertions). FFmpeg's automatic accurate-seek trim operates before
the user filters and conflicts with the explicit source-relative timestamp shift.
Disabling that trim restores the intended tone; resetting timestamps or changing
the expected onset would conceal the error. A 720p audio-first source also confirms
that the independent audio input needs the existing early sparse seek guard to
avoid losing packets before the first nominal video-frame boundary.

The correction applies the same input timing policy to both readers. If the video
input omits an early anchored sparse seek, the audio input also starts at the
beginning with the common negative offset. When a sparse input does seek, both
retain preroll with `-noaccurate_seek`, and audio's existing `atrim=start=0` removes
only negative local samples. Eight diagnostic controls verify origins zero/four,
six-/24-second starts, AAC/Opus, matched audio and the audio-first source, with the
expected two-second/near-zero onsets and complete tails. Preserve these receipts
and rerun the unchanged native timing assertions before acceptance.

The final dual-input longer-audio audit distinguishes producer behavior from
source-duration inference. Video ends at fourteen seconds with all 350 frames;
AAC ends at 14.016 seconds and Opus at fourteen, both retaining the source tone.
Coverage at the video endpoint now passes. The probe still reports the eighteen-
second container duration, and coverage at that declared endpoint fails. The
remaining roadmap task is therefore the selected-video endpoint and its consumer
clock contract. Evidence is retained under
`var/test-runs/short-audio-tail-gates/final-dual-input-long-audio/`.

## Browser transport observation amendment

The final default-audio fresh control stopped at source second 24.13 because the
observer treated a normal rendition replacement as a transport failure. The
canceled 720p fragment has a successful, uncached, signed 360p replacement for the
same 24–30 second interval. The replacement begins 12.668 milliseconds later and
completes with HTTP 200; presented frames change to 360p at timestamp 24 without a
clock reset, media error or new attachment. This receipt proves the replacement,
not the unobserved remainder of playback. Retain it and its transport analysis.

Apply the existing adaptive test's bounded cancellation policy to this observer.
Only explicitly canceled video media requests with `net::ERR_ABORTED` may wait for
final classification. All other network, init, audio, playlist, HTTP and blocked
requests remain immediate failures. At natural completion, every deferred request
must have one clear completed, uncached, signed replacement from a different known
rendition in the same authenticated set and generation, covering the same native
source interval within the bounded overlap/timing allowance. Retain both server
receipts and transfer timestamps. Reject unmatched, ambiguous, stale, cached,
wrong-interval, same-rendition or merely started replacements.

Use a small pure helper with focused Node tests for valid replacement and each
invalid category, including timing boundaries. The existing runner must copy that
exact helper and CI must run its tests. Preserve all playback, frame, colour,
clock, audio, source identity and full-tail assertions and the final deadline.
Do not change production playback or encoding, lock quality, or ignore aborts
globally. Complete a new default-audio fresh browser run after the observer change.
The six passing controls on the same production revision retain their stronger
no-abort evidence; the existing adaptive control already checks valid replacements.

Independent review approved this observation change before implementation.

## Completion checkpoint and remaining limits

The final producer at `0e63a4e2` passes 45 focused native tests with 6,495
assertions, including fourteen short-audio cases and the existing default-track
and source-origin controls. Paced AAC/Opus output publishes two advancing,
independently decoded joint prefixes while encoding remains active. Both retain
all 600 sought video frames and silent audio through the 24-second tail.
Cancellation stops and reaps the same process. The full affected backend selection
passes 1,112 tests with 29,554 assertions across 84 files: ordinary 1,015/12,619
and native 97/16,935. Full PHPStan passes; Deptrac reports zero violations,
warnings and errors with 484 existing baseline skips.

The observer-only revision `992b6c24` passes all 55 replacement-evidence Node
tests and independent code review. JavaScript syntax, runner shell syntax and CI
YAML checks pass. Its production and PHP test trees are unchanged from
`0e63a4e2`. The helper admits only a fully validated replacement for the exact
abandoned server receipt; the final browser run contains no canceled request and
therefore does not itself exercise that exception.

Seven required browser scenarios have passing receipts and verified
cleanup. Short-audio fresh, short-audio seek, default-audio seek, ordinary delayed
fresh, Matroska delayed/subtitle fresh and adaptive switching pass at
`0e63a4e2`. Default-audio fresh passes at `992b6c24` in 39.357 seconds, reaching
30.015999 seconds with all 750 decoded frames, correct selected audio and 742
closed captures. Short-audio fresh and seek retain their complete thirty-/24-second
tails with 750/600 frames and measured silence through the final cursor.

An earlier default-audio fresh attempt on the same observer revision failed when
snapshot sequence 607 carried timestamp 24.64 while its callback reported 24.60.
All 608 constructed captures closed; there were no reported transport or media
errors, seeks or attachment resets. Separate callback and snapshot sampling makes
a capture race plausible, but the failing sample discarded timing needed to prove
that attribution. Exactly one unchanged diagnostic rerun produced the passing
receipt above. No timing assertion was relaxed and no capture-race repair is
claimed. Retain the failed receipt and analysis under
`browser-final-v3-default-audio-fresh`; a bounded observer follow-up must preserve
failing callback/construction timing before changing its measurement contract.

`var/test-runs/short-audio-tail-gates/acceptance-summary.json` records the final
revision-specific evidence, retained failed attempts and unresolved limits.
`ROADMAP.md` records this delivery and selects the demonstrated selected-video
endpoint/consumer-duration problem next: fourteen-second output covers its actual
end, while the probe/DTO still declares the longer eighteen-second container
duration. Acceptance remains limited to the paths stated above. Changes are
committed locally; merge and push remain separate requests.
