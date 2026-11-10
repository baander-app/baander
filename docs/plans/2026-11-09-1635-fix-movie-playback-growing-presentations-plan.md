---
title: Movie playback on growing presentations - Plan
type: fix
date: 2026-11-09
topic: movie-playback-growing-presentations
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-brainstorm
execution: code
---

# Movie playback on growing presentations - Plan

## Goal Capsule

**Objective:** A movie whose presentation is still being encoded plays through continuously at
the viewer's own cursor: the clock never moves toward the published window's leading edge,
player-internal seeks do not restart encoders, the overlay shows one seek bar, and a failed
playback transfer never wedges the movie.

**Product authority:** The user reported the defects from a live persistent instance: two
progress bars, playback cutting forward roughly every six seconds, and a player that froze and
would not recover without retries. The diagnosis below reproduces each defect from the
instance's own state and the worktree source; nothing in this plan is speculative.

**Means:** Correct the player's HLS attachment, the position the server reports to attaching
clients, transfer-failure settlement, the overlay control layout, stale-session lifecycle, and
the browser gate that passed while the defects existed (KTD1–KTD7).

**Execution profile:** One branch named after this plan with seven dependency-ordered units.
Playback is verified on a preview instance against the same source movie before merge; the
persistent instance receives the fixes only after master merges and the updater advances it.

**Authority and stop conditions:** Follow `AGENTS.md`, the Product Contract and then the
Planning Contract. Stop affected work if a change would weaken media access checks, session
ownership fencing, or the lead-throttle's producer bound. The two-command drill's existing
guarantees are preserved, not restated.

**Product Contract preservation:** Requirements and R/A/F/AE identifiers below are new for
this plan and govern it; no earlier plan's identifiers are changed.

---

## Product Contract

### Summary

Fix movie playback against growing presentations so the client's cursor stays authoritative
while encoders publish ahead of it, and so every failure path leaves the movie playable.

### Problem Frame

All evidence is from the persistent instance at `baander.test` on 2026-11-09, playing a
1920x1080 H.264/Opus Matroska music video (223.59 s) through the 1080p/720p/360p encode
ladder, and from the worktree source at master's revision.

- Segments are 6.0 s (`SegmentEncoder::SEGMENT_DURATION`) and the ladder encodes at roughly
  1.3x real time, so the published window's leading edge races ahead of the playhead.
- `ui/web/src/features/catalog/hooks/hls-playback.ts` constructs hls.js 1.7.4 with
  `latencyController: undefined` and a comment stating the published edge is not a live
  target. In hls.js 1.7.4 that undefined does disable the latency controller — an own key
  set to `undefined` overrides the default controller class through the config spread, and
  `createController` skips a falsy class — but the contract is implicit and unpinned: a
  hls.js upgrade that filters undefined keys or changes override semantics silently
  re-enables live-edge chasing, and `lowLatencyMode` stays enabled by default. The forward
  cuts come from a self-sustaining restart loop rather than the latency controller: every
  attachment replacement flushes the media element's buffer (`video.load()`), the player
  then reaches the freshly publishing edge, and the `useBaanderPlayer` `seeking` handler
  converts the resulting native seek into a server seek — which restarts the ladder
  encoders into a new execution generation and replaces the attachment again. Each cycle
  is a visible cut, and the loop runs at the segment cadence.
- After each jump the client reports the jumped position, and
  `TranscodeStreamingService::getPlaybackState` returns `desired->position`. A test attach
  an hour later was told the movie's position was 170.5 s, the drifted edge, and positioned
  its player there. That attach was not a fresh session: jobs are keyed by the client's
  request id, and a start that repeats the id gets its existing session back, with the
  cursor the client itself had reported. On the instance every session's cursor equals its
  execution's `desired->position` (ten of ten rows, 2026-11-10), because the only writers
  of that position are admission, which sets zero, and the client's own controls.
- `useBaanderPlayer`'s `seeking` handler enqueues a server seek whenever a native seek lands
  more than 50 ms off the session position. Every server seek restarts all ladder encoders
  into a new execution generation. One playback burned generations `0 → 18` in two minutes;
  three retry eras left 14 generation directories, every encoder stopped mid-segment, and
  eight accumulated sessions holding roughly 361 MB of output.
- A rejected playback transfer leaves the movie's snapshot with a stuck transfer state;
  `playback-session-coordinator` then returns early from later load intents with no error, no
  performance mark, and no video element. The wedge survives page reloads.
- `MoviePlayerOverlay` renders the movie volume slider and the seek slider in one flex row
  without constraining the volume slider; the shared `Slider` component defaults to
  `width: 100%`. Measured live: a 440 px disabled volume bar overlapping a 1248 px seek bar.
- `app:transcode:job:cleanup` removed only 1 of the 8 jobs because the stale sessions still
  reference the rest; no session-expiry path reclaims them. The eight held jobs are not
  dead: twenty hours after the client left, each still has a worker renewing its lease
  every 15 s with the encode parked at the lead throttle, so each also holds a transcode
  slot (instance state, 2026-11-10).
- The two-command browser gate passed while all of the above existed; its producer does not
  outrun playback far enough to expose edge-chasing.

### Key Decisions

- **The client's cursor is authoritative on growing presentations.** Governs R1–R3; the
  published window is a delivery window, never a playback target.
- **Failures settle instead of wedging.** Governs R4 and R6; every refusal is surfaced and
  every stale holder is reclaimable.
- **The gate must catch what the field caught.** Governs R7; an outrunning producer is now
  part of qualification.

### Requirements

**Continuous playback while encoding**

- R1. While a movie presentation grows during encoding, the player's clock advances only by
  playback and by user or app-committed seeks; it never moves toward the published window's
  leading edge.
- R2. The position a client receives when attaching, re-attaching or creating a session is
  that session's authoritative cursor — the client's requested start for a fresh session, or
  its last reported position thereafter — never the producer's published edge.
- R3. Player-internal seeks, including buffer-gap corrections and attachment restores, do not
  restart server encoders; only user or app-committed intents advance an execution
  generation.

**Recoverable failure and lifecycle**

- R4. A failed or superseded playback transfer settles the movie's playback state; a later
  load intent either runs or fails with a surfaced error. No refusal is silent and no wedge
  survives a page reload.
- R5. Stale transcode sessions — no client reports past a bounded age and no runnable jobs —
  stop holding jobs and output, so job cleanup can reclaim them.
- R6. The movie player overlay renders exactly one full-width seek bar; secondary controls
  such as volume are constrained and visually distinct from progress.

**Qualification**

- R7. The browser playback gate runs a producer that outruns playback and fails any run
  whose player clock advances without playback or a committed seek.

### Actors

- A1. The viewer plays, seeks and pauses a movie on a local or remote destination.
- A2. The server and worker pair encode, publish, fence and reclaim movie presentations.

### Key Flows

- F1. **Continuous playback while encoding.** A1 plays a movie; A2's ladder publishes ahead;
  A1's clock advances only by playback. Covers R1–R3.
- F2. **Retry after failure.** A1's playback fails or a transfer is rejected; A1 retries and
  either plays or sees the error; A2 reclaims stale holders. Covers R4, R5.
- F3. **Unambiguous controls.** A1 scrubs one seek bar; volume stays a bounded control.
  Covers R6.

### Acceptance Examples

- AE1. **Fresh play through the ladder.** Covers R1–R3. The reported source movie plays from
  zero through the encode ladder; the clock advances at wall speed; no execution generation
  advances without a user seek; one era of sessions and jobs serves the whole play.
- AE2. **Encoder outruns and throttles.** Covers R1, R3. The producer runs more than 72 s
  ahead and the lead throttle pauses it; playback continues into the already published
  window, waits at the leading edge without jumping, and resumes when the throttle resumes
  the producer.
- AE3. **Late attach inherits the cursor.** Covers R2. A second client attaches while
  playback stands at 60 s with the edge at 150 s; it is positioned at 60 s, or at its own
  requested start, never at 150 s.
- AE4. **Rejected transfer does not wedge.** Covers R4. A transfer is rejected or superseded;
  the next Play click on any client starts playback or surfaces the error; no reload or
  manual state clearing is required.
- AE5. **Stale holders are reclaimable.** Covers R5. After playback ends and the expiry bound
  passes, `app:transcode:job:cleanup` removes the era's jobs and output.
- AE6. **The gate fails edge-chasing.** Covers R1, R7. With a producer at 1.5x real time or
  faster, the gate fails any run whose clock moves beyond playback plus committed seeks.
- AE7. **One seek bar.** Covers R6. The overlay renders a single full-width slider; the
  volume control is bounded and visually distinct in the same row.

### Success Criteria

The reported movie plays start to finish on a preview instance with no uncommanded clock
movement, one seek bar, a single era of sessions and jobs per play intent, and a cleanup that
reclaims everything after playback ends. The existing two-command drill, adaptive-quality and
media-control gates keep passing.

### Scope Boundaries

- Included: the HLS attachment configuration, session position semantics, client seek
  classification, transfer settlement, overlay control layout, session expiry, and the
  browser gate extension.
- Excluded: adaptive-quality redesign, lead-throttle redesign, remote-destination semantics
  beyond transfer settlement, the copy-pacing band, the OpenTelemetry `host.docker.internal`
  resolution (a separate small fix), and the retained-redis tooling item already recorded in
  `ROADMAP.md`.

### Dependencies and Assumptions

- hls.js stays at 1.7.4; its controller and live-sync behavior is read from the installed
  `ui/web/node_modules/hls.js` typings and dist, not from memory.
- Encoder rates above 1x real time are normal for the ladder on this host; the gate must not
  depend on encoder speed.
- The persistent instance runs remote master; fixes reach it only after merge and update.
  Preview instances run the worktree, so previews verify first.

### Outstanding Questions

The configuration, position, seek-classification, transfer, layout, expiry and gate designs
are resolved in KTD1–KTD7.

### Sources and Research

- Instance evidence, 2026-11-09: transcode session list and shows (eight sessions, three
  eras), `/storage/conversions/01a1c0d7-cdc8-7ca6-987e-db088dc2ed94` (14 generation
  directories, all stopped mid-segment, seeks `0 → 18 → 96`), `generation.json` metadata
  (`startTime`, `hlsTargetDuration` 7), worker process table, and an instrumented browser
  session (performance marks, no-op load intents after a rejected transfer, a fresh session
  positioned at 170.5 s).
- [hls.js 1.7.4 API: `lowLatencyMode`, `latencyController`,
  `liveSyncDurationCount`](https://github.com/video-dev/hls.js/blob/v1.7.4/docs/api.md),
  verified against the installed package.
- [Media and playback qualification](../../docs-book/part-2-developer-guide/testing/media-and-playback.md)
  for the existing two-command, adaptive and media-control gates.

---

## Planning Contract

### Key Technical Decisions

- KTD1. **Pin the live-edge disable explicitly in the HLS attachment.** Keep the
  `latencyController: undefined` override — verified against the installed hls.js dist as a
  real disable, not a no-op — and make it explicit and regression-proof: set
  `lowLatencyMode: false`, state the spread-override mechanism in the comment, and pin the
  constructed configuration with a test that captures what is passed to the hls.js
  constructor, so a hls.js upgrade that re-enables chasing fails the suite. Keep the
  explicit `startPosition`. Governs R1. (chosen over raising `liveSyncDurationCount`: the
  disable is already effective; it needs pinning, not replacing. The cut loop itself is
  broken by KTD3.)

- KTD2. **The server already reports the session cursor; no server change.** Corrected
  2026-11-10 after reading the write paths and the instance's rows. Admission creates a
  fresh session's desired position at zero and never inherits another session's, since
  jobs are scoped to one client request id. The session cursor mirrors the execution's
  desired position on every control. The sign request carries no start position, so
  "requested start" is zero. The drift was fed entirely by the client's reported edge
  seeks, which KTD3 removes; a repeated start resuming its own live session at its last
  reported position is the intended resume. A first implementation of the original
  decision changed no behaviour and was discarded. Governs R2 together with KTD3 and, for
  sessions whose client has gone, KTD6.

- KTD3. **Classify seeks before they reach the server.** Only user or app-committed intents
  enqueue server seeks. The `seeking` event handler stops auto-enqueuing on native seeks:
  app-initiated restores are already tracked, buffer-gap and sync corrections are corrected
  by the next progress report, and an unexplained native seek surfaces in diagnostics rather
  than restarting encoders. Governs R3. (chosen over a drift tolerance: any tolerance still
  restarts encoders for a large enough correction.)

- KTD4. **Settle transfers and surface refusals.** A rejected or superseded playback transfer
  settles the movie's snapshot — the stuck transfer state is cleared with the same fence that
  recorded it — and the coordinator's early returns on unusable state report an error through
  the existing performance-mark and error paths instead of returning silently. Governs R4.

- KTD5. **Constrain secondary controls in the movie overlay.** The volume slider gets an
  explicit bounded width in `MoviePlayerOverlay`, matching how `NowPlayingBar` constrains its
  controls, so the seek slider is the only full-width slider. Governs R6.

- KTD6. **Expire stale sessions so cleanup can reclaim their jobs.** A transcode session
  that no playback report and no stream signing has touched for 24 hours, the longest
  lifetime of a signed stream URL, is stale unless its presentation is finished. Signing
  now touches the session, so a paused player, which sends no reports but renews its URL,
  never expires. Expiry runs at the start of job cleanup: it cancels the execution so the
  worker stops and frees its slot, ends the session and releases the job without
  cancelling it, which makes the job orphaned. Cleanup skips a job whose worker still
  holds a live lease and removes it on the next run. The original condition "no runnable
  job" was dropped (corrected 2026-11-10): an abandoned session's job stays runnable
  indefinitely, as the instance shows, so that condition could never be met. Sessions over
  a finished presentation stay, so the same client resumes without a new encode. Cleanup
  reports both counts. Governs R5.

- KTD7. **Gate edge-chasing with an outrunning producer.** Extend the existing browser
  playback checkpoint with a producer running at least 1.5x real time and an assertion that
  the player clock never advances beyond wall playback plus committed seeks, using the
  checkpoint's existing evidence receipts. Governs R7. (chosen over a dedicated new gate: the
  two-command checkpoint already owns growing-presentation playback proofs.)

### High-Level Technical Design

The client owns the clock; the server owns the fence.

```mermaid
flowchart TB
    play[Play intent] --> create[Session anchored at requested start]
    create --> attach[Attach at session cursor]
    attach --> play2[Playback advances clock]
    publish[Ladder publishes ahead] --> throttle[Lead throttle bounds producer]
    play2 --> report[Progress reports carry the cursor]
    report --> state[Session cursor stays authoritative]
    state --> reattach[Re-attach restores the same cursor]
    user[User seek] --> generation[New execution generation]
    play2 -.no path.-> generation
```

Attachments restore the session cursor; only user or app-committed seeks advance a
generation; the throttle bounds the producer; failed transfers settle; stale sessions expire.

### Alternatives and boundaries

- Raising `liveSyncDurationCount` or clamping the reported position were rejected in KTD1 and
  KTD2.
- Rebuilding the player around a non-live manifest format (for example, a periodically
  rewritten VOD playlist) is out of scope: it changes delivery semantics for every gate.
- Server-side seek suppression for native seeks was rejected in KTD3: the server cannot
  distinguish intent classes the client has not declared.

### Dependencies and implementation unknowns

- The exact hls.js 1.7.4 mechanism for neutralizing the latency controller (no-op class vs
  null) is confirmed from the dist source in U1 before the change lands.
- The session-expiry bound in KTD6 needs the existing session lease lifetimes read before a
  number is chosen.

### System-Wide Impact

Shared transcode application services, the web player, the session coordinator and the
browser qualification fixtures change. The persistent instance's updater delivers the fixes
after master merges. No schema change is expected; if session expiry needs a column, the
migration follows the repository's PostgreSQL rules.

### Research Constraints to Preserve

The two-command drill's existing proofs — authenticated playback, paused seek, restart,
containment, recovery, completion — must keep passing unchanged. The lead throttle's 72/36
second band and the copy-pacing burst stay as they are.

---

## Implementation Units

### U1. Disable live-edge chasing in the HLS attachment

**Goal:** The player never moves its clock toward the published edge.

**Requirements:** R1; KTD1. **Dependencies:** None.

**Files:** `ui/web/src/features/catalog/hooks/hls-playback.ts`; a new
`ui/web/src/features/catalog/hooks/__tests__/hls-playback.test.ts` capturing the constructor
configuration.

**Approach:** Keep the verified `latencyController: undefined` override, add
`lowLatencyMode: false`, document the spread-override mechanism in the comment, and keep
`startPosition` and the existing error and subtitle paths untouched.

**Test scenarios:** The captured configuration contains `latencyController: undefined` and
`lowLatencyMode: false`, and never a defined latency controller; playback attach, error and
dispose behavior is unchanged.

**Tests:** The new capture test; the existing player hook and protocol suites.

**Verification:** Focused frontend tests, then the U7 browser run.

### U2. Anchor and report the session cursor

**Goal:** Attaching clients receive the session cursor, not the published edge.

**Requirements:** R2; KTD2. **Dependencies:** None.

**Files:** `src/Transcode/Application/CommandHandler/CreateTranscodeSessionHandler.php`,
`src/Transcode/Infrastructure/Transcode/TranscodeStreamingService.php`, their tests, and the
signing controller tests that assert the response shape.

**Approach:** None; see the corrected KTD2. No file changes. The scenarios below already
hold on master and are covered by the existing admission and playback-control suites.

**Test scenarios:** A fresh session over a reused job with drifted desired state reports the
requested start; a refreshed session reports its last reported position; the worker fence and
revision semantics are unchanged.

**Tests:** Extend the transcode session and streaming service suites.

**Verification:** Focused backend tests and static gates.

### U3. Stop server seeks from player-internal seeks

**Goal:** Native seeks never restart encoders.

**Requirements:** R3; KTD3. **Dependencies:** U1.

**Files:** `ui/web/src/features/catalog/hooks/useBaanderPlayer.ts`; the existing player hook
tests.

**Approach:** Remove the auto-enqueue from the `seeking` event handler; keep app-initiated
restore tracking; let progress reports correct drift; record unexplained native seeks in
diagnostics.

**Test scenarios:** A native seek far off the cursor produces no server seek and no
generation change; a user slider commit still seeks; an app restore still restores.

**Tests:** Extend the player hook suite with simulated media events.

**Verification:** Focused frontend tests, then the U7 browser run.

### U4. Settle failed transfers and surface refusals

**Goal:** A rejected transfer never wedges the movie, and no refusal is silent.

**Requirements:** R4; KTD4. **Dependencies:** None.

**Files:** The server transfer endpoint and its handler in
`src/Transcode`/`src/Session`, and `ui/web/src/features/session/services/playback-session-coordinator.ts`;
their tests.

**Approach:** Settle the snapshot's transfer state with the failure's fence; make the
coordinator's early returns report through the existing error and mark paths.

**Test scenarios:** A superseded transfer clears the state; the next load intent runs or
errors visibly; the reload path recovers without manual clearing.

**Tests:** Extend the coordinator and transfer handler suites.

**Verification:** Focused frontend and backend tests.

### U5. Constrain the movie overlay's secondary controls

**Goal:** Exactly one full-width slider in the overlay.

**Requirements:** R6; KTD5. **Dependencies:** None.

**Files:** `ui/web/src/features/catalog/components/MoviePlayerOverlay.tsx`; a component
test.

**Approach:** Bound the volume slider's width as `NowPlayingBar` bounds its controls; leave
the seek slider the row's only full-width element.

**Test scenarios:** The rendered overlay contains one full-width slider; the volume control
is bounded; controls keep their labels and disabled states.

**Tests:** A new overlay component test; the existing catalog component suites.

**Verification:** Focused frontend tests.

### U6. Expire stale transcode sessions

**Goal:** Stale sessions release their jobs for cleanup.

**Requirements:** R5; KTD6. **Dependencies:** U2.

**Files:** A new `StaleTranscodeSessionExpiry` application service,
`CleanupOrphanedJobsHandler`, `TranscodeJobService`, `AuthorizedPlayback` (signing touches
the session), the cleanup endpoint and console command with the API specification and
operator page, and their tests.

**Approach:** Add the bounded expiry described in KTD6 after reading the existing lease
lifetimes; let the cleanup command treat jobs held only by expired sessions as orphaned.

**Test scenarios:** A session past the bound with a dead job is expired and its job removed;
a paused player inside the bound keeps its jobs.

**Tests:** Extend the session and cleanup suites.

**Verification:** Focused backend tests; the live instance's cleanup run after U7.

### U7. Gate edge-chasing and verify the whole fix

**Goal:** The gate that missed the defect now fails it, and the reported movie plays
through.

**Requirements:** R1–R7; KTD7. **Dependencies:** U1–U6.

**Files:** The two-command browser checkpoint and its fixture; the qualification runner
configuration.

**Approach:** Add the outrunning producer and the clock-bound assertion to the existing
checkpoint; run the full checkpoint and the existing two-command, adaptive and media-control
gates; verify the reported movie end to end on a preview instance with the diagnostic marks
from this diagnosis.

**Test scenarios:** The checkpoint with the defect reintroduced fails; with the fix it
passes; the existing gates keep passing.

**Tests:** The checkpoint's own receipts and assertions.

**Verification:** The browser gates on the CI image, plus one instrumented preview run
against the reported source movie recorded as the delivery evidence.
