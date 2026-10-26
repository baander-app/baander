---
title: Qualify movie departure and next-movie startup
date: 2026-10-26
status: reviewed
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
