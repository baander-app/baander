---
title: Recover uncertain source seeks with bounded idempotent delivery
date: 2026-10-24
status: implemented
execution: code
---

# Recover uncertain source seeks with bounded idempotent delivery

## Scope and evidence

A transient seek failure clears the movie player's seek chain and starts a fresh
signing read. Its outer failure handler then destroys the media attachment. When
the server accepted the seek but its response was lost, that handler can destroy
the replacement just attached by the fresh read. Later snapshots retain the same
execution identity and cannot repair the disposed attachment. Queued later targets
also lose the predecessor acknowledgement that permits their first delivery.

The existing position endpoint accepts a caller UUID. Its transaction locks the
playback group, checks the expected generation and records the request identity
before applying desired state. A duplicate or generation-fenced request returns
HTTP 200 without an accepted generation. A new no-op seek still returns an accepted
generation. These contracts support a bounded repair without backend, schema or
API changes. The existing transcode control receipt vocabulary applies.

Continue the previously authorized equivalent planning, independent review and
local-commit workflow because the compound-engineering skills remain unavailable.
Work stays on this plan's branch. Existing application databases, Redis and media
storage remain untouched. No merge or push is planned.

## Delivery and ownership contract

Assign one UUID when a source-seek target is actually submitted. Capture its
position, expected generation and identity once; a queued target may coalesce
before submission but cannot change the submitted payload. A newer logical target
receives a different identity. Fence both the original send and resend against
the current job/session, active effect and terminal state.

For recognized Axios network errors, either timeout code and HTTP 5xx, preserve
usable media and pending seek ownership. Send the identical owned seek at most
once more, with its original UUID and generation. Do not apply this resend to
pause, resume, progress, cancellation, configuration, unrelated or HTTP 4xx errors.
Initial signing refusal and genuine current-media decoder failures retain their
existing behavior. A retiring attachment may still fail while its replacement is
pending; preserve control ownership independently of media disposal.

A successful second response closes delivery uncertainty for that request under
the existing transaction, receipt and generation rules. It does not by itself
prove which position is current. If it carries an accepted generation, retain the
normal acknowledgement chain. Otherwise, obtain authenticated canonical state from
a read started after that response before assigning a generation to any unsent
successor. The read must match the owned job and session, report running or paused
status while the hook remains active and nonterminal, and respect existing monotonic
generation/revision and request-order fences. Signing does not expose the runtime
outcome; do not infer one from publication readiness. Never use an echoed response
position as proof of application. A fresh read after a
client timeout alone does not close delivery uncertainty: the original request
could still commit after that read.

The owned read after a definitive resend needs an explicit exception to the guard
that normally defers signing while the seek chain has remaining targets. A first
attempt that returns HTTP 200 without an accepted generation retains the existing
tail-abandonment behavior; only the definitive resend path gains this reconciliation.

Preserve first/latest target coalescing and control ordering, including pause or
resume between seeks. A definitive duplicate response plus fresh canonical state
may authorize the first delivery of a queued successor; this is distinct from
pretending that the lost response supplied an accepted generation. While that
read is unavailable, retain pending intent and keep polling. A replacement owner,
terminal state or cleanup discards and releases outstanding reconciliation work.

If both transport attempts fail, do not send a third copy or dispatch the remaining
ambiguous chain. Cancel its unsent targets and recover the current execution and
cursor through a fresh owned read. Keep media and pending recovery until that read
settles; subsequent explicit user intent must use a fresh owned generation rather
than the abandoned chain. This bounded fallback does not claim that a timed-out
server request has stopped. A later committed generation still arrives through
normal polling and its generation fence prevents stale work from replacing newer
accepted work.

A new gesture during fallback reserves its control-queue position with a new
intent revision. Coalesce consecutive unsent seek targets, preserving pause and
resume commands as ordering boundaries. Assign the generation only after a fresh
owned read; never inherit the abandoned UUID or generation. Canonical restoration
must not clear the newer pending UI target. This is a new explicit command, not a
retry of the failed chain.

Restore the canonical source cursor through the existing source-to-media mapping.
Preserve paused status across seek recovery. A native seek can remain unfinished
when its requested media is unavailable; recovery must not wait forever for that
native seek to complete before restoring an owned canonical cursor. Actively cancel
or replace that unfinished seek while preserving seekable-target checks and native
feedback suppression. Newer local intent, stale completions, owner replacement
and unmount must remain fenced.

## Implementation and verification

The lead owns this plan, integration, runner coordination and commits. One worker
owns the player hook and its tests; another owns the browser fixture. A third may
maintain the operator guide and roadmap after implementation. Workers do not stage
or commit. Review the plan before implementation, simplify at the implementation
boundary, and independently review the final source and browser assertions.

First reproduce the failures on unchanged application source. Cover paused and
running seeks lost before forwarding and accepted responses lost, stable payloads
and identities, both timeout codes and HTTP 5xx, exactly one resend, duplicate
responses without acknowledgements, queued first/latest targets and intervening
status controls. Cover delayed original completion, fresh-read outages, old reads,
both-attempt failure, native seeking that does not finish, newer explicit intent,
owner replacement, terminal state and cleanup. Preserve excluded-error behavior.
Run the four existing player suites in the CI runner.

Extend the production client-controls phase with two bounded source-slider cases:
a seek lost before forwarding, and an accepted seek whose response is lost while
newer slider targets queue. Require the second transport attempt to carry exactly
the first payload and UUID. The first case must apply one logical seek and advance
one generation. In the second case, prove the first seek's canonical position and
advanced generation before dropping its response; require the duplicate response
to omit its accepted generation, then permit only the latest unsent target with a
new UUID and the freshly observed generation. Exactly two logical seeks may apply
in that case. Signed acceptance may report unavailable media: require generation
and position before response loss, then readiness and decoding at final restoration.
Do not count an echoed position or HTTP 200 alone as acceptance.

Keep response holds below the existing five-second client timeout, including
forwarding. Bound the two new cases together to 90 seconds and retain the current
360-second client-controls and 600-second lifecycle budgets. Preserve the existing
healthy rapid-seek, status/progress outage, capability renewal, subtitle, offline,
remount, restart and worker-recovery assertions. Use actual committed slider values,
authenticated state, current-generation delivered master/audio/video resources,
decoded and seekable media, the restored source cursor and paused status. Require
resumed playback to advance time and decoded frames. Remove routes/listeners in
finally blocks and retain sanitized attempt, identity, generation and media evidence.

Run typechecking, lint, production build, asset verification and browser/runner
syntax checks. Build the production worker image from tested application source,
then run all eight lifecycle phases and verify disposable cleanup. Serialize
expensive runs and retain failed receipts. Record actual counts, reviewed changes,
artifacts, bounded fallback and remaining qualification limits in ROADMAP.md.
Apply prose-fix to changed documentation and commit the completed phase locally.

Independent source and backend/browser reviews approved this plan with the
ownership, first-response, fallback-intent and publication-readiness clarifications
included above.

## Completion evidence

Implementation `1f8b0a07` and browser fixture `29d187d7` passed independent review.
The final CI run passed 206 tests across four suites, including 191 player-hook
tests. Regression runs first demonstrated ten seek-recovery failures, one repeated
native-rollback failure, three stale-completion failures, four cancellation
failures and two fallback-ordering failures. Typechecking, the production build,
asset verification and browser/runner syntax checks passed. Lint reported zero
errors and eight existing warnings outside the changed files.

The production worker image built from `29d187d7` passed all eight lifecycle
phases in Chromium 148.0.7778.96. Loss before forwarding produced two identical
transport attempts and one generation advance. Accepted-response loss produced
an identical resend without acknowledgement, a fresh owned read, then only the
latest queued target with a new identity; two logical seeks advanced two
generations. Both cases restored decoded paused media and resumed playback.
Existing capability renewal, progress/status loss, subtitle, offline, remount,
restart and worker recovery checks also passed. Cleanup was verified.

Gate evidence is under `var/test-runs/player-seek-outage-gates`, including
`verification-summary.json`. Production receipts and `acceptance-summary.json`
are under `var/test-runs/player-seek-outage-production`. ROADMAP.md records the
measured results and remaining qualification limits. No merge or push was made.
