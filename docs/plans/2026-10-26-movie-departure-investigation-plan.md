---
title: Qualify movie departure and next-movie startup
date: 2026-10-26
status: implemented
execution: code
---

# Qualify movie departure and next-movie startup

## Selected investigation

The user selected another playback investigation after the roadmap's capture
diagnostic was delivered. Read-only review identified a concrete candidate:
leaving a long movie with Back may leave all three video execution slots owned
by its producers, preventing another movie from starting.

Back currently unmounts the movie overlay. Hook cleanup aborts its lifetime before
pausing the media element; control submissions require that lifetime to remain
active. Starting playback creates three rendition sessions. The execution worker
has three video-child slots and retains a child while its encoder is stopped at
the 72-second lookahead limit. Its 15-second ownership lease renews every three
seconds without viewer requests. The warm-cache viewer window controls file
eviction, not producer expiry. Pause retains capacity; cancellation releases it.
Independent backend review corroborates these mechanisms, but their composition
has not yet been reproduced through the real player.

The two user-provided videos were probed read-only. Both have ordinary square-pixel
1080p video and are longer than the lookahead budget, making the departure
workflow relevant. No geometry defect was established. Original media is not
modified or used as writable fixture storage.

## Evidence and boundaries

Use the existing disposable production two-command stack with one execution
worker and two distinct generated movie records. Movie A must outlast the
lookahead window so early encoder completion cannot conceal retained ownership.
Open A through the real product UI and require advancing decoded frames and all
three owned rendition claims before departure. Use the actual Back button.

Observe the abandoned sessions at fixed source position across at least two
15-second lease periods. Retain desired status, generation, ownership, admission,
lease renewal and native process state. Open B through its real movie page and
retain signing/startup responses and decoding state. Do not label ordinary
encoding latency as capacity starvation: correlate failure with A's still-live
children and unavailable worker capacity.

As an explicit diagnostic control, cancel A through the existing authenticated
owner API. Require its current sibling renditions to retire and release their
claims/admissions; then retry B through the actual UI and require advancing audio
and video delivery. This control does not represent the product's current Back
behavior. Retain before/after evidence rather than treating a successful retry as
proof by itself. Verify test deployment retirement and container/process cleanup.

The reproduction may modify fixture, runner and browser copies in an isolated
temporary worktree. Preserve those copies and their hashes with the receipts.
Use the same production runtime and UI bytes for both sides of the diagnostic
control; any derived image changes only test fixtures. Keep generated data and
credentials inside disposable services. No existing database, Redis, media or
application service is reset. Do not add a CI test that requires the defect to
continue existing.

No production teardown repair is authorized by a static hypothesis. A confirmed
result selects a subsequent reviewed remediation design covering ownership,
sibling cancellation, early departure, in-flight startup, rapid reopening and
shared-session behavior. Unconditional cancellation from React effect cleanup is
not an assumed solution: StrictMode and stale lifetimes can share sessions.
This phase changes neither producer caching nor the semantics of pause or Back.

## Workflow and acceptance

Continue the established equivalent reviewed-plan workflow while the named
compound skills remain unavailable. Review this plan before fixture changes.
The lead owns the plan, isolated worktree, runner, images, authoritative execution,
integration and local commits. A worker owns the isolated fixture/browser proof;
another independently reviews the causal evidence and scope. Workers do not
stage or commit. Apply prose-fix to documentation and retain historical roadmap
checkpoints. Merge and push require another request.

1. Freeze the baseline revision and source/image identities. Preserve the static
   trace and read-only source probe receipts.
2. Implement the smallest isolated two-movie reproduction and inspect its evidence
   boundaries before running it. Reuse existing authentication, publication and
   cleanup machinery. Use a 300-second work budget with sixty seconds reserved
   for cleanup; retain failed setup or unverified cleanup separately.
3. Run one complete diagnostic sequence. Infrastructure or observer failures may
   receive one justified correction and rerun, with both attempts preserved;
   do not rerun merely to obtain the desired hypothesis.
4. Require sufficient evidence to confirm or falsify the departure/capacity
   mechanism. If the candidate is not demonstrated, report that limit without
   inventing a fix. If demonstrated, record the exact user-visible trigger and
   causal control, then select the bounded remediation in `ROADMAP.md`.
5. Record actual checks, receipts and cleanup, obtain independent final review,
   and commit the investigation checkpoint locally. Do not claim a production
   fix, universal player reliability or qualification of unrelated media paths.

Independent review approved this plan before fixture implementation. Begin the
lease observation after A's final committed progress update, preserve all three
job/session identities through cancellation, and distinguish B's admission
refusal from a generic playback timeout.

## Observer correction after the first attempt

The first attempt played A and used Back, then timed out at an observer
precondition before testing B. It required every published edge to exceed the
final cursor by 72 seconds within 45 wall-clock seconds. The retained native
signal logs show 360p stopping after 25.94 seconds and 720p after 56.50 seconds;
1080p had not stopped before cleanup. Three software tiers share two execution
CPUs. Cleanup passed, but this attempt does not establish B's startup behavior.

The arithmetic also ignored throttle hysteresis. An encoder that stopped at 72
seconds ahead need not resume after a late progress update: it resumes only when
its lead falls to 36 seconds. Its final stopped edge therefore need not remain
72 seconds beyond the final cursor.

Use the plan's one permitted observer correction and rerun. Keep the same generated
media commands and production runtime. Observe all three actual native encoders stopped,
with a bounded 120-second readiness window, then perform the existing thirty-
second settled-position and lease-renewal observation. Retain process snapshots,
publication edges and generation-local coordinates on success and failure.
Require the same stopped process identities after observation. The overall work
and cleanup budgets remain unchanged; no production threshold is relaxed.

## Investigation checkpoint

The bounded investigation is complete with a partial result. Back leaves the
three original producers occupying the execution worker's video slots. The full
next-movie failure and cancellation control remain unqualified; this status does
not mark a production repair or a passing end-to-end sequence.

The corrected attempt played A with advancing decoded video and nonzero WebAudio
output, then used the actual Back button. All three native encoders reached
stopped state after 82.59 seconds of readiness observation. Across 31 subsequent
samples spanning 30.111 seconds, their original generation-2 ownership remained
live at source position 4.477333. Lease expiries advanced by 28.50–31.50 seconds.
Publication stayed incomplete at video edge 78 and audio edge 77.930667. The same
three native PID/startTicks identities remained stopped before and after that
window. This establishes abandoned capacity retention beyond two lease periods.

B began admission, but the browser observer terminated before recording its
startup outcome. The final database snapshot contains B's pending, unclaimed
360p job just before its five-second admission deadline. Concurrent signing
refreshes returned not-ready responses. Docker events record the browser
container exiting 1 and its checkpoint client exiting 137. No completed refusal,
explicit cancellation or recovered B playback receipt exists. Browser stderr was
not retained, so the terminating error remains unproved.

Read-only inspection identified an observer failure hypothesis: the inherited
signing interceptor calls `route.fetch` with a 4500-millisecond timeout and no
local rejection handler, while startup admission can wait roughly five seconds.
That timing matches the browser exit but cannot establish its cause without the
missing error. The product's own signing request has a 5000-millisecond timeout;
newer polling responses can also supersede an older request's failure. A mounted
B therefore cannot be assumed terminal before the cancellation control. A new
reviewed investigation must retain browser termination diagnostics, observe the
actual startup outcome and control B's mounted/retry state before qualifying the
causal sequence. The current two-attempt allowance is exhausted. No production
remediation is selected from this incomplete sequence.

Both attempts and their fixture, browser and runner copies are retained under
`var/test-runs/movie-departure-investigation/`, together with source hashes,
image provenance and `investigation-summary.json`. The production runtime matches
`d78fafbb4981e9d5231d1e5e208675f613b512ed`; derived images replace only the fixture.
The generated media commands and browser source stayed unchanged between attempts.
The first attempt exited 1 at its observer precondition; the second exited 137.
Both cleanup receipts report success. A post-cleanup identity check confirms all
seven captured worker processes are absent, and the three original service
container identities are unchanged. Shell, Node and PHP syntax checks and the
isolated diff whitespace check passed. No application suites were rerun for this
documentation checkpoint, and no production source or user media was changed.
