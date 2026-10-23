---
title: Renew attached browser playback capabilities
date: 2026-10-23
status: implemented
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
capability to recover the same execution. An observed signing transport or server
outage also permits network recovery for that attachment, including before its
capability expires. Ordinary decoder failures and explicit signing refusal remain
terminal. After an initial accepted snapshot, transient signing failures preserve
owned intent during preparation, restoration and decoded playback.

Recovery requires a classified HLS.js or native media network failure and either
expiry or an observed signing outage. A fresh attachment consumes the outage
marker; a second network failure without another observed outage or expiry remains
terminal. An ordinary successful poll does not clear the marker, because a delayed
media failure can still belong to that outage. A replacement requires a newer,
nonexpired authenticated capability. Decoder errors and rejected play requests
remain terminal even after an outage, as does an initial signing failure.
The capability requirement also applies when recovery expands the rendition set
within the same execution. Every fresh attachment consumes the outage marker,
including replacements for changed renditions or generations.
An explicit seek accepted during recovery supersedes the saved pre-failure cursor.
After its acknowledgement chain settles, a same-generation acceptance must restore
the canonical accepted position on a fresh attachment; it cannot wait for the
released attachment to become ready.

The adapter fences callbacks before reporting failure, but destroys its captured
HLS instance only after the hook has retained the cursor. Destruction can reset the
native clock synchronously. Teardown must still run if the failure callback throws.
Recovery intent survives transient signing failures after attachment release. A
terminal failure fences subsequent polling responses against revival.

No backend signing policy, HMAC validation, generation semantics, encoding or
manifest format changes are planned. Native HLS uses the common replacement
path; real-browser acceptance in this phase covers Chromium with HLS.js only.

## Verification

First add a deterministic fake-clock regression that fails on unchanged code.
Hold job, generation and rendition set constant while signed deadlines advance.
Require replacement near expiry, preservation of a nonzero local cursor and
paused/running intent, and no per-poll reload. Cover deferred seeks/restoration,
expired attachment recovery, stale callbacks, unmount cleanup and signing refusal.
Cover an unexpired attachment failing during an observed signing outage, a delayed
failure after a successful poll, one replacement per observed outage, and callback
ordering before HLS destruction resets the native cursor.
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

Published renditions are selected for the current cursor and compatible desired
state; admission does not guarantee publication, and the set can grow or shrink.
The browser proof therefore records and retries an observation if its set changes,
with at most three attempts per mode and a shared 60-second renewal budget. A
successful interval must retain exactly its original set through expiry, refusal
and replacement delivery. Setup and restoration of the normal lifetime may use
the currently available set. Rapid-seek targets must move the actual slider value;
a click on the current thumb does not submit a seek.

The offline check requires the original decoded attachment to survive the failed
signing poll. After reconnection, an unchanged set still requires that attachment.
A fresh accepted snapshot with a changed set may replace it only with decoded
current media at the saved paused cursor, without a seek or generation change.

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

The combined browser run exposed a further integration failure during web restart:
transient signing failures were tolerated, but a later unexpired HLS network failure
made the new terminal guard stop polling permanently. The outage marker and adapter
teardown order above address that failure while retaining terminal refusal and
decoder behavior. The amendment requires new failing regressions and a rebuilt
production image before repeating complete acceptance.
Independent review accepted the amendment with the explicit capability guard for
same-execution recovery when the rendition set changes; that guard is included
above.

## Delivered verification

Application revision `4bb4ca27` implements renewal and observed-outage recovery.
Independent review accepted the final changes after the capability and accepted
seek guards were applied. Failing regressions cover renewal, outage recovery,
teardown ordering and the combined recovery/seek/rendition-change case.

The CI runner passed 116 tests across four suites: 101 hook, nine adapter, two
import and four overlay tests. Typechecking, the production build and asset
verification passed. Full lint reported zero errors and eight warnings outside
the changed files. Final logs end in `-complete.log` under
`var/test-runs/browser-capability-renewal-gates`; its `verification-summary.json`
records the tested application revision and production image.

The complete production lifecycle passed all eight phases in Chromium
148.0.7778.96, including web restart, worker containment and recovery. Paused and
running renewal each crossed the original capability's deadline, observed its
403 refusal and delivered newly signed media without a seek or execution change.
The paused cursor remained at 1.492748 seconds; running playback advanced from
34 to 64 decoded frames within the replacement attachment. Web restart exercised
recovery of an unexpired capability with the same rendition set and restored the
native cursor at 18 seconds. The runner exited zero and verified cleanup.
Receipts are under `var/test-runs/browser-capability-renewal-production-run-7`.

Backend expiry acceptance remained intact: 58 tampered and 58 expired requests
returned 403, and authenticated renewal delivered 62 resources with status 200.
This phase changes no backend, API or schema and claims no new full backend-suite
run. Real native-HLS browsers, additional browser platforms, full live FFmpeg
DASH EOF, formal CMAF conformance, DASH subtitles, the separate native pressure
observer and running progress/control outages remain unqualified. ROADMAP.md
records this checkpoint; all changes remain local pending a push request.
