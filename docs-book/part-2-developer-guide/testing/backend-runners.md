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

Workflow orchestration requires PHP 8.5+ with Swoole, Composer, Bash and GNU
coreutils on the host. Process groups also require `setsid`. Generate the standalone
loaders with `composer tooling:autoload`; this installs the Composer projects under
`scripts/` and `relay/` without booting the application kernel. Production worker
images install only the standalone scripts project's loader without development
dependencies, alongside the application's separate production loader. Worker and
HTTP fixtures select the matching Composer boundary; host-source archives carry
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

The tooling suite uses `phpunit-tooling.xml.dist` and remains separate from the
four application suites. `composer run-script --timeout=7200 test:tooling` excludes the
`registry-python-subject` group. `composer test:registry-subjects` runs that group
against the unchanged registry backup service and enrollment issuer; it requires
Python 3 for those programs under test. Set `REGISTRY_SUBJECT_PYTHON` to select
their interpreter. Both suites are mandatory CI gates. Test and analysis commands
install the standalone loaders before running. Use `composer format:tooling` to
apply the scoped PER-CS3 formatting rules before checking them.
`composer check:tooling-architecture` combines `composer deptrac:tooling` with
`composer check:tooling-source-policy`: dependency and source checks reject reverse
CLI dependencies, raw process/Docker bypasses, manual implementation loaders and
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

The default image reference in the runners may require registry access. An existing
locally qualified image can be selected with the same variable. Check its PHP
extensions and locked Swoole version; host PHP is not a substitute unless those
requirements pass. The unit and messaging runners stream the current checkout and
installed Composer dependencies into an isolated container. Image selection does
not select an older copy of the application source.

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
run, including setup, and defaults to `3600` seconds. Cleanup uses a separate
shared total budget, defaulting to `120` seconds (`BAANDER_CLEANUP_TIMEOUT`), after
interruption or timeout. Unresolved ownership receipts remain in the run artifacts;
keep them and their saved transport material for cleanup against the recorded
daemon. Cleanup failure fails an otherwise successful run. Messaging has a finite
`600`-second catalog budget, overridable through `tooling.budgets.messaging`.
`BAANDER_TEST_IMAGE` selects the application runtime, and `BAANDER_TEST_POSTGRES_IMAGE` selects the
extension-capable database image. CI uses `BAANDER_TEST_CHECKOUT_IN_IMAGE=1`
with its freshly built image; local runs copy the current source and installed
dependencies into each container.

The coordinator discovers the selection once, then runs three phases. Ordinary
unit and rule tests use ParaTest workers recycled after 25 files. The explicit
native manifest, `scripts/backend-native-tests.json`, assigns Swoole, fork,
signal and fixed-resource tests to one worker in a privileged container with
networking disabled; that worker starts afresh for every file. Add new native
tests to this manifest. Functional and integration files are distributed
round-robin across at most the requested number of independent
app/PostgreSQL/Redis stacks declared in `compose.test.yaml`. Each stack uses a
unique disposable Compose project and runs one worker with a fresh process for
every file. Scheduling stays at class/file level; ParaTest's method-level
`--functional` mode is not used.


Each stack owns its cache, temporary files, sockets, database, Redis server and
OAuth keys. No writable host mounts or published ports are used. Database and
Redis DSNs are exported so nested PHP processes reach the same stack. Each
database runs migrations twice. Setup uses root inside the disposable container;
tests run as `www-data`, preserving permission checks. Test worker PHP processes
receive an explicit 512 MiB memory limit. Coordinator and reporting PHP
processes receive 2 GiB because full-suite serialized coverage needs more memory
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

For qualification,
`php scripts/compare-backend-runs.php <reference> <candidate>` compares
selected and completed identities, seed, timings, coverage denominators and
exact executable/covered line sets. Coverage validation and comparison require the
PHP DOM extension. Coverage comparison also uses the root Composer dependencies
to read serialized artifacts.

Full coverage qualification matched 9,098 selected/completed test identities,
44,191 executable lines and 35,385 covered lines between the sequential reference
and ParaTest. Use the run comparison command above to inspect timings and
identity or coverage differences in retained artifacts.


The separate schema test above uses the fully migrated application kernel to check
that ORM introspection preserves the DBAL-owned lease table. The search tests exercise
production Doctrine filtering and scored SQL against mapped PGroonga indexes;
they require the project's extension-capable database image. Both runners fail on
PHPUnit notices and skipped tests. Neither reads an
ignored local `phpunit.xml` or runs the whole functional suite. The Makefile's
`phpunit`, `paratest`, and `ci` test steps use the same coordinator and tracked
configuration. `PHPUNIT_OPTIONS` and `PARATEST_OPTIONS` forward test selections;
use `BAANDER_TEST_PROCESSES` to choose the worker count. Direct `make exec` commands
select this worktree's preview and require a separately configured development
test environment. Prefer the disposable coordinator for database-backed tests;
these commands do not provision a test database or select the persistent instance:

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
make exec cmd="./vendor/bin/phpunit -c phpunit.xml.dist --filter testCreateAlbum"

# Run with Xdebug off (faster)
make exec cmd="XDEBUG_MODE=off ./vendor/bin/phpunit -c phpunit.xml.dist"
```

The functional runner creates an isolated PostgreSQL/Redis network, extracts the
checkout into a fresh directory, and runs Doctrine migrations twice before
executing the requested PHPUnit paths or options. With no arguments it selects `tests/Functional`.
A fresh database executes `Version20261017000000` and all forward migrations in
`migrations/` (currently through `Version20261110120000`); the second run checks that recorded migrations
leave no pending work. The paired-deployment migration must also reject unresolved
or inconsistent singleton history and preserve confirmed retirement records.
Integration tests and runtime fixtures use
`tests/Support/FinalSchemaBootstrap.php` to create the same final schema by applying
the current migration files in timestamp order. Isolated-schema fixtures require
their own schema to be empty and reuse extensions already provisioned in the
database.

Playlist persistence conflicts can be checked with:

```bash
bash scripts/test-functional-container.sh tests/Integration/PlaylistConcurrencyTest.php tests/Integration/PlaylistMutationFirewallTest.php tests/Integration/InitialSchemaMigrationTest.php
```

The concurrency selection uses independent PostgreSQL sessions to check competing
playlist writes, collaborator additions, reorders and cascaded membership removal.
It verifies that a losing save rolls back without replacing the winner. The
firewall selection checks revision validation and stale-reorder responses alongside
the existing permission matrix. Migration checks cover fresh initialization, a
populated database upgraded with its playlists intact, and a repeat run with no
pending work.


The functional runner also supplies the isolated PostgreSQL/Redis environment
aliases used by integration tests that need the full migrated schema and kernel.
`CoverExtractionPersistenceTest` disables DAMA rollback with its supported attribute
and observes commits through a second connection. It injects a real flush failure,
then verifies that the worker's Swoole pool release and Symfony reset allow the
same handler to retry successfully. Its exact expected pool-reset diagnostic is
asserted; unexpected-output checks remain enabled.
These selections use the shared coordinator's 512 MiB test workers, 2 GiB reporting
processes and default 3600-second overall timeout. Each disposable Compose project
supplies its own database and Redis DSNs, overriding the tracked PHPUnit defaults,
and removes its containers, network and volumes after diagnostics are collected.
The test firewall uses the test authenticator; this does not verify production
bearer-token or DPoP authentication. The full-suite qualification checkpoint above records the verified selection.

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
check. Address runtime defects before annotation cleanup. The full application
publisher requires both analyses to succeed, in addition to its test gates.

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

