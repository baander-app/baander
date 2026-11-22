---
title: Movie departure and next-movie recovery
date: 2026-11-22
type: fix
status: completed
---

# Movie departure and next-movie recovery

## Goal Capsule

Determine whether leaving one encoded movie through Back prevents the next movie from starting. Capture both movies' startup outcomes and demonstrate authenticated cancellation and recovery in the isolated preview. If explicit Back leaves an owned producer running, retire that presentation before the runner confirms stop.

## Product Contract

- R1. Back on the browser that owns playback closes the movie after its owned presentation has retired. The next movie can start when the deployment has one video encoder slot.
- R2. A failed cancellation leaves the movie mounted and allows the same stop to be retried. Original or completed cached delivery can stop without a producer cancellation.
- R3. Departure respects endpoint and presentation ownership. Handoff continues to quiesce the local runner without retiring a presentation. A departure during admission waits for the request to settle and retires the session when the response supplies its identifier.
- R4. If the next movie fails to start, Back closes that failed player. Reopening the movie and choosing Play makes a fresh admission attempt.

## Planning Contract

The earlier [roadmap investigation](../../ROADMAP.md#playback-investigation-and-evidence-limits) measured renewed leases on the first movie but missed the second movie's final outcome. The current overlay sends a scoped stop before closing. The player hook quiesces and cleans up on stop, while presentation replacement calls the authenticated cancellation endpoint. Confirm the effect with two generated movies before changing the stop path.

Use the existing browser runner, fixture and authenticated Transcode cancellation. Preserve the coordinator's handling of remote and uncertain transfers. The scope is explicit Back and its stop acknowledgement. Page unload, server expiry policy and changes to admission capacity remain outside this plan.

The development controller has no shared preview slot override. For the one-slot check, pass `BAANDER_DEPLOYMENT_PROFILE` through the controller's `--environment-file` in a disposable checkout. Temporarily allow that variable through `WorkerDeployment` and set `VideoEncoderSlots` in `ImageProfile::DEFAULT_SETTINGS` so the worker manifest uses the same profile. Confirm both profiles before the run. Stop the disposable preview and restore the source when the check ends. If the preview cannot activate with matching profiles, record the one-slot gate as blocked.

Admission can allocate a session and then lose the response or return an error before the browser receives its identifier. The cancellation endpoint requires that identifier, and the account's session lists cannot distinguish another browser's admission for the same movie. Handling this case requires the server to reconcile admission and cancellation under the same Playback client-session identity. [Video playback controls](../../docs-book/part-2-developer-guide/development/video-playback-controls.md#audio-tracks-and-timing).

## Implementation Units

### U1. Capture departure, startup and recovery

Use two generated encoded movies in one fixture account. Keep the first source long enough for its producer to remain active after playback begins. Prove the first job owns the preview's only video encoder slot before Back. Observe requests without intercepting their timing. Record the second movie's mounted state, delivery result, retry state and native playback. If the first producer survives Back, use the fixture user's authenticated cancellation and observe the release before retrying the second movie. Preserve the browser outcome even when startup fails. Clean up the fixture's own rows and media.

### U2. Confirm retirement on explicit stop

If U1 confirms retained ownership, make the explicit stop path retire the owned presentation before cleanup and acknowledgement. Reuse the cancellation path used by audio and quality replacement. Drain in-flight allocation, prevent a superseded response from publishing playback, and keep the owned session available on cancellation failure. Keep quiesce usable by transfer and preparation. Add focused regression coverage for the stop race and retry.

The baseline also showed that after authenticated cancellation released the first slot, Back from the failed second movie acknowledged the first movie's cached stop. The failed preparation had already selected the second movie and then rejected before clearing the cache. Invalidate the cached observation when selecting the next movie so Back can close that runtime and Play can prepare it from a fresh mount. Verify the retry with the original stop hook and the runtime correction after an owner-authenticated cancellation.

### U3. Record acceptance

Check the focused player, runtime, overlay and coordinator behavior. Run the two-movie browser gate after the change, then record the observed results and any failed or blocked checks in [ROADMAP.md](../../ROADMAP.md). Retain the earlier observation as historical evidence.

## Verification Contract

- AE1. With the first encoded movie decoding frames and holding a claim, Back leads to an authenticated cancellation and the claim is released. The next movie mounts and decodes advancing frames within the browser test's startup deadline.
- AE2. If startup fails on the baseline, capture its final visible state and request outcome. Authenticated cancellation releases the first movie's claim, after which retry starts the second movie.
- AE3. If stop races allocation, retire the session identified in the response before acknowledging stop. If cancellation fails, retry targets that session again. Transfer quiesce keeps its existing behavior.
- AE4. With the first movie already stopped, a failed attempt to prepare the next movie clears the old stop observation. Back closes the failed player. After the slot is available, reopening the movie and choosing Play issues a new admission and decodes frames.
- Run the focused web tests, typecheck, touched-file lint and the isolated Playwright gate. Record separately any gate whose prerequisites prevent it from running.

## Definition of Done

The current departure behavior and the next movie's startup outcome are captured. Any confirmed explicit-stop defect is covered by focused tests and checked in the browser. Record the evidence and remaining scope in the roadmap.

The [roadmap checkpoint](../../ROADMAP.md#playback-investigation-and-evidence-limits) records the original refusal, authenticated recovery with the runtime correction, and first-attempt startup with both fixes. The browser gate uses `BAANDER_MOVIE_DEPARTURE_BASELINE=1` for the recovery run against the original stop hook.
