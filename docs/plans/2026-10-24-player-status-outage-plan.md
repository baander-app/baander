---
title: Reconcile pause and resume after uncertain delivery
date: 2026-10-24
status: reviewed
execution: code
---

# Reconcile pause and resume after uncertain delivery

## Scope and evidence

The movie player preserves healthy media when automatic progress reporting fails,
but a failed pause or resume request still disposes its attachment. This happens
both when the command never reaches the server and when its accepted response is
lost. A later signed snapshot for the same execution does not replace that disposed
attachment. Existing tests only demonstrate recovery after a generation change.

This phase repairs the active pause/resume workflow and qualifies uncertain
delivery through real authenticated controls and decoded browser media. Seek
delivery uncertainty remains separate. The previously authorized equivalent
planning, independent review and local-commit workflow continues because the
compound-engineering skills remain unavailable. No push or merge is planned.
Existing application databases, Redis and media storage remain untouched.

## Contract

Recognized Axios network errors, either timeout code and HTTP 5xx responses from
pause/resume preserve the attachment, cursor and pending seek/restoration state.
Do not replay the POST or infer acceptance from a transport failure. Obtain an
authenticated snapshot from a read started after that failure, then reconcile the
latest owned pause/resume status to the server's canonical status. Preserve the
optimistic local status while that read is unavailable. An older in-flight signing
response cannot settle the uncertain command.

Reconciliation changes status only. Canonical paused stops the current attached
clock; it does not rewind to an older durable position or submit a seek. Canonical
running permits playback, including after programmatic pause closed the local
playback gate. Existing execution replacement still restores its canonical cursor.
Newer local pause/resume intent takes precedence, and queued source seeks retain
their existing acknowledgement and cursor ownership.

While an owned pause/resume intent is pending or uncertain, an opposite user
gesture remains a new command even if signing reports unavailable media. Media
readiness is not a backend prerequisite for this status control. Submit that new
intent once and retire the older reconciliation ticket; do not replay the failed
command. This exception does not change initial controls without pending intent.

Use an owned reconciliation ticket carrying the session, job, local status revision
and minimum signing-request revision. Keep it distinct from successful controls
deferred behind a seek acknowledgement. Accepted fresh state may settle it only
for its current owner and latest intent. Newer status intent, owner replacement,
terminal failure and cleanup discard stale reconciliation work. Preserve ordinary
signing-refusal and decoder-error behavior, progress handling, and the existing
handling of seek, cancellation, configuration, unrelated and nontransient failures.
This phase changes no backend, schema, API or shared vocabulary.

## Implementation and verification

The lead owns this plan, integration, runner coordination, documentation and local
commits. One worker owns the hook and its unit tests; another owns the browser
fixture. Workers do not stage or commit. Review the plan before implementation;
review the final code and evidence checks independently and apply justified
findings without broadening the phase.

First reproduce pause/resume failures on unchanged source with an attached, decoded
player. Cover lost requests and accepted-but-lost responses, native and programmatic
controls, both timeout codes and HTTP 5xx. Prove fresh-read ordering, continued
optimistic intent during signing outages, latest queued intent, queued source seeks,
stale completions, unmount and owner replacement. Preserve terminal and excluded
error behavior. Assert that reconciliation does not assign the native cursor or
submit an extra control. Run all four player suites in the CI runner.

Extend the production client-controls phase with four cases in order: pause lost
before forwarding, accepted pause response lost, resume lost before forwarding,
and accepted resume response lost. They converge respectively to running, paused,
paused and running. Start each gesture from the opposite canonical status, intercept
only the owned session's target action, and require exactly one explicit command
with no opposite-command echo or source seek. Normal progress reporting is allowed
only while running. Ownership and execution generation remain unchanged.

For response loss, forward the real command and observe its changed canonical
status, position and newer revision through authenticated signing before dropping
the held response. Bound forwarding and observation below the existing five-second
client timeout. HTTP 200 alone does not prove acceptance. For loss before forwarding,
the original server status remains authoritative. Remove routes and listeners in
a finally block and keep the four-case observation within a shared 60-second budget.

Pause/resume legitimately change compatible rendition sets. Any replacement must
follow a fresh authenticated publication after the preceding attachment request;
that publication must change its rendition set and match the replacement master.
Require delivered current-set master/audio/video resources and actual decoded media.
For running convergence, capture a post-failure baseline on the current attachment
and require advancing time and frames. For paused convergence, observe a stable
local source clock and decoded, seekable media across two subsequent canonical
paused polls; do not compare the native clock with an older durable cursor.
Native media errors and unexplained teardown fail acceptance. Record the failure,
canonical status, command counts and media evidence without credentials.

Run typechecking, full lint, production build and asset verification, plus browser
and runner syntax checks. Build the production worker image from tested source and
run the complete eight-phase two-command lifecycle with client controls, preserving
progress outages, capability renewal, subtitles, seeking, web restart, containment
and recovery assertions. Serialize expensive runs and verify cleanup. Record exact
suite counts, static gates, artifacts and remaining qualification limits in
ROADMAP.md. Apply prose-fix to documentation and commit the completed phase locally.

Independent plan review approved this scope with the explicit changed-rendition
requirement for browser replacement evidence included above.
