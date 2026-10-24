---
title: Preserve sparse movie frames during publication and seeking
date: 2026-10-24
status: reviewed
execution: code
---

# Preserve sparse movie frames during publication and seeking

## Failure and selected consumer

The active movie worker preserves source cadence below 30 fps. A generated
0.1-fps movie therefore produces ten-second fragments. The production HLS renderer
rejects those fragments against its stable seven-second target. The Opus repair
checkpoint retained this concrete failure separately; it is the selected task.

Increasing the target on each playlist refresh would violate the stable EVENT
playlist contract. Source r_frame_rate and the native MPD's requested segment
length do not establish a future maximum frame gap for arbitrary variable-rate
input. This change retains six-second segmentation and the seven-second HLS
target rather than deriving a moving bound from already published fragments.

Bounded experiments in the CI image's FFmpeg 5.1.9 established the required timing
behavior. Converting the sparse movie to one fps preserves each held frame and the
final hold. Accurate input seeking discards the frame covering a seek between
source frames; padding from the next frame would display future content early.
Retaining input preroll and applying fps=1:start_time=0:round=up preserves the covering
frame. B-frame reordering at one fps shifts coded timestamps by two seconds, so
this path disables B-frames. Retained audio preroll requires atrim=start=0 before
existing audio filters. Audio timestamps must not be rebased to the first sample:
a track delayed eight seconds retains approximately two seconds of delay after
a six-second restart. No application data or original video was used.

## Implementation boundary

Carry the probed source frame rate through TranscodeSessionSubscriber,
TranscodeStreamManager and SegmentEncoder::buildStreamArgs using a sourceFrameRate
argument. Select the sparse path only for finite positive rates below one fps.
Unknown rates and rates at or above one retain their current command behavior.
The existing upper frame-rate cap remains intact. No generation metadata schema,
API, storage, subtitle or playlist target changes are required.

In the command builder, append fps=1:start_time=0:round=up to the existing video filters and
disable video B-frames for sparse input. On a nonzero sparse restart, retain fast
input seeking with -noaccurate_seek and prefix audio processing with atrim=start=0
when audio exists. Do not use output seeking that decodes the complete prefix,
do not reset positive audio timestamps and do not change six-second source seek
arithmetic. Preserve Opus/AAC encoder selection, MP4 packaging, signed composition
offsets, thread bounds and video-only mapping. Probe propagation applies to initial
launch, generation replacement and worker recovery through the existing loop.

Rounding upward prevents a fractional source timestamp from displaying its next
frame early. Output transitions and a nonintegral final hold are quantized to
one-second ticks; retain all intended source content and bound tail extension to
less than one output frame. Integer-duration fixtures retain their exact tail.
The [FFmpeg fps filter documentation](https://www.ffmpeg.org/ffmpeg-filters.html#fps)
defines the rounding and start-time behavior; the CI runtime remains authoritative.

This qualifies the reproduced sparse constant-rate sources. Reported nominal
rates do not identify every variable-rate gap; general VFR repair and physical
hardware qualification remain outside scope. Existing Source position and
Published presentation terms apply without new shared concepts.

## Native and browser evidence

Add native regressions using distinct red, green and blue source frames held
across long intervals. Cover fresh output and restarts at source second six,
including a restart within a held frame and near the final hold. Include a
fractional sparse cadence and a nonintegral source end to check the stated rounding
and tail bounds. Require actual
source cadence, complete independently decodable MP4 fragments, continuous native
timelines, zero-based video presentation timestamps and correct colour at each
local source time. Verify the complete intended tail, not merely successful exit
or frame counts. Cover software H.264 and HEVC, AAC audio and video-only output;
include delayed audio so a reset-to-first-sample defect cannot pass.

Build actual HLS playlists from the published native track durations and require
the same target throughout growing prefixes and completion. Independently decode
those playlists, requiring positive video frames and audio signal where present.
The unchanged encoder behavior must fail the new regression. Since its signature
lacks sourceFrameRate, the disposable comparison may add only that unused
parameter to the base encoder so failures measure media behavior rather than a
named-argument error; retain before/after commands,
probes, manifests and decode observations. Keep ordinary/fractional frame-rate
controls in the full existing Transcode selection.

Extend the existing disposable adaptive fixture with explicitly selected
sparse-fresh and sparse-seek scenarios. Generate a thirty-second 0.1-fps marked
movie with audio, use the real probe, production encoder and signed streaming
service, and pass the actual source rate through the new command argument. The
seek scenario encodes the tail from source second six and records that origin in
generation metadata and runtime doubles. Keep ordinary adaptive AAC/Opus scenarios
and all their assertions unchanged.

A separate browser assertion script uses the existing production HLS adapter and
fixture entry. Observe native displayed frames, canvas colour samples, source
clock and audio signal. Fresh playback must show red before second ten, green
before twenty and blue afterward; the restarted presentation must retain red
until local second four, then green until fourteen and blue through its tail.
Require fresh signed resources, stable seven-second media targets, one attachment,
no media/transport errors and natural completion at the expected local duration.
Do not force playback levels or weaken the ordinary adaptive checks. Retain
screenshots, samples, manifests, versions and cleanup receipts.

Validate scenario names before allocating resources. Run the new scenarios through
bounded isolated CI containers with the existing pinned browser runtime, separate
evidence directories and no access to application databases, Redis or original
videos. Preserve existing default runner behavior and add the sparse cases to CI.

## Workflow and verification

Use the previously authorized equivalent workflow while compound-engineering
skills remain unavailable. Obtain independent plan approval before implementation.
One worker owns the three production files and their focused argument/wiring tests;
one owns the new native media test; a third owns fixture changes and the sparse
browser assertion script. The lead owns shared native-lane registration, runner,
CI wiring, integration, documentation and commits. Workers preserve each other's
work and do not stage or commit.

Run focused native regressions against unchanged and repaired code, then the full
Transcode unit selection in the authoritative CI image. Run full PHPStan and
Deptrac, shell/browser syntax and workflow YAML checks. Run both sparse browser
scenarios and an existing adaptive control after final source changes. Serialize
expensive checks. Apply justified code/simplification review findings before final
acceptance. Record exact counts, source revisions, versions, native/browser
observations and limitations in ROADMAP.md and the testing guide through prose-fix.
Commit locally on the branch named after this plan; no merge or push is included.

The acceptance establishes sparse software movie publication, seeking and Chromium
HLS playback through the selected path. It does not certify arbitrary VFR, every
source container, hardware, other browsers, DASH browser playback, formal CMAF
conformance or the whole application lifecycle.

Independent plan review approved implementation after the rounding amendment.
Native terminal extension must be nonnegative and less than one second, allowing
only a small numerical epsilon; a symmetric tolerance must not permit truncation.
Audio retains its independent source interval and delay without padding or clock
rebasing. The integer-duration browser cases retain exact completion bounds.

## Review findings and fixture calibration

Independent code review required a genuine video-only restart in addition to the
half-fps fresh case. The native suite now includes both. Delayed audio uses an
indexed MOV/PCM source and verifies container/video origin zero and audio origin
eight seconds before checking the encoded result. The original sparse Matroska
fixture reports format/video origin eight seconds despite earlier video packets;
FFmpeg then subtracts that origin and shortens the output. Its failing evidence
remains under `var/test-runs/sparse-movie-gates/native-green` as a separate open
container-origin finding. Changing the fixture establishes the intended source
clock; it does not repair that Matroska behavior.

A terminal AAC fragment can contain a single silent padding packet. The native
assertion permits silence only in the final fragment with one packet and duration
at most one 1,024-sample AAC frame plus a small numerical tolerance. Independent
decoding must still produce samples, and complete-playlist signal, audio end and
duration checks remain strict. Explicit source-origin metadata is required for
the delayed MOV fixture; silent Matroska sources are checked through their actual
packet timestamps rather than absent optional metadata.

The unchanged encoder comparison adds only an unused sourceFrameRate parameter
to the disposable base copy. Its final run reports 48 tests, 560 assertions,
seven errors and 14 failures. The repaired encoder passes the same selection
with 48 tests and 1,604 assertions, including all 12 native media cases. Artifacts
are `native-red-verified` and `native-green-verified` under the phase evidence
directory. Earlier diagnostic runs remain available.

## Growing-publication amendment

The first Chromium run at c5ece38a found that both sparse renditions became ready
only after encoding completed. The CI FFmpeg 5 calibration confirms that x264's
40-frame lookahead retained the entire thirty-frame output until EOF. Completed
media checks alone cannot establish progressive publication.

Extend the sparse software path with buffering settings selected through isolated
CI-runtime comparisons. Preserve the existing command for unknown rates and rates
at or above one fps. Scope encoder-specific settings to their software encoders;
preserve existing thread limits, timing filters and source-coordinate arithmetic.
Change input decoder buffering only if a separate controlled comparison shows it
is required. Record effective encoder settings and native publication times.

Add paced native cases for H.264 and HEVC. Poll the production presentation reader
while the real encoder runs: require a readable closed segment in an incomplete
presentation, followed by a larger published edge before EOF. Retain native index
snapshots and observations. Bound each run without promising a fragile subsecond
startup time. Keep all completed-media regressions and both browser readiness
assertions intact. Rerun the full Transcode selection, static gates, both sparse
browser scenarios and an ordinary adaptive control after the final change.

Independent review approved this bounded extension. The exact settings remain
subject to CI-runtime calibration and focused review before production edits.

CI calibration and independent review approved sparse input decoding with one
thread, libx264 output rc-lookahead zero and libx265 rc-lookahead zero in its
existing parameter list. Ordinary input decoding remains at two threads; x265's
pool and frame-thread bounds remain unchanged. Each controlled repair published
six seconds of video at about 11.5 seconds and twelve seconds at about 21.2,
before completion at about 31.3. The baseline waited until the final flush.

The paced tests require their second advancing edge before elapsed second 29
of a thirty-second real-time input. This conservative margin excludes an EOF
flush that briefly leaves the process running and the MPD dynamic; it is not a
subsecond startup guarantee. Commands, effective encoder logs and observed edges
are retained under the phase calibration directory.

## Browser observer correction

The first run after the buffering repair attached during publication, then rejected
a frame callback with video PTS 13 while the audio-backed native clock remained
at 12.010666 during recovery from a buffer pause. Both positions correctly showed
the green held frame. The [frame callback definition](https://wicg.github.io/video-rvfc/#dom-videoframecallbackmetadata-mediatime)
and [Chromium clock explanation](https://web.dev/articles/requestvideoframecallback-rvfc#using_the_method)
distinguish submitted-frame PTS from currentTime; exact equality is not the API
contract.

Independent review approved a bounded observer correction. Check canvas colour
against both source positions, permitting adjacent colours only within 50 ms of
a transition. Bound video PTS lead to one normalized frame plus 50 ms and retain
the existing reverse bound. Retain callback presentation metadata, native clock
progress, full held-frame coverage, recent audio, signed delivery and natural end
checks. This changes the test observer; it does not change playback or encoders.
The rejected run remains under `browser-sparse-fresh-final` in phase evidence.
