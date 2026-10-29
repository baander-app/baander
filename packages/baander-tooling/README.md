# Standalone workflow tooling

The `baander/tooling` Composer package maps `Baander\Tooling` to `src/`.
Both the root manifest and `scripts/composer.json` require it through a local path
repository, so PhpStorm and runtime loading use the same namespace. The library
requires PHP 8.5 and has no external package dependencies. Generate the scripts
loader with `composer --working-dir=scripts install --no-dev` or the root command
`composer tooling:autoload`, which installs both the `scripts/` and `relay/`
Composer projects. `scripts/bootstrap.php` requires the scripts loader without
booting the application kernel. Workflow entrypoints call
`Runtime\Requirements::check()` before changing state. The baseline is PHP 8.5
with Swoole. Leaf commands that do not run processes can pass `false`; commands
needing additional extensions pass their names in the second argument.

## Configuration and image roles

[`resources/tooling-defaults.json`](resources/tooling-defaults.json) owns image
references, declared role capabilities, Docker defaults and finite workflow budgets.
[`resources/application-layout.json`](resources/application-layout.json) separately
owns the versioned application root. Local settings cannot override that root or
role capabilities.

`Configuration\ToolingConfiguration` resolves supported settings once, using
explicit invocation settings, then captured environment settings, then the chosen
`dev.local.json` file's `tooling` object, then repository defaults. The immutable
result records non-secret values and their provenance; nested consumers receive it
rather than rereading local files or the environment. The local file can also
contain `libraries`. A linked worktree without its own file uses the primary
checkout's file from the same repository; files are not merged. Qualification
entrypoints using `RepositoryConfiguration` ignore local overrides in CI.

Each image override supplies a complete `reference`; registry, repository and tag
or digest changes belong in that value. Supported roles and environment overrides
are:

| Role | Environment setting |
| --- | --- |
| `application` | `BAANDER_TEST_IMAGE` |
| `postgres` | `BAANDER_TEST_POSTGRES_IMAGE` |
| `developmentRedis` | `BAANDER_DEVELOPMENT_REDIS_IMAGE` |
| `fixtureRedis` | `BAANDER_TEST_REDIS_IMAGE` |
| `nginx` | `BAANDER_NGINX_IMAGE` |
| `mailpit` | `BAANDER_TEST_MAILPIT_IMAGE` |
| `proxy` | `BAANDER_TEST_PROXY_IMAGE` |
| `browser` | `BAANDER_BROWSER_IMAGE` |
| `operatorHelper` | `BAANDER_TEST_DOCKER_CLI_IMAGE` |

A custom browser reference must also declare `playwrightVersion` in its local image
object, or set `BAANDER_BROWSER_PLAYWRIGHT_VERSION` alongside the environment image
override. Browser preflight compares that declaration with the installed
`ui/web` Playwright package before creating the browser. Image tag spelling is not
used to infer compatibility. The declared capabilities describe the selected role;
they do not qualify an arbitrary image's runtime behavior.

Docker settings are `executable` and one of `host` or `context`. Their environment
settings are `BAANDER_DOCKER_EXECUTABLE`, `DOCKER_HOST` and `DOCKER_CONTEXT`.
`BAANDER_TEST_DOCKER_CONTEXT` overrides the ambient context for qualifications.
When environment context and host settings coexist, context wins. An explicit
host or context replaces the lower-precedence selector; supplying both in the same
local or explicit settings object fails. Development `up` accepts
`--docker-executable`, `--docker-host` and `--docker-context` through `OPTIONS`.
Other entrypoints expose their own documented arguments, not a universal flag set.
The selected executable, endpoint, TLS material and daemon identity travel with
Docker operations. Compose is checked only by workflows that use it; a remote
endpoint does not establish that client bind paths or loopback ports are usable.

Budget overrides use named numeric seconds under `tooling.budgets`. Unknown keys,
wrong types and nonpositive or nonfinite budgets fail preflight. The catalog lists
every budget: backend defaults to 3600 seconds, messaging to 600 seconds and cleanup
to a shared total of 120 seconds. `BAANDER_TEST_TIMEOUT` overrides `backend`;
`BAANDER_CLEANUP_TIMEOUT` overrides `cleanup`. Bounded process calls are clamped to
their owning deadline. Managed services and builds retain their explicit owning
lifetimes. Interruption records failure and enters owned cleanup; exhausted cleanup
retains unresolved ownership receipts.

Exclusive checkpoint settings use `tooling.checkpoints.controlCapacity` or
`tooling.checkpoints.autoscaling`, with one path selected at a time. Their
environment settings are `BAANDER_TEST_CONTROL_CAPACITY_CHECKPOINT` and
`BAANDER_TEST_AUTOSCALING_CHECKPOINT`. They select the corresponding worker
qualification checkpoint; they do not enable a generic callback mechanism.

See [`dev.local.example.json`](../../dev.local.example.json) for local syntax.

## Application builds and saved releases

Build root-Dockerfile targets through the standalone entrypoint:

```bash
php scripts/build-application.php --buildx --target ci --tag baander-ci:local
php scripts/build-application.php --buildx --target worker --tag baander-worker:test
```

The build request reads the selected source's layout, supplies `APP_HOME` and image
layout metadata, then checks the built image's immutable ID and layout. The
Dockerfile requires that argument and has no independent application-root default.
`--buildx` loads the result into the selected daemon. Repeat `--tag` for additional
tags; `--source`, `--revision`, `--cache-from` and `--cache-to` supply build inputs.
`--compose-env FILE` writes the inspected immutable image and its `APP_HOME` for
Compose consumers. Use that generated file when the consuming Compose command
requires it.

`Docker\Client` pins builds to the selected daemon's default BuildKit builder.
It removes ambient `BUILDX_BUILDER` and `BUILDKIT_HOST` settings and enables
BuildKit only in the build command's environment; the caller's environment stays
unchanged. A remote builder cannot override the captured daemon selection.

Builds that consume selected local image IDs use owned temporary tags, verified
against those IDs before and after the build. Each shared base must retain another
verified tag or a digest from a different repository so removing the temporary tag
cannot remove the base. A dangling source image is rejected before alias creation.
Cleanup owns the temporary aliases and build outputs; it never claims ownership
of a shared base image.

A development prepared release saves its layout, Docker target, immutable image
identities, effective settings and generated-input/source identities before
activation. Recovery uses that saved contract even when current local preferences
are invalid or changed. Manual startup and update preparation validate selected
settings; unattended updates use preparation settings saved by `up`.
Existing PostgreSQL and Redis data retain their recorded image identities; changing
a preference does not authorize upgrading those data stores. Retained-record
conversion preserves original records and refuses ambiguous ownership. A conversion
refusal requires inspection of the saved state, not a data reset.

Installed controllers publish complete generations and atomically select one.
Invocations hold generation leases, so publication cannot prune code still in use.
Conversion drains older readers before replacing their saved-record contract;
unleased legacy dispatchers require an established invocation fence before that
conversion can proceed.

## Process and resource ownership

`Process\CommandExecutor` is the workflow execution contract;
`Process\EngineExecutor` applies `CompletionPolicy` to process results. Expected
nonzero exits may be admitted explicitly, but timeout and interruption remain
failures even if a child exits zero. `Docker\Client` uses that executor with one
resolved target; workflow code does not assemble raw Docker processes.

`Resources\ResourceLedger` records labeled creation intentions before Docker
creation and retains immutable identities afterward. Cleanup checks the saved
daemon and resource ownership, attempts independent resources within the total
cleanup budget, and verifies absence. Failed or uncertain removal keeps the
receipt for retry against the saved target. `Resources\ImageLedger` records build
image ownership separately. Keep receipts and transport snapshots until cleanup
resolves; a failed inspect or enumeration is not proof of absence. A cleanup error
makes an otherwise successful qualification fail while retaining any earlier
failure. Workflow-specific artifacts identify the receipt location; there is no
shared recovery command for every workflow.

`Process\SignalScope` keeps SIGINT/SIGTERM ownership across workflow operations;
runner waits share that ownership and retain the first interruption. Use it outside
application event loops: Swoole cannot return a foreign signal handler for
restoration. GNU `env` resets inherited signal masks before starting children.
Multiple processes can start before a wait, and each wait services all active
children. Captured output is returned in the result. With `logPath`, output streams
to a private, truncated file and the result's output strings are empty. The log
directory must be owned by the current user and must not permit other users to
write. Interactive processes inherit the caller's descriptors.

Use `stderrLogPath` when diagnostics must stay separate from structured stdout.
`appendLog` preserves earlier output when several commands share a log. A command
can set `terminationGrace` when its cancellation handler needs time to finish;
normal completion retains the short descendant-cleanup bound.

The filesystem helpers preserve private state with same-directory replacement,
file and directory synchronization, and process-owned locks. They reject symlink
paths. State directories belong to the current user; these checks do not isolate
the files from other processes running as that same user.

## Checks

Tooling tests use the root Composer dependencies for PHPUnit, without booting the
application kernel. `composer run-script --timeout=7200 test:tooling` runs the suite excluding
`registry-python-subject`; `composer test:registry-subjects` runs that group against
the unchanged registry backup service and enrollment issuer. The separate subject
gate requires Python 3, selected with `REGISTRY_SUBJECT_PYTHON` when needed. Both
gates are mandatory in CI. Static analysis uses `composer analyse:tooling`. These
test and analysis commands first install the standalone loaders.
Format workflow PHP with `composer format:tooling`; verify formatting with
`composer check:tooling-format`. Run `composer deptrac:tooling` for dependency
boundaries, `composer check:tooling-source-policy` for source ownership rules, or
`composer check:tooling-architecture` for both. These gates reject reverse CLI
dependencies, raw process/Docker bypasses, manual implementation loaders and image
or layout defaults outside their owners. The scoped PHP-CS-Fixer configuration
covers this package, migrated scripts, registry harnesses, selected runtime fixtures
and the prose audit helper. Use PHP 8.5 pipe chains for sequential transformations that
would otherwise require nested calls. Keep
control blocks expanded and split long argument lists and structured arrays
across lines.

The `tooling` target in `docker/runner/Dockerfile` provides the pinned runtime
without Python, pcntl or posix. CI uses `docker/runner/tooling-test.Dockerfile`
to run the harness suite in that runtime with the application CI image's source
and Composer dependencies. The excluded Python subjects run in their separate gate.
