---
title: Fixed Product Name - Plan
type: fix
date: 2026-10-27
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: code
---

# Fixed Product Name - Plan

## Goal Capsule

- **Objective:** Users see Bånder as the application's name regardless of operator environment settings.
- **Means:** Fixed backend branding and matching native display metadata (KTD1, KTD2).
- **Authority:** The Product Contract owns behavior; the Planning Contract owns implementation choices within it. Repository architecture and testing rules apply.
- **Execution:** Three bounded units, delivered as local commits on a branch named after this plan. The lead integrates and verifies; pushing requires a user request.
- **Stop conditions:** Stop before any change to credential identity, stored user data, protocol identifiers, or package identity.

## Product Contract

### Summary

Make Bånder the fixed display name across app identity surfaces and remove operator-facing naming overrides.
Existing naming variables become inert.

### Problem Frame

`APP_NAME` currently changes email branding and passkey prompts, while native app titles and TOTP provisioning use the spelling Baander.
The configuration guide also advertises a MusicBrainz naming setting that the adapter does not read.
These paths conflict with the requested fixed product identity.

### Requirements

- R1. Application identity shown in email branding, passkey prompts, TOTP enrollment, app titles, launcher labels, splash screens and login headings is Bånder.
- R2. `APP_NAME`, `MUSICBRAINZ_APP_NAME` and equivalent operator-facing product-name settings cannot change that identity; absent, empty or conflicting values must not prevent startup.
- R3. Supported environment examples and operator guides expose no product-renaming option.
- R4. Technical identifiers remain stable, including package/module names, domains, HTTP product tokens, passkey RP IDs and OAuth issuer URLs.
- R5. Existing credentials and local data remain intact; the change affects newly rendered labels without requiring re-enrollment.

### Scope Boundaries

This covers product identity fields and headings, not a repository-wide replacement of every occurrence of Baander in prose, source symbols, historical plans or logs.
Instance URLs, mail addresses, metadata credentials and user/device/library names remain independently configurable.
MusicBrainz version/contact configuration drift is outside this change.
No compatibility layer, startup rejection, environment-variable scrubber, branding service or schema migration is needed: unsupported variables are simply unread.
A custom source fork can still edit the product; this contract governs supported operator configuration.

### Acceptance Examples

- AE1. With both naming variables set to `SomethingElse`, a fresh container still resolves Bånder and generated email/passkey/TOTP branding uses that spelling. Covers R1, R2.
- AE2. With both variables missing or empty, startup succeeds with the same name. Covers R2.
- AE3. Native display metadata and login headings show Bånder while module registration, bundle IDs and protocol identifiers retain their existing values. Covers R1, R4.
- AE4. TOTP provisioning encodes Bånder in both the account-label prefix and issuer query value; code verification and passkey RP IDs retain their existing behavior. Covers R1, R4, R5.

## Planning Contract

### Key Technical Decisions

- KTD1. Keep `app.name` as a fixed literal in `config/services.yaml`, preserving existing scalar injection into mail and passkey services. Bind TOTP branding to that parameter instead of maintaining separate configurable or divergent names. This closes R1 and R2 without introducing a new service.
- KTD2. Update native human-readable display fields directly. Keep React Native registration names and package/build identifiers unchanged under R4; there is no cross-language branding framework to maintain.
- KTD3. Remove the unused MusicBrainz naming variable from examples and documentation. Preserve the adapter's existing ASCII User-Agent token under R4; changing HTTP metadata is unnecessary to fix display branding.
- KTD4. Use fresh, isolated application-container tests for R2, with environment restoration and separate cache directories. Pair them with behavior assertions in existing auth tests so a literal-only source check cannot stand in for generated output.

### Current Evidence

- `config/services.yaml`: `app.name` feeds `AfterResponseMailer`, `PasskeyService` and `SendEmailHandler`.
- `config/packages/auth.yaml`: `auth.totp.issuer` is independently fixed to Baander.
- `src/Auth/Infrastructure/Security/Totp/TotpService.php`: issuer constructor default is Baander; its provisioning helper uses that issuer.
- `src/Auth/Interface/Controller/Totp/TotpController.php`: a separate provisioning builder hard-codes Baander twice.
- `src/Metadata/Infrastructure/Api/MusicBrainz/MusicBrainzAdapter.php`: User-Agent is already fixed, with no `MUSICBRAINZ_APP_NAME` consumption.
- `tests/Integration/CorsConfigurationTest.php`: precedent for a production-config kernel, temporary cache and environment restoration.
- `docs-book/part-2-developer-guide/testing.md` and `Makefile`: disposable CI-image backend runners and static gates.

No matching branding solution was found under `docs/solutions/`, and no Compound Packs are configured.
Local source establishes the change; no dependency/API change or external research is needed.
No new domain concept is introduced.

## Implementation Units

### U1. Fix backend branding and cover operator overrides

**Requirements:** R1, R2, R4, R5. **Dependencies:** None.

**Files:** `config/services.yaml`; `config/packages/auth.yaml`; `src/Auth/Infrastructure/Security/Totp/TotpService.php`; `src/Auth/Interface/Controller/Totp/TotpController.php`; `.env.example`; `tests/Fixtures/web-runtime.php`; new `tests/Integration/ProductNameConfigurationTest.php`; `tests/Unit/Auth/Infrastructure/Mail/AuthMailTestCase.php`; `tests/Unit/Auth/Infrastructure/Mail/AfterResponseMailerTest.php`; `tests/Unit/Auth/Infrastructure/Mail/CredentialEmailDeliveriesTest.php`; `tests/Unit/Auth/Infrastructure/Security/Passkey/PasskeyServiceTest.php`; `tests/Unit/Auth/Infrastructure/Security/Totp/TotpServiceTest.php`; `tests/Functional/Controller/TotpControllerTest.php`.

**Approach:** Apply KTD1, KTD3 and KTD4.
Wire the TOTP controller's display-name scalar through container configuration; keep URI encoding in its existing builder.
Align the TOTP service's default and configured issuer with the fixed name, removing the stale suggestion that operators should configure it.
Remove the two naming entries from the tracked environment example and correct the fixture comment.
Do not edit the operator's ignored `.env` file.
Update relevant test fixtures and the touched OpenAPI provisioning example to use Bånder and a `baander.app` account address.

**Test scenarios:**

1. Boot fresh application containers with both obsolete variables absent, empty and conflicting; each resolves the fixed name and the real configured mail/passkey/TOTP consumers receive it. Restore `$_ENV`, `$_SERVER` and process values after each case.
2. Generate a credential email and a notification email; assert the actual rendered brand and subject use Bånder. Exercise the configured handlers with fake outbound delivery, not just a manually supplied constructor string.
3. Generate passkey registration options; assert the RP display name is Bånder and the configured RP ID is unchanged.
4. Parse the service and authenticated controller TOTP provisioning URIs; assert decoded label and issuer contain Bånder, account/secret remain correct, and existing valid-code verification still passes.

**Verification:** Focused unit, integration and functional cases pass in the CI image; no production HTTP, email or DNS occurs. A hostile-variable regression fails against the old configuration and passes after the change.

### U2. Align native display names

**Requirements:** R1, R4. **Dependencies:** None; may proceed beside U1 with separate file ownership.

**Files:** `ui/electron/electron-builder.yml`; `ui/rn/app.json`; `ui/rn/android/app/src/main/res/values/strings.xml`; `ui/rn/ios/Baander/Info.plist`; `ui/rn/ios/Baander-tvOS/Info.plist`; `ui/rn/ios/Baander/LaunchScreen.storyboard`; `ui/rn/ios/Baander-tvOS/LaunchScreen.storyboard`; `ui/rn/src/features/auth/pages/LoginPage.tsx`; `ui/rn/src/features/tv/pages/TVLoginPage.tsx`.

**Approach:** Apply KTD2 to Electron `productName`, React Native `displayName`, Android `app_name`, Apple `CFBundleDisplayName`, launch labels and visible login headings.
The web and Electron renderer titles already use Bånder.
Preserve React Native `name`, module registration, native targets, bundle/package IDs and explicit artifact naming expressions.

**Test expectation:** No new tests for literal labels or metadata. Inspect parsed JSON/XML/YAML values and the diff to verify AE3, and run the existing React Native typecheck for the edited TSX files.

**Verification:** Every named display field matches R1 and identity fields match their pre-change values. iOS/tvOS packaging requires macOS; record that source validation does not certify native builds, and retain the tvOS starter-template qualification.

### U3. Update operator guidance and record acceptance

**Requirements:** R3. **Dependencies:** U1 and U2.

**Files:** `docs-book/part-1-operator-guide/configuration.md`; `docs-book/part-1-operator-guide/security.md`; `docs-book/part-1-operator-guide/external-apis.md`; `ROADMAP.md`.

**Approach:** Remove naming-variable rows and sample assignments, replace renaming instructions with the fixed-name rule, and describe MusicBrainz's name as fixed.
Do not claim unrelated version/contact settings have been repaired.
Record delivered scope, actual suite counts, static gates and native verification limits in the roadmap.

**Test expectation:** No application tests for prose. Search supported configuration documentation and tracked environment templates for obsolete naming instructions; historical plans and hostile-variable regression cases are expected matches.

**Verification:** Local documentation references resolve, prose checks pass and the roadmap distinguishes planned work from verified delivery.

## Verification Contract

Use `docs-book/part-2-developer-guide/testing.md` and the current checkout's CI image for authoritative PHP validation.
Run the focused U1 files through `bash scripts/test-backend-container.sh` with explicit test paths; serialize shared-state suites.
Run `make phpstan` and the CI-image Deptrac gate after PHP changes.
Use `yarn --cwd ui/rn typecheck` for U2 and parse its changed metadata with the corresponding JSON/XML/YAML readers.
Use `git diff --check` and targeted tracked-file searches for U3.

Add no tests that merely repeat display literals without exercising behavior.
Keep all test addresses under `baander.app`; stub external delivery and requests.
Browser work, if needed after an unexpected rendering issue, uses `make preview-up`, `make preview-url` and an explicit `E2E_BASE_URL` for the isolated preview.
Native build availability is an execution-time limitation to report, not permission to claim a successful native build.

## Definition of Done

- U1 demonstrates AE1, AE2 and AE4 through generated outputs and production configuration wiring.
- U2 satisfies AE3 with recorded source/type checks and explicit native-build coverage limits.
- U3 removes supported renaming instructions and records the delivery evidence.
- Simplification and independent code review are complete, justified findings are resolved, and abandoned code is removed.
- All changes remain in local commits until a push is requested.
