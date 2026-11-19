---
title: TypePHP application packaging - Plan
type: feat
date: 2026-11-19
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: code
---

# TypePHP application packaging - Plan

## Goal Capsule

- Objective: developers can build and evaluate a packaged executable of the Baander PHP application.
- Means: TypePHP CLI binaries containing application PHP bytecode executed by ZendVM (KTD1).
- Authority: the Product Contract owns behavior; technical decisions own the build mechanism; units implement those decisions.
- Execution profile: one branch, `feat/typephp-setup`, with local commits in a separate worktree; no push or deployment.
- Stop conditions: report compiler, runtime or dependency failures with reproducible evidence; do not claim application acceptance from compiler installation or a toy program.
- Ownership: the lead owns setup, integration and verification; production replacement waits for the application gates below.

## Product Contract

### Summary

Set up a pinned TypePHP compiler and application packaging configurations, then exercise the available build path.
The target includes the console used by the HTTP server and workers, plus the separate worker deployment operator.

### Problem Frame

Baander currently starts PHP scripts through its container PHP runtime.
The requested executable must preserve Symfony boot, Doctrine entities, Swoole coroutine isolation and worker behavior.
TypePHP supports both native PHP translation and bytecode embedding, but these paths have different compatibility limits.

### Requirements

**Application behavior**

- R1. Package the whole main PHP application's runtime dependency closure rather than a demonstration class.
- R2. Preserve `bin/console` arguments for operator commands, `app:serve` and `app:worker`, and preserve the independent `bin/worker-deployment.php` composition root.
- R3. Retain ZendVM execution where application or dependency semantics require it.

**Build and isolation**

- R4. Pin the compiler and its Composer dependencies independently of the application Composer graph.
- R5. Keep generated files and dependencies in the separate worktree, and keep the persistent instance and its data intact.
- R6. Keep secrets, writable state and development dependencies out of embedded resources.
- R7. Record setup evidence separately from package-build and application-runtime acceptance.

### Key Decisions

Use a packaged executable with ZendVM rather than require all PHP to become native machine code.
Governs R1 and R3.
(session-settled: user-directed — chosen over full native compilation after generator and Doctrine lazy-object incompatibilities were explained.)

### Scope Boundaries

The main PHP application and its local runtime packages are included.
Browser assets and native media programs remain ordinary runtime resources.
The separate relay keeps its SQLite/rqlite architecture; this setup does not replace its release process.
`ui/rn`, Essentia, production deployment, database reset and unrelated application refactors remain outside this work.

### Acceptance Examples

- AE1. A fresh worktree installs the locked compiler without changing root `composer.json` or `composer.lock` (R4, R5).
- AE2. Packaging either produces the requested executable or returns the underlying build failure; the report distinguishes those outcomes (R1, R7).
- AE3. A qualified package runs the existing console, HTTP server, workers and operator with the existing runtime isolation contracts (R2, R3).
- AE4. Embedded resources contain no `.env.local`, `config/secrets`, private key, database or `var/` tree (R6).

## Planning Contract

### Key Technical Decisions

- KTD1. Use `mode: bin`, `sapi: cli` and a PHP entry adapter, with application classes in `embedded-files` and no application AOT sources. The console adapter returns the result of requiring the existing `bin/console`; TypePHP rejects that extensionless script as a direct entry. Set `sources` to the same PHP entry: the compiler removes it from AOT inputs and embeds it. An empty source list instead triggers recursive discovery. This preserves user-class semantics for Doctrine lazy objects and generators (R1–R3). Upstream documents CLI entry embedding in `src/Build/SourcePipelineTrait.php` and [embedding](https://github.com/swoole/typephp/blob/41d91fe69d2f7ec99dffe252a95244793000c6d2/docs/en/EMBEDDED_FILES.md).
- KTD2. Install TypePHP commit `41d91fe69d2f7ec99dffe252a95244793000c6d2` in `tools/typephp` with a generated and committed tool-only Composer lock (R4). Source installation runs under PHP 8.5; upstream Unix binaries require ZTS while the host CLI is NTS. No global install is needed. Provide an optional `docker/typephp/Dockerfile` using the verified `php:8.5.12-zts-trixie@sha256:2ffa2b7471a9826f8a28f722ff21f83bc560367c0135372d88697f6c0666b5eb` base, compiler build prerequisites and pinned Composer; package in an ephemeral container with that matching ZTS toolchain.
- KTD3. Keep separate console and operator project configurations sharing the packaging settings (R2). The operator must not boot Symfony or gain authority inside an application container; its existing entry owns that boundary.
- KTD4. Use explicit embedded paths for application PHP and production vendor files; retain the physical application/resource tree during initial qualification (R1, R6). TypePHP does not provide general directory enumeration over embedded files, and Baander's coroutine container inspects PHP source through reflection. Embedding all `config` is unsafe because it includes runtime secrets.
- KTD5. Treat the PHP runtime as a separate qualification gate (R7). CLI packaging requires TypePHP's private PHP builder and the complete application extension set including FFI. The builder image is pinned to PHP 8.5.12 ZTS, but `php-version` accepts `8.5`, not `8.5.12`; normalization to `8.5.0` leaves the private builder selecting a cached or latest patch release. The exact private PHP runtime pin remains unresolved alongside PECL source pinning. Verify that the generated runtime matches PHP 8.5.12 and its ABI before publication or application qualification. The production image uses ZTS; an NTS host build cannot establish production compatibility. Upstream PECL resolution uses `stable.txt`, so a release recipe must pin or verify those extension sources before publication.
- KTD6. Keep setup as Make adapters and declarative compiler configuration; add no application orchestration abstraction (R4, R5). Follow the current Makefile's thin command pattern and keep root Composer unchanged.

### Evidence and compatibility boundaries

`docker/general/start-web.sh` and `start-worker.sh` launch the console; `start-operator.sh` launches the independent operator.
`packages/swoole-bundle/src/Bridge/Symfony/Kernel/CoroutinesSupportingKernel.php` initializes ZEngine before its coroutine branch, and its container modifier generates and loads PHP at runtime.
`ContextualErrorHandler.php` redefines built-in error-handler functions through ZEngine.
A packaged CLI must prove that these mechanisms still work.

The fully native alternative conflicts with `AudioRenditionCache::follow(): Generator` and Doctrine's `ReflectionClass::newLazyGhost` use.
[TypePHP's incompatibility list](https://github.com/swoole/typephp/blob/41d91fe69d2f7ec99dffe252a95244793000c6d2/docs/en/INCOMPATIBLE_PHP_FEATURES.md) and [generator contract](https://github.com/swoole/typephp/blob/41d91fe69d2f7ec99dffe252a95244793000c6d2/docs/en/YIELD_GENERATOR.md) establish those limits.
PHP 8.5 pipes are supported and are not a known blocker.

The runtime lessons in `docs/solutions/runtime-errors/swoole-callbacks-outside-the-bundle-leak-pooled-services.md` and `swoole-sigterm-waits-for-master-timers-and-closes-connections-before-workers-stop.md` govern runtime qualification.
`docs/solutions/developer-experience/local-gate-images-and-webkit-harness-runs.md` distinguishes local checks from authoritative image checks.

### Open Questions and Execution Gates

- Setup can proceed independently of runtime qualification. The matching build image is part of setup; a known host NTS mismatch alone does not complete the package attempt.
- Package generation must verify the selected PHP/PHPX prerequisites and production dependency tree before running; `--dry` also provisions runtime dependencies and is not a syntax-only check.
- Application acceptance remains blocked until the generated executable proves ZEngine, source reflection, extensions, path resolution and subprocess behavior. Failures must be recorded with the failing command and diagnostic, then resolved within the packaging design or brought back as a concrete design decision.
- A distributable runtime must use verified PHP 8.5.12 ZTS and extension versions matching the application. The pinned builder image does not pin the private PHP runtime: resolve its patch selection and PECL source versions before publication, and verify the generated runtime against that target.

## Implementation Units

### U1. Install the isolated compiler

- Goal: provide a repeatable compiler installation (R4, R5; AE1; KTD2).
- Files: `tools/typephp/composer.json`, `tools/typephp/composer.lock`, `tools/typephp/.gitignore`, `docker/typephp/Dockerfile`, `Makefile`.
- Approach: disable Composer plugins/scripts, install production compiler dependencies, and expose setup/version commands without changing application dependencies. Add a `typephp-toolchain` image target and the matching build environment described by KTD2; leave the application runtime Dockerfile intact.
- Verification: Composer validation, locked reinstall, compiler version/help and a clean root-manifest diff.
- Test expectation: no unit test for declarative dependency setup; exercise the actual Composer and compiler commands.

### U2. Configure whole-application packaging

- Goal: provide explicit console and operator build configurations and attempt package generation (R1–R3, R6, R7; AE2–AE4; KTD1, KTD3–KTD6).
- Files: `tools/typephp/application.yml`, `tools/typephp/console.yml`, `tools/typephp/operator.yml`, `tools/typephp/console-entry.php`, `tools/typephp/php-builder-object-directories.patch`, `tools/typephp/php-builder-swoole-sources.patch`, `Makefile`.
- Approach: preserve the existing entry scripts through the PHP console adapter, keep output under ignored `var/typephp`, and select production application paths explicitly. Install the existing locked application dependencies with `--no-dev --no-scripts`, keeping its existing Composer plugins enabled for patches and the runtime loader. Run packaging in the ephemeral toolchain container. Apply the narrow object-directory patch to the pinned private PHP builder only in the optional toolchain image: create the object target directories named by its generated Makefile before `make`. The real build exposed missing nested igbinary object directories in the separate PHP build tree; the patch leaves application code intact. Apply the Swoole source-path patch in that image as well: replace the copied extension configure fallback with `$abs_srcdir/ext/swoole`, require exactly one replacement, and change the derived source-cache fingerprint to regenerate configure. Swoole 6.2.6 otherwise resolves its relative fallback from the separate build directory, omits extension sources and fails linking its module entry and coroutine symbols. Fail clearly for missing application dependencies. Do not invoke the operator or start services as a build check.
- Verification: parse every YAML, resolve each path, inspect embedding scope for secrets, rerun the compiler's real build command with the matching ZTS toolchain after applying the object-directory patch, confirm it passes the previously failing igbinary compilation and Swoole linking steps with both builder patches, and retain the first prerequisite or compiler failure verbatim in maintained documentation if it blocks generation.
- Acceptance checkpoint: an installed compiler and valid YAML are setup evidence only; AE3 requires the runtime gates below.

### U3. Document the setup and remaining acceptance

- Goal: make the setup reproducible and its limits explicit (R7).
- Files: `tools/typephp/README.md`, `ROADMAP.md`.
- Approach: document prerequisites, install/build commands, physical resource requirements, versions, observed results and unresolved runtime acceptance. Apply `prose-fix` and a focused code review before local delivery.
- Verification: run each documented command that is available, check links and paths, and record the exact verification checkpoint in the roadmap.

## Verification Contract

Setup gates are `composer --working-dir=tools/typephp validate --strict`, a locked install, compiler version/help, YAML parsing, embedding-scope inspection and `git diff --check`.
A real package build is the integration check for the new configuration; failure is a blocker to package acceptance, not a passing build.
No backend behavior changes are planned during setup, so backend suites do not substitute for this compiler check.

For application acceptance, use a disposable image and fixture services with the same PHP ZTS ABI and extension set as the current runtime.
Verify console listing, kernel boot and container warmup before `app:serve`, then a database-backed request, worker message processing, coroutine service isolation, drain/reload and the separate operator path.
Verify application options using explicit PHP script dispatch, including `-f /app/bin/console -- --version`: bare `--version` selects PHP's interpreter version in the pinned CLI adapter. Inventory `PHP_BINARY` child commands as well as `/usr/local/bin/php -r`. In particular, the failure-transport retry currently passes `bin/console` as a bare child argument, which the packaged adapter would interpret as an application argument after prepending its entry. Retain the ordinary PHP runtime dependency and block acceptance for affected paths until verified dispatch adapters or deliberate call-site changes preserve their behavior.
Run the existing backend suites through `scripts/test-backend-container.sh` against that candidate image and the focused runtime drills from the testing guide.
For browser acceptance, use `make preview-up` and pass `make preview-url` explicitly as `E2E_BASE_URL`.
These application gates run only once a package builds; report them as not run otherwise.

## Definition of Done

Setup is delivered when the compiler is locked and runnable, application packaging configurations are present and checked, the real build attempt has an honest recorded outcome, and documentation and review are complete.
The whole application is packaged only when package generation succeeds.
It is qualified for application use only when the application runtime gates pass.
Local commits record delivered units; the roadmap records evidence and remaining acceptance.
