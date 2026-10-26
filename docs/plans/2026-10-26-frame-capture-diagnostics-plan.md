---
title: Retain failed native frame-capture diagnostics
date: 2026-10-26
status: implemented
execution: code
---

# Retain failed native frame-capture diagnostics

## Demonstrated evidence gap

The selected roadmap follow-up is the sparse browser observer's missing failure
evidence. The retained `default-audio-fresh` run under
`var/test-runs/short-audio-tail-gates/browser-final-v3-default-audio-fresh/`
rejected snapshot sequence 607: the native frame timestamp was 24.64 seconds,
while the callback reported 24.60. All 608 constructed frames closed, no copies
remained pending and cleanup passed. The preceding successful samples and final
playback state do not establish when the failing samples diverged. The unchanged
passing rerun neither repairs this discrepancy nor proves its cause.

The observer already reads callback-entry and post-constructor clocks, but builds
the successful sample record only after its timestamp and crop checks. An early
failure retains only phase, sequence and error text. Closing the frame then loses
the metadata needed to distinguish delayed callback delivery from other causes.
The affected implementation is `transcode-adaptive/sparse-browser.mjs`, shared by
the sparse, delayed-audio, default-audio and short-audio scenarios; it is not the
two-command movie endpoint observer.

The [callback specification](https://wicg.github.io/video-rvfc/#introduction)
describes best-effort delivery, while the
[WebCodecs constructor](https://www.w3.org/TR/webcodecs/#dom-videoframe-videoframe)
uses the video element's current playback frame. This makes separate sampling a
plausible explanation, not a demonstrated cause of the retained failure.

## Scope and contracts

Retain a bounded, plain-data capture diagnostic before the unchanged timestamp
assertion. Include the callback timestamp and metadata, sequence, existing
entry/post-constructor performance and playback clocks, native timestamp in
microseconds and seconds, duration, dimensions and visible rectangle. Keep
unavailable fields explicit rather than inferring them from later samples.
Constructor failures retain the available entry and callback data; later crop or
copy failures retain the constructed-frame data and available copy-stage clocks.
Copy values from browser objects so the receipt survives frame closure and JSON
serialization. Do not retain a live frame in the error record.

Keep the existing one-microsecond timestamp comparison, error message and failure
status. Preserve the fifty-millisecond colour-transition tolerance, callback
registration order, four-frame ownership bound, ordered sample publication,
two-second copy drain, error-count bound and exactly-once closure. Do not add
pixel copying after a failed timestamp assertion, per-frame file I/O, an
unbounded history, timestamp replacement, sample dropping or capture retries.
Reuse existing clock reads; any diagnostic work adds observation cost and cannot
be represented as timing-neutral.

A small self-contained observer installer may be extracted to allow the actual
browser function to run under deterministic Node tests. Invoke it at the same
point in browser setup. Its browser environment must remain explicit, with no
production or user-facing configuration. Keep existing successful sample fields
and outer failure/drain persistence working. The runner copies the shared module
into its disposable browser workspace, and CI runs the focused lifecycle tests.

No production PHP, FFmpeg, player adapter, UI, source-clock or manifest changes
belong in this phase. The existing fixture scenarios and pinned browser flags
remain unchanged. Generated media and disposable containers supply test inputs;
existing services and original user videos remain untouched. This introduces no
new domain concept or shared vocabulary entry.

## Implementation and verification

Continue the established reviewed-plan workflow while the named compound skills
are unavailable. The lead owns this plan, runner/CI wiring, authoritative checks,
integration and local commits. One worker owns the capture observer module,
browser integration and focused tests; another independently reviews the plan,
implementation and evidence. Documentation may be delegated separately. Workers
do not stage or commit. Apply prose-fix to documentation and preserve historical
roadmap checkpoints. Merge and push require another request.

1. Review this plan before implementation. Preserve historical failure and rerun
   receipts, source revisions and version boundaries.
2. Exercise the actual installer with controlled callback/frame values. Require
   a 24.60/24.64 mismatch to remain fatal while its structured evidence survives
   serialization and its frame closes once. Cover constructor failure, copy
   rejection and successful matching captures completed out of order, including
   stop/drain ownership. Controlled values prove observer behavior only.
3. Verify unchanged comparison, scheduling, bounds and successful sample fields
   through independent code/simplification review. Run JS and shell syntax,
   focused Node tests and the adjacent cancellation-classification tests. Add
   the observer tests to the existing CI checks; do not repeat unaffected backend
   suites or the production movie endpoint drill.
4. Run exactly two ordinary `default-audio-fresh` attempts and one `sparse-seek`
   control, serially, using the existing disposable browser runner and its pinned
   dependencies. Bound each invocation to 240 seconds including setup and the
   existing browser deadline. Preserve every attempt's revision, image identity,
   versions, observer source, result and cleanup; do not rerun until green. An
   infrastructure failure may be diagnosed separately but is not a capture
   result. Do not induce load, sleep inside callbacks or change browser flags.
5. Summarize whether the mismatch recurred. If it recurs, inspect the newly
   retained clocks without treating correlation as proof; production repair or
   observer semantic changes need a separate reviewed scope. If it does not,
   record bounded negative evidence and retain the unresolved historical cause.
6. Record delivered diagnostics, exact test counts, every browser outcome and
   remaining uncertainty in `ROADMAP.md` and the testing guide. Complete final
   review and commit locally with a clean working tree.

Acceptance is reliable failure evidence and bounded diagnostic results. A real
reproduction is useful but is not required to deliver the instrumentation. A
passing run does not qualify universal capture stability, prove a capture race,
or resolve the historical mismatch. Missing future evidence alone does not
authorize indefinite reruns or unrelated roadmap work.

Independent review approved this plan before implementation. Lifecycle tests also
require detached serialized diagnostics and no successful publication of rejected
samples. Reserve cleanup time within each invocation's total budget; a timeout or
unverified cleanup is an infrastructure failure, not a capture result.

## Completion checkpoint

Implementation `54e3711c` retains detached failure diagnostics in the shared
observer installer. Eight new lifecycle tests and 55 adjacent cancellation tests
passed: 63 total, with no failures or skips. JS and shell syntax, CI YAML parsing
and whitespace checks passed. Independent implementation review found no required
changes. Production code and browser flags are unchanged.

The fixed three-attempt series used Chromium 148.0.7778.96, HLS.js 1.7.3,
Playwright 1.60.0 and the FFmpeg 5.1.9 CI image. The ordinary attempts completed
their browser checks in 39.381 and 39.562 seconds, reaching native end 30.015999
with 750 decoded frames each. Their observers constructed and closed 737 and 736
frames respectively. The sparse-seek control completed in 46.395 seconds,
reaching native end 24 with 24 decoded and captured frames, all closed. Whole
invocations took 48.501, 48.772 and 50.780 seconds, within the reserved budgets.

Each attempt retained one attachment and source change, no capture errors, no
pending copies and at most one outstanding copy. All three exited successfully
and verified container cleanup. Source hashes, image identities, revisions,
versions and individual outcomes remain under
`var/test-runs/frame-capture-diagnostics/`; `acceptance-summary.json` records the
combined checkpoint. Original service identities and user media were preserved.

The historical timestamp mismatch did not recur in these three attempts. The
controlled mismatch test proves that a rejected sample now retains its evidence
and closes its frame; it does not prove a browser race. The diagnostic delivery
is complete, while the historical cause remains unresolved. Further capture
investigation requires new failure evidence or a separately selected task; this
checkpoint does not authorize indefinite retries or a production repair.
