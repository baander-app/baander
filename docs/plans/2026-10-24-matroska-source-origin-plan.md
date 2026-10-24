---
title: Preserve the verified source origin of sparse Matroska movies
date: 2026-10-24
status: implemented
execution: code
---

# Preserve the verified source origin of sparse Matroska movies

## Selected failure

At the start of this plan, master 404729b6 truncates the retained sparse Matroska
movie. Its three H.264 frames have PTS 0, 10 and 20 with ten-second holds, while
audio begins at eight seconds. FFmpeg reports format and video origins of eight seconds.
Implicit timestamp subtraction produces a 22-second fresh presentation, changes
red to green after two seconds and removes the intended audio delay. Restarting
at source second six produces sixteen seconds beginning on the wrong frame.

CI FFmpeg 5.1.9 confirms that larger analysis limits do not repair this behavior.
Preserving timestamps and explicitly shifting the common source origin repairs
fresh output; absolute timestamp seeking is also required for a restart. For
verified origin O and requested source position S, use copyts, seek_timestamp 1,
input seek O+S on restarts, and input timestamp offset -(O+S). Retain sparse
preroll, fps rounding, B-frame and buffering settings, and audio trimming.

The same repair works for actual video origin four with audio beginning at
twelve. Matroska format and video duration report 34, so the source-relative
duration must become 34 minus four. A matched positive-origin control, with both
audio and video beginning at four, also reports duration 34 although encoding
already produces thirty seconds. Restricting correction to mismatched origins
would leave its demonstrated duration error.

Embedded cues at O+2 and O+12 expose the same clock problem. Current extraction
produces cues at zero and ten; the existing six-second rebase leaves the second
cue at four instead of six. Extraction with copyts and input offset -O preserves
source-relative cues at two and twelve. Existing WebVttTimeline rebasing then
removes the first cue and places the second at six to eight. Do not subtract the
seek position in extraction as well as rebasing.

Calibration commands, sources, native outputs and cue files are retained under
`var/test-runs/matroska-origin-gates/`. These are synthetic disposable sources;
application databases, Redis and the user's original media are outside the work.

## Bounded origin evidence

Use one shared video-probe argument builder for the synchronous adapter and the
native process pool. Preserve format and all stream metadata, and additionally
read at most 128 packets with stream index, PTS and flags. The verified
FFprobe options are show_packets, read_intervals %+#128 and show_entries
packet=stream_index,pts_time,flags. The bound counts all streams, so a prefix
without the selected movie packet supplies no origin evidence. Retain existing
process deadlines and native execution ownership and cleanup.

Resolve the same first non-attached movie stream used by encoding. Consider only
its first observed packet, never the first packet of another stream or a minimum
over arbitrarily reordered packets. Eligibility requires Matroska format, H.264,
an explicitly reported zero B-frame count, finite positive frame rate below one,
a key packet without a discard flag, finite nonnegative PTS O, finite reported
format start at or after O, and finite format duration greater than O. Apply the
explicit anchor when the reported start is later than O or O is positive.
Matched zero origins retain their existing path. Missing or malformed evidence,
negative origins, B-frame input and other formats remain outside qualification;
do not infer an anchor from DTS or the reported stream origin.

Carry a nullable Source timestamp origin alongside the normalized duration in
VideoProbeResult and its serialized representation. Normalize duration once,
only for the qualified Matroska path. Propagate the anchor through initial
launch, seek replacement and recovery to media encoding and subtitle extraction.
Generation startTime remains the source-relative requested position; it must not
include O. Existing callers with no verified anchor retain their command behavior.
Add the new term to CONCEPTS.md and audit all affected probe consumers.

The Matroska duration subtraction is a measured container-specific rule, not a
general interpretation of FFprobe duration. A remux intended to create a negative
origin instead produced a missing first PTS; it does not establish support for
negative origins. Preserve this evidence limit.

## Implementation and verification

Continue the previously authorized equivalent workflow while compound-engineering
skills remain unavailable. Obtain independent plan approval before implementation.
One worker owns production probing, the value type, command propagation and their
focused unit tests. A second owns native media and embedded-subtitle regressions.
A third owns the browser fixture and assertion extension. The lead owns shared
runner, CI, native-lane registration, concepts, documentation, integration and
commits. Agree on the nullable anchor and fixture interfaces before editing;
workers preserve other edits and do not stage or commit.

Native tests must use the real shared probe command, production encoder and
publication reader. Verify actual source packet PTS and endpoints before testing
zero and positive origins, delayed and matched audio, fresh output, six-second
restart and a restart within the final hold. Cover software H.264 and HEVC, full
colour holds, independent fragment/HLS decoding, local timestamps, audio delay,
complete duration and unchanged stable HLS targets. Exercise actual production
subtitle extraction and WebVttTimeline rebasing with embedded cues before and
after the requested seek, and verify generation-scoped signed delivery.

Unit controls cover attached pictures, first-packet selection, missing prefix
evidence, malformed and nonfinite metadata, unsupported inputs, serialization and
unchanged commands without an anchor. Worker tests cover propagation and correct
source-relative generation metadata across initial execution, replacement and
recovery. Tests must catch double subtraction of origin or seek position.

Extend the existing disposable browser runner with explicit delayed-Matroska
fresh and seek scenarios using the production probe and encoder. Require correct
held colours, audio after its intended delay, source-relative duration, natural
completion, signed uncached resources, stable targets and one attachment. Preserve
the existing sparse and ordinary assertions. Verify signed subtitle delivery and
cue timing through the production path; do not add a parallel subtitle backend.

Show the new regressions failing against unchanged behavior and passing after
repair. Then run the full Transcode selection in the CI image, full PHPStan and
Deptrac, relevant syntax/configuration checks, both new browser scenarios and an
existing ordinary control. Serialize expensive checks. Complete independent code
and simplification review, apply justified findings, and record exact revisions,
counts, timings, cleanup and limits in ROADMAP.md and the testing guide through
prose-fix. Commit locally on this plan branch; merge and push are separate actions.

Acceptance covers the verified sparse Matroska source contract and selected
software/Chromium path. It does not certify arbitrary Matroska files, missing
packet evidence, negative origins, B-frame sources, general VFR, hardware, other
browsers, DASH browser playback or formal CMAF conformance.

Independent plan review approved implementation. Browser delay checks must permit
initial silence for eight seconds on fresh playback and two seconds after a
six-second restart. Observe near-silent samples before onset where that interval
is played, then positive audio after onset and through later holds. Do not infer
onset from declared profile metadata or require sound during every displayed
frame. Existing scenarios retain their assertions. The new scenarios use a
verified positive origin of four seconds to exercise duration normalization and
source-relative playback together; native cases also cover origin zero.

## Measured packet-prefix amendment

The unchanged 720p browser source places its first movie packet at position 95,
with PTS four, after audio packets. The initial sixteen-packet probe correctly
supplied no movie-origin evidence. Repeating source creation reproduces that
layout. Retain this source and extend the bounded probe to 128 packets rather
than changing source encoding to move the video packet earlier.

CI FFmpeg 5 measures 6,755 output bytes and 0.073 seconds for sixteen packets,
and 19,706 bytes and 0.068 seconds for 128. The first sixteen packet objects are
identical; format and stream metadata differ only in packet-read counters.
The larger result remains within the existing one-MiB output limit and sixty-second
probe deadline. Neither execution limit changes. Preserve the failed browser
receipt and the paired measurements under `probe128-calibration`.

Independent review approved this measured expansion. Update the shared command,
origin resolver and boundary tests together. Native fresh and seek cases must
retain the actual audio-first layout, prove its first movie packet lies after
sixteen and within 128 packets, and verify corrected media and subtitle clocks.
Unit controls cover a movie packet at position 128, no movie packet in the prefix,
and rejection of a 129-packet input. A prefix without a verified movie packet
still supplies no anchor; this amendment does not establish unbounded probing or
arbitrary-source qualification.

## Audio preceding the first movie packet

The expanded audio-first regression reveals a separate demux seek constraint.
The video keyframe at timestamp four appears at packet 95, after audio packets
with timestamps twelve through about fourteen. Seeking to absolute timestamp ten
selects that video keyframe but skips the physically preceding audio. The video
correctly retains 24 seconds after a six-second source seek, while audio begins
at 3.962 seconds instead of approximately two.

A controlled CI run removes only input seeking and keeps timestamp preservation,
common offset minus ten, video filtering and audio trimming. Reading from the file
beginning restores audio onset 1.978 seconds and 22.037333 seconds of decoded PCM,
with the same video colours, local timestamps and full tail. Fast seeks at source
seconds twelve and 24 preserve all required audio on this exact source, producing
18- and six-second tails respectively.

For a verified anchor and a sparse restart within the first nominal frame interval
(S less than 1 divided by source frame rate), read from the file beginning instead
of issuing input ss/noaccurate_seek. Keep the logical source position S, common
offset -(O+S) and audio trim unchanged. Retain fast input seeking for later
positions and all existing behavior without an anchor. The nominal interval is
not proof of arbitrary VFR timing; qualification remains the reproduced sparse
constant-rate sources.

Add the actual audio-first final-hold native case alongside fresh and six-second
restart cases. Unit checks cover the nominal interval threshold, a fractional
rate, zero and later source positions, and no-anchor command identity. Retain the
failed native receipt and paired seek controls; do not relax audio onset or end
assertions to accept missing content.

Independent review approved this measured first-interval guard before its
implementation, with all timing and complete-tail assertions retained.

## Representing delayed audio in HLS samples

The first real delayed-Matroska browser run reaches the full thirty-second
picture and displays both subtitle cues correctly, but plays the tone immediately.
The native MPD places audio at 7.978667 seconds. Its first AAC fragment instead
has decode time zero; the delay is carried by an empty edit in the initialization
file. The pinned HLS player reads fragment decode times without applying that
edit. Native packet timestamps alone therefore do not prove audible delay.

For a verified source anchor with audio, append `aresample=first_pts=0` after
negative-preroll trimming and the existing profile filters. This represents the
source's leading gap as encoded silence, while preserving the tone's source-relative
onset and endpoint. It does not shift the audio independently of video or subtitles.
Do not add asynchronous compensation, change profile selection, or alter commands
without a verified anchor. Existing source qualification remains in force.

The first controlled runs retain exact thirty-, 24- and six-second video tails.
Decoded AAC tone onset is about 8.020479 seconds fresh, 2.020479 seconds after a
six-second seek, and 0.006417 seconds in the final hold. These runs cover origins
zero and four and the original source whose audio packets precede video. Complete
profile-filter and Opus controls before accepting the exact filter order.

Strengthen native acceptance to require decoded HLS PCM for the complete remaining
presentation, including silence, and measure the actual tone onset at eight, two
or zero seconds. Check near-silent windows before onset, positive signal after
onset and near the tail, and audio endpoints aligned with the complete video.
Independent fragments wholly within the expected initial gap may be silent;
fragments containing audible source content must still decode that content.
Preserve the narrow terminal AAC padding exception. Add delayed native Opus
controls because the anchored filter applies to that profile too.

Production silence represents an observed source interval. Fixtures and observers
must not manufacture silence, rewrite timing evidence or relax onset requirements
to hide a failure. Retain the failed browser receipt and show the stronger native
checks fail against the encoder before this amendment. Both actual delayed HLS
browser scenarios and the ordinary adaptive control remain required, along with
signed subtitle cues, the full Transcode selection and static gates.

Independent review approved this amendment before implementation, with the exact
filter order conditional on the profile-filter and Opus calibration results.

## Native subtitle cues retained after their endpoint

Two Chromium HLS runs now preserve the delayed audio and complete picture but
retain the native cue from seconds two to four until second 8.584. The cue's
stored times remain correct, its track remains selected, and both screenshots
and native activeCues observations confirm the overstay. Audio packet timestamps
and fragment decode times are continuous. A standalone native MP4 control clears
the same cue correctly; that single control does not exclude a conditional browser
scheduling defect.

Chromium 148.0.7778.96 uses [closed interval overlap](https://raw.githubusercontent.com/chromium/chromium/148.0.7778.96/third_party/blink/renderer/platform/wtf/pod_interval.h)
when determining active cues and an [exclusive next interval boundary](https://raw.githubusercontent.com/chromium/chromium/148.0.7778.96/third_party/blink/renderer/platform/wtf/pod_interval_tree.h)
when [scheduling the next update](https://raw.githubusercontent.com/chromium/chromium/148.0.7778.96/third_party/blink/renderer/core/html/track/cue_timeline.cc). At an
exact endpoint, those rules can retain the current cue while scheduling the next
boundary. The repeated ten-second wall interval matches that mechanism, but the
internal timer invocation has not been observed. Treat this attribution as an
inference; the stale native cue itself is directly reproduced.

Before changing application code, measure whether removing and re-adding only an
expired active cue clears its native state and display while preserving the same
cue object, timestamps, selection and later seek re-entry. Retain the original
browser failure and distinguish native state observations from cuechange events.
Do not change source cues, fixture timings or the permitted clearing interval.

If that public-API operation passes calibration, add a small observer owned by
the HLS attachment. On native timeupdate, inspect active subtitle or caption cues
only on showing tracks; refresh a cue only when the current time exceeds its end
by more than 100 milliseconds. Preserve the same object and times. Do not poll,
reset track modes, replace the subtitle renderer or refresh ordinary active cues.
Skip an in-progress seek and ensure attachment disposal or fatal failure releases
the listener. Keep selection persistence under its existing owner.

Focused tests must cover stale and ordinary cues, the endpoint tolerance, hidden
or disabled tracks, metadata tracks, seek transitions, backward re-entry, duplicate
prevention, selection preservation and attachment teardown, including lazy import
cancellation and fatal errors. Add a browser check of actual native activeCues if
the repair changes whether cuechange fires; do not synthesize events as evidence.
Require timely activation and clearing within the existing bounds, preserved cue
objects and signed delivery, and unchanged audio/video acceptance. Run both delayed
browser scenarios and the ordinary adaptive control, relevant frontend tests,
typechecking and lint, then independent code and simplification review.

Calibration reproduced stale cues during natural fresh and seek playback. Refresh
at local seconds 14.176426 and 8.170667 cleared native state synchronously; the
next recorded screenshot showed the overlay removed within eight milliseconds.
Cue objects, times, counts and selection remained unchanged. The repaired seek
cue re-entered as the same object at 6.5 seconds, remained active at the exact
endpoint eight, and cleared after seeking to nine. The operation emits no
cuechange event, so retain that event stream as diagnostic evidence and require
actual native-state observations for timely clearing. Both original event-only
failure receipts remain available.

Independent review approved implementation after these measurements. Snapshot
live cue lists before mutation, preserve current track ownership and guard against
reentrant refresh. The final browser tests retain the existing timing bounds and
must prove actual cue clearing, signed delivery and natural audio/video completion.

## Verification checkpoint

Implementation and independent code/simplification review are complete at
`538cc7e1`. The source-origin native selection passes nineteen cases with 3,486
assertions, including actual decoded AAC/Opus onset and complete tails. The full
Transcode selection at `3b0cfc79` passes 1,044 tests with 17,426 assertions across
80 files; subsequent changes affect only the frontend guard and observer.
PHPStan reports no errors. Deptrac reports no violations, warnings or errors and
484 existing skipped violations. The catalog-hook selection passes 271 tests
across five files, full frontend typechecking passes, and focused lint passes.

Fresh and source-seek Chromium acceptance passes at `538cc7e1`, retaining natural
thirty- and 24-second completion, actual audio onset at 8.064 and 2.082667 seconds,
signed native subtitles, timely visible cue removal and same-object backward
re-entry. The ordinary adaptive control also passes with 720p-to-360p-to-720p
presentation and positive audio in each phase. Caption screenshots and native
state observations agree. Recovered publication waits remain recorded; these
results do not establish generally uninterrupted playback.

The native subtitle guard is installed only for Hls.js attachments. Native HLS
fallback remains unchanged. Controlled AAC/Opus runs also preserve the measured
loudness filter chain before leading-silence normalization, without asynchronous
resampling. Final receipts and their source revisions are collected in
`var/test-runs/matroska-origin-gates/verification-summary.json`. ROADMAP.md and the
testing guide record the commands, exact checkpoints and remaining qualification
limits. All owned containers were removed; the pre-existing nginx, PostgreSQL and
Redis containers remain. This work is committed locally; merge and push are separate.
