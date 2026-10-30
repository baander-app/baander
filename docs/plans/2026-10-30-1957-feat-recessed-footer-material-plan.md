---
title: Recessed Footer Material - Plan
date: 2026-10-30
type: feat
topic: recessed-footer-material
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-brainstorm
execution: code
---

# Recessed Footer Material - Plan

## Goal Capsule

- **Objective:** Give the music footer a subtle sense of physical depth that establishes a coherent material direction for Baander.
- **Means:** Use the selected recessed-display finish as the first application of the evolving Aqua-and-flat design language.
- **Product authority:** The user selected prototype A, then confirmed the scope: the web footer centerpiece and shared design guidance, with other surfaces and native-device redesigns deferred.
- **Open blockers:** None.
- **Execution profile:** Lightweight, two dependent units, one reviewable change on a branch named for this plan. The implementing agent owns integration, checks and local commits under `AGENTS.md`; pushing requires the user's instruction.
- **Authority:** R1–R8 govern the product result; the Planning Contract governs implementation within that scope. Stop for clarification if reproducing the chosen finish requires changing player behavior or expanding to another surface.

## Product Contract

### Summary

Apply the recessed-display treatment to the web music footer centerpiece and document its place in Baander's shared design language.
The finish will establish a restrained material reference for later work while retaining the current player arrangement and behavior.

### Problem Frame

The current design guide mandates a flat centerpiece, which prevents the subtle physical depth requested for the music footer.
The new direction needs an explicit, bounded rule so later contributors can use the same visual language without treating it as permission to decorate every surface.

### Key Decisions

- **Recessed display A is the visual reference.** (session-settled: user-directed — chosen over Soft glass B and Grazing light C: visual preference after browser comparison.) Governs R1, R2.
- **Start with one surface and its design guidance.** The user confirmed this bounded scope before broader adoption. Governs R5, R6.
- **Device-specific interpretation remains fundamental.** The user explicitly directed that desktop and TV serve their respective devices. Governs R7, R8.

### Requirements

**Material and appearance**

- R1. The web music footer centerpiece must use a shallow recessed face with a faint lower edge, matching the selected A reference at normal viewing size.
- R2. The material effect must remain subtle and localized to the centerpiece, with track information and playback controls visually dominant.
- R3. The dark mood must retain its true-black page background and distinct near-black footer and centerpiece levels; other moods must retain their own palettes with an equally restrained material treatment.
- R4. Text, timeline, slider handles and interaction states must remain legible under the material treatment and meet the existing accessibility requirements in `ui/DESIGN.md`.

**Player continuity and shared guidance**

- R5. The treatment must preserve the current footer arrangement, responsive behavior and playback interactions, including metadata containment, visible seek and volume handles, keyboard access and recovery messages.
- R6. Shared design guidance must replace the conflicting flat-only centerpiece rule with a bounded material treatment and describe how it supports Baander's gradual mix of Aqua and flat design.
- R7. The updated guidance must retain the Apple 2008–2014 HIG principles already adopted in `ui/DESIGN.md` as its interaction foundation.
- R8. Shared guidance must distinguish the visual language from device implementations, so desktop, touch and TV layouts and controls suit their own input models and viewing conditions.

### Acceptance Examples

- AE1. **Covers R1, R2.** With a track loaded at a normal desktop viewport, the centerpiece reads as a shallow inset with a restrained lower edge; its title and playback information attract attention before the material effect.
- AE2. **Covers R3, R4.** Switching between black and light appearances preserves their respective palettes, keeps text and slider handles readable, and retains subtle depth without lifting the dark page background away from true black.
- AE3. **Covers R4, R5.** A long track title at a narrow viewport stays inside its region while transport and volume remain reachable; keyboard seeking and visible focus continue to work.
- AE4. **Covers R6–R8.** A contributor can read the revised guidance and distinguish the permitted centerpiece treatment from unchanged surface rules, while understanding that a TV design must not inherit the desktop footer arrangement merely for visual consistency.

### Scope Boundaries

- The first application is the web music footer centerpiece. Radio-player redesign, other application surfaces and native-device implementations are deferred.
- This work does not redesign playback behavior, equalizer visualizations or the relationship between the footer and context player.
- The prototype's comparison controls and demonstration playback are not product features.
- The existing design guide remains authoritative outside the changes required by R6–R8; the new direction is not blanket permission to decorate other surfaces.

### Sources and Research

- `ui/DESIGN.md:21` establishes the existing HIG reference era. Its footer and surface rules at lines 43–62 currently require flat surfaces; R6 deliberately supersedes the conflicting centerpiece restriction.
- `ui/web/src/features/player/components/NowPlayingBar.tsx:133` groups artwork, metadata and the progress display between transport and volume. Its responsive arrangement and the timing/seek behavior in `ui/web/src/features/player/components/ProgressBar.tsx` provide the baseline for R5.
- `ui/rn/src/features/desktop/components/DesktopNowPlayingBar.tsx` and `ui/rn/src/features/tv/components/TVAppShell.tsx` establish separate device implementations. Their existence does not imply that every native playback presentation is wired or complete.
- The browser comparison and choice are retained under `/home/martin/dev/baander/.context/compound-engineering/ce-prototype/2026-10-30-footer-material`: `decisions.md` records the choice, and `01-material-finish/screens/index.html` contains the selected A state alongside B and C. This is a machine-local, best-effort reference while the original checkout exists. A missing prototype is not a blocker; this artifact's requirements govern any disagreement.
- The three prototype variants were visually inspected in Chromium at 1440 × 1000 in black and light appearances. A 390-pixel viewport check found no horizontal overflow, and comparison controls, transport, mute and keyboard seeking were exercised. These checks qualify the prototype only; they do not establish production behavior or physical OLED appearance.

## Planning Contract

Product Contract unchanged in meaning; the Problem Frame and decision provenance make the existing scope explicit. R1–R8 and AE1–AE4 are preserved.

### Key Technical Decisions

- KTD1. **Keep material rendering inside the existing centerpiece styles.** Extend `TrackDisplay` in `NowPlayingBar.tsx` using static background shading, a shallow inset edge and a faint lower lip. Preserve its DOM, grid, padding and semantic roles. This applies R1, R2 and R5 without adding React state, event handlers or a reusable material framework.
- KTD2. **Derive the finish from current semantic colors.** Use the existing muted surface and foreground/border roles with restrained CSS color mixing rather than a hardcoded dark palette. Keep the underlying background color intact and place decorative shading behind the content. Tune the result per R3 and R4 across all current moods; shared token outputs and native consumers do not need to change for this first use.
- KTD3. **Measure the painted surface.** The existing `expectReadable` helper composites `backgroundColor` but does not inspect background images or inset shadows. Retain it for flat controls; use a focused rendered-surface check for text and handles over the new centerpiece treatment. This prevents a false pass for R4.
- KTD4. **Update the authoritative rule with the implementation.** Revise the footer, surface and philosophy guidance in `ui/DESIGN.md` together so the exception is explicit and no flat-only rule contradicts it. Preserve the documented distinction between the 2008–2014 reference era and the supplementary 2018 archive. This implements R6–R8 without redefining native layouts.

### Boundaries and Execution-Time Judgments

The effect is static CSS, with no image asset, animation, pointer-driven lighting, preference switch or new dependency.
Existing theme mixing in shared controls provides a local pattern; the selected prototype supplies the material reference, so external implementation research is not needed.
A generic material component is not warranted for one consumer; reconsider extraction when another approved surface needs the same contract.

Exact shading strengths are an implementation-time visual judgment against A, constrained by R1–R4.
Do not use clipping or a foreground overlay to obtain the effect: both could obscure the existing seek focus ring or intercept controls.
Retain the current recovery status, radio branch and context-player ownership without changing their behavior.

## Implementation Units

### U1. Apply the recessed finish and align design guidance

- **Outcome:** The existing music centerpiece gains the selected depth while the design guide explains its intended use.
- **Requirements:** R1–R8; KTD1, KTD2, KTD4.
- **Files:** `ui/web/src/features/player/components/NowPlayingBar.tsx`, `ui/DESIGN.md`.
- **Dependencies:** None.
- **Approach:** Implement the finish in the existing styled centerpiece, deriving shading from theme roles. Keep decoration behind content and within the current dimensions. Update the guide's conflicting flat-only statements and describe the restrained Aqua-and-flat direction with device-specific interpretation.
- **Patterns:** `.agents/rules/frontend.md`; current `TrackDisplay` structure; semantic color mixing in `ui/web/src/shared/components/ui/card.tsx` and `select.tsx`.
- **Execution note:** Start with a visual smoke check of the actual preview footer against A; CSS source inspection cannot establish whether the finish reads correctly.
- **Test expectation:** No new unit tests for decorative CSS. Browser qualification belongs to U2, including the existing playback regressions.
- **Verification:** At normal desktop scale the finish satisfies AE1; dark and light appearances satisfy AE2. The guide satisfies AE4 and no longer prohibits the treatment it documents. Run prose-fix on the changed documentation.

### U2. Qualify the painted surface and preserve player interactions

- **Outcome:** Browser evidence establishes the material's readability and the unchanged player behavior.
- **Requirements:** R1–R5; AE1–AE3; KTD3.
- **Files:** New `ui/web/tests/e2e/styling/footer-material.spec.ts`; existing `ui/web/tests/e2e/styling/browsing-playback.spec.ts` only if a focused assertion needs adjustment; `docs-book/part-2-developer-guide/testing.md`; `ROADMAP.md`.
- **Dependencies:** U1.
- **Approach:** Reuse `populatedCatalog`, real login, the local silent WAV and `setTheme`. Add a narrow rendered-surface sampler in the new spec using browser image/canvas facilities; do not widen the flat-surface helper into a general CSS renderer. Compare resolved text/control colors with unobstructed surface pixels at matching vertical positions, including gradient extrema under content. Keep artwork, glyph edges and shadow borders out of background samples; account for alpha when comparing a translucent foreground.
- **Patterns:** `ui/web/tests/e2e/styling/fixtures.ts`, `helpers.ts`, `browsing-playback.spec.ts`, `ui/web/tests/e2e/helpers/theme-utils.ts`.
- **Test scenarios:**
  1. Covers AE1, AE2. At 1280 pixels, exercise all 32 current mood/accent combinations with a loaded track. Assert the existing 4.5:1 text and 3:1 essential-control thresholds against the painted surface; preserve the true-black page and distinguishable dark layers. Capture one representative footer per mood for human finish review.
  2. Covers AE3. At 390, 768 and 1280 pixels with the existing long-title fixture, retain contained metadata, reachable controls, visible slider handles and unclipped keyboard focus. Reuse the existing playback suite for seek, play/pause, mute and volume dragging instead of duplicating those flows.
  3. Covers AE1, AE2. In Chromium, Firefox and WebKit, inspect representative dark and light footer screenshots at rest and with seek focused. The shallow inset and lower lip remain subtle, and neither shading nor the focus state obscures metadata or controls.
- **Verification:** Record actual suite counts, browser engines, inspected screenshots, static-check results and any physical-screen limitation in ROADMAP. Document the focused browser command and its contrast-sampling boundary in the testing guide. Run prose-fix on the changed documentation.

## Verification Contract

Use the worktree's isolated preview from `make preview-up` and obtain its URL from `make preview-url`; set that value explicitly as `E2E_BASE_URL`.
Keep real authentication and the existing local fixture boundary. Refresh the preview after source changes, without resetting data.

| Gate | Command or evidence | Passing result |
|---|---|---|
| Focused material and playback checks | `yarn --cwd ui/web e2e:headless tests/e2e/styling/footer-material.spec.ts tests/e2e/styling/browsing-playback.spec.ts` with the explicit preview URL | All selected cases pass; the new matrix covers all current mood/accent combinations and the existing interaction regressions remain green. |
| Engine coverage | Run the focused material spec through Chromium, Firefox and WebKit using the repository browser runner and installed engines | Representative dark/light rendering and focus remain readable in each engine; report any unavailable engine as an open check. |
| Visual judgment | Inspect actual footer captures at normal size against prototype A and AE1–AE3 | The result reads as a shallow recess, not prominent gloss; text and controls stay dominant. |
| Type and lint checks | In the authoritative CI image, `yarn --cwd ui/web typecheck` and scoped ESLint for changed TypeScript files | Both TypeScript configurations and scoped lint pass. |
| Documentation | Prose-fix audits of changed prose, requirements-preservation review and `git diff --check` | Documented behavior matches the change and unrelated roadmap entries remain intact. |

The painted-surface check measures rendered UI contrast; it does not prove a physical OLED panel's near-black behavior.
Where a physical OLED screen is available, inspect the real preview at normal brightness and record the observation separately; otherwise report that qualification limit without claiming a hardware test.
Full backend, DSP and audio-graph suites are not gates for this styling-only change unless implementation unexpectedly touches those areas.

## Definition of Done

- U1 and U2 meet their verification outcomes and R1–R8 remain satisfied.
- The required browser, static, visual and documentation gates above have passing evidence; any hardware-only qualification limit is stated explicitly.
- The authoritative design guidance, testing guide and ROADMAP agree with the delivered scope and checks.
- The implementation has passed the repository's simplification and independent code-review workflow, with justified findings resolved.
