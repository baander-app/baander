---
title: Keep completed movies stopped and replay from the source beginning
date: 2026-10-24
status: reviewed
execution: code
---

# Keep completed movies stopped and replay from the source beginning

## Consumer and failure

The movie overlay uses the video element's native controls. Its stream may start
partway through the source after a seek. At natural completion, the hook only
changes its displayed state to ended. Native replay then resets the stream's local
clock to zero, which the seeking handler maps back to that stream's source start.
A stream starting at 162 seconds can therefore replay the tail instead of the
movie. The play event can also submit a resume using the old end cursor before
the seek. Existing EOF tests cover cursor restoration but not natural completion
or replay.

Completion has a second failure path. The ending pause uses ordinary status
delivery. If it fails before forwarding, a fresh running snapshot can call play
on the ended element and start the tail without a new user gesture. Signing polls
and capability renewal continue after completion. Encoding completion is separate
from playback status; the existing server contract has running and paused desired
state, not a new playback-ended state.

Repair the active movie workflow using existing source positions, execution
generations and control receipts. No backend API or schema change is planned.
Continue the previously authorized equivalent planning, independent review and
local-commit workflow because the compound-engineering skills remain unavailable.
Use this plan's branch; no merge or push is included.

## Completion and replay contract

Recognize completion only for an active, error-free owned attachment that reaches
the known source end. Use the existing 0.1-second media-end tolerance for track
rounding; an incomplete presentation ending earlier is not a finished movie.
Record completion against the current job, session and execution generation.
Ignore old ended events during a newer seek, owner replacement, cancellation,
terminal failure or cleanup. Restore a paused canonical EOF without submitting a
new seek or autoplaying it.

The native ending pause and ended event must settle once at the displayed source
duration, without duplicate pause commands or seek echoes. Use existing pause
delivery semantics and the canonical source end for the ending pause. A transient
ending-pause failure must leave the completed movie stopped even if a fresh owned
snapshot still reports running. Do not add status retries or claim that a failed
pause was accepted. Refusal, terminal and genuine media-error handling remain.

While the same execution is completed, ordinary signing polls, optional rendition
publication and capability expiry must not replay or rewind the movie. Keep the
completed frame and source cursor. Buffered ended media does not need to be
reattached solely to renew authority for future requests. A newer explicit seek,
replay or authoritative execution/owner change must supersede completion through
the existing lifecycle and generation fences. This local completion state does
not promise persistence of an unacknowledged pause across a new player instance.

Explicit native Play/replay from completion must submit one source-zero seek
through the existing bounded seek protocol, then resume if canonical status
requires it. Cover both native event orders: seeking before play and play before
seeking. Do not send the old source start or EOF as a replay target, duplicate the
source-zero intent, or let programmatic stopping of the retiring media echo a
pause. Preserve a newer explicit seek and newer pause/resume intent while replay
is pending. Retain stale-completion, idempotency, error and cleanup behavior.
The hook's explicit play method must follow the same replay contract even though
the current overlay uses native controls.

## Verification and ownership

One worker owns the player hook and its focused tests. A second owns the existing
production browser fixture and independently reviews the hook. The lead owns this
plan, integration, shared runner/CI configuration and commits. An independent
reviewer checks the plan before implementation. Workers do not stage or commit
and must preserve one another's edits. A documentation worker may update the
operator guide and ROADMAP.md after the final behavior is known.

First reproduce failures against unchanged application source. Cover natural
completion from nonzero source origin, uncertain ending pause, same-generation
polling and renewal, both replay event orders, paused canonical EOF restoration,
newer local intent, owner/generation replacement, short presentations and stale
events. Keep the existing four player suites and review simplification at the
implementation boundary. Run the CI-image player suites, typechecking, lint,
production build, asset verification and runner/browser syntax checks. Build the
production worker image from the verified application source.

Add a bounded natural-tail completion and replay proof to the existing production
browser fixture after worker recovery and before teardown. Use the actual movie
source slider to seek near the known end, then play the remaining tail at normal
speed until a real ended event. Require completed current-set audio/video
playlists, delivered final resources, decoded playback and the correct source
end without forcing currentTime to EOF, dispatching ended, changing playback rate
or manufacturing end-of-stream. This proves natural completion after a user seek;
it does not claim uninterrupted full-source playback or byte-exact decode.

Drop the ending pause before forwarding and prove that fresh canonical running
polls cannot restart the finished movie. Observe the stopped attachment through
its current capability deadline, then invoke native Play and require a new
source-zero execution, current-generation delivery and advancing clock and raw
frames. Retain sanitized completion, control, publication, lease and replay
evidence. Preserve all existing eight lifecycle phases and their assertions.

Bound the added scenario to 60 seconds, keep the existing phase limits and
600-second lifecycle deadline, and run Chromium and Firefox sequentially. Inspect
retirement fixture assumptions before adding the replay generation; update only
test bookkeeping justified by the new real controls. If the measured work cannot
fit, review a focused acceptance path instead of weakening checks or silently
extending deadlines. Preserve failed receipts and verified cleanup. Existing
application data and the user's original videos remain untouched.

Record measured acceptance and remaining limits in ROADMAP.md. Apply prose-fix to
changed documentation and commit the completed work locally.

Independent review approved the ownership, completion, replay and verification
contract before implementation, including the existing deadline boundary.

## Native HLS completion repair

The first Chromium deployment run passed the preceding six phases but could not
reach native EOF in the added completion check. Both final audio/video fragments
were delivered with ENDLIST. The failure and verified cleanup remain under
`var/test-runs/movie-completion-replay-chromium`; its 579.96-second runtime stayed
within the unchanged outer deadline.

An isolated reproduction uses the production SegmentEncoder command builder and
a reconstructed synthetic MKV source. It reproduces the stall without the hook,
renewal or attachment replacement. Audio reports EOS; video does not, so HLS.js
never calls MediaSource.endOfStream. The final one-frame video's raw presentation
timestamps run from 30.066667 to 30.1 seconds, while Chromium's buffered samples
end at 30.033333. The video init segment has an edit-list media time of 1024 at
15360 ticks per second. Chromium applies that two-frame adjustment; HLS.js's
fragment tracking uses raw composition timestamps. Retain the reconstructed
media, init/fragment inspection and browser traces under
`var/test-runs/movie-completion-replay-eof-probe`. This is a producer-equivalent
reproduction, not the deleted production generation's exact bytes.

Extend this phase to correct the producer's composition timestamps through the
DASH muxer's MP4 format options. The candidate is
`-format_options movflags=+negative_cts_offsets`: signed composition offsets make
the video edit-list media time zero while preserving its presented timeline.
Compare native EOS with the unchanged producer before implementation. Keep AAC
encoder preroll and audio packet timing intact; disabling edit lists globally is
a diagnostic comparison, not the intended fix. Preserve B-frames, genuine native
EOF and the existing duration tolerance. Do not fabricate completion, pad the
manifest or increase deadlines to make acceptance pass.

A backend worker owns SegmentEncoder and its argument/native media regressions;
the lead owns the plan, integration and authoritative checks. Read backend
architecture and port rules. No application port, API, persistence or schema
change is needed. First reproduce the raw-versus-edited video timestamp mismatch
in a native regression, including a nonzero-origin one-frame tail. Verify that
signed offsets remove it and preserve AAC preroll, independent fragment decoding,
segment boundaries and publication behavior. Run the affected native media,
stream-argument and publication suites in the CI image, plus applicable static
gates. Keep the existing frontend gates unless source changes require a rerun.
Rebuild the worker image from the verified producer and repeat the full Chromium
and Firefox lifecycle runs, preserving all eight phases and assertions. Record
the failure, producer repair and measured final acceptance in ROADMAP.md.

Independent review approved this addendum before backend implementation. Isolated
Chromium and Firefox comparisons reached genuine native EOF with negative CTS
offsets while retaining the baseline presented audio/video ranges and identical
AAC payloads. The unchanged producer stalled in both browsers.

The progressive native consumer must count decoded source frames without FFmpeg's
implicit constant-frame-rate duplication. The first repaired-producer run kept
480 copied HLS video packets but wrote 483 gray frames, including four copies of
the first image. Verify the raw-output measurement against retained media before
adjusting that fixture. Limit any repair to the gray-video consumer's timestamp
origin and frame synchronization; keep copied packet CRCs, PCM output, strict
480-frame full-output checks with their existing one-frame tolerance, exact
312-frame prefix checks, duration limits and live-versus-completed equality checks.
Do not change the producer or increase count tolerances for this observation.

## Progress observation timing

The rebuilt Chromium run stopped in an existing progress-outage observation before
the completion scenario. Decoding survived the dropped progress request, and a
later progress write succeeded. The source detached 11 milliseconds before Node
recorded a response with a changed rendition set; the successor master matched
that response's set and capability. Its capability expiry was a day away. The
failure and verified cleanup remain under
`var/test-runs/movie-completion-replay-chromium-accepted`; that directory name does
not indicate a passing run. The exact browser response time was not captured in
this progress observation.

Use the fixture's existing browser XHR-DONE signing receipts and causal detachment
checks for progress outages, as the status-outage checks already do. Read media
events and signing receipts together. Keep owner/generation checks, real progress
acceptance and canonical-position evidence, advancing decoded media, no-seek
assertions and bounded attempts. Require every replacement to follow an actual
browser receipt for its successor rendition set; retain failure for media errors,
unexplained teardown or unmatched replacement. Do not substitute Node scheduling
time for browser delivery order or accept a later response retrospectively.

This is a browser-fixture change only. Review it before implementation, preserve
all existing assertions and deadlines, and rerun both full browser lifecycles.
The verified worker image can be reused when application-source parity with its
recorded revision is checked.

Independent review approved the observer repair before implementation and its
final causal checks afterward. Progress and status observations both snapshot
attachment capabilities before reading browser events and receipts, so a later
master request is checked in the next observation instead of against older data.
