---
title: Loudness Target Levels - Plan
date: 2026-10-30
type: feat
topic: loudness-target-levels
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: code
---

# Loudness Target Levels - Plan

## Goal Capsule

- **Objective:** Let listeners choose understandable Baander loudness presets or their own normalization target.
- **Authority:** The user requested Baander levels and selected presets plus a custom target while preserving the current default.
- **Means:** One shared target control and a finite numeric preference contract.
- **Execution profile:** Two bounded units, one reviewable change on a branch named for this plan; follow `AGENTS.md` for checks and local commits. Push only when requested.
- **Open blockers:** None. Do not change normalization measurement or gain behavior as part of this work.

## Product Contract

### Summary

Replace service-branded LUFS choices with Baander listening levels and a custom numeric target in Settings and the Equalizer.

### Problem Frame

The existing controls label targets after other services and permit only four numbers.
The processor already accepts finite targets, but the preference boundary rejects custom values, so changing only the selector would not produce a saved setting.

### Requirements

- R1. Offer Quiet at −23 LUFS, Relaxed at −18 LUFS, Balanced at −16 LUFS and Louder at −14 LUFS, without service-brand labels.
- R2. Allow a custom finite numeric target, including fractional values, with the number clearly labeled in LUFS.
- R3. Settings and the Equalizer must edit the same target and display a restored custom value correctly.
- R4. Custom values must survive the existing save, fetch, local restore and version-rollback paths.
- R5. Reject empty, nonnumeric and nonfinite values without committing an invalid preference or replacing the current valid target.
- R6. Preserve the −14 LUFS default, normalization-off default, current gain limits and audio measurement algorithm.

### Acceptance Examples

- AE1. A listener selects Custom while the current target is −14, enters −20.5, commits it and later restores preferences; both controls show the saved custom target.
- AE2. A listener clears the custom field or enters an overflowing exponent; validation explains the error and the last valid target remains active.
- AE3. A listener selects Balanced from a custom value; the shared target becomes −16 and can be saved normally.

### Scope Boundaries

Track/album loudness analysis, true-peak limiting, transcoding loudness standards and changes to normalization gain behavior remain outside this work.
Preset versus custom is presentation state; persistence remains a numeric `targetLufs` value.

## Planning Contract

- KTD1. **Reuse the numeric preference.** Replace the closed TypeScript union and preset-only payload validation with finite-number validation. Use Symfony's installed `Assert\Type('finite-number')` with `NotNull` at the backend request boundary. No database migration or new preference field is needed.
- KTD2. **Share the target control.** Define the preset metadata and finite-target predicate once, and use one accessible selector/custom-input component in Settings and ProcessingPanel. Local input drafts may be incomplete; commit only valid numbers on Enter or blur. Selecting a preset commits immediately. Disable the controls while normalization is off.
- KTD3. **Keep custom targets unrestricted by an invented listening range.** The existing processor caps gain adjustment independently of the target. Reject nonfinite numbers consistently without adding arbitrary minimum/maximum targets; the UI explains that this is a target and does not remove existing gain limits.
- KTD4. **Regenerate the contract.** Remove the OpenAPI enum and regenerate `openapi.json` and the Orval client from the request definition. Do not hand-edit generated client types.

## Implementation Units

### U1. Accept and restore finite custom targets

- **Requirements:** R2–R6; KTD1, KTD3, KTD4.
- **Files:** `src/UserPreference/Interface/Request/SaveAudioPreferencesRequest.php`; `tests/Functional/Controller/AudioPreferencesControllerTest.php`; `ui/web/src/features/equalizer/stores/eq-processing-store.ts`; `ui/web/src/features/settings/audio-preference-payload.ts`; the existing preference restore and processing-store tests; generated `openapi.json` and `ui/web/src/shared/api-client/gen/endpoints/index.ts`.
- **Approach:** Keep numeric payload shape, revision control and rollback unchanged. Replace enum validation at both boundaries and guard direct store writes with the same frontend finite-target predicate. Preserve the current processor implementation.
- **Tests:** Accept −20.5 through save/fetch/rollback and local restoration. Replace the old rejection cases for −15/−20 with custom-value acceptance. Reject strings, null, NaN, infinities and overflowing JSON exponents through the applicable boundary; rejected writes leave current state/history untouched. Retain existing default and gain-limit coverage.
- **Verification:** Focused controller, store and preference restore suites pass; generated OpenAPI and client agree on a numeric target without the old enum.

### U2. Expose shared presets and a custom input

- **Requirements:** R1–R6; AE1–AE3; KTD2.
- **Files:** New shared target definitions/control under `ui/web/src/features/equalizer/`; `ui/web/src/features/settings/pages/SettingsPage.tsx`; `ui/web/src/features/equalizer/components/ProcessingPanel.tsx`; both existing SettingsPage test suites; a focused test beside the new control; `ROADMAP.md`.
- **Dependencies:** The numeric contract from U1; agree the shared predicate and preset module location before parallel edits.
- **Approach:** Render the same target semantics in both locations. Keep custom input visible while editing even if its current number equals a preset. Give the field a programmatic label, error association and keyboard submission. Synchronize external target changes without treating incomplete drafts as stored preferences.
- **Tests:** Covers AE1–AE3. Exercise selecting Custom from the default, fractional entry, Enter and blur commits, invalid drafts, switching back to a preset, external target updates, and disabled normalization. Existing Settings integration tests assert Baander labels and preserved defaults.
- **Verification:** Focused control/settings tests and both TypeScript configurations pass. In the isolated browser preview, exercise a custom target in Settings, observe it in Equalizer, then reload and verify persistence; restore the user's original preferences afterward.

## Verification Contract

- Backend: use `scripts/test-functional-container.sh` for `AudioPreferencesControllerTest.php` against disposable services and the CI image.
- Frontend: run focused Vitest control, SettingsPage, processing-store, preference restore and reapply tests; run both TypeScript configurations and scoped ESLint in the CI image.
- Regeneration: export with `app:export-openapi-spec`, run `yarn --cwd ui/web generate`, and verify generated drift according to the API reference guide.
- Browser: use `make preview-up`, the explicit `make preview-url` URL, real authentication and the existing settings persistence flow. Check desktop and narrow input containment and keyboard use. Preserve local library data.
- Run prose-fix on changed documentation and `git diff --check`. Record actual suite counts and browser observations in ROADMAP.

## Definition of Done

All six requirements and both units pass their verification, generated consumers match the request contract, and simplification plus independent code review leave no justified open findings.

## Sources

- `ui/web/src/features/settings/pages/SettingsPage.tsx` and `ui/web/src/features/equalizer/components/ProcessingPanel.tsx`: two existing preset controls.
- `ui/web/src/features/player/services/audio-processor.ts`: finite target acceptance and independently bounded gain.
- `ui/web/src/features/settings/audio-preference-payload.ts`: current restore validator.
- `src/UserPreference/Interface/Request/SaveAudioPreferencesRequest.php` and `vendor/symfony/validator/Constraints/TypeValidator.php`: request validation and supported finite-number constraint.
- `docs-book/part-2-developer-guide/api-reference.md`: authoritative OpenAPI/Orval generation workflow.
