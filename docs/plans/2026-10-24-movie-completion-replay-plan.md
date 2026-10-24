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
