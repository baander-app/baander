---
title: Preserve signed delivery expiry through manifest descendants
date: 2026-10-23
status: implemented
execution: code
---

# Preserve signed delivery expiry through manifest descendants

## Scope and evidence

The signing endpoint accepts lifetimes from one second to one day, but manifest
delivery discards the authenticated expiry. Each descendant URL receives a fresh
24-hour lifetime. A short-lived HLS master can therefore issue a longer-lived
media playlist, which can issue still later fragment URLs. DASH and subtitle
playlists use the same signing helper. These are active movie-delivery routes and
the discrepancy violates the requested lifetime of their bearer capability.

This phase preserves one absolute expiry through that URL chain. It follows the
previously authorized equivalent planning, independent review and local-commit
workflow because the compound-engineering skills remain unavailable. Existing
application databases, Redis and media storage remain untouched. No push is planned.

## Contracts

`SignedStreamRequest::validate()` returns the authenticated integer expiry.
Manifest controllers pass it through the four manifest methods on the streaming
port, its QoL decorator and its implementation. The parameter is required; no
default may silently replace the caller's deadline. The existing boolean request
validation remains available to leaf controllers.

The stream-auth port adds an operation that signs with an absolute expiry.
Relative top-level signing computes its deadline once and delegates to this
operation. Every HLS video/audio/subtitle playlist, initialization file, media
fragment, subtitle VTT and DASH resource inherits exactly its parent's expiry.
Do not convert an absolute deadline to a relative lifetime and back: crossing a
clock second between those operations could extend access.

Preserve signed resource identity, rendition sets, generations, track keys,
indices, domain prefixes and constant-time signature comparison. Tampering with
expiry remains invalid. Existing validation admits the integer second equal to
`exp` and rejects subsequent seconds; this phase retains that boundary. Dynamic
DASH still revalidates after its bounded wait and uses the original deadline for
both manifest builds. An authenticated signing refresh can issue a new top-level
capability. Reading a child playlist cannot renew it.

Expiry governs admission of a delivery request. Cancelling bytes already admitted
before expiry, changing in-memory browser buffering or removing files when a URL
expires are separate policies and are outside this change. No persistence,
encoding, frontend or manifest-format redesign is required.

## Verification

First reproduce the current lifetime extension through real signing, manifest
controllers and streaming service using the existing adaptive-delivery fixture.
Require exact inherited expiries at every level, including repeated playlist
reads and subtitle resources. Use fixed explicit deadlines for deterministic
assertions; wall-clock sleeps are unnecessary for that regression. Cover valid
signatures, expiry tampering, past deadlines and domain-prefixed absolute signing.
Retain generation, track, index, ownership and authenticated-refresh regressions.

Extend the existing disposable production fixture with a bounded real-HTTP check:
obtain short-lived HLS and DASH capabilities, traverse their child resources while
valid, retain their exact URLs, then require refusal after their deadline. Obtain
fresh signed capabilities through the authenticated endpoint and prove delivery
recovers. Preserve the ordinary positive-frame/PCM and browser checks. Fixture
routers that also sign descendants must use the same absolute-expiry contract.
No test may silently skip expiry assertions if setup takes too long.
Issue these capabilities after publication readiness, fail if traversal misses
their deadline, and begin refusal assertions at `exp + 1` to retain the existing
integer-second boundary.

Run affected backend suites in the CI image, full PHPStan, Deptrac and OpenAPI
consistency. Run the production two-command browser acceptance against a rebuilt
worker, including the existing client-control phase. Preserve failed receipts and
verify cleanup. Production publication-format tests need rerunning if their
fixture router changes. Existing historical suite counts are not new evidence.

## Work and review

The lead owns production contracts, controllers, adapters, decorators, integration,
shared configuration and commits. One worker owns backend test updates and expiry
regressions. A second owns the production HTTP fixture and standalone publication
router adjustments. File ownership is agreed before implementation; workers never
stage or commit. Review this plan before implementation, simplify at phase
boundaries, and independently review the final diff before recording the actual
verification checkpoint in `ROADMAP.md`.

Document inherited expiry under the existing signed-delivery vocabulary. Native
pressure instrumentation, full live FFmpeg DASH EOF, formal CMAF and additional
browser platforms remain open separate items.

Independent plan review approved the scope before implementation. Fixed explicit
deadlines and the existing fake auth/sleeper cover deterministic expiry and DASH
wait behavior without a new production clock abstraction.

## Verification-discovered DASH duration repair

The reference browser rerun exposed a pre-existing completion race. Its final
native MPD declares a 20-second presentation, while the published audio timeline
ends at 20.010666667 seconds. A dash.js completion timer can fire in that gap,
seek to the declared end and pause; its end clamp then moves playback to 19.5
seconds without a native `ended` event. The failed and previously passing MPDs
have identical timing after signatures and dates are normalized. The failed run
and trace remain under `var/test-runs/signed-expiry-reference-browser`.

An isolated probe will reconcile completed Period duration with the last
sample boundary of the selected tracks. The
[DASH-IF timing model](https://dashif.org/Guidelines-TimingModel/) maps sample time
to presentation time using the Period start, presentation-time offset and track
timescale. For this full-published-tail policy, the completed Period covers the
maximum selected video/audio end. Remove the redundant MPD presentation-duration
attribute; clients derive the total from the preserved Period start and explicit
Period duration. This avoids a new duration parser. Calculate from the supplied
renditions and canonical audio, not only the primary presentation's tracks.
Preserve dynamic timing, empty output and media samples. Round the published
duration upward to microsecond precision so decimal formatting cannot truncate
the final sample boundary. This is a delivery policy correction, not a claim that
DASH forbids intentionally clipped periods or that this phase establishes formal
timing-model conformance.

If the probe confirms natural completion, apply this bounded generator correction
with failing-before-fix tests for unequal audio/video ends, video-only output,
presentation-time offsets and Period offsets. Keep the browser's unforced natural
EOF assertions intact. Rerun affected native comparisons, browser completion and
static gates, then qualify the final production image. This repair does not claim
to resolve the separate FFmpeg live DASH demuxer EOF limitation.

Independent review approved this bounded amendment before implementation, subject
to the isolated probe establishing natural completion.

## Verification checkpoint

Final backend evidence covers 281 distinct tests and 9,189 assertions. The combined
receipt selects the latest passing result for each case across the application,
firewall and native lanes. It includes the focused duration rerun. Full PHPStan,
Deptrac and OpenAPI consistency passed after the final code change; Deptrac retains
484 existing skipped violations with no new violations, warnings or errors.
Receipts and static logs are under `var/test-runs/signed-expiry-gates`.

The final production run passed all eight Chromium 148.0.7778.96 checkpoints in
`var/test-runs/signed-expiry-production-final`. Every one of 31 retained HLS URLs
and 27 DASH URLs preserved its parent expiry, rejected a changed expiry, and
returned 403 after the original deadline. Fresh authenticated signing restored
35 HLS and 27 DASH resources. The run also passed decoded video/audio delivery,
subtitles, rapid seeking, paused remount, web restart, worker containment/recovery
and retired-resource refusal. Exit status was zero and cleanup was verified.

The completed DASH duration repair passed its deterministic regressions and the
final reference browser run under `var/test-runs/signed-expiry-reference-browser-final`.
The browser decoded 480 frames with positive audio evidence and reached natural
EOF at 20.010666 seconds through the dynamic-to-static transition. The failed
pre-repair trace and successful isolated probe remain available. Final native
comparisons still match full HLS and the explicit 13-second live DASH prefix to
their completed baselines; full static DASH also decodes. Full live FFmpeg DASH
EOF remains unqualified.

Independent reviews approved the expiry implementation and the bounded duration
repair, including simplification and negative coverage. Documentation audits
preserve the previous roadmap history. No push or merge is included in this phase.
