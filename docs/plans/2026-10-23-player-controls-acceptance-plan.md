---
title: Production browser acceptance for rapid source seeking and paused remount
date: 2026-10-23
status: implemented
execution: code
---

# Production browser acceptance for rapid source seeking and paused remount

## Scope and evidence

The movie page now plays progressive HLS through MSE and exposes a full-source
slider. The existing optional browser control drill assigns native `currentTime`
without checking the published seekable window. Its targets can therefore clamp,
and it does not exercise the source slider. The runner also requires FFmpeg launch
instrumentation to select this browser phase, while the production image contract
forbids that instrumentation. The preceding delivery checkpoint consequently left
rapid scrubbing and paused remount unqualified.

This phase makes that existing workflow executable against the production image
and records its result. It retains the previously authorized equivalent planning,
independent review and local-commit workflow because the compound-engineering
skills are unavailable. Work stays local on this plan's branch. Existing databases,
Redis and media storage remain untouched.

## Contracts

Introduce `BAANDER_TEST_BROWSER_CLIENT_CONTROLS=1` to select the existing browser
phase independently of native process observation. Reject invalid flag values and
missing browser prerequisites before starting services. Enable the phase in the
production browser CI job. Preserve the ordinary immutable-image contract and the
separate saturated worker-control qualification.

Drive three committed positions through the actual source slider while withholding
the first real seek response within the client's timeout. Require exactly the
first and latest commands, predecessor acceptance as the successor generation,
the final source cursor, paused state and unchanged job/session ownership. Targets
need only fit the source duration; the slider supports unpublished positions.
Do not require a retiring attachment to survive a genuine terminal loader failure.
Record actual committed slider positions. Bound the total response hold, including
backend forwarding, below the five-second client timeout, and release the response
and remove interception in a `finally` block.

Reproduce the suspected race between a retiring attachment's fatal event and a
pending seek acknowledgement in a focused hook test before changing production
code. An accepted seek must still process the latest queued target and restore
the resulting generation. A genuine current-generation fatal failure must retain
its terminal behavior. Apply only fixes justified by failing regressions or the
production browser run.
Attachment teardown and pending control ownership are separate: a fatal event may
dispose the retiring loader without discarding the submitted chain. Also test a
rejected predecessor and an unmount or session change; neither may submit a stale
queued successor or recover the abandoned playback.

Capture a remount cursor only from decoded, seekable, paused media whose attachment
matches the current signed rendition set. Reload the actual movie page, reopen the
player, require the same playback ownership, generation and source cursor, then
resume and observe increasing decoded frames and playback time. Keep offline poll,
web restart, worker recovery, subtitles and retired-resource checks in the run.

Native instrumentation still recognizes the retired muxed-HLS output contract;
repairing or qualifying that separate pressure fixture is outside this phase.
Signed descendant expiry, native live DASH EOF, formal CMAF and additional browser
platforms remain separate open items.

## Implementation and verification

1. Review this plan independently before implementation. The lead owns runners, CI,
   documentation, integration and commits; one worker owns the hook and its tests,
   and one owns the browser checkpoint. Workers do not stage or commit.
2. Run failing-before-fix regression coverage for the suspected seek race. Review
   each change for unnecessary state or abstractions at the phase boundary.
3. Run the four player suites, typechecking, targeted ESLint, shell/browser syntax,
   production build and asset verification. Build the worker image if application
   assets change. Serialize container acceptance and preserve failed receipts.
4. Run the production two-command browser acceptance with client controls enabled.
   Require all eight phases, real signed HLS/DASH decode, rapid seeking, paused
   remount, recovery and verified cleanup. Retain command, browser and runner logs.
5. Independently review the final diff, apply justified findings, and record actual
   counts, evidence paths and remaining limits in `ROADMAP.md`. No push is planned.

No new vocabulary or backend persistence contract is introduced. Consult the
existing frontend rules and player ownership conventions when changing the hook.

Independent plan review approved this scope with the response deadline and control
ownership clarifications incorporated before implementation.

## Verification checkpoint

The production run passed all eight browser phases and verified cleanup under
`var/test-runs/player-controls-production-first`. Actual slider commits were
21, 25 and 29 seconds; only the first and latest reached the server. The latest
used generation 7 from its predecessor's acceptance and settled at generation 8.
Reload retained the same job, session, generation and paused source cursor
31.097109 seconds; resumed playback advanced from 4 to 37 decoded frames.

Both seek-ownership defects have failing-before-fix regressions. The final four
player suites passed 77 tests, including 66 hook tests. Typechecking, targeted
ESLint, production build, asset verification, shell/browser syntax, CI YAML and
runner preflight/cleanup checks passed. Logs are retained under
`var/test-runs/player-controls-gates`. Independent final review approved the
implementation and found no further simplification necessary. The roadmap records
this delivery and the remaining qualification boundaries.
