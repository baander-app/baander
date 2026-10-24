---
title: Preserve movie subtitle selection across playback replacement
date: 2026-10-24
status: reviewed
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
