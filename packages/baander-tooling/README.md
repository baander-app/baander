# Standalone workflow tooling

The `baander/tooling` Composer package maps `Baander\Tooling` to `src/`.
Both the root manifest and `scripts/composer.json` require it through a local path
repository, so PhpStorm and runtime loading use the same namespace. The library
requires PHP 8.5 and `composer/semver` for Composer constraint comparisons.
Generate the scripts loader with
`composer --working-dir=scripts install --no-dev` or the root command
`composer tooling:autoload`, which installs both the `scripts/` and `relay/`
Composer projects. `scripts/bootstrap.php` requires the scripts loader without
booting the application kernel. Workflow entrypoints call
`Runtime\Requirements::check()` before changing state. The baseline is PHP 8.5
with Swoole. Commands that do not need Swoole pass `false`; commands needing
additional extensions pass their names in the second argument.

Production worker images install the standalone `scripts/` loader without
development dependencies. The application loader also remains free of development
packages, including PHPUnit. Worker and HTTP fixtures use the Composer loader for
the code they execute; host-source archives include the required standalone loader.
Disposable autoscaling fixtures generate development classmaps for their workload
classes without installing development packages; PHPUnit remains absent. They
require injected scenario services and reject immutable-image mode.

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
every budget: backend defaults to 3600 seconds, messaging to 600 seconds,
`workerCapacity` to 900 seconds and cleanup to a shared total of 120 seconds.
Autoscaling retains its 2000-second aggregate bound; its observation callback and
performance thresholds have separate unchanged limits. `BAANDER_TEST_TIMEOUT`
overrides `backend`;
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
With `--buildx`, `--builder NAME` selects a named BuildKit builder instead of the
daemon's default one. `--compose-env FILE` writes the inspected immutable image and
its `APP_HOME` for Compose consumers. Use that generated file when the consuming
Compose command requires it.

`Docker\Client` pins builds to the selected daemon's default BuildKit builder
unless `--builder NAME` names another. It accepts that builder only when
`docker buildx inspect` on the captured daemon shows the `docker-container` driver
with every node on the captured endpoint, and `--load` still delivers the image to
that daemon. It removes ambient `BUILDX_BUILDER` and `BUILDKIT_HOST` settings and enables
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

## Deployment preset and qualification

`Deployment\Preset` starts the gateway, web service, PostgreSQL, Redis and
worker pair from one deployment configuration. It reads the selected profile from
the immutable application, worker and operator images, checks their agreement,
and uses the shared worker lifecycle. Application paths come from image metadata.
The application does not receive the Docker socket.

Prepare a private configuration from
[`deploy/preset/example.json`](../../deploy/preset/example.json), following
the [deployment instructions](../../deploy/preset/README.md). Then run:

```bash
php scripts/deploy-preset.php start --config /path/to/preset.json --state /path/to/preset-state
php scripts/deploy-preset.php status --state /path/to/preset-state
php scripts/deploy-preset.php stop --state /path/to/preset-state
```

Normal stop retains the database, media, derived files and signing keys. Change a
profile by stopping the deployment, updating its configuration and starting it
again with the same state directory. A different deployment name or volume
inventory needs a separate state directory. Configured limits describe resource
admission; they are not measurements or a minimum host requirement.

`Fixtures\Qualification\Qualification` is the separate disposable workload harness.
`scripts/qualify-deployment.php` provides `prepare`, `start`, `scenario`, `stop`
and `report` actions. Preparation generates media before measurement and adds
fixture-only provider responses and native launch logging to the production
images. `prepare --from` copies an unchanged fixture dataset into a new owned
volume and borrows its exact images for another run. Run the invoking machine
and browser outside the measured host. See the
[qualification procedure](../../docs-book/part-2-developer-guide/testing.md#deployment-qualification)
for the configuration and evidence required.

Qualification `stop` retains data for a restart check. Its `--cleanup` option
removes owned fixture data and images after confirming worker retirement and
volume retention. Borrowed images remain until every child is cleaned up; clean
the fixture source last. Reports and failed-run evidence remain in the private
state directory. A successful setup or functional check alone does not establish
a whole-host memory fit.

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

Worker measurements join and drain their samplers before final verification.
Memory and PID exhaustion drills accept exit 137 from a contained probe only with
typed evidence of the terminal event, native process identity, exhaustion and
continuous heartbeat/drain observations for the exact stopped worker pair.
The terminal drain proof comes from the actual persisted control heartbeat,
captured from the stopped container and checked against the saved IPC volume,
source, boot and epoch within the existing continuity bound. Sample rows and
measurement thresholds remain unchanged.
Unexpected statuses fail before evidence validation; timeout, cancellation and
malformed evidence remain failures. Host samplers require successful completion.
Only the main qualification path publishes the final result.

Worker fixture readiness requires every expected Redis consumer within its
bounded wait. Failure diagnostics preserve the original error. Media publication
diagnostics are private, limited to four generations of capped metadata, and
exclude media bytes; observation adds no readiness or performance allowance.
Media seek readiness measures monotonic availability of both audio and video bytes.
Decoding, signature-tampering and subtitle certification follow and remain mandatory;
the same 30-second availability limit applies.

The filesystem helpers preserve private state with same-directory replacement,
file and directory synchronization, and process-owned locks. They reject symlink
paths. State directories belong to the current user; these checks do not isolate
the files from other processes running as that same user.

## PHP dependency maintenance

Run the local check after changing PHP manifests, locks, runtime recipes, patches
or the Swoole fork. Install the standalone loader first, then put PHP 8.5 or newer
and exactly Composer 2.11.1 on `PATH`. The checker rejects other Composer versions
because its validation and JSON parsers target that interface. Host PHP must also
load Swoole: the process engine uses its signal and timer APIs.

```bash
composer run-script --timeout=720 check:php-dependencies:local
composer run-script --timeout=720 check:php-dependencies
php scripts/check-php-dependencies.php --local-only --format json
php scripts/check-php-dependencies.php --format json
```

The local command discovers manifests and checks their classifications against
[`resources/php-dependencies.json`](resources/php-dependencies.json). It reads
production and development packages from the four owned locks, validates all seven
manifests with Composer plugins, scripts and network access disabled, compares
runtime source declarations, and checks patch hashes, targets, depth and proof
ownership. Validation keeps `--strict` schema and lock checks, with
`--no-check-all` disabling warnings about overly strict or loose requirements.
Exact native and stub pins and classified local development constraints are
intentional; independent stable-release and constraint checks assess currency.
The fork check compares every current source file with the reviewed
presence and SHA256 inventory. Generated trees need explicit policy exclusions;
new source manifests need an owner and classification. Missing installed metadata
does not replace or invalidate lock evidence.

The online command compares fresh Packagist stable releases with every external
direct requirement and locked production or development package. It fetches each
package once while retaining each project and lock section as evidence. Direct
requirements also retain their declared constraint eligibility; transitive lock
entries have no direct manifest constraint to assess. The command adds Composer
locked audit and outdated reports, runtime release metadata, and patch/fork
upstream references.
An empty owned lock is reported explicitly; Composer can skip its audit and return
an empty outdated list.
Latest stable availability and declared constraint eligibility are reported
separately; a release blocked by the current PHP platform or an old major constraint
still appears. Installing that release requires a separate solver assessment.

Online inspection also requires a `curl` executable with HTTPS support, access to
the named upstream sources, and the configured Docker CLI and target. Docker must
have a Buildx plugin that supports
`buildx imagetools inspect --format '{{json .Image}}'`. The checker uses it to read
the declared Composer image's registry configuration for every returned platform
and compare its embedded PHP version with the policy minimum. It does not build,
pull or execute images. Local mode needs neither curl nor Docker and explicitly
excludes online currency, advisories and registry image metadata. Neither mode
qualifies installed extensions, native behavior or patch application.

Each Composer call uses an explicit project directory, disabled plugins and scripts,
no cache, and a private temporary Composer home. Ambient manifest, vendor, home and
cache overrides are removed. Supported path, home, temporary-directory, locale,
TLS certificate and HTTP(S) proxy settings pass through, as does `COMPOSER_AUTH`.
The upstream curl calls disable curl configuration files and use bounded HTTPS
requests. The checker does not update dependencies, apply patches or change project
configuration.

The shared `dependencies` workflow budget defaults to 600 seconds; each external
operation also uses the `process` budget, which defaults to 120 seconds. Override
these through `tooling.budgets` using the normal configuration precedence. The
Composer examples allow 720 seconds for the outer script runner so its default
300-second timeout does not interrupt the checker early. JSON and human output
retain collected findings when another requested source fails.

| Exit status | Meaning |
| --- | --- |
| `0` | Requested checks completed without findings; local mode still excludes online evidence. |
| `1` | Requested checks completed and reported drift, advisories or other findings. |
| `2` | Requested evidence is incomplete, including an unsupported interface, malformed response, timeout or cancellation. Earlier findings remain in the report. |

CI runs the local command without network access alongside the tooling gates.
Online assessment is an explicit maintenance action; it is not a scheduled CI gate.

## CI package store

On Forgejo, CI keeps its caches and coverage baselines in the Forgejo generic
package registry through `scripts/ci-package-store.php`, which wraps
`Ci\PackageStore`. GitHub uses `actions/cache` for the same data; the
[CI guide](../../docs-book/part-2-developer-guide/continuous-integration.md#caches-and-coverage-baselines)
lists the packages and keys. The command takes an operation, a package, a file and
a path:

| Operation | Effect |
| --- | --- |
| `restore` | Downloads the archive `<file>` and extracts it into the empty directory `<path>` |
| `save` | Archives the directory `<path>` and replaces `<file>` with it |
| `fetch` | Downloads the baseline `<file>` to `<path>` |
| `store` | Replaces the baseline `<file>` with the file at `<path>` |

Every file lives in version `latest` of the package, owned by the owner named in
`GITHUB_REPOSITORY`. The command reads the API base from `GITHUB_API_URL` and the
token from `FORGEJO_TOKEN`; the token reaches `curl` through standard input, never
the command line. A missing or unreadable package, or an archive that cannot be
extracted, restores an empty directory or fetches nothing and exits 0, so a cold
cache never fails a job. `store` without a baseline file stores nothing and exits 0.
A failed upload fails `save` and `store`. Install the standalone loader with
`composer --working-dir=scripts install --no-dev` before calling it.

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
