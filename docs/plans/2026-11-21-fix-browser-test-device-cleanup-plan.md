---
title: Browser Test Device Cleanup - Plan
type: fix
date: 2026-11-21
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: code
---

# Browser Test Device Cleanup - Plan

## Goal Capsule

- **Objective:** Running application browser checks leaves the user's saved device list free of devices created by those checks.
- **Means:** Give each browser context a test-owned browser device token and remove its registrations after the run (KTD1–KTD3).
- **Authority:** The requirements below own behavior; technical decisions and units implement them within `AGENTS.md`.
- **Execution profile:** One branch, `fix/browser-test-device-cleanup`, with local commits for the implementation units. The lead integrates, verifies, updates `ROADMAP.md`, and completes review. Push only when requested.
- **Stop conditions:** Stop destructive cleanup if the target cannot be tied to this worktree's managed preview or ownership cannot be established. Retain the ownership manifest and report the failure.

## Product Contract

### Summary

Track device ownership in the browser test harness and remove those registrations when the run ends. Cover the shared Playwright authentication path and direct seeded-admin login specs.

### Problem Frame

The 2026-11-21 preview investigation found 430 device rows, including 428 for the seeded administrator, with distinct browser device tokens and the same Chrome on Linux description. Fresh test contexts sign into that account and discard their local storage afterwards. The application preserves a token within one browser installation, but the harness never removes the server registrations it creates. Relevant evidence is in `ui/web/tests/e2e/fixtures.ts` and `ui/web/src/features/session/utils/device-id.ts`.

### Requirements

**Ownership and cleanup**

- R1. Application browser checks assign a fresh, recorded browser device token to each context that can authenticate the seeded administrator, including manually created contexts.
- R2. Normal completion and test failure both attempt cleanup after all owned browser contexts close; cleanup failure makes the run fail and preserves ownership evidence.
- R3. Cleanup removes only registrations matching both an owned browser device token and its expected account; unrelated registrations and existing saved devices remain intact.

**Existing behavior and scope**

- R4. Application device identity and registration behavior remain unchanged, and existing ambiguous rows are retained because their user agent and timestamps do not establish test ownership.
- R5. Real login remains the authentication boundary, and the affected login specs use the seeded account's current credentials and post-login route.

### Acceptance Examples

- AE1. A normal run opens two fresh contexts against the same seeded account, each registers a different token, and a reload preserves its context's token; teardown removes both registrations and leaves a pre-existing sentinel device intact. Covers R1–R3.
- AE2. A test fails after registration and another logs out before teardown; both owned registrations are removed without using either page's authentication state. Covers R2–R3.
- AE3. A manifest contains one owned token, while another account has a registration with the same token; cleanup leaves the other account's registration intact. Covers R3.
- AE4. A test fails after login and cleanup also fails; both failures remain visible and the ownership manifest is retained. Covers R2.

### Scope Boundaries

This work covers test ownership, teardown, the verified login-spec drift, and the browser testing documentation. It adds no production endpoint or device identity redesign (R4). Existing disposable-user fixtures already remove their users and cascading data in `tests/Fixtures/LiveUpdates/capacity-fixture.php`; preserve that lifecycle rather than replacing it. `scripts/dev_environment_browser_probe.mjs` runs against disposable qualification state through the development environment's `Qualification` workflow, which removes successful qualification resources and retains failed state as evidence; leave that lifecycle unchanged. A process killed before teardown can leave owned rows, so retain the run manifest for exact cleanup rather than adding a background janitor. Historical cleanup is separate work requiring stronger ownership evidence (R4).

## Planning Contract

### Key Technical Decisions

- KTD1. **Own tokens before navigation.** A shared Node helper allocates UUIDs, records them outside browser memory, and installs `baander-device-id` before the application can register a device. Reuse it for fixture-managed and explicitly created contexts; keep the token stable across reloads within a context. This implements R1 without changing application identity.
- KTD2. **Clean once after the suite.** Playwright setup creates a unique per-invocation ownership manifest that workers append to safely; suite teardown invokes a one-shot test-only PHP fixture after browser contexts and existing `FixtureClient` executors have exited. The managed development executor holds a lifecycle lock, so opening another long-lived executor during tests would conflict with the existing fixtures. Implements R2.
- KTD3. **Bind deletion to the preview and account.** Resolve the actual managed preview origin and compare it to the requested browser origin before initializing seeded-account ownership and again before cleanup. Other disposable harness configurations retain their existing lifecycle. The fixture resolves the fixed seeded administrator itself, validates every supplied UUID before mutation, and deletes exact owned tokens in parameterized chunks within one transaction. Successful cleanup is idempotent, including tokens that never registered; mismatched target, missing account, malformed ownership, or database failure fails cleanup without widening its scope. An explicit fixture CLI environment guard prevents accidental direct execution. Implements R2–R3.
- KTD4. **Keep cleanup independent of browser authentication.** Use the existing managed CLI execution mechanism rather than authenticated page requests, because teardown must work after logout, page closure, and failed authentication. No schema change is needed. Implements R2–R4.

### Assumptions and Open Questions

The supported live target is this worktree's managed preview, as documented in `docs-book/part-2-developer-guide/testing/frontend-and-browser.md`. Scripted browser-auth and other isolated rendering harnesses do not register application devices. There are no blocking product questions. Exact helper names and receipt fields are implementation details; cleanup must retain the owning manifest until success (R2).

## Implementation Units

### U1. Add owned-device lifecycle and scoped cleanup fixture

- **Intent:** Establish the ownership and cleanup boundary in KTD1–KTD4.
- **Files:** `ui/web/tests/e2e/helpers/browser-device-lifecycle.mjs`, `ui/web/tests/e2e/helpers/browser-device-lifecycle.test.mjs`, `tests/Fixtures/BrowserDevices/device-fixture.php`, and `tests/Integration/BrowserDeviceFixtureTest.php`.
- **Approach:** Keep manifest allocation, context initialization, preview binding, one-shot fixture execution, and failure receipts together. Delegate PostgreSQL fixture implementation and query review to the postgres specialist under `postgres-remediation`; use existing DBAL bootstrapping patterns and introduce no migration.
- **Test scenarios:** Empty and repeated cleanup succeed; malformed UUIDs and target mismatch reject without mutation; the exact owned registration is removed while an unowned token, a same-token registration for another account, and playback destination rows survive. Simulated cleanup failure retains the manifest and is reported alongside a prior test failure.
- **Verification:** Focused lifecycle tests and fixture PHPUnit tests pass in their documented runners.

### U2. Integrate Playwright and repair the touched login checks

- **Intent:** Apply R1–R2 and R5 to every current seeded-account browser entry point.
- **Files:** `ui/web/tests/e2e/fixtures.ts`, `ui/web/tests/e2e/playwright.config.ts`, new suite setup/teardown module, `ui/web/tests/e2e/auth/login.spec.ts`, and `ui/web/tests/e2e/styling/appearance-matrix.spec.ts`.
- **Approach:** Seed and record fixture-managed contexts before navigation, and audit raw Playwright imports and manual contexts for seeded-account authentication. Route current uncovered callers through the shared lifecycle, including direct `authenticate(page)` callers. Existing disposable-account contexts retain their user-deletion cleanup. Correct the touched login specs' `password` credential and `/` success expectation to the seeded `admin` credential and `/music` route established by `fixtures.ts`; require the actual logout action instead of passing when its button is absent.
- **Test scenarios:** Run AE1–AE4 against the refreshed isolated preview, including an intentional failure after registration. Confirm teardown executes after context closure, and one fixture-backed test can complete before suite cleanup without a lifecycle-lock conflict. The login and logout checks pass with real authentication.
- **Verification:** Focused live Playwright coverage and injected-failure lifecycle checks leave no owned registrations.

### U3. Document the lifecycle and record acceptance evidence

- **Intent:** Make the cleanup contract and delivered verification traceable.
- **Files:** `ui/web/tests/e2e/README.md`, `docs-book/part-2-developer-guide/testing/frontend-and-browser.md`, and `ROADMAP.md`.
- **Approach:** Explain owned tokens, managed-preview targeting, teardown failure evidence, and the limit for interrupted processes. Record completed scope and exact verification checkpoints in the roadmap; use the existing browser device token term from `CONCEPTS.md`.
- **Test expectation:** No separate behavior tests; verify the documentation against U1–U2 and apply `prose-fix`.

## Verification Contract

Use the runners in `docs-book/part-2-developer-guide/testing/README.md`; authoritative PHP gates run in the CI image. Run focused PHP fixture tests, Node lifecycle tests, web type checking and lint for changed harness files, and the applicable tooling formatter. Refresh the preview with `make preview-up` and pass the URL from `make preview-url` explicitly as `E2E_BASE_URL` for live checks.

For live acceptance, capture the seeded account's baseline device IDs, run normal and intentionally failing browser checks, and assert every owned token is absent afterwards while baseline IDs are unchanged. Include reload identity, logout-before-cleanup, manual-context ownership, and a fixture-backed case that exercises executor ordering. Record suite counts, relevant static gates, and the before/after result inline in `ROADMAP.md`; do not cite temporary receipts from `var/`.

## Definition of Done

U1–U3 satisfy R1–R5 and AE1–AE4. The lead applies `ce-simplify-code`, completes `ce-code-review`, resolves justified findings, and records verification in `ROADMAP.md`. Changes remain local commits on the named branch until push is requested.
