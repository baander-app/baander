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
- Ownership: the lead owns setup, candidate-image integration and authoritative verification. Continue through runtime qualification in this worktree; production replacement remains outside scope.

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
- KTD5. Treat the PHP runtime as a separate qualification gate (R7). Use TypePHP's private PHP builder and the complete application extension set including FFI. The builder image uses PHP 8.5.12 ZTS; the language setting remains `8.5`. KTD10 resolves the private runtime's exact PHP and PECL source selection through the verified runtime lock. Verify that the generated runtime matches PHP 8.5.12 and its ABI before publication or application qualification. The production image uses ZTS; an NTS host build cannot establish production compatibility.
- KTD6. Keep setup as Make adapters and declarative compiler configuration; add no application orchestration abstraction (R4, R5). Follow the current Makefile's thin command pattern and keep root Composer unchanged.
- KTD7. Rebuild both packages at `/var/www/html`, the canonical root in `packages/baander-tooling/resources/application-layout.json`, and retain the matching physical source/resource tree there. The shared candidate image derives from the existing CI image and stores the generated executables outside that tree. Provide a candidate-only `/usr/local/bin/php` dispatcher selecting the operator executable for the paired fixture's operator-script invocation and the console executable otherwise. Both artifacts remain in one image, so existing controller and worker image selection stays intact. `PHP_BINARY` children identify the selected ELF executable and use the patched native CLI dispatch (R2, R3, R5).
- KTD8. Patch only the locked PHPX CLI adapter to recognize an existing regular file through `stat` before prepending the default entry, including the physical extensionless console. The check does not inspect embedded resources or establish readability. Preserve interpreter options and the default application-command path. This addresses the bare script invocations in backend runners, container startup scripts and failure-transport retries without rewriting their call sites (R2). Apply the patch only in the optional TypePHP toolchain and rebuild both artifacts.
- KTD9. Keep development dependencies outside embedded resources. Before replacing ordinary PHP in the candidate, generate the CI development autoload mappings and bootstrap-file metadata into `/usr/local/lib/typephp/test-autoload.php`, outside embedded paths. An external `auto_prepend_file` initializes the packaged production loader through `vendor/composer/autoload_real.php` and its generated initializer, then extends that existing loader with development/test mappings and guarded development bootstrap files. Supplement Composer's installed-version inventory only with missing development package records, preserving production versions and root metadata. Leave `vendor/autoload.php` un-included: Symfony Runtime uses its first `require_once` result to decide whether to execute the console closure. Do not initialize a second Composer loader with the same generated class name. Verify its behavior in nested test processes before claiming backend coverage. The candidate uses an isolated external INI with FFI enabled and the documented memory settings; it must not scan the CI image's shared-extension INI directory (R3, R6).
- KTD10. Inventory extension versions and enabled features against the existing application runtime before qualification. Add the missing build flags and extensions needed to preserve application behavior, rather than treating `extension_loaded` as parity. Add a tool-owned runtime lock for PHP 8.5.12, the selected PECL source versions and SHA-256 checksums, and required GD/Redis/Swoole configure flags. A narrow patch makes the private builder consume that lock instead of dynamic stable metadata and includes its hash in the derived cache identity. No distribution is authorized (R2, R3, R7).

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

### Qualification design

The runtime qualification follows these boundaries; the diagram shows responsibility rather than prescribing class structure.

```mermaid
flowchart TB
  Toolchain[Locked TypePHP and patched PHPX toolchain] --> Console[Console executable built at canonical root]
  Toolchain --> Operator[Independent operator executable built at canonical root]
  Console --> Candidate[Disposable CI-derived application candidate]
  Operator --> Candidate
  Physical[Matching physical source and external configuration] --> Candidate
  Dev[External development support loader] --> Candidate
  Candidate --> Suites[Existing backend suites and runtime drills]
  Candidate --> Paired[Existing paired operator and lifecycle drills]
```

The current setup's successful package builds and console listing are recorded in `tools/typephp/README.md` and `ROADMAP.md`; they do not establish runtime acceptance. `packages/baander-tooling/src/Backend/Runtime.php` invokes literal `php` and requires the canonical root, while `scripts/backend-test-runner.php` starts PHPUnit and ParaTest through `PHP_BINARY`. The web and worker fixtures also launch scripts through ordinary PHP command shapes. Therefore a candidate image tag alone does not prove that these tests execute the generated artifact.

### Open Questions and Execution Gates

- Setup can proceed independently of runtime qualification. The matching build image is part of setup; a known host NTS mismatch alone does not complete the package attempt.
- Package generation must verify the selected PHP/PHPX prerequisites and production dependency tree before running; `--dry` also provisions runtime dependencies and is not a syntax-only check.
- Runtime qualification proceeds through U4–U6 using disposable services only. Application acceptance remains blocked until the generated executable proves ZEngine, source reflection, extensions, path resolution and subprocess behavior. Failures must be recorded with the failing command and diagnostic, then resolved within the packaging design or brought back as a concrete design decision.
- A distributable runtime must use verified PHP 8.5.12 ZTS and extension versions matching the application. The builder image alone does not pin the private PHP runtime; KTD10 supplies exact PHP and PECL source selection. Verify the generated runtime against that lock before publication.

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
- Approach: preserve the existing entry scripts through the PHP console adapter, keep output under ignored `var/typephp`, and select production application paths explicitly. Install the existing locked application dependencies with `--no-dev --no-scripts`, keeping its existing Composer plugins enabled for patches and the runtime loader. Run packaging in the ephemeral toolchain container. Apply the narrow object-directory patch to the pinned private PHP builder only in the optional toolchain image: create the object target directories named by its generated Makefile before `make`. The real build exposed missing nested igbinary object directories in the separate PHP build tree; the patch leaves application code intact. Apply the Swoole source-path patch in that image as well: replace the copied extension configure fallback with `$abs_srcdir/ext/swoole`, require exactly one replacement, and change the derived source-cache fingerprint to regenerate configure. The initial Swoole 6.2.6 build otherwise resolved its relative fallback from the separate build directory, omitted extension sources and failed linking its module entry and coroutine symbols. Fail clearly for missing application dependencies. Do not invoke the operator or start services as a build check.
- Verification: parse every YAML, resolve each path, inspect embedding scope for secrets, rerun the compiler's real build command with the matching ZTS toolchain after applying the object-directory patch, confirm it passes the previously failing igbinary compilation and Swoole linking steps with both builder patches, and retain the first prerequisite or compiler failure verbatim in maintained documentation if it blocks generation.
- Acceptance checkpoint: an installed compiler and valid YAML are setup evidence only; AE3 requires the runtime gates below.

### U3. Document the setup and remaining acceptance

- Goal: make the setup reproducible and its limits explicit (R7).
- Files: `tools/typephp/README.md`, `ROADMAP.md`.
- Approach: document prerequisites, install/build commands, physical resource requirements, versions, observed results and unresolved runtime acceptance. Apply `prose-fix` and a focused code review before local delivery.
- Verification: run each documented command that is available, check links and paths, and record the exact verification checkpoint in the roadmap.

### U4. Preserve ordinary PHP CLI dispatch and rebuild at the application root

- Goal: preserve the existing console, script and subprocess invocation contracts under the packaged runtime (R1–R3; AE3; KTD7, KTD8).
- Files: `tools/typephp/phpx-cli-script-dispatch.patch`, `docker/typephp/Dockerfile`, `Makefile`, focused CLI regression fixtures under `tools/typephp/tests/`.
- Approach: patch the pinned PHPX adapter before its private-runtime build, invalidate derived caches as needed, and build both artifacts with the application mounted at `/var/www/html`. Recognize script inputs before applying the default entry; preserve argument boundaries and exit statuses without introducing a shell wrapper that `PHP_BINARY` children bypass.
- Test scenarios: the real console binary handles default-entry `list --raw`, an explicit console script and a small readable script with arguments; `-r`, `-d`, `-f` and `--` retain interpreter behavior; an unknown application command still fails through Symfony rather than becoming a filename. A child launched through `PHP_BINARY` executes the requested script once with its original arguments. Verify the separate operator entry similarly without invoking a real Docker socket.
- Verification: run the focused scenarios against the rebuilt executables, inspect embedded path identity, and record the source revisions and artifact hashes. These tests cover the new dispatch behavior, not the one-line console adapter.
- Verified stdin defect: PHPUnit's isolated children invoke `PHP_BINARY` without arguments and pipe their PHP program to stdin. The packaged adapter instead selects the default console entry; a direct probe prints Symfony help instead of the supplied marker, and two heap-limit cases terminate before any assertion. Recognize piped or redirected script input when there are no arguments, while retaining the default entry for terminal or `/dev/null` input. Add a no-argument stdin regression alongside the explicit `--` case.

### U5. Compose isolated runtime candidates and qualify prerequisites

- Goal: give existing verification runners the generated application and operator runtimes with their required physical resources and development support (R2, R3, R5, R6; AE3; KTD7–KTD10).
- Files: `docker/typephp/qualification.Dockerfile`, `tools/typephp/qualification.ini`, `tools/typephp/qualification-php`, `tools/typephp/test-support.php`, `tools/typephp/dump-test-support.php`, `tools/typephp/tests/dispatch.php`, `tools/typephp/application.yml`, `tools/typephp/runtime-lock.json`, `tools/typephp/php-builder-runtime-lock.patch`, `Makefile`.
- Approach: derive the shared application/operator qualification image from the existing CI image at the same source revision. Keep executables under `/usr/local/lib/typephp`, outside the canonical application tree. Select the appropriate executable through the candidate-only dispatcher described by KTD7. Match the fixture's canonical or relative operator script and its supported explicit `-f` form without consuming or reinterpreting application arguments; execute the chosen ELF with the original arguments. Isolate the INI scan path and externalize writable state and secrets. Generate the external support metadata with the ordinary CI PHP and the matching locked vendor tree before selecting the candidate executable. Extend the packaged production loader as described by KTD9, without replacing production autoload mappings or embedding development dependencies.
- Prerequisites: compare the current runtime's PHP/ZTS/FFI, Swoole, Redis and GD versions and capabilities with the generated runtime, plus the complete enabled-extension inventory. The measured CI baseline is PHP 8.5.12 ZTS, Swoole 6.2.4, Redis 6.3.0 and GD with FreeType, JPEG, XPM and WebP; `Dockerfile` records the additional extension and configure requirements. Resolve missing application-required options and extensions in the private builder and rebuild from the verified runtime lock. Check downloaded source hashes before use, and make lock changes invalidate derived runtime caches; pre-seeding PECL caches alone does not prevent upstream stable-version resolution. Do not load the CI image's shared extension binaries into the private runtime or disable required behavior to obtain a passing check.
- Test scenarios: prove literal `php`, absolute `/usr/local/bin/php` and `PHP_BINARY` children use the selected artifact; verify the dispatcher routes the fixture's operator-script forms to the operator ELF, ordinary scripts/default commands to the console ELF, and preserves arguments and exit status; the canonical physical tree supports source reflection and generated container loading; PHPUnit, ParaTest and test bootstrap classes load through the external support tree while production classes resolve to the intended application graph. Verify effective INI settings in parent and child processes. In a disposable copied tree, make a physical fixture differ from its embedded counterpart and demonstrate which one executes, so a matching path or image label cannot substitute for artifact coverage. The selected operator process remains independent of Symfony boot.
- Verification: record executable identities, effective INI/extension manifests, dependency resolution and artifact-to-source identity. Run console listing, kernel boot and cache warmup through the candidate against disposable services before full suites. `BAANDER_TEST_CHECKOUT_IN_IMAGE=1` keeps the verified image checkout; do not let a runner replace its application/vendor tree with an unrelated checkout.
- Verified dispatch defect: `WorkerLifecycle.php` supplies `-d auto_prepend_file=...` before the operator script. A first-argument-only dispatcher selected the console executable. Parse leading interpreter settings for executable selection while forwarding the original arguments, and retain the failing executable-identity case as a regression check.
- Verified build defect: Redis's bundled LZF include path resolves from the PHP source root when its extension is compiled into the private runtime. `tools/typephp/php-builder-redis-lzf.patch` corrects that path only in the copied extension configuration and invalidates derived source identity. Preserve the existing LZF feature and verify the build passes the observed `lzf.h: No such file or directory` failure.
- Verified link defect: the static native PDO PostgreSQL driver and Swoole's hooked copy export 26 identical symbols; the hooked SQLite copy has two corresponding collisions when native PDO SQLite is enabled. `tools/typephp/php-builder-swoole-pdo-symbols.patch` applies a forced alias header only to Swoole's translation units and changes derived source identity. Native PDO symbols and public PHP method names remain unchanged. Verify symbol separation, synchronous and coroutine database queries, available PostgreSQL COPY/LOB/PID/notification methods and coroutine yielding through `tools/typephp/tests/pdo.php` against disposable services. The current CI runtime returns `PDO`, not `Pdo\Pgsql`, from `PDO::connect` under Swoole and exposes no legacy notice callback method; adding that modern API is separate extension work, not a packaging requirement.
- Verified COPY defect: after symbol separation, the Swoole PostgreSQL result helper repeatedly drains `PGRES_COPY_IN` before its caller can send data; the fixture hangs with PostgreSQL waiting for COPY input while the existing CI runtime passes that operation. `tools/typephp/php-builder-swoole-copy-result.patch` returns COPY direction statuses to the caller immediately, preserving the caller's final result checks and ordinary error draining. Retain both synchronous and coroutine COPY round trips as regression coverage and invalidate derived source identity.
- Verified test-loader defect: the candidate can autoload development classes while its embedded `Composer\InstalledVersions` still reports those packages absent; console boot fails when the web profiler requests its package version. Supplement the external test metadata with development package records and merge only missing records marked as development requirements into the embedded production inventory. Preserve production versions and root metadata, and verify console boot and development test discovery against the candidate.

- Verified eval-loader defect: PHP's `-r` path skips `auto_prepend_file`, so child programs requiring the embedded production autoloader cannot resolve test fixtures. Add a qualification-only hook to the generated CLI adapter, enabled by `BAANDER_TYPEPHP_QUALIFICATION=1`, which executes the effective prepend file before direct code evaluation. Keep the supplied code, arguments and actual `PHP_BINARY` unchanged; honor `-n`, explicit prepend overrides and bootstrap failures. Execute an explicitly configured prepend even under `-n`, preserve its deliberate exit status and stop direct evaluation when the prepend exits or fails. Without the marker, retain upstream interpreter behavior. Development code and metadata stay outside both executables.
- Verified INI mismatch: the candidate enabled `register_argc_argv`, while the CI image disables it. PHP 8.5 emits a deprecation before handling local CLI-server requests, corrupting readiness responses and sending status 200 before redirect headers. Match the baseline setting and rerun the affected HTTP fixture tests without suppressing errors or changing their assertions.
- Verified filesystem mismatch: after a child process removes a physical file, ordinary PHP reports it absent but the packaged runtime can reuse a positive stat result. PHPX's replacement file wrapper bypasses PHP's native access-check path, affecting existence and permission checks. The focused regression also reports denied physical paths accessible outside `open_basedir`; ordinary PHP rejects those checks. Preserve fresh physical-path access checks, `open_basedir` enforcement and embedded-resource detection in the runtime; retain delegation for other stream wrappers. `tools/typephp/phpx-file-access.patch` proxies the physical access checks while preserving embedded and custom-wrapper delegation. Verify external removal and creation, permission changes, changed working directories, symlinks, argument coercion, custom wrappers, Swoole file-hook transitions and `open_basedir` through `tools/typephp/tests/filesystem.php`. TypePHP compiles the changed opcode-table source as a project object; rebuild both packages rather than invalidating the unrelated private PHPX archive. Do not hide this runtime difference by clearing the cache only in the failing socket-cleanup test.

### U6. Run application qualification and record its actual coverage

- Goal: prove the existing application behavior through the generated executables and record any remaining blocker separately from setup/build success (R1–R3, R7; AE3; KTD7–KTD10).
- Files: `Makefile` for thin qualification command adapters if needed, `tools/typephp/README.md`, `ROADMAP.md`; focused runtime regression tests only for verified packaging defects uncovered by these checks.
- Approach: run the existing backend suites and runtime drills with the candidate image selected, serializing shared or expensive runs. Use disposable PostgreSQL, Redis and fixture-owned Docker resources only. `OperatorProvisioning::setup` derives controller and worker images from the same application role. Use the candidate-only dispatcher to select the independent operator executable for the fixture's `/usr/local/bin/php` operator-script command while keeping the worker on the console executable. Verify the controller process executable is the operator ELF and the worker process executable is the console ELF; ordinary console interpretation of the operator source is not proof of the separate operator artifact. Fix verified packaging defects within these units and rerun the affected checks.
- Test scenarios: full Unit, StaticAnalysisRules, Functional and Integration suites; native Swoole coroutine isolation and logging; database-backed HTTP and authentication flows; outbox/producer rollback and message delivery; current worker supervision; web reload/drain and shutdown; paired operator and worker lifecycle. Keep each existing runner's assertions and deadlines, and record any skips or unsupported environment prerequisites without claiming the corresponding behavior passed.
- Verification: execute the commands in the runtime matrix below. Store suite/assertion counts, runtime drill outcomes, candidate/artifact identities and applicable static gates inline in maintained documentation. Run `ce-simplify-code`, focused code review, `prose-fix` and `git diff --check` before local commits; do not push or change deployment configuration.

## Verification Contract

Setup gates are `composer --working-dir=tools/typephp validate --strict`, a locked install, compiler version/help, YAML parsing, embedding-scope inspection and `git diff --check`.
A real package build is the integration check for the new configuration; failure is a blocker to package acceptance, not a passing build.
No backend behavior changes are planned during setup, so backend suites do not substitute for this compiler check.

For application acceptance, first establish U4 dispatch and U5 candidate-image prerequisites. The commands below use `BAANDER_TEST_IMAGE` for the verified application candidate and `BAANDER_TEST_CHECKOUT_IN_IMAGE=1` where supported. The host coordinator may run through its ordinary development PHP; discovery, test workers, application processes and their children inside the candidate must use the generated executable. Do not infer artifact coverage from the image name: verify the runner's actual argv, selected executable and autoload graph.

| Check | Existing command or scope | Required evidence |
|---|---|---|
| Backend suites | `bash scripts/test-backend-container.sh --no-coverage` | All four suites through the candidate, with counts and explicit skips; retain the runner's expected/observed test-identity checks. |
| Coroutine behavior | Native lane including `ContextualDoctrineRegistryTest` and `CoroutineSafeLoggingTest` | Swoole remains enabled and the existing isolation assertions execute under the candidate. |
| HTTP and persistence | `bash scripts/test-web-runtime-container.sh` | The existing database-backed HTTP, mail, streaming, PostgreSQL reload/outage and Redis recovery assertions pass. |
| Web lifecycle | `bash scripts/test-web-shutdown-container.sh` | Existing PID-1, reload, signal, drain and descendant-cleanup assertions pass under the candidate. |
| Message behavior | `bash scripts/test-outbox-runtime-container.sh` and `bash scripts/test-producer-runtime-container.sh` | Existing acknowledgement/replay, rollback and producer recovery assertions pass. |
| Worker supervision | `bash scripts/test-worker-command-container.sh` | Current worker supervisor and lease lifecycle run through the candidate. |
| Operator and worker lifecycle | `bash scripts/test-worker-operator-container.sh` and `bash scripts/test-worker-lifecycle-container.sh` | Use the shared candidate image and its operator dispatcher with the documented container-mode and host prerequisites; prove the independent packaged operator and candidate worker execute the existing disposable drill. |

The runner contracts and detailed assertions are maintained in `docs-book/part-2-developer-guide/testing/README.md`, `backend-runners.md`, `runtime-drills.md` and `workers-and-messaging.md`. Their PostgreSQL and Redis services remain disposable; no schema redesign or persistent-instance change is included. The legacy Supervisor worker-runtime fixture does not establish current deployment behavior.

Preserve the existing application behavior if a runtime mismatch is found. Record the failing command and diagnostic, resolve a packaging or candidate-environment defect within U4–U6, and rerun the affected check. An application design change requires a concrete decision rather than weakening an assertion. Browser checks are conditional on a verified affected browser flow; use `make preview-up` and its explicit `E2E_BASE_URL` only when that flow is needed, and do not claim that an ordinary preview verifies the candidate executable.

## Definition of Done

Setup is delivered when the compiler is locked and runnable, application packaging configurations are present and checked, the real build attempt has an honest recorded outcome, and documentation and review are complete.
The whole application is packaged only when package generation succeeds.
It is qualified for application use only when the runtime matrix passes through the candidate artifacts, their extension/INI and physical-path prerequisites are verified, and maintained documentation records exact coverage and remaining limits. A successful test under ordinary PHP or a different dependency graph cannot close this acceptance gate.
Local commits record delivered units; the roadmap records evidence and remaining acceptance.
