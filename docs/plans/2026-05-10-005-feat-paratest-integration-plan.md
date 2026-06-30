---
title: "feat: Add Paratest parallel testing with DB-compatible transaction isolation"
type: feat
status: completed
date: 2026-05-10
origin: docs/brainstorms/paratest-requirements.md
---

# feat: Add Paratest parallel testing with DB-compatible transaction isolation

## Summary

Upgrade PHPUnit 12→13, add paratest ^7.22 and dama/doctrine-test-bundle (dev-master for Symfony 8 / DoctrineBundle 3 support), configure transaction-based DB isolation, and add a `make paratest` Makefile target. All three test suites (Unit, Functional, Integration) become parallel-safe.

---

## Problem Frame

The test suite (178 files) runs sequentially. As it grows, feedback loops will slow down. Paratest enables parallel execution but requires database isolation — Functional tests share a single `baander_test` database with no cleanup between tests, causing state collisions under parallel workers. The brainstorm established transaction wrapping as the isolation strategy; this plan implements it.

---

## Requirements

- R1. Add `brianium/paratest` as an explicit require-dev dependency
- R2. Upgrade PHPUnit 12→13 (prerequisite for paratest ^7.22)
- R3. Add `dama/doctrine-test-bundle` (dev-master) for automatic transaction wrapping per test
- R4. All three test suites (Unit, Functional, Integration) must be parallel-safe
- R5. Add `make paratest` Makefile target alongside existing `make phpunit`
- R6. Existing `make phpunit` target and CI pipeline must remain unchanged and passing

---

## Scope Boundaries

- Per-worker database isolation (deferred to later)
- Switching CI pipeline from PHPUnit to Paratest
- Coverage report generation under Paratest
- Modifying existing test assertions or test structure
- Changes to Unit tests (already parallel-safe)
- Changes to the test bootstrap file

### Deferred to Follow-Up Work

- Per-worker DB isolation: revisit if transaction wrapping proves insufficient under high parallelism
- CI pipeline switch to paratest: separate PR after local verification is stable
- Coverage under paratest: add `--coverage-*` flags to paratest target later

---

## Context & Research

### Relevant Code and Patterns

- `tests/Functional/TestCase.php` — Functional base class: creates KernelBrowser, gets EntityManager, closes EM in tearDown. No transaction wrapping.
- `phpunit.xml.dist` — Three directory-based suites (Unit, Functional, Integration). Already paratest-compatible.
- `tests/bootstrap.php` — Minimal: loads autoloader, registers citext type. Stateless, paratest-safe.
- `.env.test` — Fixed `baander_test` database URL.
- `config/packages/doctrine.yaml` — `when@test` block with commented-out `dbname_suffix` (not in use).
- `config/packages/cache.yaml` — `when@test` uses filesystem adapter (parallel-safe).
- `config/packages/messenger.yaml` — `when@test` uses in-memory transport (parallel-safe).

### External References

- dama/doctrine-test-bundle v8.3.0 requires Symfony ^7.2 max; dev-master adds Symfony 8 + DoctrineBundle 3 support
- paratest ^7.22 requires PHPUnit ^13; ^7.14.2 is the last PHPUnit-12-compatible version (frozen)
- PHPUnit 12→13 migration: `assertContainsOnly` removed, `$this->any()` hard-deprecated. Neither is used in this project — clean upgrade.

---

## Key Technical Decisions

- **PHPUnit 13 upgrade required:** paratest ^7.22 needs PHPUnit ^13. The migration is clean — no test code changes needed (verified by grep). (see origin: brainstorm assumption)
- **dama/doctrine-test-bundle dev-master:** Only the unreleased master branch supports Symfony 8.0 and doctrine/doctrine-bundle ^3.x. Test-only dependency so the risk is contained. Pin to a specific commit hash via Composer's inline alias if stability is a concern.
- **Transaction wrapping over per-worker DBs:** dama wraps each test in a DBAL transaction that rolls back after the test. Shared single database, no schema duplication needed. (see origin: brainstorm decision)
- **Functional TestCase tearDown adjustment:** dama manages the DBAL connection lifecycle. The current `$this->entityManager->close()` may need to be removed or reordered to avoid closing the connection before dama's rollback fires.

---

## Open Questions

### Resolved During Planning

- **PHPUnit version:** Upgrade to 13 required — user confirmed.
- **dama bundle version:** Use dev-master — user confirmed.
- **Makefile target:** Separate `make paratest` — user confirmed.

### Deferred to Implementation

- **Exact tearDown changes:** Whether `entityManager->close()` must be removed, reordered, or can stay as-is depends on testing with the actual dama bundle. The dama PHPUnitExtension hooks into PHPUnit's test lifecycle (beforeTest/afterTest), which wraps around setUp/tearDown. The close() call operates at the ORM level while dama operates at the DBAL connection level — they may coexist, but this needs runtime verification.
- **dama dev-master stability:** If dev-master causes issues, the fallback is manual transaction wrapping (begin in setUp, rollback in tearDown) in the Functional TestCase.

---

## Implementation Units

### U1. Upgrade PHPUnit 12 → 13 and add paratest

**Goal:** Make PHPUnit 13 and paratest ^7.22 available as require-dev dependencies.

**Requirements:** R1, R2

**Dependencies:** None

**Files:**
- Modify: `composer.json` (update `phpunit/phpunit` constraint to `^13.1`, add `brianium/paratest`)
- Modify: `composer.lock` (via `composer update`)

**Approach:**
- Update `phpunit/phpunit` from `^12.5.23` to `^13.1` in require-dev
- Add `brianium/paratest: ^7.22` to require-dev
- Run `composer update phpunit/phpunit brianium/paratest`
- Run the full test suite to verify zero breakage (no `assertContainsOnly` or `any()` usage exists)

**Patterns to follow:**
- Existing composer.json require-dev structure

**Test scenarios:**
- Test expectation: none — dependency installation. Verified by running the existing test suite and confirming all tests still pass.

**Verification:**
- `./vendor/bin/phpunit --version` reports PHPUnit 13.x
- `./vendor/bin/paratest --version` reports paratest 7.22+
- `make phpunit` passes with zero failures

---

### U2. Add and configure dama/doctrine-test-bundle

**Goal:** Install dama bundle (dev-master) and register it as a test-only Symfony bundle with PHPUnit extension.

**Requirements:** R3

**Dependencies:** U1

**Files:**
- Modify: `composer.json` (add `dama/doctrine-test-bundle: dev-master`)
- Modify: `config/bundles.php` (register bundle as test-only)
- Create: `config/packages/dama_doctrine_test_bundle.yaml` (test config)
- Modify: `phpunit.xml.dist` (register dama's PHPUnitExtension as bootstrap)

**Approach:**
- Add `dama/doctrine-test-bundle` with `dev-master` version constraint to require-dev
- Register `DAMADoctrineTestBundle::class => ['test' => true]` in bundles.php
- Create config file under `when@test` block with `enable_static_connection: true`, `enable_static_meta_data_cache: true`, `enable_static_query_cache: true`
- Add `<extensions><bootstrap class="DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension" /></extensions>` to phpunit.xml.dist
- No `use_savepoints` config needed (DBAL 4 handles this automatically)

**Patterns to follow:**
- Symfony Flex recipe for dama-doctrine-test-bundle 8.3 (bundles.php registration, config structure)

**Test scenarios:**
- Test expectation: none — config installation. Verified by running Functional tests and confirming they still pass with dama's transaction wrapping active (data is rolled back after each test).

**Verification:**
- Bundle is registered in bundles.php for test env only
- PHPUnit extension is registered in phpunit.xml.dist
- `make phpunit` passes — Functional tests still work with dama wrapping
- After running Functional tests, `baander_test` database is clean (no leftover test data — confirms rollback works)

---

### U3. Adjust Functional TestCase for dama compatibility

**Goal:** Ensure the Functional TestCase's tearDown is compatible with dama's DBAL transaction lifecycle.

**Requirements:** R3, R4

**Dependencies:** U2

**Files:**
- Modify: `tests/Functional/TestCase.php`

**Approach:**
- The current tearDown calls `$this->entityManager->close()` then `ensureKernelShutdown()`. Dama hooks into PHPUnit's afterTest event to roll back the DBAL transaction. The EM close() operates at the ORM level and should not interfere with the DBAL-level rollback, but this must be verified at runtime.
- If tests fail with dama active: remove or move `entityManager->close()` before `ensureKernelShutdown()`, or let dama handle the full lifecycle.
- No changes to setUp should be needed — dama intercepts at the DBAL connection level transparently.

**Test scenarios:**
- Happy path: createTestUser + authenticatedRequest works — user is persisted within the test, request succeeds, response is correct
- Happy path: RecommendationRepositoryTest persists and retrieves recommendations correctly
- Integration scenario: dama rollback works — after running all Functional tests, the database has no leftover test users or recommendations

**Verification:**
- All Functional tests pass with dama active
- Database is clean after test run (verify by querying test DB)

---

### U4. Add `make paratest` Makefile target

**Goal:** Provide a convenient target to run the test suite in parallel via paratest.

**Requirements:** R5

**Dependencies:** U1

**Files:**
- Modify: `Makefile`

**Approach:**
- Add a `paratest` target that runs `./vendor/bin/paratest` inside the container, following the same pattern as the existing `phpunit` target
- Include reasonable defaults (e.g., `-c phpunit.xml` for config, `--processes auto` for worker count)
- Keep `make phpunit` unchanged

**Patterns to follow:**
- Existing `phpunit` Makefile target structure (`make exec cmd=...`)

**Test scenarios:**
- Test expectation: none — Makefile target. Verified by running `make paratest` and confirming it executes the full suite in parallel.

**Verification:**
- `make paratest` runs all three test suites in parallel
- Output shows multiple processes/threads in use
- All tests pass

---

### U5. Verify all suites pass under paratest

**Goal:** End-to-end verification that all three test suites run successfully under paratest.

**Requirements:** R4, R6

**Dependencies:** U2, U3, U4

**Files:** None (verification only)

**Approach:**
- Run `make paratest` and verify all test files pass
- Run `make phpunit` and verify it still passes (no regression)
- Verify Unit tests parallelize cleanly (no shared state)
- Verify Functional tests pass with dama transaction isolation (no data collisions)
- Verify Integration test passes (no DB dependency)

**Test scenarios:**
- Happy path: `make paratest` completes with zero failures
- Regression: `make phpunit` still passes after all changes
- Edge case: running paratest multiple times in succession produces consistent results (no flaky tests from parallelism)

**Verification:**
- `make paratest` passes with all test files
- `make phpunit` passes unchanged
- Test database is clean after paratest run

---

## System-Wide Impact

- **Interaction graph:** dama's PHPUnitExtension hooks into PHPUnit's test lifecycle events (beforeTest, afterTest). No changes to application code or production services.
- **State lifecycle risks:** dama wraps each test in a DBAL transaction. If a test triggers a DDL statement (e.g., schema migration), the rollback will fail. Current Functional tests only do DML (INSERT/UPDATE/DELETE), so this is not a risk.
- **Unchanged invariants:** `make phpunit` behavior, CI pipeline, test assertions, and test structure all remain identical.

---

## Risks & Dependencies

| Risk | Mitigation |
|------|------------|
| dama dev-master is unreleased and may break | Test-only dependency, contained blast radius. Fallback: manual transaction wrapping in TestCase |
| dama + EM close() interaction in tearDown | Verify at runtime (U3). If problematic, remove close() — dama handles cleanup |
| PHPUnit 13 may have undiscovered incompatibilities with symfony/phpunit-bridge 8.0 | Bridge has no PHPUnit version constraint and is compatible per research. Verify during U1 |
| paratest process isolation may expose hidden shared state in tests | If flaky tests appear, run with `--processes 1` to bisect, then fix the shared state |

---

## Sources & References

- **Origin document:** [docs/brainstorms/paratest-requirements.md](docs/brainstorms/paratest-requirements.md)
- dama/doctrine-test-bundle: https://github.com/dmaicher/doctrine-test-bundle
- paratest: https://github.com/paratestphp/paratest
- PHPUnit 13 release: https://phpunit.de/announcements/phpunit-13.html
