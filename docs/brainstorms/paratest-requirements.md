# Paratest Integration & Database Test Compatibility

**Date:** 2026-05-10
**Scope:** Standard
**Status:** Draft

## Problem

The test suite (178 test files) runs sequentially. As the suite grows, feedback loops will slow down. Paratest enables parallel execution but requires database isolation — currently all Functional tests share a single `baander_test` database with no cleanup between tests, causing state collisions under parallel workers.

## Goal

Add Paratest to the project and make all test suites parallel-safe, as a forward-looking infrastructure investment.

## Decisions

- **Database isolation:** Transaction wrapping via `dama/doctrine-test-bundle` — wraps each test in a transaction that rolls back on tearDown. Shared single database, no per-worker DBs needed yet.
- **Test runner target:** Separate `make paratest` target alongside existing `make phpunit`. CI stays on PHPUnit for now.
- **Upgrade path:** Per-worker databases can be introduced later if transaction wrapping proves insufficient.

## Requirements

### 1. Add Paratest as explicit dependency

`brianium/paratest` is currently a transitive dependency in composer.lock. Add it to `require-dev` so it's a first-class dependency with a version constraint.

### 2. Add dama/doctrine-test-bundle

Add `dama/doctrine-test-bundle` to `require-dev`. Enable it in the test environment only. This automatically wraps every test using the Doctrine EntityManager in a transaction that rolls back after the test — no manual setUp/tearDown changes needed in individual tests.

The Functional TestCase (`tests/Functional/TestCase.php`) currently closes the EntityManager in tearDown. This may need adjustment to work with dama's transaction wrapping (dama handles its own lifecycle).

### 3. Verify all suites are parallel-safe

- **Unit (166 files):** No DB interaction. Already parallel-safe.
- **Functional (11 files):** Need dama bundle active for transaction isolation.
- **Integration (1 file):** Likely needs the same treatment — verify after dama is configured.

### 4. Add Makefile target

Add a `make paratest` target that runs `./vendor/bin/paratest` inside the container. Keep existing `make phpunit` unchanged.

### 5. PHPUnit configuration

Verify `phpunit.xml.dist` is compatible with Paratest (Paratest reads the standard PHPUnit config). May need to ensure the test suite definitions are Paratest-compatible (directory-based suites work; file-based lists may need adjustment).

## Scope Boundaries

### Deferred for later
- Per-worker database isolation
- Switching CI pipeline from PHPUnit to Paratest
- Coverage report generation under Paratest (can be added later)

### Out of scope
- Modifying existing test assertions or test structure
- Changes to Unit tests (already parallel-safe)
- Changes to the test bootstrap file
- Performance benchmarking or tuning worker count

## Dependencies & Assumptions

- **Assumption:** `dama/doctrine-test-bundle` is compatible with Doctrine ORM 3.6 and Symfony 8.0. Needs verification during implementation — this bundle hasn't always kept pace with major version bumps.
- **Assumption:** Paratest ^7.22 is compatible with PHPUnit 12. Needs verification.
- **Dependency:** Test database (`baander_test`) must have up-to-date schema before running parallel tests (same as today).
