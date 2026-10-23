---
title: Preserve playback through temporary progress update failures
date: 2026-10-23
status: reviewed
execution: code
---

# Preserve playback through temporary progress update failures

## Scope and evidence

The active movie player reports its source position every second while running.
A rejected progress request currently enters the general control-failure handler,
which releases the media attachment and clears recovery and seek state. A later
successful signing poll for the same execution retains the loaded identity and
does not restore that attachment. A temporary progress endpoint outage can thus
interrupt otherwise healthy delivery until capability renewal.

Existing browser outage checks pause before disconnecting. They do not exercise
this running path. This phase repairs that demonstrated consumer failure and
qualifies it through the existing production browser lifecycle. The previously
authorized equivalent planning, independent review and local-commit workflow
continues because compound-engineering skills remain unavailable. Push is outside
this phase. Existing application databases, Redis and media remain untouched.

## Contract

An automatic progress request that fails with a network error, timeout or HTTP
server error preserves the owned attachment, playback intent and cursor. It must
not report a playback error or clear pending seek/restoration state. Initial
signing failure, explicit signing refusal and current decoder failure retain their
existing behavior. Other progress failures and explicit pause, resume and seek
failures retain their current handling.

Do not replay the failed position or add a retry queue. Once its request settles,
the normal timer may send the latest eligible cursor with the current generation
and control revision. A failed response does not advance the acknowledged progress
position. The server may already have accepted a request whose response was lost;
the subsequent authenticated snapshot and existing generation/revision fences
remain authoritative. Queued explicit controls must still run, and unmount,
session replacement and terminal failure must prevent stale work from reviving
playback. Do not classify unrelated exceptions as transport outages.

This phase introduces no backend, schema, API or vocabulary change. Explicit
control delivery uncertainty and retry semantics remain separate work.

## Implementation and acceptance

The lead owns this plan, runner coordination, documentation and commits. One
worker owns the hook and its unit tests; another owns the production browser
fixture. Review this plan before edits and review the final implementation for
correctness and unnecessary complexity. Workers do not stage or commit.

First reproduce the failure on unchanged source with a decoded, running attachment
and a rejected automatic progress request. Verify that transport and server
failures preserve the attachment and local state, then that the next eligible
update uses the current cursor. Cover a lost response after server acceptance,
pending explicit control ordering, stale completions, timeout classification and
the retained behavior of nontransient failures. Preserve the existing player
regressions and capability-renewal acceptance.

Extend the client-controls browser phase after normal signing lifetimes resume.
Intercept only automatic progress requests for the owned session: abort one before
forwarding, then accept one through the real server and drop its response. Leave
signing and media requests live. Each observation requires decoded running media
before failure, no attachment teardown or playback error, advancing native time
and decoded frames afterward, a subsequent genuine successful progress request
and an authenticated snapshot reflecting progress. Require unchanged job, session
and generation and no unsolicited seek. Record the failure boundary and recovery
evidence without recording credentials. Remove interception in a finally block.
Each qualifying observation also retains its actual attached rendition set.
A fresh accepted signing response that changes the set supersedes that observation;
record it and retry within three attempts per case and a shared 60-second budget.
Correlate the later real progress response with its submitted cursor and require a
subsequent signing snapshot at or beyond that cursor. A successful signing request
alone does not establish that progress writes recovered.

Run the four player suites in the CI runner, typecheck, full lint, production build
and asset verification. Check browser JavaScript and runner shell syntax. Build
the production worker image from the tested source, then run the complete
eight-phase two-command lifecycle with browser client controls and verified
cleanup. Serialize expensive runs and retain failed receipts. Record exact counts,
artifact locations and remaining qualification limits in ROADMAP.md. Use prose-fix
for the documentation and commit the completed phase locally.

Independent plan review approved this scope with the stable-rendition observation
and genuine post-failure progress acknowledgement requirements included above.
