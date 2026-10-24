---
title: Preserve movie subtitle selection across playback replacement
date: 2026-10-24
status: implemented
execution: code
---

# Preserve movie subtitle selection across playback replacement

## Consumer and evidence

The production movie overlay uses native video controls and the HLS attachment
adapter. Chromium and Firefox already expose three subtitle TextTracks and
activate the expected Dialogue cues in the last production acceptance runs.
The browser fixture selects Dialogue repeatedly while polling, including after
replacement, so those results do not establish selection continuity.

The player destroys its HLS instance on source seeks, publication changes and
signed capability renewal. The replacement adapter receives a playback position
but no subtitle preference. Installed HLS.js selects the manifest default for a
new controller. A viewer's nondefault track or Off choice can therefore disappear
without another caption gesture. Reproduce this against unchanged application
source before implementing the repair.

The backend already distinguishes subtitle tracks by absolute source stream key,
such as `s2`, in their signed playlist path and display-name suffix. Two English
tracks in the production fixture deliberately have different identities. Do not
identify a selection by language, transient playlist ordinal, signed query or
execution generation. Codec declarations need no parallel repair: current signed
HLS and DASH delivery uses the published native MPD's codec descriptions.

## Selection contract

Keep subtitle preference local to the active movie playback owner. Preserve the
selected source track or Off through attachment replacement for a seek, optional
rendition publication, capability renewal, recoverable delivery failure and
source-zero replay. Reset preference for a different movie or playback owner and
on final hook cleanup. This phase does not add cross-page or durable preference
storage. A page reload may use the manifest default again.

Leave initial selection to the existing manifest/browser defaults until a settled
selection is available. Observe actual native TextTrack selection changes, including
Off. Restore the remembered choice when replacement tracks become available,
without selecting a different duplicate-language track. If the selected track is
temporarily absent, keep captions off and retain its identity for later publication;
absence must not overwrite the choice with Off or another default. A later user
selection supersedes the remembered choice.

Attachment teardown, default initialization and programmatic restoration must not
be mistaken for a new viewer choice. Fence delayed callbacks against the current
attachment and owner. Release listeners with their attachment, including failed
imports and fatal errors, and preserve existing loading, cursor restoration,
control delivery and error semantics. Do not introduce a new caption control or
change backend APIs, manifests or storage schemas. Native HLS gets equivalent
lifecycle handling where TextTracks are exposed; Safari behavior remains
unqualified without that engine's acceptance evidence.

## Implementation and review

Use the previously authorized equivalent planning, implementation and independent
review workflow because the compound-engineering skills remain unavailable.
An independent reviewer must approve this plan before implementation. One worker
owns the attachment adapter, movie hook and their focused tests. A second owns the
production browser fixture. The lead owns integration, shared configuration,
documentation, authoritative checks and local commits. Workers do not stage or
commit, and preserve each other's changes.

Agree the attachment preference interface before parallel implementation. Keep the
repair in the active movie path and simplify it at the implementation boundary.
Review the final code independently and apply justified findings before final
verification. No merge or push is included.

## Verification

First demonstrate loss of a nondefault selection and Off against unchanged source.
Use meaningful adapter/hook tests for replacement, duplicate languages, delayed
track availability, stale teardown callbacks, explicit later choice and owner reset.
Mock the browser track lifecycle realistically; unit tests alone do not establish
browser behavior. Cover StrictMode and listener/resource cleanup with the existing
lifecycle suites where applicable.

Extend the existing production fixture to choose a nondefault track once, then
observe its exclusive showing mode and active expected cue after replacement.
Also select Off once and observe that all subtitle tracks stay disabled after
replacement. Reuse existing source seeks and paused/running renewal steps to cover
both track and Off preferences without adding unrelated controls or time budgets.
The assertion polling must only observe; it must not repair selection. Retain the
existing Dialogue cue checks with one explicit selection at the start of each
intentional Dialogue scenario, rather than setting modes on every poll.

Run Chromium and Firefox sequentially against the same verified worker image.
Require real current-generation media and existing clock/frame, ownership, outage,
completion/replay, signed delivery and cleanup assertions. Preserve all eight
phases, their deadlines and the 600-second outer limit. Save screenshots for
inspection and TextTrack/cue receipts; do not claim automated pixel-level caption
rendering or native-menu interaction from mode changes alone.

Run the four affected frontend suites, typechecking, lint, production build, asset
verification and browser/runner syntax checks in the existing CI runtime. Rebuild
the production worker image when application source changes. Backend behavior is
unchanged, so do not rerun unrelated PHP suites. Preserve any failed-run artifacts
and verify disposable cleanup. The user's original videos and existing application
databases, Redis and media storage remain untouched.

Record the delivered behavior, exact suite counts, browser evidence and remaining
limits in ROADMAP.md and the testing guide. Apply prose-fix to documentation and
commit the verified phase locally. Formal CMAF conformance, DASH subtitles,
Safari/native HLS qualification and unused native-reader experiments are outside
this phase.

Independent review approved this plan before implementation, including source-key
identity, owner resets, teardown/default-selection races and observation-only
browser checks.

## Progressive movie synchronization repair

The first repaired Chromium run passed all eight phases in 576.566 seconds.
Firefox passed every new subtitle continuity check, then failed the existing
progress-outage generation invariant. Its retained run under
`var/test-runs/movie-subtitle-selection-firefox` exited after 326.790 seconds with
verified cleanup. The current media clock reached 24.00566 at a buffer ending at
24 seconds; a subsequent playlist update was followed by a backward native seek
to 10.501. The hook submitted that movement as a source seek, advancing execution
generation from 6 to 7. No fixture pointer, keyboard or position mutation occurred
in this interval. This run is not final acceptance evidence.

Installed HLS.js synchronizes growing playlists as live streams. Its stream
controller can move backward to the live sync point when playback is outside the
current playlist window and the buffer is starving. A focused reproduction using
the actual installed library produces the exact 24.00566-to-10.501 movement. The
chosen synthetic playlist age reproduces the target; the production receipt does
not separately establish its internal age and stall-counter values. Retain this
probe and variants under `var/test-runs/movie-subtitle-selection-gates`.

Extend this phase narrowly to disable the optional latency controller for the
movie attachment through `latencyController: undefined`. The library preserves
this configuration, skips controller construction and returns null from its
[public live-sync position getter](https://hlsjs.video-dev.org/api-docs/hls.js.hls.livesyncposition).
The stream controller's null guard then prevents live-edge seeking. Movies retain
explicit source positions as their clock authority while their output grows.
The focused actual-library variant preserves the cursor and configured start
position. Do not patch private library methods, change manifests, reinterpret
native user gestures, remove the generation assertion or increase deadlines.

The application worker owns the adapter and a focused integration regression that
uses the real installed HLS.js synchronization behavior through the adapter's
configuration. First show the regression failing with the current adapter; a mock
configuration assertion alone is insufficient. Retain the existing hook controls,
subtitle checks, native fallback and cleanup. Independently review this addendum
before implementation and the final diff before acceptance. Repeat affected
frontend gates, rebuild the worker image, then run both full browser lifecycles
sequentially. Preserve the earlier Chromium pass and Firefox failure separately
from final acceptance and record this additional mechanism in ROADMAP.md.

Independent review approved the synchronization addendum before implementation,
including the optional-controller configuration, actual-library regression and
unchanged control and deadline assertions.

## Delivered verification checkpoint

The movie adapter retains subtitle selection by source key across replacement and
disables live-edge latency synchronization for growing movie output. Independent
review findings about owner reset, queued selection and track-event ordering were
applied before final acceptance. The unchanged adapter failed both initial
preference regressions, and the installed-library regression reproduced the
backward clock write before the synchronization repair.

Final CI-image verification passed 245 tests across five suites: 215 hook,
22 adapter, two actual-library, two import and four overlay tests. Typechecking,
production build, asset verification and browser/runner syntax passed. Lint
reported no errors and eight existing warnings outside the changed files.
Backend code and APIs were unchanged, so PHP suites were not rerun for this phase.

Chromium 148.0.7778.96 and Firefox 150.0.2 each passed all eight production phases
on worker image `ab35840b6b69`, built from application revision `5b592ec5`.
The runs took 579.154 and 577.856 seconds; completion/replay took 41.073 and
40.301 seconds. Both kept the original 600-second lifecycle and 60-second
completion bounds and verified cleanup. Each retained 22 subtitle observations,
including four before/after continuity proofs with no intervening reselection.
Forced remained exclusively showing with the expected cue; Off remained disabled
across two observations of the same replacement attachment. Existing progress,
status, seek, renewal, signed-delivery and completion assertions passed.

Final receipts are under `var/test-runs/movie-subtitle-selection-chromium-final`
and `var/test-runs/movie-subtitle-selection-firefox-final`; gate results and the
failed-run history are indexed in ROADMAP.md. Native-menu interaction and automated
caption-pixel comparison were not qualified. Safari/native HLS and the other
recorded transcoding gaps remain outside this checkpoint. Choosing Off while all
available tracks are already disabled has no observable TextTrack mode change;
an absent selected-track preference remains retained in that case.
