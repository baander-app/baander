---
title: Renew attached browser playback capabilities
date: 2026-10-23
status: reviewed
execution: code
---

# Renew attached browser playback capabilities

## Scope and evidence

The browser polls the authenticated signing endpoint every second, but replaces
its HLS attachment only when the job, generation or rendition set changes. Fresh
URLs for the same execution remain unused. Descendant delivery now preserves the
parent expiry, so an otherwise healthy player can lose access after a long pause
or a long playback session. ROADMAP.md records this missing acceptance check.

This phase repairs that active consumer and proves renewal through the real
signing and delivery endpoints. It uses the previously authorized equivalent
planning, review and local-commit workflow because the compound-engineering
skills remain unavailable. Push is outside this phase.

## Behavior

Track the attached URL's absolute expiry separately from execution identity.
Use a newer authenticated capability near the attached deadline, with a bounded
renewal margin that also supports short-lived capabilities. Do not reload on
every successful signing poll, accept an older deadline, or create a seek command
or execution generation merely to renew access. Missing or malformed expiry
must not create a reload loop.

Reuse the existing owned attachment lifecycle for HLS.js and native HLS. Preserve
the attached source cursor and current playback intent across renewal. A stale
server progress position must not move the player backwards or forwards. Defer
replacement while a source seek, acknowledgement chain or timeline restoration
owns the cursor. Old media callbacks and asynchronous imports remain fenced by
attachment revision and effect cleanup.

After expiry interrupts delivery, retain the cursor and permit a newer signed
capability to recover the same execution. Limit this path to expired delivery;
ordinary decoder failures and explicit signing refusal remain terminal. Temporary
signing transport failures must not discard usable media. A successful refresh
after suspension or an offline interval can renew expired delivery.

Recovery requires both an expired attached capability and a classified network
failure: HLS.js network errors or native media network errors. Expiry alone cannot
make a decoder error or rejected play request recoverable. Preserve recovery intent
through transient signing failures even after releasing the attachment. A terminal
failure fences subsequent polling responses so they cannot revive the player.

No backend signing policy, HMAC validation, generation semantics, encoding or
manifest format changes are planned. Native HLS uses the common replacement
path; real-browser acceptance in this phase covers Chromium with HLS.js only.

## Verification

First add a deterministic fake-clock regression that fails on unchanged code.
Hold job, generation and rendition set constant while signed deadlines advance.
Require replacement near expiry, preservation of a nonzero local cursor and
paused/running intent, and no per-poll reload. Cover deferred seeks/restoration,
expired attachment recovery, stale callbacks, unmount cleanup and signing refusal.
Run the existing player, attachment and overlay suites, typecheck, targeted lint,
production build and build verification.

Extend the disposable two-command browser fixture. Forward real signing requests
with its existing short-lifetime parameter and return the genuine response
unchanged. Establish positive decoded playback before the renewal observation.
Stay paused across an actually attached capability's deadline, verify its old URL
returns 403 after the last valid integer second, then resume with advancing time
and decoded frames. Cross another deadline while running. Require actual renewed
master and media requests, unchanged ownership/generation and no unsolicited seek.
Record deadlines, safe capability identifiers, cursor and frame evidence; raw
signed URLs stay in memory. Restore normal signing lifetimes before the existing
offline/control checks. Preserve the backend HLS/DASH descendant expiry proof.

Run the complete production browser lifecycle with client controls and cleanup.
Record exact suite counts, artifacts, static gates and limitations in ROADMAP.md.
Independent review and simplification precede the final local commits. Formal
CMAF conformance, full native live DASH EOF, additional browser platforms and the
separate native pressure observer remain separate work.

## Plan review

Independent review confirmed the active-consumer gap and accepted the scope with
explicit network-error classification, terminal-response fencing and transient
failure handling during expired-delivery recovery. These refinements are included
above. Implementation follows this reviewed contract.
