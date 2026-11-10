---
title: A seek to the end of a finished HLS presentation never completes on a fresh attachment
date: 2026-11-10
category: runtime-errors
module: Web video player (useBaanderPlayer, hls.js attachment)
problem_type: runtime_error
component: frontend
symptoms:
  - "Play on a finished movie hangs on any player that did not itself reach the end: a new tab, a reloaded page, or after Stop. The prepare command expires after 20 s with 'The playback transfer failed or was superseded.'"
  - "The video element stays at seeking true, readyState 1, with currentTime equal to its duration (24) and buffered [[0, 5.93], [23.936, 23.999999]]"
  - "The movie-endpoint browser gate fails at its exact-end seek: 'Timed out waiting for the accepted endpoint seek to 42 to restore real media'"
  - "A native seek 86 ms before the end of the video also stays seeking, although the position lies inside the buffered range"
root_cause: async_timing
resolution_type: code_fix
severity: high
tags: [hls-js, media-source, end-of-stream, seek, restore, replay, alternate-audio, web-player]
---

# A seek to the end of a finished HLS presentation never completes on a fresh attachment

## Problem

The player restores a session's cursor on every new attachment. When the cursor stands at the source end, as it does after a movie has finished, the restore sought the element straight to its duration as soon as metadata loaded. That seek never completed. The session's seek control waited for it, the transfer's prepare command expired, and every later prepare waited on the same stuck restore.

Only the player that had reached the end by playing could replay, because it never had to restore. The fix is pending on the branch `movie-playback-growing-presentations` (commit subject "fix(player): restore into a presentation's last second through a landing") and is not merged yet.

## Symptoms

- After a movie finishes, a reload followed by Play shows "Preparing movie playback…" and then "The playback transfer failed or was superseded."
- The element reports `seeking: true`, `readyState: 1`, `currentTime` equal to `duration`, and a buffered range that ends one microsecond short of the duration.
- The movie-endpoint gate (`scripts/test-movie-endpoint-browser.sh`) failed at its exact-end seek from 2026-11-06 on. At first the session's seek control stayed unacknowledged until a stop superseded it 20 seconds later; with a settle workaround in place it failed later, at "Timed out waiting for the accepted endpoint seek to 42 to restore real media".
- A seek to a position slightly before the end hangs the same way, which makes "seek target past the last sample" look like the cause. It is not.

## What Didn't Work

- **Suspecting the publication.** The buffered range ended at 23.999999 against a duration of 24, so the last fragment looked short. The publication was complete: both end-list fragments were buffered and hls.js's `_streamEnded()` returned true.
- **Settling the restore after one second anyway.** This let the session-level seek complete, so the prepare no longer expired, but the element stayed in `seeking`. The gate requires an element that has finished seeking and rejected the result, correctly.
- **Probing with a seek inside the buffered range.** A seek to 19.5 of 19.59 also stayed `seeking`, which seemed to rule out the end position as the trigger. It lay inside the frames that do not decode until the stream has ended, so it showed the same deadlock from another side.

## Solution

Measured on a preview with the hls.js instance and the `MediaSource` exposed (hls.js 1.7.4, Chromium):

1. Every attachment creates a new hls.js instance. Its alternate-audio track starts in the "switching" state, and the switch completes only once the element has `readyState > 1` and is not seeking.
2. Until that switch completes, neither stream controller signals end of stream. In the stuck state `_streamEnded()` was true and `altAudio` was still 1; the `MediaSource` stayed `open`.
3. Until the `MediaSource` has ended, the last frames do not decode. A seek to 19.5 stayed `seeking`; a seek to 19.3 completed in under 50 ms. That 19.3 seek let the switch complete, the `MediaSource` ended 100 ms later, and seeks to 19.58 and to the duration then completed at once with `readyState` 4 and `ended` true.

Seeking to the end at `loadedmetadata` therefore deadlocks: the element waits for frames that need the end of stream, and hls.js waits for the element to settle before it ends the stream.

A restore whose target lies in the last second of a session's presentation now goes there in two steps (`tailLanding` and `approachTail` in `ui/web/src/features/catalog/hooks/useBaanderPlayer.ts`):

```ts
const SOURCE_END_APPROACH = 1        // seconds before the end where the restore lands first
const SOURCE_END_DWELL_MS = 250      // hls.js looks for a playing fragment on a 100 ms tick
const SOURCE_END_SEEK_GRACE_MS = 1000 // backstop when the landing or the end seek never settles
```

- hls.js starts loading at the landing instead of at fragment 0.
- The element seeks to the landing, stays there for the dwell so hls.js's tick sees a settled element, and then seeks to the real target.
- Playback is held until the restore settles, so a playing restore does not play from the landing.
- The reported position stays the source end. A paused session at the end stays there, and Play at the end replays from zero.

The landing covers the whole last second, not only the exact end. In the first gate run with the fix, a capability renewal replaced the attachment 0.117 s before the end and restored into the same undecodable tail.

## Why This Works

The landing is a position hls.js can decode without the stream having ended. Once the element settles there, the audio switch completes, both controllers signal end of stream, the `MediaSource` ends, and the tail decodes. The second seek then targets frames that exist.

The dwell is timing, not a signal. The hook cannot see hls.js's end-of-stream, so it waits long enough for two of hls.js's ticks. The one-second grace remains for two cases the dwell cannot cover: a hidden tab whose throttled timers stretch the dwell, and a presentation shorter than one second, which has no landing that decodes. In the gate and on the preview the grace did not fire.

## Prevention

- Do not seek a fresh hls.js attachment into the tail of a finished presentation in one step. Settle the element on an earlier decodable position first.
- When a native seek hangs, read `MediaSource.readyState` and hls.js's stream-ended state before reasoning about buffered ranges. A buffered range that includes the target does not mean the frames decode.
- A workaround that completes the application-level seek while the element is still `seeking` hides the defect from the session and leaves it in the element. Check the element's own state in the proof.
- `ui/web/src/features/catalog/hooks/hls-playback.ts` could report end of stream to the hook. With that signal the dwell and the grace could both go.
- The tests `restores a paused cursor at the source end by landing before the end and then seeking to it` and `restores a playing cursor in the last second through the landing, without playing from it` in `useBaanderPlayer.test.ts` pin the two steps with a simulated element. Only a real browser reproduces the deadlock: the movie-endpoint gate is the proof.

## Related

- `docs/solutions/best-practices/pace-held-frame-fixtures-by-generated-frames.md`: another case where a finished playlist changed what the player could do.
- Receipts: `var/test-runs/final-movie-endpoint/browser/failure.json` (failing, element stuck at 24 of 24) and `var/test-runs/endseek-2/browser/movie-endpoint.json` (passing: element at 24 of 24, `ended` true, `readyState` 4, buffered `[18, 24]`).
