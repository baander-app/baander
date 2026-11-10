---
title: A browser gate that passes by where a server tick falls proves nothing
date: 2026-11-10
category: best-practices
module: Playback session coordination and the two-command browser checkpoint
problem_type: test_failure
component: testing_framework
related_components: [service_layer, frontend]
symptoms:
  - "The two-command drill's client-controls phase fails in Firefox after a page reload: Play is refused with 'Previous playback is unconfirmed' and no video mounts"
  - "The same failure occurs on the merge base, although both engines were recorded as passing four days earlier"
  - "The API response tail shows two session pause requests about 50 ms apart before the reload"
  - "About one unmodified Firefox run in six passes, with the same reload-to-click timing as the failing runs"
root_cause: async_timing
resolution_type: code_fix
severity: medium
tags: [two-command-drill, playback-session, firefox, race, relay-tick, request-replay, evidence, registration]
---

# A browser gate that passes by where a server tick falls proves nothing

## Problem

The two-command drill reloads the page while a movie is paused and then clicks Play at once. In Firefox the click was refused with "Previous playback is unconfirmed". The drill never retries and waits 30 seconds for a video element, so the failure looked like a permanent wedge.

It was a race. A reloaded tab registers a new endpoint incarnation, and `PlaybackStore::register` retires the old one. The release of the confirmed paused runner, though, ran only in the Snapshot operation, which the relay triggers on a one-second timer (`Timer::tick(1000, …)` in `src/Session/Infrastructure/Swoole/PlaybackCommandRelay.php`). The drill's click landed about 200 ms after registration. Unless the tick fell inside that window, the page still saw the old incarnation as the active runner and the web client refused the click through `hasUnconfirmedPlayback` without contacting the server.

Chromium on Linux hid the defect. It supports local video recovery (`supportsLocalVideoRecovery`), so the reloaded page called `recover-local` before registering and the runner was already released. Every other engine depends on the tick.

The fixes are pending on the branch `movie-playback-growing-presentations` (commit subjects "fix(session): release and settle a browser runner that has gone" and "test(player): make the two-command checkpoint independent of timing and cursor") and are not merged yet.

## Symptoms

- Firefox fails at the `client-controls` reload; Chromium passes the same phase.
- The failure reproduces on the merge base, so the branch under test did not cause it.
- The roadmap recorded both engines passing on 2026-11-06. One of six unmodified Firefox runs of the same drill passed, with a 733 ms reload-to-click time identical to the failing runs.
- The "Previous playback is unconfirmed" alert stays on screen after the runner is released, which adds to the impression of a wedge.

## What Didn't Work

- **Reading the API tail as a command log.** The tail showed two `POST` requests with action `pause` about 50 ms apart. This was recorded in the roadmap as "a second pause command, left unacknowledged by the reload, blocks the release". No second command exists. The second request is `PlaybackSessionCoordinator.refreshRequests` re-reading the result of the same request: same `requestId`, same `expectedRevision`. The first answer carries `requestResult.outcome: null`, the runner's acknowledgement advances the revision, and the client posts the same payload again to learn the outcome. The server treats it as a replay and creates no command. Chromium does the same.
- **Looking for an engine-specific sender.** The media-session handler, native `pause` forwarding, the coordinator's reconcile and the drill itself were all candidates. None of them sends anything here; native pause forwarding goes to the transcode position endpoint, not the session.
- **Treating the earlier pass as evidence the code had regressed.** Session and web code were unchanged between the recorded pass and the merge base.

## Solution

The diagnosis came from instrumenting the checkpoint (`ui/web/tests/e2e/two-command/browser.mjs`, which the drill reads from the host, so no image rebuild is needed) to capture session HTTP bodies and WebSocket frames with their `requestId`, `expectedRevision`, `revision` and `activeIncarnationId`, and running both engines:

| | Chromium 156 | Firefox 157 |
|---|---|---|
| After the reload | `recover-local` answers with no active runner before registering | no `recover-local` call |
| Registration answer | runner already released | still names the old incarnation |
| Release snapshot | not needed | arrived 710 ms after registration |

A bounded wait for `activeIncarnationId === null` before the click made Firefox pass all eight phases, which confirmed the race.

Two changes followed.

**Product.** The release of a confirmed paused or stopped browser runner is one check, `releaseGoneRunner` in `src/Session/Application/Service/PlaybackCoordinator.php`, called from the snapshot, from the endpoint registration in the same transaction that retires the old incarnation, and from a control and a transfer after their revision guards. The registration's own answer now shows the runner released.

**Gate.** The checkpoint records the first `session.snapshot` that answers this page's registration after the reload and asserts it before the unchanged, immediate Play:

```js
assert.equal(answer.activeIncarnationId, null,
  'The registration answer after a reload must already show the confirmed paused runner released')
```

The observed answer (revision, `activeIncarnationId`, milliseconds since registration) goes into the receipt as a `remount-registration` entry. At the final code the answer arrived 36 ms after registration in Firefox and 14 ms in Chromium, both with no active runner.

## Why This Works

The gate used to click and hope. It proved "Play works after a reload" only when a periodic server timer happened to fire inside a 200 ms window, and it reported the product as broken or sound depending on timer phase. Asserting the registration answer tests the state the click depends on, so a regression fails every run and a pass means the server released the runner in the registration itself.

## Prevention

- A repeated request with the same `requestId` in an API tail is a result read, not a second command. Record request identity (`requestId`, `expectedRevision`) in drill evidence so the two cannot be confused. The checkpoint's failure evidence now keeps each session request's action, outcome and observed state.
- An intermittent pass with identical timings in passing and failing runs points to a race against something periodic. Look for a timer before looking for a sender.
- A gate should assert the state its next action depends on. A click whose success depends on timing is not a proof.
- A recorded pass is one sample. When a gate fails on the unchanged base too, measure the pass rate before concluding that something regressed.
- One engine can mask a server-side defect through a client-side path. Here Chromium's local recovery releases the runner by itself, so its registration proof can pass without the server change. The Firefox run is the one that binds the proof; keep both engines in the gate.
- The same instrumentation found two more fragile steps in the drill. After a late reconnect the "reopen" click landed on the overlay's own Play button, which queued two play intents 66 ms apart. A fixed 35-second seek target could fall inside the slider thumb when the paused cursor stood near it. Decide between reopen and resume only once Play is enabled, and choose seek targets relative to the current cursor.

## Related

- `docs/solutions/best-practices/browser-font-checks-that-pass-without-proving-anything.md`: another browser check that passed without testing what it claimed.
- `docs/solutions/best-practices/pace-held-frame-fixtures-by-generated-frames.md`: a fixture whose pass depended on host speed.
- The instrumented timeline that settled it, for comparison when this recurs (Firefox, before the fix): control `pause` with `expectedRevision` 20 answers revision 21 with a command and outcome `null`; the acknowledgement answers revision 22; the same control, same `requestId` and `expectedRevision` 20, answers revision 22 with no command and outcome `paused`; reload; the registration answer at revision 22 still names the old incarnation; the release snapshot at revision 23 follows 710 ms later.
