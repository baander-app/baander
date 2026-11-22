---
title: Settle stale playback control retries
date: 2026-11-22
type: fix
status: active
---

# Settle stale playback control retries

## Goal Capsule

A browser can refuse every new playback control after a lost response, even after the server rejects the retry as stale. Let the next intent through when the server can establish that the old control is absent from the request ledger and can no longer apply. [Roadmap](../../ROADMAP.md#playback-destinations-and-notifications).

## Product Contract

- R1. A stale, unrecorded control no longer blocks the next playback intent. The browser still reports the server's refusal.
- R2. A recorded request still uses its recorded outcome. A retry whose outcome remains unknown, including endpoint authority or transport failures, retains the pending control and its request identity.

## Planning Contract

`PlaybackCoordinator` checks endpoint authority before reading the request ledger. It checks for an exact replay under the session lock before checking the requested revision. Consequently, a generic HTTP 409 cannot establish whether a request ran. The shared conflict envelope uses `details.reason` with the value `playback_control_stale_revision` when the control is absent from the ledger and its expected revision precedes the revision seen when the server locked the session. This comparison excludes revision changes calculated during the rejected transaction.

`PlaybackSessionCoordinator.refreshRequests` will clear a pending request on that specific conflict. A replay that returns a recorded command still follows `completeRequest`. The existing endpoint and initial-request handling remain in force. Keep the existing HTTP status and refusal messages.

## Implementation Units

### U1. Mark a stale control and settle its retry

Add the structured reason to stale control conflicts in `src/Session/Application/Service/PlaybackCoordinator.php`, after the existing replay check. Update `ui/web/src/features/session/services/playback-session-coordinator.ts` to clear the matching pending retry when that reason arrives. Add regressions to the existing backend control-delivery and web coordinator suites.

### U2. Record verification

Record the behavior and check counts under Playback destinations and notifications in [ROADMAP.md](../../ROADMAP.md).

## Verification Contract

- AE1. A lost control response followed by a stale-revision refusal clears the pending request. The next intent reaches the server under a new request ID.
- AE2. A retry refused before replay leaves the original pending and keeps its request identity for the next valid replay. The caller still sees the refusal message.
- AE3. Exact replay still answers a previously recorded control at an older revision. A revision conflict caused solely by work in the current transaction does not carry the terminal reason.
- AE4. A live transfer still supplies the transfer refusal message. Its conflict includes the terminal reason only if the request is absent and its revision is already stale.
- Run the focused backend and web Session suites, web typecheck and touched lint. Run PHP syntax and diff checks for the changed files.

## Definition of Done

The refusal and authority cases are covered at the coordinator boundary, with evidence recorded in the roadmap.
