---
title: Qualify Firefox movie playback through the production deployment
date: 2026-10-24
status: reviewed
execution: code
---

# Qualify Firefox movie playback through the production deployment

## Consumer and evidence

The movie player selects HLS.js through Media Source Extensions when supported,
then falls back to native HLS. Its production browser fixture launches only
Chromium. The fixture requires native HLS capability before authentication but
later requires a blob-backed MSE attachment. The native requirement does not
describe the path under test and excludes Firefox despite its MSE implementation.
No existing evidence qualifies Firefox movie playback through authentication,
worker-produced media and control recovery.

The pinned Playwright image includes Firefox. First measure its H.264/AAC MSE
capabilities without network access or feature overrides. The isolated probe found
Firefox 150.0.2 reports H.264/AAC MSE support and no native HLS support; capability
reporting alone does not establish successful decoding. Native Safari requires its actual
media stack and remains outside this phase. The current web player does not
consume DASH, so historical full live FFmpeg DASH EOF is not selected here.

Continue the previously authorized equivalent planning, independent review and
local-commit workflow because the compound-engineering skills remain unavailable.
The branch is named after this plan. No merge or push is included.

## Scope and acceptance

Allow the existing production browser checkpoint to select `chromium` or
`firefox`, with Chromium remaining the default. Reject unknown engine values
before creating test resources. Pass the selected engine explicitly into the
browser container and retain its actual version in success and failure receipts.
If launch fails before a version is available, retain the requested engine and
launch error instead. Validate engine selection in the outer deployment runner,
before it creates any resources, as well as at the browser entrypoint.
Keep Chromium's launch options; launch Firefox without Chromium-only options.
Any output muting must leave playback, codecs and autoplay semantics unchanged.

Replace the native-HLS-only precondition with a capability check for the actual
H.264/AAC MSE path. Record native HLS separately as evidence. Continue requiring
the product's MSE attachment, delivered current-generation audio/video, decoded
frames, an advancing clock and real seekable ranges. Do not substitute a fixture
player, forced feature support, synthetic events or mocked media for the product.

Run all eight existing phases for Firefox: authentication, attachment, client
controls, paused seek, web restart, worker containment, worker recovery and
completion. Preserve subtitle identity/cue assertions, capability renewal,
progress/status/seek delivery-loss proofs, rapid first/latest seek coalescing,
pause/resume ordering, offline recovery, remount and old-generation refusal.
The browser event-order observers must retain their causal assertions in Firefox.
Retain the existing phase deadlines and the 600-second lifecycle deadline.

First isolate failures to the environment, fixture or application. Preserve failed
receipts and demonstrate the cause before changing behavior. A fixture portability
repair must preserve the checked behavior; a discovered application defect needs
a focused failing regression and independent review before implementation.
Unavailable codecs or an unsupported environment are failures to qualify, not
permission to skip media assertions or silently select a different engine.

Add a blocking Firefox run to the existing CI workflow after the Chromium run,
using the same built worker image and distinct artifact directories. Execute the
two local acceptance runs sequentially to avoid contention. Preserve disposable
service ownership and verified cleanup; do not reuse or reset existing application
databases, Redis or media storage.

## Ownership and verification

One worker owns the browser fixture. The lead owns shell runner propagation, CI,
this plan, integration and local commits. A second worker independently reviews
the plan and final changes. A documentation worker may update the testing guide
and ROADMAP.md once the execution contract and receipts are available. Workers
do not stage or commit and must preserve one another's changes.

Retain a bounded browser probe proving why the old native-only gate is wrong for
the selected MSE path. Check engine-selection failure behavior, JavaScript and
shell syntax, CI configuration and whitespace. Run the existing relevant player
suites if application code changes; broader static/build gates follow the actual
change. Reuse the already tested worker image only after proving its application
source and built assets match this branch. Rebuild if application inputs change.

Run the final fixture in both Chromium and Firefox with all client controls
enabled. Record actual engine/version, image identity and source revision, phase
results, media/control evidence, failed attempts and cleanup. Apply prose-fix to
changed documentation, record the acceptance boundary in ROADMAP.md and commit
the completed work locally. Firefox acceptance does not qualify Safari, native
HLS, formal CMAF conformance, DASH subtitles or unrestricted live FFmpeg DASH EOF.

Independent plan review approved the active-consumer scope, unchanged media and
control assertions, outer-runner validation, startup-failure evidence and explicit
Safari boundary before implementation.
