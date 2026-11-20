---
title: Split tooling into context packages
type: refactor
date: 2026-11-20
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: code
---

# Split tooling into context packages

## Goal Capsule

- Objective: Developers and operators can identify and install the Baander libraries their work requires without pulling development tools into production.
- Means: Replace the tooling monolith with context packages and a narrow shared core (KTD1–KTD3).
- Authority: The user's package split, shared-core request, correction that TypePHP is removed, and request to delete implemented plans govern this work.
- Execution profile: One branch, `refactor/tooling-context-packages`, with local commits. The lead owns integration, final checks and the roadmap. No push is requested.
- Stop conditions: Preserve ordinary PHP entrypoints, environment values, deployment admission rules, controller publication integrity and local user data.

---

## Product Contract

### Summary

Replace `packages/baander-tooling` with named Composer packages for its operational and development responsibilities. Introduce `baander-core` for shared environment names. Remove completed plans after checking their implementation evidence.

### Problem Frame

The tooling package describes itself as a development library but contains operational deployment, process, container and inventory code. Production installs its complete dependency graph through `scripts/composer.json`, including website and dependency-analysis libraries. Its single source root also hides the dependency boundaries already enforced by `deptrac-tooling.yaml`.

### Requirements

- R1. Move every retained tooling class and resource into a coherent `packages/baander-*` context package, remove the old package, and update all active consumers without compatibility aliases.
- R2. Mark development packages in directory names, Composer names and descriptions. Production installs and final production images exclude their dependencies and source.
- R3. Define shared environment variable names in dependency-light `baander-core`, consumed by application and package PHP code. Preserve literal values and every consumer's existing validation, defaults and admission rules.
- R4. Preserve ordinary PHP application, operational CLI, developer CLI, fixture, registry and website behavior across their independent autoload roots.
- R5. Preserve immutable controller generations, exact allowed symlink targets, resource identities, leases and atomic publication after package relocation.
- R6. Preserve meaningful test discovery, static dependency rules, source-policy exceptions and lean-host portability checks across the new source roots.
- R7. Delete only plans whose in-scope implementation is complete, retain unfinished plans, and replace incoming links with current documentation or recoverable historical references.
- R8. Record delivered scope and actual verification counts in `ROADMAP.md`. Do not reintroduce TypePHP.
- R9. Organize larger package internals by responsibility and colocate package-owned tests and fixtures. Consolidate or split packages where their responsibilities warrant it, preserving shared integration runners and their test discovery.

- R10. Use PHP 8.5 idioms in new and refactored code, including typed constants, first-class callables and readable pipelines.
- R11. Correct named argument order to match the callable declaration throughout project-owned PHP, preserving evaluation behavior and excluding vendored code, `ui/rn` and Essentia.

- R12. Provide an immutable configuration-path builder in core and use it for repeated deployment setting reads. Core owns traversal and typed reads; each context owns field names, validation policy and defaults.

### Acceptance Examples

- AE1. A clean root production install can load the shared environment catalog without loading development package classes. A production image contains no development context source. Covers R2–R4.
- AE2. A standalone developer install runs existing scripts through its own autoloader; the application vendor directory is unnecessary. Registry tooling keeps its independent loader. Covers R4.
- AE3. A copied development controller still starts after the source checkout changes, resolves each required local package and resource, and rejects an unexpected package symlink. Covers R5.
- AE4. A worker environment containing an inadmissible key remains rejected even when that name exists in core. Covers R3.
- AE5. Source-policy and dependency tests still reject process bypasses and production-to-development dependencies after source relocation. Covers R6.

### Scope Boundaries

No application context extraction beyond shared environment-name contracts, database changes, dependency version refresh, new runtime, or replacement test framework. The later named-argument request includes mechanical call-site corrections throughout project-owned PHP. `ui/rn`, Essentia, persistent instance changes and local data cleanup are excluded. Historical benchmark evidence remains evidence; it does not create a TypePHP integration requirement. Environment catalogs do not become process-environment allowlists. No generic plugin framework or compatibility package is needed for this pre-release repository.

---

## Planning Contract

### Key Technical Decisions

- KTD1. Use the package map below with explicit Composer requirements and distinct namespaces. Keep Docker transport with resource ownership and image builds with artifact inventory. Separate filesystem integrity from process execution, and consolidate backend gates, architecture checks and CI artifact storage. Existing imports show an acyclic graph; folder count alone does not determine package count. Source: the original tooling source inventory and `deptrac-tooling.yaml`.
- KTD2. Keep core free of framework and tooling dependencies. An environment-name catalog shares spelling, not policies. Migrate common application/tooling PHP consumers while retaining literal protocol names in shell, YAML and fixtures where constants cannot be evaluated. Source: `DeploymentRuntimeEnvironment`, deployment writers and worker entrypoints.
- KTD3. Keep root, scripts and relay as separate Composer projects. Scripts declares operational requirements and development requirements separately; development/CI installations include both, production uses its operational closure. Remove development source from final production artifacts as well as installed package metadata. Source: `Dockerfile` production install, `scripts/bootstrap.php`, `relay/composer.json`.
- KTD4. Copy the controller's complete declared package closure and resources, validate each local package symlink against its exact known target, and preserve immutable publication semantics. Do not broaden copying to arbitrary vendor symlinks. Source: `Development/ControllerPayload.php`, `Development/ReleaseContract.php`.
- KTD5. Preserve current CLI arguments and the existing shared tooling test runner. Move source-policy ownership and collectors without weakening their rules; add package graph/install coverage where the old single-package tests cannot establish the split. Source: `ToolingArchitectureTest`, `FixtureAutoloadTest`, `DevelopmentReleaseInputIntegrityTest`.
- KTD6. Retire implemented plans by evidence, not age or checked boxes. Keep the roadmap's verification record and Git history recovery points, and repair active links. The user's request supersedes the earlier policy of retaining completed plan files.

### Package Map

Composer names use `baander/` followed by the directory suffix. Development descriptions begin with “Development only”. The namespace column is beneath `Baander`.

| Directory | Ownership | Namespace | Availability |
| --- | --- | --- | --- |
| `baander-core` | Shared environment names and typed configuration paths | `Core` | Production |
| `baander-filesystem` | Private paths, atomic files and locks | `Filesystem` | Operational |
| `baander-process` | Process execution, arguments and runtime preflight | `Process` with Execution/Arguments/Runtime | Operational |
| `baander-configuration` | Configuration and layout/default resources | `Configuration` | Operational |
| `baander-containers` | Docker transport and Resources | `Containers` with existing subareas | Operational |
| `baander-images` | Image source preparation, builds, inventory and inventory worker | `Images` with Build/Inventory | Operational/build |
| `baander-deployment` | Deployment | `Deployment` | Operational |
| `baander-dev-environment` | Development except Acceptance; controller launcher | `DevEnvironment` | Development only |
| `baander-dev-qualification` | Fixtures, Development/Acceptance, qualification resources | `DevQualification` with Fixtures/Acceptance | Development only |
| `baander-dev-dependencies` | Dependencies and JavaScriptDependencies; policy resources | `DevDependencies` with PHP/JavaScript | Development only |
| `baander-dev-website` | Website | `DevWebsite` | Development only |
| `baander-dev-ci` | Backend gates, architecture checks and CI artifact storage | `DevCi` with Backend/Architecture/Artifacts | Development only |
| `baander-dev-registry` | Registry and its StaticAnalysis | `DevRegistry` | Development only |

Operational marks a host/build capability, not a requirement for every application process. Composer requirements reflect actual imports. The application needs core; the standalone operational scripts need their own capability closure. Website dependencies stay with website tooling and Composer semver with dependency tooling. Registry PHP qualification helpers are development-only; the registry's service architecture remains unchanged.

### Risks and Evidence

`ControllerPayload` currently copies one exact package and permits one vendor symlink. `ReleaseContract`, fixture image generation, build requests and backend checks also embed paths to the old package's resources. These consumers require behavioral checks beyond namespace replacement.

Production currently copies the full checkout before installing the standalone scripts project. Moving requirements alone leaves development source in the final image. CI and development images must retain it; production removal happens only after build/inventory work that requires it has finished.

The source policy has path-sensitive exemptions and generated-PHP checks. Preserve exemption anchors and synthetic negative tests. Existing solutions also require matching source/package mounts for CI checks and distinguish test coverage exhaustion from passing verification: `docs/solutions/developer-experience/local-gate-images-and-webkit-harness-runs.md` and `docs/solutions/developer-experience/coverage-runs-push-the-stack-lanes-past-the-backend-budget.md`.

---

## Implementation Units

### U1. Retire completed plans

- Goal: Remove implemented planning artifacts without losing verification history. Covers R7–R8, KTD6.
- Files: completed files in `docs/plans`, their incoming references, `ROADMAP.md`.
- Approach: Check plan scope against delivered roadmap checkpoints and commits; keep partial/uncertain plans. Use current document links or a fixed pre-cleanup Git recovery point for history.
- Verification: Every deletion has completion evidence; retained documents have no dangling links to deleted plans; whitespace and documentation checks pass.

### U2. Extract context packages and shared core

- Goal: Replace the monolith and make shared names available to production. Covers R1, R3–R4, KTD1–KTD2.
- Files: new package source/manifests/resources, old package removal, application environment-name consumers, PHP script and fixture imports.
- Approach: Move according to the map, preserve algorithms and protocol strings, declare direct dependencies, and migrate shared environment names without broadening policies.
- Verification: PHP syntax, core consumer tests, package graph consistency and namespace/resource reference audit. Existing tests retain their assertions.

### U3. Integrate installation and packaged consumers

- Goal: Install and publish the correct package closures. Covers R2, R4–R5, KTD3–KTD4.
- Files: root/scripts Composer manifests and locks; Dockerfiles; controller publication and release contracts; generated fixture contexts; CI installs and path filters; standalone loader messages.
- Approach: Split scripts production/development requirements, migrate all packaging paths, retain exact symlink validation and remove development code from final production images.
- Preview startup exposed a related source/dependency mismatch: the collector overlays current source onto a retained vendor volume before dependency refresh. Run the collector with the prepared operator image's matching source and dependencies; retain the prepared image during prior-release checkpoint preflight. Verify the generated mounts and successful activation of the retained preview.
- Verification: Clean root/scripts installs with and without development dependencies; controller multi-package publication/rejection tests; fixture source archive tests; production artifact package/source inspection.

### U4. Restore gates and document ownership

- Goal: Keep architectural and behavioral coverage over every moved area. Covers R6–R8, KTD5.
- Files: PHPStan, Deptrac, formatter and PHPUnit configuration; source policy and exceptions; tooling architecture/install tests; current package/developer documentation; roadmap.
- Approach: Replace old collectors with explicit new source ownership, enforce the production/development direction, and explain each package and shared core boundary. Run prose-fix on changed documentation.
- The full suite exposed a stale qualification assertion: log seeding added a sixteenth helper, but the report test still expected fifteen. Update that expectation and retain the helper out-of-memory rejection coverage.
- Update the current-checkout archive assertion for the standalone scripts autoloader and dependency tree now staged by the workflow; retain the baked-checkout case and checks that stopped probe commands never run.
- The full boundary check also found the existing log-viewer PTY driver implemented in Python. Preserve its eight terminal scenarios in a PHP driver using available FFI and process primitives; keep the no-Python workflow boundary strict. Verify terminal restoration in the CI image and the boundary check in both CI and lean runtimes. The application terminal command requires PCNTL through Symfony; its interactive scenarios are outside the lean tooling runtime's contract.
- Verification: Authoritative tooling format, architecture, static analysis, normal tooling and registry-subject suites; focused application environment tests; lean-host portability checks; relevant website build checks. Run simplification and code review, resolve justified findings, and record actual evidence inline.

### U5. Organize package internals and test ownership

- Goal: Make each library navigable by its own responsibilities, as requested by the user after the initial extraction. Covers R1, R4, R6, R9.
- Files: package source and tests, shared test support, consumers and test/static-analysis configuration.
- Approach: Group deployment presets, worker lifecycle, database setup and diagnostics; group development controller publication, host setup, instance state, releases and provisioning; group website content, rendering and publication. Keep small cohesive areas together. Colocate package-owned tests and fixtures; retain application/composition tests in the shared runner. Update namespaces and every consumer without compatibility aliases. The final package map also separates filesystem from process execution, names the image artifact lifecycle explicitly, and combines the three CI/check areas.
- Verification: Preserve the discovered test case inventory through moves, rerun package and integration tests, and verify static dependency/source rules and resource paths. The preliminary core checkpoint was 123 tests and 238 assertions; preliminary tooling Deptrac reported zero violations. The full tooling run was interrupted before reorganization and does not establish acceptance.

### U6. Apply PHP 8.5 idioms and named argument ordering

- Goal: Follow the user's additional syntax requirements. Covers R10–R11.
- Files: New and refactored package code; project-owned PHP calls with named arguments outside excluded areas.
- Approach: Resolve callable declarations before reordering named arguments. Keep positional arguments in place and preserve the original evaluation order where argument expressions have observable effects. Report unresolved dynamic calls rather than guessing their parameter order. Use readable pipelines and first-class callables where they simplify the new implementation.
- Verification: Run the parameter-order scan before and after changes, syntax checks, affected static-analysis gates and focused behavioral tests. Review side-effect-sensitive corrections individually.

### U7. Build shared configuration paths

- Goal: Replace repeated raw deployment setting lookups with an explicit, reusable path API. Covers R3, R12.
- Files: `baander-core` configuration path class and tests; deployment profile field catalog and readers; package documentation and core test wiring.
- Approach: Compose literal key and list-index segments immutably; accept context-owned string-backed enums as key segments. Resolve values without dot-path parsing, coercion, environment access or defaults. Distinguish absent keys, present nulls and wrong types without including values in errors. Migrate resolved deployment capacity reads while retaining preset/image default ownership and serialized field names.
- Verification: Cover nested paths, immutable branches, literal dotted keys, indexes, false/zero/empty values, nulls, missing segments and strict integer reads. Run profile, preset and pool-budget tests and ensure existing serialized output and capacity formulas are unchanged.

Review also identified that retirement must handle package links published before the split. Preserve publication's package allowlist while validating retirement links by their exact generation-relative location and target. Verify successful retirement of the former package plus rejection of escaping targets and misplaced links.

---

## Verification Contract

Use the current runners documented in `docs-book/part-2-developer-guide/testing/backend-runners.md` and the CI image. Serialize expensive/shared-state checks. The required tooling gates are `check:tooling-format`, `check:tooling-architecture`, `analyse:tooling`, `test:tooling`, and `test:registry-subjects`; retain the lean-host Docker runner. Install smoke checks must use clean vendor directories so old autoload maps cannot mask a missing dependency. Verify root production core availability, standalone operational availability and development absence independently.

Check controller publication, fixture-generated code and relocated resource identities with their existing behavioral tests plus bounded multi-package cases. Run focused application tests for environment admission and worker identity where their PHP consumers change. Verify production Docker composition and final source inventory. Build/test the website where its package paths and imports change. Record any environmental limitation explicitly rather than reporting an unrun gate as passed. `git diff --check` must pass, and every local commit excludes the existing `.idea/baander.iml` edit.

The final local PHP dependency audit reports seven existing Swoole fork-review
inventory discrepancies. The affected source and `packages/swoole-bundle/upstream-review.json`
are unchanged from `7491ddcecfa546a1845ff79f85878649e6f79560`. Reviewing those fork
changes and updating their evidence is separate work; this package split does not
refresh unrelated dependency approvals.

---

## Definition of Done

The old tooling package has no active consumer or source. Every retained area has one package owner, direct dependencies form an acyclic graph, and production excludes development packages and sources. Core shares environment names without changing policy. Standalone tools, controller generations and existing fixtures retain their behavior. Completed plans are removed, unfinished plans remain, and their incoming links resolve. Required verification and review evidence is in the roadmap. No TypePHP integration, compatibility alias, abandoned experiment or unrelated user change is included.
