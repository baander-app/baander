# Backend Runners and Coverage

Baander uses PHPUnit 13 with four test suites in the tracked `phpunit.xml.dist`.
Disposable container runners exercise the application's PHP runtime without using
the development database or Redis instance. The Makefile test targets use the same
disposable coordinator.

## Test Suites

| Suite | Directory | Scope |
|-------|-----------|-------|
| **Unit** | `tests/Unit/` | Domain behavior and application/infrastructure contracts; no Symfony kernel boot |
| **StaticAnalysisRules** | `packages/baander-phpstan-rules/tests/` | Custom PHPStan rules, including parsed-source request-payload fixtures |
| **Functional** | `tests/Functional/` | With Symfony kernel container, database, and services |
| **Integration** | `tests/Integration/` | Database, transport, and other integration contracts against configured test services |


## Running Tests

The orchestration scripts run on the host and need PHP 8.5+ with Swoole, Composer,
Bash and GNU coreutils; process groups also need `setsid`. Generate their standalone
loaders with `composer tooling:autoload`, which installs the Composer projects under
`scripts/` and `relay/` without booting the application kernel. Production worker
images install only the standalone scripts project's loader, without development
dependencies, next to the application's separate production loader. Worker and
HTTP fixtures select the matching Composer boundary, and host-source archives carry
the standalone loader required by tooling entrypoints.

With the root development dependencies installed, run the workflow checks from the
repository root:

```bash
composer run-script --timeout=7200 test:tooling
composer test:registry-subjects
composer analyse:tooling
composer check:tooling-format
composer check:tooling-architecture
```

The tooling suite uses `phpunit-tooling.xml.dist` and stays separate from the
four application suites. `composer run-script --timeout=7200 test:tooling` excludes the
`registry-python-subject` group, and `composer test:registry-subjects` runs that group
against the unchanged registry backup service and enrollment issuer. Those programs
under test need Python 3; set `REGISTRY_SUBJECT_PYTHON` to select their interpreter.
Both suites are mandatory CI gates. The test and analysis commands install the
standalone loaders before running. Use `composer format:tooling` to apply the scoped
PER-CS3 formatting rules before checking them.

`composer check:tooling-architecture` combines `composer deptrac:tooling` with
`composer check:tooling-source-policy`. Together they reject reverse CLI
dependencies, raw process/Docker bypasses, manual implementation loaders and
duplicated image/layout defaults. These commands describe the required gates;
passing results and live qualification limits belong in the roadmap.

CI also runs the harness suite in the `tooling` runtime from
`docker/runner/Dockerfile`, without Python, pcntl or posix. The
`docker/runner/tooling-test.Dockerfile` copies source and Composer dependencies
from the application CI image, so this check needs no host bind mount. The CI
image's build context keeps `.github/workflows` and `.github/actions` and excludes
the rest of `.github`, so the workflow contract tests read the shared workflow
tree inside the image.

Use an application image that satisfies the current Composer lockfile. To build
one locally and select it for the disposable runners:

```bash
php scripts/build-application.php --buildx --target ci --tag baander-ci:local
export BAANDER_TEST_IMAGE=baander-ci:local
```

The build entrypoint supplies the canonical `APP_HOME` and image layout metadata
from the selected source and verifies the resulting image. Direct runtime-image
builds without that contract fail. The
[tooling package reference](../../../packages/baander-tooling/README.md#configuration-and-image-roles)
lists role image overrides, Docker selector precedence and finite budgets. Supported
configuration is captured once per invocation; nested hooks use the same resolved
target and image selections.

The default image reference in the runners may require registry access. The same
variable selects an existing, locally qualified image instead; check its PHP
extensions and locked Swoole version first. Host PHP is not a substitute unless
it meets those requirements. The unit and messaging runners stream the current
checkout and installed Composer dependencies into an isolated container, so the
image does not supply an older copy of the application source and a code change
needs no rebuild. Rebuild the image with the command above when its own content
changes (PHP extensions, the locked Swoole version, system packages, FFmpeg) or
when `docker images` no longer lists the tag.

The shared backend coordinator runs all four suites when no selection is given:

```bash
bash scripts/test-backend-container.sh
bash scripts/test-backend-container.sh --testsuite Unit,StaticAnalysisRules
bash scripts/test-backend-container.sh tests/Integration/RefreshTokenGraceTest.php --filter test
bash scripts/test-backend-container.sh --coverage-text=coverage/report.txt --coverage-clover=coverage/clover.xml
```

It requires the host tooling baseline above, Docker and the Docker Compose plugin.
`BAANDER_TEST_PROCESSES` defaults to `2` and accepts integers from `1` through
`8`; `1` is the diagnostic setting. `BAANDER_TEST_TIMEOUT` bounds the complete
run, including setup, and defaults to `3600` seconds. After an interruption or
timeout, cleanup runs under a separate shared total budget, `120` seconds by default
(`BAANDER_CLEANUP_TIMEOUT`). Ownership receipts that cleanup could not resolve stay
in the run artifacts; keep them and their saved transport material to clean up
against the recorded daemon. A cleanup failure fails an otherwise successful run.
Messaging has a finite `600`-second catalog budget, overridable through
`tooling.budgets.messaging`.
`BAANDER_TEST_IMAGE` selects the application runtime, and `BAANDER_TEST_POSTGRES_IMAGE` selects the
extension-capable database image. CI uses `BAANDER_TEST_CHECKOUT_IN_IMAGE=1`
with its freshly built image; local runs copy the current source and installed
dependencies into each container.

Without `BAANDER_TEST_POSTGRES_IMAGE`, the runners use the local tag
`baander-database:latest`, the default in `compose.test.yaml` and
`packages/baander-tooling/resources/tooling-defaults.json`. Nothing rebuilds that tag
for you: only a manual build refreshes it, so it keeps whatever
`docker/postgres/Dockerfile` described when it was last built. On the development host
it was a Debian 12 build with PGroonga 4.0.5 until 2026-11-16, although the
Dockerfile had moved to Debian 13 on 2026-11-03. After the Dockerfile changes, rebuild
the tag, or name a current build with `BAANDER_TEST_POSTGRES_IMAGE`:

```bash
docker build --file docker/postgres/Dockerfile --tag baander-database:latest docker/
```

An image with PGroonga older than 4.1.0 fails `PgroongaSearchCompatibilityTest` with a
message that names 4.1.0. CI does not use the local tag; it pulls
`baander-postgres:latest`, which the "Build PostgreSQL Image" workflow publishes on a
`master` push that changes `docker/postgres/`.

The coordinator discovers the selection once, then runs three phases:

1. Ordinary unit and rule tests run in ParaTest workers that are recycled after 25 files.
2. The native manifest, `scripts/backend-native-tests.json`, lists Swoole, fork,
   signal and fixed-resource tests. They run on one worker in a privileged
   container with networking disabled, and that worker starts afresh for every
   file. Add new native tests to this manifest.
3. Functional and integration files are dealt round-robin to at most the requested
   number of independent app/PostgreSQL/Redis stacks declared in `compose.test.yaml`.
   Each stack uses a unique disposable Compose project and runs one worker with a
   fresh process for every file.

Scheduling stays at class/file level; the coordinator does not use ParaTest's
method-level `--functional` mode.


Each stack owns its cache, temporary files, sockets, database, Redis server and
OAuth keys, and uses no writable host mounts or published ports. The runner exports
the database and Redis DSNs so nested PHP processes reach the same stack. Each
database runs migrations twice. Setup runs as root inside the disposable container,
and tests run as `www-data`, so permission checks still apply. Test worker PHP
processes get an explicit 512 MiB memory limit. Coordinator and reporting PHP
processes get 2 GiB, because full-suite serialized coverage needs more memory
than individual test files.

JUnit validation requires every discovered class, method and dataset label
exactly once. Crashes, missing reports, unexpected identities and configured
strict failures fail the run. Skipped or incomplete cases remain observed
identities without suppressing PHPUnit's failure policy. The coordinator pins
one random seed across lanes; use `--random-order-seed` to reproduce it. Custom
configurations preserve relative paths and source filters, but suite-specific
bootstraps are rejected.

Logs, manifests, JUnit and serialized coverage remain under the printed
`var/test-runs/<run>/` directory, including failed runs. Set
`BAANDER_TEST_ARTIFACTS` to choose a different parent directory. Containers,
networks and volumes are removed on completion, timeout or interruption, and
cleanup failure fails the run. Service stacks use bounded
`docker compose down --volumes --remove-orphans` after collecting diagnostics.
Final coverage is published only after identity and artifact checks; merging
retains uncovered executable lines and exclusions. Validate the standard reports
with `php scripts/validate-backend-coverage.php coverage` before using
`coverage/metrics.json`.

Runner regressions use `composer run-script --timeout=7200 test:tooling` and the
`BackendTestRunnerTest` PHPUnit file. The destructive isolation probe,
`php scripts/test-backend-stack-isolation.php`, creates two disposable stacks
to check database outages, Redis pause/flush, cache/socket separation and
inherited DSNs. Runtime security drills remain separate serial commands.

For qualification, `php scripts/compare-backend-runs.php <reference> <candidate>` compares
selected and completed identities, seed, timings, coverage denominators and
exact executable/covered line sets. Coverage validation and comparison require the
PHP DOM extension. Coverage comparison also uses the root Composer dependencies
to read serialized artifacts.

Full coverage qualification matched 9,098 selected/completed test identities,
44,191 executable lines and 35,385 covered lines between the sequential reference
and ParaTest. Use the run comparison command above to inspect timings and
identity or coverage differences in retained artifacts.


`WorkerDeploymentLeaseSchemaTest`, run through the functional runner, uses the fully
migrated application kernel to check that ORM introspection preserves the DBAL-owned
lease table (see [Messaging and Worker Qualification](workers-and-messaging.md)). The
PGroonga search tests exercise production Doctrine filtering and scored SQL against
mapped PGroonga indexes; they require the project's extension-capable database image.
The functional and messaging runners both fail on PHPUnit notices and skipped tests,
and neither reads an ignored local `phpunit.xml`.

The Makefile's `phpunit`, `paratest`, and `ci` test steps use the same coordinator
and tracked configuration. `PHPUNIT_OPTIONS` and `PARATEST_OPTIONS` forward test
selections; use `BAANDER_TEST_PROCESSES` to choose the worker count. Direct
`make exec` commands select this worktree's preview and require a separately
configured development test environment. They do not provision a test database or
select the persistent instance, so prefer the disposable coordinator for
database-backed tests:

```bash
# Run all tests
make phpunit

# Run a single test file
make exec cmd="./vendor/bin/phpunit -c phpunit.xml.dist tests/Unit/Catalog/Domain/Model/AlbumTest.php"

# Run a specific suite
make exec cmd="./vendor/bin/phpunit -c phpunit.xml.dist --testsuite Unit"

# Run the custom PHPStan rule tests
make exec cmd="./vendor/bin/phpunit -c phpunit.xml.dist --testsuite StaticAnalysisRules"

# Run a specific controller's functional tests
make exec cmd="./vendor/bin/phpunit -c phpunit.xml.dist --filter NotificationControllerTest"

# Run a single test method
make exec cmd="./vendor/bin/phpunit -c phpunit.xml.dist --filter testCreateWithRequiredFields"

# Run with Xdebug off (faster)
make exec cmd="XDEBUG_MODE=off ./vendor/bin/phpunit -c phpunit.xml.dist"
```

The functional runner, `scripts/test-functional-container.sh`, passes its arguments
to the backend coordinator. Functional and integration files in the selection
therefore run on isolated PostgreSQL/Redis stacks with a fresh copy of the checkout,
and Doctrine migrations run twice on each stack before PHPUnit starts. With no arguments the runner selects `tests/Functional`.
A fresh database executes `Version20261017000000` and all forward migrations in
`migrations/` (currently through `Version20261117130000`); the second run checks that
recorded migrations leave no pending work. The paired-deployment migration must also
reject unresolved or inconsistent singleton history and preserve confirmed retirement
records.
Integration tests and runtime fixtures use
`tests/Support/FinalSchemaBootstrap.php` to create the same final schema by applying
the current migration files in timestamp order. Isolated-schema fixtures require
their own schema to be empty and reuse extensions already provisioned in the
database.

To check playlist persistence conflicts, run:

```bash
bash scripts/test-functional-container.sh tests/Integration/PlaylistConcurrencyTest.php tests/Integration/PlaylistMutationFirewallTest.php tests/Integration/InitialSchemaMigrationTest.php
```

The concurrency test opens independent PostgreSQL sessions to check competing
playlist writes, collaborator additions, reorders and cascaded membership removal,
and verifies that a losing save rolls back without replacing the winner. The
firewall test checks revision validation and stale-reorder responses alongside
the existing permission matrix. The migration test covers fresh initialization, a
populated database upgraded with its playlists intact, and a repeat run with no
pending work.


The functional runner also supplies the isolated PostgreSQL/Redis environment
aliases that integration tests need for the full migrated schema and kernel.
`CoverExtractionPersistenceTest` disables DAMA rollback with its supported attribute
and observes commits through a second connection. It injects a real flush failure,
then verifies that the worker's Swoole pool release and Symfony reset let the
same handler retry successfully. The test asserts the exact expected pool-reset
diagnostic, and the unexpected-output checks stay enabled.

These selections use the shared coordinator's PHP memory limits (512 MiB for test
workers, 2 GiB for reporting processes) and its default 3600-second overall timeout.
Each disposable Compose project supplies its own database and Redis DSNs, overriding
the tracked PHPUnit defaults, and removes its containers, network and volumes after
diagnostics are collected. The test firewall uses the test authenticator, so these
runs do not verify production bearer-token or DPoP authentication. The full-suite
qualification checkpoint above records the verified selection.

New migrations use `VersionYYYYMMDDHHMMSS` and normal timestamp ordering after
`Version20261017000000`. The initial migration is irreversible. Its empty-target
guard rejects existing user objects while initialization is pending, and the
unknown-history guard rejects records from the former chain. Neither ordinary
confirmation nor `--no-interaction` bypasses these checks. See the
[manual reset procedure](../../part-1-operator-guide/maintenance/upgrading.md#manual-reset-of-an-existing-database)
for an existing database; a reset needs explicit owner approval for that target.

## Coverage

`make phpunit` runs the disposable coordinator with coverage and writes
`coverage/report.txt` and `coverage/clover.xml`. Per-lane JUnit and serialized
coverage remain under the printed `var/test-runs/<run>/` artifact directory.
`make paratest` runs the same suites without coverage unless report options are
supplied. `make ci` uses `make phpunit` for its backend test step.

## Static Analysis

PHPStan runs alongside tests:

```bash
# Run PHPStan
make phpstan

# Check architectural dependencies
make exec cmd="vendor/bin/deptrac analyse --no-cache --no-progress"
```

Run these in the application PHP runtime with the current Composer dependencies.
Existing baselines record debt; do not regenerate or expand them to hide a failing
check. Fix runtime defects before cleaning up annotations. The application
publisher requires both analyses to pass, in addition to its test gates.

`make phpstan` and the static steps of `make ci` run through
`scripts/check-backend-container.php`. The script copies a snapshot of the
worktree, uncommitted changes included, and the installed `vendor`,
`scripts/vendor` and `relay/vendor` directories into a fresh application root in a
disposable container without network access. It then runs the given command there
as `www-data` with `APP_ENV=test`. The installed dependencies must match
`composer.lock`. `--image` selects the image, and the Make targets pass
`BAANDER_TEST_IMAGE` as `--image`; without it, the script first builds a CI image
from the snapshot. The same script runs the other static gates for the current
tree, which are Deptrac, the container lint and the OpenAPI drift check:

```bash
export BAANDER_TEST_IMAGE=baander-ci:local
make phpstan
php scripts/check-backend-container.php --image "$BAANDER_TEST_IMAGE" -- vendor/bin/deptrac analyse --no-cache --no-progress
php scripts/check-backend-container.php --image "$BAANDER_TEST_IMAGE" -- php bin/console lint:container --env=test --no-debug
php scripts/check-backend-container.php --image "$BAANDER_TEST_IMAGE" -- php bin/console app:export-openapi-spec --env=test --no-debug --check
```

CI runs PHPStan, Deptrac and the OpenAPI check with a plain `docker run` on the
image it has just built, so each analyses the source copied in at build time. To
point such a container at the current tree instead, bind-mount the changed paths
read-only over the application root, `/var/www/html`, and mount `packages` whenever
you mount `vendor`. `vendor/baander/tooling`, `vendor/baander/phpstan-rules`,
`vendor/swoole-bundle/swoole-bundle` and `vendor/tsduck/php` are symlinks into
`packages/`. Without that mount they resolve to the image's older package sources,
and class loading against the current `src/` fails with incompatible-declaration
errors. On an SELinux host, a bind mount from a directory that host setup has not
labelled, such as a scratch file outside the worktree, needs the `:z` option;
otherwise the container reports "Permission denied".

## Application publication gates

The application publisher in `.github/workflows/ci.yaml` requires backend quality,
frontend quality, DSP qualification and every Registry qualification job (release
and sanitize builds, containers, threads, fuzzing and static analysis). Frontend
qualification runs `ui/shared` checks, the embedded player checks, web checks,
`yarn test:browser-auth` (Chromium and WebKit), `yarn test:audio-graph` (Chromium,
Firefox and WebKit), and `yarn test:store-debug` (Chromium). Forgejo and GitHub both run this workflow; see
[Continuous Integration](../contributing/continuous-integration.md). Checkout steps in the gate
workflows fetch and verify the pull request's head commit, or the event SHA for
other events, so a later branch update cannot replace the source under
qualification, and both forges qualify the same commit.

`PublicationWorkflowTest` checks the actual YAML dependency graph, executes gate shell
commands with failing local tools, and exercises checkouts against a local Git remote
whose branch advances. It rejects unavailable or mismatched event commits. It
evaluates each workflow condition once for GitHub and once for Forgejo, covering the
runner label, registry, cache and baseline store, artifact action, trusted-run
guards and the GitHub-only steps, and it enforces the shared tree's rules. These are
local contracts and shell regressions; they do not certify either forge's live
scheduler. Standalone CLI and base-image publication policies need their own review.

