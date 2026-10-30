---
title: Refine Footer Player - Plan
type: fix
date: 2026-10-30
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: code
---

# Refine Footer Player - Plan

## Goal Capsule

**Objective:** Make the footer read as one coherent music player, with track
identity, playback position and controls easy to follow at desktop and mobile
sizes.

**Means:** A responsive console with a grouped track display and the shared seek
slider (KTD1–KTD2).

**Authority:** The requirements below and `ui/DESIGN.md` govern the result;
`.agents/rules/frontend.md` governs implementation. Existing playback behavior
remains subject to R3–R5.

**Execution profile:** One implementation unit and one local change set. Follow
`AGENTS.md` for branch, local commits, review and roadmap checkpoints; the lead
owns integration and shipping. Stop for a material conflict with the preserved
playback contracts, not for routine layout choices.

## Product Contract

### Summary

Group the music footer's metadata and timeline into a restrained central display,
with transport and volume arranged around it. Adapt that grouping to a small
screen while retaining the current playback and recovery controls.

### Problem Frame

The timeline currently spans a separate row above artwork, metadata and controls.
The user experiences these as disconnected parts rather than one player. The
reference is a modern tribute to iTunes from 2008–2014, interpreted through
Baander's existing design language.

### Requirements

- R1. On desktop, present one console with transport on the left, a flat central
  card grouping artwork, title/artist and timeline, and volume on the right.
- R2. On mobile, place the central display above one row containing transport
  and volume; long metadata must not push controls outside the viewport.
- R3. Preserve previous/play/pause/next, seeking, volume, mute/unmute and keyboard
  behavior, with visible, accessible slider handles.
- R4. Keep elapsed time and duration readable, including the existing reconnecting
  and tap-to-resume behavior and its single recovery announcement.
- R5. Preserve radio priority and context-player exclusivity for active or paused
  tracks and active radio, with Queue, Lyrics and Details still available.
- R6. Use existing tokens and shared controls under `ui/DESIGN.md`; the central
  card is flat, without gradients or shadows, and does not adopt Apple Music's
  visual direction.
- R7. In the dark mood, retain a true-black base and distinguish footer, display
  and control layers with near-black surfaces suited to OLED screens; other moods
  retain their own surface colors.

### Scope Boundaries

Change the music footer composition and seek presentation. Preserve player stores,
streaming, recovery policy and the radio player's design. No new playback modes,
queue behavior, artwork extraction, token palette or animation system is needed.

## Planning Contract

### Key Technical Decisions

- KTD1. Keep `NowPlayingBar.tsx` as the composition boundary and group the central
  display with local responsive styling. This joins identity and position without
  changing player ownership. Governs R1–R2 and R6. (session-settled: user-directed
  — chosen over the detached full-width timeline to make the footer coherent.)
- KTD2. Replace `ProgressBar.tsx`'s bespoke track, hidden thumb and document mouse
  listeners with the existing shared Slider. Preserve the current time-tracker
  subscription and route user changes through the existing seek action. Bound
  values to the usable duration and prevent invalid seeking when no usable
  duration exists. Governs R3–R4.
- KTD3. Keep `PlaybackClock` and the single `PlaybackRecoveryAnnouncer` in the
  existing music-player lifetime. Size the time/recovery area for text and the
  resume button as well as digits. Do not move clock ticks into the parent footer
  or persistent state. Governs R4–R5.
- KTD4. Use background for the black base, card for the footer, muted for the
  central display and secondary for the play button. The existing dark tokens
  provide the required levels without palette edits; other moods use their own
  values for those roles. Governs R6–R7.

### Layout

| Viewport | Composition |
| --- | --- |
| Desktop | Transport · central artwork/metadata/timeline card · volume |
| Mobile | Central card, then one transport/volume row |

Choose the breakpoint from measured fit at the verification widths. Truncate long
metadata within its own flexible region; controls and recovery actions must remain
reachable. The existing 8-pixel control and 12-pixel card radii remain authoritative.

### Grounding and Risks

`NowPlayingBar.tsx` currently places `ProgressBar` above `PlayerContent` and gives
duration a second location beside volume. Consolidate the time presentation in
the central display. `ProgressBar.tsx` currently supplies slider semantics on a
mouse-driven div; shared `ui/slider.tsx` supplies the visible thumb and keyboard
behavior used by the repaired volume control.

The main risks are long recovery text compressing the timeline, programmatic clock
updates accidentally issuing seeks, and lost pointer/keyboard behavior during the
slider replacement. Keep progress updates observational and verify user-triggered
seeks separately. Retain the context panel's conditional player rendering.

Apple's [2012 iTunes announcement](https://www.apple.com/ie/newsroom/2012/09/12Apple-Unveils-New-iTunes/)
provides historical context for a compact player that keeps music prominent. It
is not a screenshot source or a specification for Baander's layout. No external
component library or playback mechanism is introduced.

## Implementation Units

### U1. Integrate the footer display and seek control

**Goal:** Deliver R1–R7 through KTD1–KTD4 as one cohesive, verified change.

**Dependencies:** Existing generated theme tokens, shared Slider, player actions
and the reviewed styling repairs; no new service or schema dependency.

**Files:** `ui/web/src/features/player/components/NowPlayingBar.tsx`,
`ui/web/src/features/player/components/ProgressBar.tsx`,
`ui/web/src/features/player/components/__tests__/PlaybackRecoveryStatus.test.tsx`,
`ui/web/tests/e2e/styling/browsing-playback.spec.ts`, `ui/DESIGN.md` and `ROADMAP.md`.
A focused progress-control test may be added beside the existing recovery test
if its behavior cannot be covered clearly there.

**Approach:** Recompose the existing controls and central display, then replace
only the seek presentation using the shared Slider. Preserve the volume component,
radio priority, clock ownership and context panel boundary. Update the design
contract for the footer grouping and record actual verification in the roadmap.

**Test scenarios:**

1. At 390, 768 and 1280 pixels in all four moods, long title/artist/album text
   remains contained; metadata, artwork, elapsed/duration and all controls are
   visible and usable. Review screenshots against R1–R2 and R6–R7, including
   distinct black/near-black layers and readable controls in the dark mood.
2. The seek thumb is visible and labeled. Pointer clicking/dragging and keyboard
   arrows/endpoints seek within the duration; ordinary clock updates issue no seek.
   A missing or zero duration cannot produce an invalid seek.
3. Elapsed time advances during playback and remains consistent after seeking.
   Reconnecting keeps the frozen position, one polite announcement and the existing
   message; tap-to-resume remains keyboard reachable and resumes playback.
4. Volume retains its 0–100 range, step one, visible thumb, keyboard and pointer
   behavior, and unmuting when raised. Previous/play/pause/next remain usable.
5. Active and paused music expose one transport/seek control with Queue reachable;
   radio start/stop retains priority and never exposes a duplicate context player.

**Verification:** The focused unit and browser checks pass, screenshots are
reviewed, and no preserved behavior in R3–R5 changes. Follow the repository's
simplification and code-review workflow before recording completion.

## Verification Contract

Use the isolated preview from `make preview-up`, passing its URL explicitly as
`E2E_BASE_URL` under the browser-test guide. Retain the deterministic catalog GET
fixtures and local silent WAV; authenticate through the real login flow without
scanning libraries or injecting authentication state.

Run the affected recovery/progress unit tests and the footer, volume, playback and
radio cases in `ui/web/tests/e2e/styling/browsing-playback.spec.ts`. Check the shared
seek interaction in Chromium, Firefox and WebKit, including keyboard access and
visible thumb geometry. Run scoped ESLint, both TypeScript configurations, the
production build and asset verification in the CI image. Apply prose audits to
changed documentation and run `git diff --check`.

Record actual counts, browser versions, screenshots and any unverified boundary
in `ROADMAP.md`; focused footer checks do not imply whole-application or native
media qualification.

## Definition of Done

The footer satisfies R1–R7 at the stated widths and moods. The seek replacement
passes interaction and recovery checks, existing volume/radio/context regressions
remain green, screenshots have been visually reviewed, and required static/build
and documentation checks pass. No launch-blocking planning question remains;
exact breakpoint and spacing choices are resolved against the existing tokens
and measured fit during implementation.
