# Baander roadmap

Updated: 2026-11-20. This roadmap selects future work and records unresolved
acceptance. Current operating contracts belong in the linked guides; completed
milestones and their verification evidence remain in Git history.

## TypePHP retirement

The [removal plan](docs/plans/2026-11-20-1652-feat-remove-typephp-plan.md)
retires the optional TypePHP runtime at the user's request after its measured
comparison. Ordinary PHP remains the application runtime. The dedicated compiler
dependencies, build and qualification images, patches, entry adapters, test loader,
benchmark runner and their Makefile targets have been removed. Generic storage,
cancellation and fixture corrections remain; application source, ordinary runtime
entry points and root Composer dependencies are unchanged.

The comparison page and its references are removed from `ui/site`. Its
[historical report](docs/benchmarks/2026-11-20-typephp/README.md) and all five raw
evidence files remain in the repository only. The archived JSON and compressed
pilot retain their original bytes. Local build output, Docker images, other
worktrees and user data are outside this removal.

Removal verification: all five archived raw files match their bytes at
`104f48fc82deb49996d5da91e2013eb1d4df8c46`, before removal. The website build passes
with 246 manual pages. The CI-image Website suite passes 164 tests and 1,520
assertions; the affected menu, rendering and website browser suites pass all 76
cases. Browser inspection confirms that `/runtime-performance/` returns HTTP 404
and no longer appears in Pagefind results. The generated page, deployment copy,
sitemap entry and menu entry are absent.

Tooling PHPStan, site TypeScript, targeted formatting and whitespace checks pass.
`make help` has no TypePHP targets, and the active-reference audit finds no
remaining integration outside historical plans, this roadmap and the archive.
Source comparison confirms no changes to ordinary application code, configuration,
entry points, backend tests or root Composer dependencies. Simplification found
no retained implementation to refactor, and code review returned no findings.
The removal is committed locally with no push or deployment; no verification
checkpoints remain open.

### Historical performance checkpoint

The [comparison plan](docs/plans/2026-11-20-1632-feat-typephp-performance-comparison-plan.md)
recorded a reproducible benchmark and a [measured report](docs/benchmarks/2026-11-20-typephp/README.md)
for the executable qualified by the package-organization checkpoint below.
The harness checks production source bytes, executable hashes, selected effective
INI settings and exact workload outputs before accepting timings. Its ten
focused tests pass, including failed-child and incorrect-checksum rejection.
The simplification pass removed one duplicated condition and retained the proof
tests. PHP syntax, Python parsing and whitespace checks pass.

The [final measurements](docs/benchmarks/2026-11-20-typephp/results/measurements.json)
contain 180 accepted samples and 18 warmups: 30 samples and three warmups for each
of two runtimes across three workloads. Independent recomputation confirms all
ten metric summaries, output identities, counts and executable hashes against
the [manifest](docs/benchmarks/2026-11-20-typephp/results/manifest.json).
All 13,325 source/dependency reads match the production tree. Both runtimes use
PHP 8.5.12 ZTS and Swoole 6.2.4, production dependencies and matching selected
settings in one network-disabled disposable container.

TypePHP/ordinary median-time ratios are 3.191 for console discovery, 1.042 for
operator help and 1.066 for the sustained application loop. The report includes
p95, observed ranges, process CPU and peak resident memory, plus the shared-host
and extension-linkage limits. These results establish neither HTTP nor worker
throughput. The calibration pilot is retained separately and excluded from the
published tables. No application optimization or deployment is part of this work.

Review reproduced an untracked-source acceptance gap in the runner. Its CLI
regression failed before the correction and passes afterward; the runner now
checks application paths against the named source commit, allowing generated
vendor files. Three source-provenance cases bring the harness suite from seven
to ten tests. A [recheck](docs/benchmarks/2026-11-20-typephp/results/source-validation.json)
confirms no unexpected application files in the measured snapshot. The timing
collector and workload are unchanged, so the original measurements are retained.

Before retirement, the site rendered the report at `/runtime-performance/` through
its reference-page template. Resources → Build on it, Pagefind search and the
sitemap exposed the page. Desktop and mobile browser inspection confirmed its
layout. The following counts record that original publication checkpoint.
The CI-image Website suite passes 164 tests and 1,521 assertions, and all ten
harness tests pass in the same image. `composer analyse:tooling`, the site
TypeScript check, targeted tooling formatting and `git diff --check` pass.
Independent numerical and prose review found no report discrepancies. Code
review has no outstanding actionable findings after the source-provenance fix.

The website build passes with 246 manual pages and the new report. The full
browser run passes 221 existing cases; the two new report cases initially fail
because a raw heading-text assertion includes the decorative anchor symbol.
After checking the exact accessible heading name, both cases pass against the
rebuilt site at 1,440 px and 390 px with JavaScript disabled. These
[historical report checks](https://github.com/baander-app/baander/blob/104f48fc82deb49996d5da91e2013eb1d4df8c46/ui/site/tests/website.spec.ts) cover menu access, results tables,
evidence links, section navigation and narrow-screen containment. All 223 cases
are covered by the full run and focused rerun. The work is committed locally;
no push or deployment is included. No verification checkpoints remain open.

## Internal capability packages

The [package extraction plan](docs/plans/2026-11-20-1148-refactor-internal-capability-packages-plan.md)
keeps application policy in `src/` and moves four reusable mechanisms into local
Composer packages. Its document review has no open findings.

### Organization correction

U6–U8 organize the existing packages by responsibility and make application
adapters explicit. Parsing, image processing, limiter scripts and HTTP validation
retain their behavior.

| Package | Source organization |
| --- | --- |
| `baander/media-metadata` | `Model`, `Reader`, `Parser`; format detection and the reader facade at the root |
| `baander/image-processing` | `BlurHash`; GD conversion at the root |
| `baander/redis-rate-limiter` | `Limiter`, `Storage`, `Administration`, `Exception`, `Bridge/Symfony` |
| `baander/http-client` | Three related classes at the root |

Nineteen package test files now live beside their source. The shared Unit suite
and root development autoloader discover them. The tagged-media byte fixture
belongs to the metadata package; application reader assembly remains in
`tests/Support/Metadata/MetadataReaderFixture.php`. Application tests still cover image
presets, provider settings, Redis pooling, command behavior and real transports.
The before/after `vendor/bin/phpunit --list-tests` comparison accounts for all
13,818 original cases, including provider datasets, exactly once. The final discovery lists
13,819 cases; its only addition is the bundle administration-registration proof. Relocated
files retain their ordinary-runner assignment; none belonged to the native lane.

`MetadataContentReaderAdapter` and `ImageConversionAdapter` name the application
wrappers. `config/services_packages.yaml` supplies application logger channels,
HTTP transport, DNS resolution and Redis borrowing, with its own service defaults.
The limiter bundle owns administration registration and its interface alias.
TypePHP explicitly embeds the new service import and retains the recursive package
source roots; package tests are outside those production roots.

The CI-image focused suite passes with 761 tests and 2,903 assertions:

```bash
php scripts/check-backend-container.php --image baander-ci:local -- \
  vendor/bin/phpunit packages/baander-media-metadata/tests \
  packages/baander-image-processing/tests packages/baander-redis-rate-limiter/tests \
  packages/baander-http-client/tests tests/Unit/Metadata tests/Unit/Media \
  tests/Unit/Shared/Infrastructure/RateLimiter \
  tests/Unit/Shared/Infrastructure/Redis/RedisRateLimiterStoreFactoryTest.php \
  tests/Unit/Shared/Interface/Console/RateLimiterClearCommandTest.php
```

The same CI-image wrapper passes `vendor/bin/phpstan analyse --no-progress --memory-limit=2G`,
`vendor/bin/deptrac analyse --no-cache --no-progress` and
`php bin/console lint:container --env=test --no-debug`. Deptrac reports zero
violations and errors with the same 444 existing skipped violations. Its existing
metadata-adapter baseline key follows the class rename; no dependency permission
was added. Review caught the old adapter name in the Catalog port alias; the
corrected alias passes container lint and full PHPStan. The bundle registration
proof failed before implementation and passes afterward with one test and five
assertions. Simplification found no further changes, and review has no unresolved
source findings.

All four package manifests pass strict Composer validation. Root validation passes
with its existing constraint warnings; root and package normalization checks pass.
Production dependencies retain their versions.

The rebuilt TypePHP executable contains 13,306 embedded files and 12,274 opcode
blobs. Its nine skipped files are the same IDE-helper declarations and copies;
no application or package PHP is skipped. All 35 package source files use their
new paths without duplicate copies through vendor aliases; package tests are not
embedded. Both archived service configuration files match the corrected build
snapshot byte for byte.
The executable remains 319,621,784 bytes, with SHA-256
`408ad77b210bb0a9bdf94acb0cc93ce01029aef043af50e81b8612a41be88b25`.

The qualification image is
`sha256:e9ccdc03ba9c52c51ce730820f07261638a0101d024281959a3d6068a9d1b487`.
Its focused coordinator run passes 358 tests and 1,505 assertions across 29 files:
315 tests / 926 assertions in the ordinary lane, 22 / 165 in the first database
lane and 21 / 414 in the second. The final identity comparison accounts for all
358 selected cases.

```bash
BAANDER_TEST_IMAGE=baander-organization-qualification:way0tn \
BAANDER_TEST_CHECKOUT_IN_IMAGE=1 BAANDER_TEST_PROCESSES=2 \
BAANDER_TEST_POSTGRES_IMAGE=baander-database:latest \
bash scripts/test-backend-container.sh --no-coverage \
  packages/baander-http-client/tests packages/baander-image-processing/tests \
  packages/baander-media-metadata/tests packages/baander-redis-rate-limiter/tests \
  tests/Functional/Metadata/MetadataSyncControllerAccessTest.php \
  tests/Functional/Metadata/Interface/Console/MetadataFileCliParityTest.php \
  tests/Functional/Media/ImageDerivativeWorkerTest.php \
  tests/Functional/Controller/RateLimiterMonitorControllerTest.php \
  tests/Functional/Console/RateLimiterCommandsTest.php \
  tests/Integration/RateLimiterConcurrencyTest.php \
  tests/Integration/RateLimiterHttpAvailabilityTest.php \
  tests/Unit/Shared/Infrastructure/Http/PublicHttpTransportTest.php \
  tests/Unit/Shared/Infrastructure/EventListener/UseCaseOutcomeMappingTest.php \
  tests/Unit/Media/Infrastructure/Converter/ImageConversionAdapterTest.php
```

Production console and operator boot pass with external development autoloading
disabled. Ten moved package/application types load through the rebuilt executable.
Twelve compiled-service inspections verify both application port aliases, all
metadata/media logger channels, HTTP transport, DNS closure, Redis factory and
administration alias. The compiled catalog contains exactly the 26 configured
limiters with unchanged storage namespaces. The smoke container is removed.
All 2,553 runtime/configuration inputs and 1,487 test inputs match implementation
commit `7aeaccc30d86e15a8fb6de9bd46e2b94e317a2a1`.

The build used an isolated checkout with production dependencies installed by
`composer install --no-dev --no-scripts --prefer-dist --no-interaction --no-progress`.
The cached toolchain invocation was
`baander-typephp-toolchain:local tools/typephp/application.yml --no-progress --no-color --job 8`,
with the checkout mounted at `/var/www/html` and the existing TypePHP output/cache directories mounted
writable. The corrected build completed in 425.2 seconds.

The coordinator exits successfully in 261.7 seconds after identity comparison and
owned-resource cleanup. U6–U8 are delivered with no open verification checkpoints.

### Initial extraction checkpoint

File metadata readers and their values now live in `baander/media-metadata`.
The Catalog reader port retains an application adapter; matching and enrichment
remain in Metadata. The CI-image focused reader, value and matching checks pass:
151 tests and 431 assertions on PHP 8.5.12 using `baander-ci:local`.
GD conversion and BlurHash now live in `baander/image-processing`. The application
keeps image presets, filenames and authorization. CI-image converter tests pass
with 28 tests and 69 assertions, including dimensions, transparency, no upscaling
and atomic publishing. Atomic Redis rate limiting now lives in
`baander/redis-rate-limiter`, with its compiler pass registered by a bundle.
The application owns Redis borrowing and pool failure translation. Its focused
CI-image checks pass: 73 tests and 319 assertions. HTTP exception mapping adds
14 passing tests and 50 assertions.

Public destination validation and pinned HTTP transport now live in
`baander/http-client`. Native DNS process ownership, provider credentials and
allowlists stay in the application. Focused HTTP, provider, push, radio and OAuth
checks pass: 448 tests and 1,722 assertions. Qualification fixture checks pass:
19 tests and 193 assertions. All counts use PHP 8.5.12 in `baander-ci:local`.

Application and tooling PHPStan pass. Application Deptrac reports zero violations
and errors with 444 existing skipped violations; tooling Deptrac reports zero
violations, errors and skipped violations, and its source-policy check passes.
The Symfony container lint and OpenAPI drift check pass. Compiled service inspection
confirms the metadata and media logger channels for the moved readers and BlurHash
generator. Root Composer validation passes with existing constraint warnings;
all four new manifests pass strict validation, and all changed manifests pass
normalization. The six-lens code review retained no findings after the qualification
fixture fix. Simplification review retained the concrete types and callable seams.

The fresh TypePHP build succeeds with 13,305 embedded files and 12,274 opcode blobs.
Its nine skipped files are IDE-helper declarations and library copies; no application
or package PHP is skipped. The 319,621,784-byte executable has SHA-256
`64e4392aa8724668f1236ea64254bc819c80a2e6edbaad7f5262c249e64a426e`.
The qualification image is
`sha256:54021cdeeeef2be39bf4631c0a274331116b648e6281e383ec740131cd1aa61a`.
Package-load smoke checks resolve all four namespaces through that executable.
The full packaged backend gate passes in 3,420.5 seconds with four workers and
coverage disabled: 13,817 tests and 148,851 assertions. All discovered test identities
match the completed results, and owned-resource cleanup passes. The focused
capability, native DNS, image-worker and limiter files also match individually.
Observed PHP processes in all six lanes execute the fresh binary.

| Packaged lane | Tests | Assertions |
| --- | ---: | ---: |
| Ordinary | 8,913 | 51,217 |
| Native | 954 | 30,622 |
| Database stack 1 | 1,197 | 20,774 |
| Database stack 2 | 935 | 12,959 |
| Database stack 3 | 1,091 | 24,683 |
| Database stack 4 | 727 | 8,596 |

The packaged gate command is:

```bash
BAANDER_TEST_IMAGE=baander-capability-qualification:ul6pde \
BAANDER_TEST_CHECKOUT_IN_IMAGE=1 BAANDER_TEST_PROCESSES=4 \
BAANDER_TEST_POSTGRES_IMAGE=baander-database:latest \
bash scripts/test-backend-container.sh --no-coverage
```

Ordinary PHP checks run through `php scripts/check-backend-container.php --image baander-ci:local --`.
They cover the focused PHPUnit scopes described above,
`php vendor/bin/phpstan analyse --memory-limit=1G --no-progress`,
`php vendor/bin/deptrac analyse --no-cache --no-progress`,
`php bin/console lint:container --env=test --no-debug` and
`php bin/console app:export-openapi-spec --env=test --no-debug --check`.
Tooling checks use `phpunit-tooling.xml.dist`, `phpstan-tooling.neon` and
`composer check:tooling-architecture`.

Packaged interpreter checks, 11 bootstrap cases and 106 entry-point assertions pass.
Production console listing, operator help and cache warmup pass with `APP_ENV=prod`,
`APP_DEBUG=0` and the external test loader disabled.

The first web-runtime drill exposed stale fixture configuration: its loopback
DPoP proof was rejected by the existing explicit-origin validator. Runtime and
shutdown fixtures now set `AUTH_DPOP_EXTRA_ORIGINS=http://127.0.0.1:9501` while
retaining their public issuer. Both targeted regression checks fail without that
setting and pass with it in the CI image: 2 tests and 21 assertions. Scoped
tooling PHPStan and formatting pass. The correction changes only physical test
tooling, and its focused code review has no findings. The web-runtime verification image is
`sha256:2eb04ccea8ca2022eec22d35055f9a6646205ca9ff34826989d1ab393371f063`;
its executable hash is identical to the fully tested binary above. A second
probe confirmed that the delegated token's user also lacked access to the
generated library.
Shared preparation now accepts the intended listener and grants that user
access to the generated library. Both workflow callers pass their listener;
application authorization and delegated-token behavior stay unchanged. The
membership correction's command regression fails before the fix and passes in the
CI image afterward: 1 test and 11 assertions. Scoped application and tooling
PHPStan pass, and its focused code review has no findings. The corrected packaged
web drill passes, including progressive streaming, byte ranges, Safari requests, PostgreSQL and Redis outages, recovery and revocation.

The shutdown drill then exposed a separate fixture identity mismatch: WebSocket
tickets require a first-party client, but the fixture sent a delegated token.
`StreamClient` now accepts an optional concrete client. Only ticket-producing
fixture paths supply the configured first-party client; general streaming and
TwoCommand HTTP/transcode credentials remain delegated. The focused CI-image
suite passes 11 tests and 26 assertions, and scoped PHPStan and review pass.
The corrected shutdown drill passes in 228.4 seconds, including reload, signals,
WebSocket close handling, stalled streams, readiness/liveness, watchdogs, file/range
responses, descendant cleanup and PostgreSQL-outage cases. Both workflows clean up
their owned resources. Shutdown verification uses image
`sha256:aab0e78a2c0f240a7e85daffa2ed1d2ec717ed9172ab5632ff0017c0c0fb28bc`,
with the same application executable hash as the full backend run.

The successful runtime commands use `BAANDER_TEST_CHECKOUT_IN_IMAGE=1` and
`BAANDER_TEST_POSTGRES_IMAGE=baander-database:latest`:

- `BAANDER_TEST_IMAGE=baander-capability-qualification:ul6pde-membership bash scripts/test-web-runtime-container.sh`
- `BAANDER_TEST_IMAGE=baander-capability-qualification:ul6pde-ticket bash scripts/test-web-shutdown-container.sh`

These fixture corrections were verified with focused CI-image tests and the real
runtime drills; the application binary and production dependencies were unchanged.
All five extraction units are delivered, with no open verification checkpoints.

## Retired TypePHP packaging experiment

The results below record the retired 2026-11-19 runtime setup. The package
extraction above records the later application build. TypePHP support has since
been removed; these are historical qualification results.

The [TypePHP setup plan](docs/plans/2026-11-19-1159-feat-typephp-setup-plan.md) targets the main PHP application as one packaged CLI executable using ZendVM.
The [historical setup guide](https://github.com/baander-app/baander/blob/104f48fc82deb49996d5da91e2013eb1d4df8c46/tools/typephp/README.md) covers the console used by the HTTP server and workers, and the independent deployment operator.
The user selected one binary on 2026-11-19: `baander console ...` and `baander operator ...` choose the existing composition roots. The reviewed implementation consolidates the project and build target, embeds both scripts, preserves CLI metadata for Symfony re-entry, and reserves those exact selector tokens ahead of same-named files. The qualification image uses a direct `php` symlink and contains no second application binary or retained ordinary PHP copy.

The unified build passes: `var/typephp/baander` is 317,524,632 bytes, SHA-256 `fbef8d92218d62ec93dfa774de5879e170609f782667851b3ab3d247261d7452`, with 13,218 embedded files and 12,192 opcode blobs. The same nine IDE-helper files are skipped; no application PHP is skipped. The obsolete operator output has been removed. The package reports PHP 8.5.12 ZTS, Swoole 6.2.4 and 59 extensions. The `php` alias and `PHP_BINARY` resolve to that ELF. The toolchain image is `sha256:945e4439dd52e71434101ef0780fac0f5ccd3f43fb7f2f3520d75f684215863c`; the single-artifact qualification image is `sha256:688281c69ffd9719c9f01139ed8f3b34d76afac462960b8032730900f6a6b315`.

The current application adds `Async::throwIfCanceled()` immediately after the audio generator yields, before another runtime lookup or file read. Its focused candidate checks pass: 319 tests and 865 assertions, the interpreter checks, 11 bootstrap cases, 142 filesystem checks, 106 entry-point assertions, 40 source-stream checks and 76 source-mutation checks. The unchanged complete shutdown drill now passes in 212.7 seconds. The formerly failing stalled-client case exits cleanly in 4.3 seconds; readiness/liveness during drain, stopped-worker and manager watchdog handling, progressive/file/range transfers, descendant cleanup and PostgreSQL-outage cases also pass. Both selectors and production cache warmup also pass in a network-isolated disposable container with `APP_ENV=prod`, `APP_DEBUG=0`, the external prepend disabled and shared CLI OPcache enabled, after both physical entry scripts are replaced with throwing markers. The synchronous and coroutine PostgreSQL/SQLite fixture also passes, including COPY, large objects, backend PID, notifications, transactions and scheduler yielding. The final backend gate passes with four workers and coverage disabled: 13,257 tests and 145,892 assertions across six lanes, with zero errors, failures or skipped tests. All expected test identities match the observed results, and owned-resource cleanup passes.

The same artifact passes the outbox drill in 40.5 seconds, producer drill in 35.5 seconds, web runtime drill in 94.6 seconds and worker-command drill in 470.6 seconds. The unchanged operator drill passes in 165.7 seconds and the two-phase lifecycle drill in 512.2 seconds. Executable inspection verifies five boot groups: the operator boot and the predecessor/successor boots in each Redis and PostgreSQL phase. Each group has an observed operator process, control and execution worker processes, and an explicit `baander operator status` invocation matching the current ELF hash. Lifecycle proofs match the fixture's exact predecessor/successor container IDs, and both phases verify cleanup.

The final backend run completes in 3,103.7 seconds (3,105.3 seconds including the observer), with these lane results:

| Lane | Tests | Assertions |
| --- | ---: | ---: |
| Ordinary | 8,496 | 49,935 |
| Native | 949 | 30,600 |
| Database stack 1 | 860 | 11,338 |
| Database stack 2 | 999 | 22,543 |
| Database stack 3 | 762 | 10,883 |
| Database stack 4 | 1,191 | 20,593 |
| Total | 13,257 | 145,892 |

Executable inspection records 26 representative category/lane captures matching the same ELF: the discovery runner and its PHPUnit child, plus each lane's runner, ParaTest, PHPUnit wrapper and a nested `PHP_BINARY` child. These captures establish representative process identity, not observation of every child. The observer finishes successfully with no missing categories or errors. The final integrated code review reports no findings or uncovered requirements.

Static checks use the ordinary CI interpreter against the final source and locked dependencies. Composer validation and PHPStan pass; Deptrac reports zero violations with 452 existing baseline skips. The Composer normalization check exits 1 because the existing root `composer.json` orders its script entries differently from the normalizer. The root Composer files are unchanged from `9542816c9`; normalization remains a separate pre-existing metadata issue.

The earlier artifact, SHA-256 `7482e9e74f8f1a2624e0e413bc664da7ef5d07174c19658cdfa22ce6dffeba63` in image `sha256:dfdb30317b80aac89ef28af3543d2b2f91cd8ff8510328c84ad2b24eaf3f8536`, passed 106 entry-point assertions, the interpreter/script/stdin/child-process regressions, 11 bootstrap cases, 142 filesystem checks and 40 source-stream checks, expanded to 76 with physical copies changed and removed. The synchronous/coroutine PostgreSQL and SQLite fixture also passed on that artifact, including COPY, large objects, notifications, transactions and scheduler yielding. Both selectors use the intended composition root, preserve arguments and script metadata, and retain native script invocation forms. The operator route does not load the application kernel. Console error comparisons ignore only per-invocation log timestamp and correlation ID; all other diagnostics, status and stdout remain checked. Earlier unified builds also passed production cache warmup without the external test loader and executed both embedded entry scripts after their physical copies were replaced with throwing markers. That artifact passed the outbox drill in 39.9 seconds, the producer drill in 34.9 seconds and the web runtime drill in 95.1 seconds, then failed the web shutdown drill. These are historical results and do not qualify the current application rebuild. Earlier evidence below also describes the superseded two-artifact setup.

The operator fixture exposed an empty source stream from PHPX: ordinary PHP stripped the same 383-byte archived enum to 124 bytes, while the candidate returned zero. The corrected opener uses the existing archive memory stream when raw source exists, retaining opcode lookup and the opcode-only fallback. Composer's unchanged optimized development-autoload command now passes with and without the qualification prepend. The source fixture accounts for PHP's native shebang handling and uses external commands to mutate physical copies in a disposable container. Patch application with zero fuzz, syntax, simplification, focused review and whitespace checks pass. The previous single-artifact backend run was stopped after its ordinary lane passed. Before the storage-boundary correction, artifact `3668bc51a0a14646dbb48b87862fbbbe0809163566dc1182feff426454f91185` completed 13,247 tests with 145,839 assertions and two errors. Its ordinary lane passed 8,487 tests and 49,895 assertions, its native lane passed 948 tests and 30,580 assertions, and seven database lanes passed. Both errors came from audio handoff path resolution; process inspection confirmed the runner, ParaTest and PHP children executed the packaged ELF. Production cache warmup also passed on that earlier artifact with the external test loader disabled.

The audio handoff errors exposed an existing storage-boundary race: a worker can create or rename a file after `realpath()` reports it missing but before the guard checks existence. Deterministic probes through the actual boundary class reproduce both interleavings under ordinary CI and the candidate. The correction retries canonical resolution once in that case, preserving containment, dangling-link and ancestor checks. The deterministic regression failed before the fix: seven tests, 22 assertions and six failures. Ordinary CI passes the new and existing boundary files with 135 tests and 258 assertions. The preceding `7482e9e7` artifact passed 284 focused boundary and direct-consumer tests with 718 assertions, plus all six audio handoff tests with 73 assertions. The new probe runs in an isolated child; its impurity annotation describes its deliberate filesystem mutation to PHPStan. Targeted level-6 analysis of all three changed PHP files passes, as do syntax, simplification, adversarial review and whitespace checks. The updated probe passed seven tests and 28 assertions in that candidate, which included the correction. Its full rebuilt backend gate was not completed. The eight-stack run also exhausted its shared cleanup budget. Recovery through the saved ownership records removed the remaining containers, networks and volumes; the final full run used four workers. Cleanup-budget changes are separate runner work.

The web shutdown failure exposed a shared class-cache slot collision in the PHPX decoder. The master resolved `Symfony\Bridge\Doctrine\ContainerAwareEventManager` through shared slot 259409; after the fork, a child assigned the same slot to `OpenTelemetry\SDK\Common\Attribute\AttributesInterface`. The later physical proxy had the correct parent in its source, but class lookup returned the interface and failed. The new `phpx-process-cache.patch` removes the decoder's two optional class-name cache allocations and initializes deferred callable caches to NULL for direct storage. Physical-file OPcache remains unchanged, and shared CLI OPcache stays enabled in the canonical qualification INI. The complete patch chain applies with zero fuzz; decoder syntax checking against the matching PHP headers passes.

The process-cache fixture for the unchanged decoder passes `make typephp-test-process-cache`: 252 checks with CLI OPcache disabled and 252 enabled, each comprising 240 exact global-function/static-method callable results and 12 class/interface/type-identity checks before and after fork. Callable coverage includes inherited warm closures and separate constants first resolved in the child, then first resolved in the parent after that child exits. The helper removes the staged fixture source before execution and reuses the private PHP cache after the application build. Its executable under ignored `var/typephp/process-cache-fixture` is disposable validation output; `var/typephp/baander` remains the one application binary. The preceding decoder rebuild, SHA-256 `d2808c88f825d17df67f1b76efe8cef788071a044df24dfa5908d0327ef01505` in image `sha256:0d5ce4a96cafb69ff90f9f247c1886684dcbd1b0e5aa66c024d0216c79d6eef5`, passed the interpreter checks, 11 bootstrap cases, 142 filesystem checks, 106 entry-point assertions, 40 source-stream checks and 76 source-mutation checks, plus 284 focused boundary and direct-consumer tests with 718 assertions. That decoder rebuild also passed both selectors and production cache warmup in a network-isolated disposable container with `APP_ENV=prod`, `APP_DEBUG=0`, no external prepend and shared CLI OPcache enabled, after both physical entry scripts were replaced with throwing markers.

That decoder rebuild passed the stop-command and preceding shutdown cases but failed at the stalled-client case after 139.1 seconds. Native tracing identifies a separate cancellation delay: a sender blocked in `Response::write()` resumes on cancellation, then its progressive audio generator continues reading chunks of up to 64 KiB and repeating runtime/path checks until it reaches `Async::sleep()`. That cancellation takes 5.638 seconds; a later cancellation returns in about 185 microseconds. The output-buffer callback discards further payloads but does not stop the producer. A temporary `Async::throwIfCanceled()` checkpoint immediately after yield passes a real blocked-write probe in both ordinary PHP and the candidate. Without it, each representative generator produces 981 extra chunks; with it, neither produces another chunk, both release their generator and request/stream state, and neither leaks media payload to stdout or stderr. Throwing from the output-buffer callback was rejected because it leaked the current payload. The probe does not exercise `AudioRenditionCache` itself. The checkpoint is now implemented in `AudioRenditionCache::follow()`. Its actual-generator regression failed before the fix, and the maintained blocked-write regression separately failed on continued chunk production. Before correcting the test's exact read-size assumption, the ordinary focused suite passed 35 tests and 144 assertions. With that test accepting valid short reads, the complete audio-cache file passes 23 tests and 79 assertions under both ordinary PHP and the candidate, retaining cancellation, no-further-lookup and exact file-handle cleanup checks. PHP may return less than the requested read size; both runtimes reconstruct the same 131,072-byte file. Targeted PHPStan level 6 passes all eight changed files. The current application build, shutdown result and completed runtime matrix are recorded above.

Setup verification on 2026-11-19: the reviewed plan has no open setup findings; the isolated compiler lock installs 12 packages and passes strict Composer validation and locked reinstall.
The source compiler reports v0.10.0 at locked revision `41d91fe69d2f7ec99dffe252a95244793000c6d2`; PHPX is locked at `90e6d22a9c3f4d5dbd2c75d62e90bf5adfe58c89`.
The optional Docker toolchain builds and reports PHP 8.5.12 ZTS with OPcache.
Both project YAML files parse and resolve their selected paths, the console adapter passes PHP syntax checking, and the production application installation contains 153 packages with development mode disabled.
Root application Composer manifests are unchanged.
Two builder-only patches address failures observed in real builds: missing nested igbinary object directories and Swoole's relative source-directory discovery.
The rebuilt Makefile contains 145 Swoole object inputs, including the previously omitted module entry and coroutine system sources; the original PECL source remains unchanged.
The focused code review and simplification pass found no remaining setup issues.
Both patches apply with zero fuzz and reproduce the reviewed builder files byte for byte; the complete change passes `git diff --check`.

The initial `make typephp-build` and `make typephp-build-operator` runs passed with eight compilation jobs.
Each package contains 13,217 files and 12,191 opcode blobs; nine skipped PHP files are IDE-helper declarations and Swoole library copies outside Composer's autoload paths, with no application PHP files skipped.
The operator build reused 9,498 vendor opcode blobs from the console build with the same runtime signature and source paths.
The console executable is 311,125,560 bytes; the operator is 311,125,912 bytes.

In disposable containers with networking disabled, the console executable loaded all 13 root Composer extension requirements and reported PHP 8.5.12 ZTS, Swoole 6.2.6, Redis 6.3.0 and FFI 8.5.12.
Explicit script dispatch booted Symfony 8.1.10 and listed the application commands, including `app:serve` and `app:worker`, using only tracked dotenv defaults, a temporary writable state directory, FFI enabled and `memory_limit=2048M` from the development runtime's settings.
Default-entry dispatch also passed `baander list --raw` with those settings supplied through an external INI file; interpreter `-v` and `-m` checks passed.
Explicit application `--version` dispatch reported Symfony 8.1.10.
The initial 128 MiB default failed during container compilation; `-d ... list` without `-f` treated `list` as a PHP filename.
The separate operator's explicit `--help` dispatch passed without loading its services or accessing Docker.

Runtime qualification preparation on 2026-11-19:

- The reviewed continuation builds at `/var/www/html`, preserves bare script dispatch through `PHP_BINARY`, and supplies an external development autoloader for the existing CI runners. Production dependencies retain their embedded mappings. The candidate dispatcher selects the independent operator executable even when PHP settings precede its script; the executable-identity regression failed before that correction and passed afterward.
- The private builder now locks PHP 8.5.12 and ten PECL source archives by version and SHA-256. Its runtime-lock regression passes inside the built toolchain, including corrupt-cache rejection and cache invalidation. All common extension versions match the existing CI image, including Swoole 6.2.4. Each runtime loads 58 extensions: the candidate adds required build dependency msgpack 3.0.1 and omits test-only Xdebug 3.5.0. GD capabilities and Redis serializer/compression constants match the baseline.
- Enabling the application's extension features exposed three native build/runtime defects. Derived-source patches correct Redis's bundled LZF include path, isolate 26 PostgreSQL and two SQLite symbols belonging to Swoole's copied PDO drivers, and return intermediate PostgreSQL COPY statuses to the caller for data transfer. The private runtime builds with the required features; downloaded source archives remain unchanged.
- Against disposable PostgreSQL and in-memory SQLite, the existing CI PHP, rebuilt private PHP and packaged console pass synchronous and coroutine checks for prepared queries, COPY array/file round trips, binary large objects, backend PID, notifications, SQLite commit/rollback and scheduler yielding. Malformed COPY input raises SQLSTATE `22P02`; rollback restores the same connection. Both runtime variants expose the existing legacy PDO methods rather than the modern `Pdo\Pgsql` API.

The first qualification build contains 13,217 files and 12,191 opcode blobs per package, with the same nine IDE-helper skips and no application PHP skipped. The operator reused 9,498 vendor opcode blobs.
The console is 317,522,704 bytes, SHA-256 `a617e9c9d44350a19c13db44de2f552e4d18d2f0a878b5608f1f52f6c31baebe`; the operator is 317,523,056 bytes, SHA-256 `2dc61d0e75c9abcd9daf4fa90246f0c36737b76b1fcc8580c5bfc71b06f84205`.
Both pass interpreter, script, argument, stdin and nested `PHP_BINARY` checks. Four dispatcher cases select the expected ELF, including operator calls with leading PHP settings. Default and explicit console listings, Symfony's unknown-command error, production cache warmup and independent operator help pass.
In a disposable image copy, ordinary PHP executed a deliberately changed physical kernel file while the packaged console loaded the original embedded `App\Kernel`.
The qualification image adds missing development package records to `Composer\InstalledVersions` while preserving production versions and root metadata; this fixes the observed profiler boot failure without changing the packaged dependency graph.
Focused native tests pass through the candidate: Doctrine coroutine isolation has two tests and 16 assertions; coroutine logging has two tests and six assertions.

The first full-suite attempt exposed further interpreter differences. Its ordinary lane ran 8,487 tests with 49,813 assertions, two errors and 12 failures; the native lane ran 948 tests with 26,410 assertions, 43 errors, three failures and one warning. The remaining database lanes were interrupted after the corrected build became available; that attempt did not qualify the application.
Argument-less PHPUnit children selected the default console instead of interpreting piped code, and `-r` children skipped the external development loader. The CLI adapter now accepts redirected stdin; an explicit qualification marker enables the effective prepend file before direct evaluation, preserving overrides, `-n` and exit codes. Both executables pass all 11 bootstrap regression cases.
Matching CI's disabled `register_argc_argv` removes the PHP 8.5 diagnostic that contaminated local HTTP responses and media manifests. A separate filesystem regression showed that PHPX's wrapper cached stale existence and permission results and bypassed physical-path `open_basedir` access checks. The runtime now preserves PHP's physical access checks while delegating embedded resources and other wrappers. Both executables pass 142 filesystem checks; embedded files, directories and kernel autoload remain available after removing the physical source tree in a disposable container.
The focused rerun of ten affected test files passes: 71 tests and 5,315 assertions, including isolated heap tests, ZEngine, worker subprocesses, socket cleanup and progressive media publication. Code review and the simplification pass have no remaining findings; PHP syntax, patch application and whitespace checks pass.

The last two-artifact console is 317,524,712 bytes, SHA-256 `d93a46dad8106c87254f750f06a8654021c3765739bd340087ef9ee8e5a714d4`; its operator is 317,525,056 bytes, SHA-256 `e9158bec9839eb88b6a874f1da0dc0315808a143027f6799e2f0fc941b9b0c73`. File/blob counts and skipped-file scope are unchanged.
The packaged console loads 59 extensions: the private runtime's 58 plus the generated `typephp_baander` module. Production cache warmup passes with the qualification marker and external test loader disabled.
The qualification image is `sha256:00366efff99fa1bc79eeeb37c842782a7aaf7fd6b573d2ec21c16ce39eeb13c2`. Process inspection confirms the backend runner, ParaTest and test children use the packaged executable. The full run executed 13,247 tests in 1,193 files across six lanes with four workers: 145,806 assertions, two failures, no errors or warnings. The ordinary and native lanes passed, as did three database lanes. Both failures came from extra job-monitor rows left by child-process retry fixtures; database inspection found exactly the finished and failed fixture rows that explained the excess counts. Ordinary CI reproduced both failures in a deterministic four-test run with 45 assertions.

The worker-command fixture's relocation to `/workspace` caused a duplicate Composer initializer declaration in a disposable candidate probe. The reviewed fixture correction keeps its shared volume, working directory and added listener at the configured application root. Image mode uses Docker's initial volume population; host mode disables that population and stages its checkout explicitly. The candidate passes the relevant tooling checks: 53 tests and 187 assertions across worker drill, application layout and source-policy tests. PHP syntax, the tooling formatter, simplification and focused review passed at that checkpoint; the current artifact's worker-command drill now passes as recorded above.

The retry fixtures now assign their own job IDs, assert the child's recorded ID and status, and remove only those monitor rows through their independent connection during cleanup. The cleanup transaction also removes the fixture's failed messages and always closes the observer. The change is confined to tests; those packaged executables were unchanged. The two-artifact fixture image is `sha256:eb72c99ce0c70547abae221f19cb994d696cd30936237930b37cd27a3dc1619a`. Both controller files pass in transport-before-monitor order: 21 tests and 201 assertions. Syntax, PostgreSQL review, simplification and focused code review pass. A further full run passed its ordinary and native lanes before the user requested one binary; it was stopped during database setup and its disposable resources were removed.

Qualification limits:

- The current single-artifact build passes the complete backend gate, focused checks, production-loader/embedded-entry proof, PDO and all listed runtime drills. Application qualification is complete for the documented Linux/PHP 8.5.12 ZTS profile.
- The ordinary CI image remains the baseline. The matching physical resource tree, native libraries and external writable state remain required. The root Composer normalization issue remains separate metadata work.
- No browser checks have run against a packaged application.

## Forge-neutral CI

Forgejo and GitHub run the same workflows from `.github/workflows`; each workflow
selects its runner, registry, cache store and artifact action from
`github.server_url`. On GitHub, the runner, PostgreSQL and FFmpeg images are public
GHCR packages, and the application and Nginx images are private ones that a probe
checks after each push. The
[CI guide](docs-book/part-2-developer-guide/contributing/continuous-integration.md) describes the
setup and the operator runbook.

Open items:

- Dual-forge acceptance is partial: no `master` commit has yet run every gate green
  on both forges, the gate workflows have not run through a pull request on each,
  and no scheduled nightly has run on each.
- Forgejo acceptance is deferred (user decision, 2026-11-05). Every job of the oldest
  queued run that ran inside the Forgejo registry's `baander-runner:latest` (built
  2026-10-15) failed within a minute, and Forgejo 15's API serves no job logs or
  cancellation. Resuming needs: cancel the stale runs in the web UI, read one failed
  job's log there, and rebuild the Forgejo runner image from the current tree.
- Unverified on GitHub: whether Forgejo 15 scopes its workflow token by
  `permissions`; whether socket-launched test containers bind-mount workspace paths
  on the hosted runner; and whether the backend gate fits a hosted runner's memory
  and disk.
- The frame observer's lagged tolerance measures the snapshotted frame's duration,
  which equals the callback frame's for the constant-rate fixtures; a variable-rate
  source would misclassify. The lagged rate has no ceiling of its own beyond the 60
  percent coverage thresholds.
- The copy's lead after the first throttle pause is unmeasured. FFmpeg's read-rate
  clock keeps running while the producer is stopped, so a resumed copy may publish
  its accrued allowance in one burst; measure the published end across a pause and
  resume cycle before changing the rate.
- A native pause reaches the playback session only with the next presence
  observation, up to ten seconds later, so a reload inside that window recovers an
  older cursor.
- The held-frame transcode fixtures publish the frames generated after the source's
  last packet about ten seconds late, so playback stalls for about ten seconds in
  every held-frame run; the runs pass.
- `uniq_transcode_jobs_owner_user_id_video_id_quality_tier_name` names three of its
  five columns.
- `ui/web/src/features/session/live-coverage.ts` lists only `listening_sessions` and
  `devices` as the sources of `session.sessions`, although the snapshot also reads
  the four playback tables; whether that capture should stay is undecided.
- A cancelled Forgejo run may skip the step that removes its per-run BuildKit builder
  and leave that builder's container on the shared daemon.
- `PublicationWorkflowTest` is about 1,400 lines, including a workflow-expression
  evaluator and a tree linter that could move into test-support classes.
- The coverage comment steps still interpolate the stored baseline into `node -e` as
  code.
- `PackageStore` error paths without tests: restore into a non-empty target,
  constructor validation, and saving a missing directory.

## System overview pages

Delivered and published on 2026-11-05: the operator guide's
[Deployment Topology](docs-book/part-1-operator-guide/getting-started/deployment-topology.md) and the
developer guide's [Communication Map](docs-book/part-2-developer-guide/architecture/communication-map.md).

Open items:

- The Mermaid helper's own tests (`ui/site/scripts/render-blocks.test.mjs`) render
  only the three original flowchart pages, so the book's first sequence diagram is
  checked by the website build and the browser spec but not by the helper's font,
  marker and no-foreignObject assertions.
- The browser spec's diagram page list is kept by hand; nothing asserts that every
  docs-book page with a Mermaid fence is listed.
- The Auth context guide still names a task transport that no longer exists.
- Two entries under `docs/solutions` name a transport that no longer exists and a
  single combined consumer; they are candidates for `ce-compound-refresh`.

## Interactive manual diagrams

Delivered and published on 2026-11-05: every Mermaid diagram in the published manual
is interactive, through `ui/site/assets/diagram-explore.js`, without a change to its
source.

Open items:

- No built page carries two diagram figures, so the shared document listener, the
  hint ids and cross-figure pin release are untested in a browser; the spec also
  lacks an OS-level theme change without the toggle and a wrapped (`<br/>`) sequence
  message.
- The render helper's `describeDiagram` option exists only so a test can force an
  unmatched edge; it should not grow further test-only hooks.
- The inlined SVG carries its own embedded font subset, so page font slicing never
  sees diagram labels; a diagram with non-Latin labels has not been checked.
- The diagram fade uses the fast duration token with the browser's default easing;
  `ui/DESIGN.md` states ease-out for the app shell and is silent for the static site.

## Small-host household deployment

The user confirmed the
[small-host household deployment plan](docs/plans/2026-11-02-1819-feat-small-host-household-deployment-plan.md).
It defines a separate profile for 2,000 songs, 50 movies and 250 TV episodes, with 1–2 simultaneous streams alongside normal scanning and metadata updates.
[System Requirements](docs-book/part-1-operator-guide/getting-started/system-requirements.md) publishes the measured figures and their limits.

The profile split is delivered and on `master`: the
[capability and size deployment settings plan](docs/plans/2026-11-07-1804-feat-capability-and-size-deployment-settings-plan.md)
(U1–U8) replaced the `full` and `household` profiles with a `transcode` or `remux`
capability and bounded size settings, and the operator manifest is version 9. By the
user's decision of 2026-11-12 it landed before the 4 GB qualification, so no host size
is qualified: System Requirements and the configuration guide say so, and the 4 GB
`remux` starting point keeps its starting values until U9 measures it.

Open items:

- The 4 GB `remux` qualification run (U9) has not run; the user deferred it on
  2026-11-12 so that U1–U8 could land first. A first attempt on 2026-11-07 was
  stopped during setup. It reuses the QEMU guest in `~/.cache/baander-household-vm/`
  (start it with `-m 4096`, no libvirtd needed). The images `baander-app:local`,
  `baander-worker:local` and `baander-operator:local` were built from fa240b3d0,
  before the merge with `master`; rebuild all three from the merge commit first.
  It needs three comparable repetitions of the one-player scenarios, restart,
  recovery and deployment replacement on 4 GiB with swap off, then the
  insufficient-memory run on 3 GiB; KTD8 governs any size reduction. Afterwards,
  publish the values in `deploy/preset/example-4gb-remux.json` and the measured host
  size in System Requirements. Until then no host size is qualified, and the
  published measurements are those of the previous household profile from
  3 November 2026.
- Blocker for U9: the local `baander-database:latest` image lacks the
  `baander-pooler-credentials` executable the preset runs, so the database image must
  be rebuilt from `docker/postgres/Dockerfile` before the preset can start.
- The 8 and 16 GB starting points are unqualified guidance; their qualification is
  deferred. ARM64 images and Raspberry Pi hardware are outside this plan.
- Qualification harness follow-ups from review: the deployment-replacement scenario
  does not check fixture borrowers; recovery fails if web or worker processes restart
  during the PostgreSQL outage; `Qualification.php` grew to about 1,900 lines and its
  recovery and replacement logic could move into its own class.
- `docker-compose.yml` now loads the rendered 16 GB PostgreSQL file, so the source
  installation's `max_connections` is 35 instead of PostgreSQL's default 100; check
  that host tools connecting directly still fit the four direct connections.
- `make docs` is shadowed by the `docs/` directory and needs a running preview;
  declare it `.PHONY` or document `php scripts/build-website.php`.
- The web control socket twice closed an `app:server:stats` request without answering under load (household repeats 1 and 2, 2026-11-03); the cause is unknown. The harness now retries and records such samples.
- After a PostgreSQL crash the worker pair stops ("deployment reconciliation is required") and stays stopped until an operator intervenes.
- Affected browser playback specs and the worker runtime container suites have not run against the new profile.

## Configurable media capacity

Delivered on 2026-11-04: full-profile operators set the video and audio job slots, 8
and 12 by default or 12 video slots with a configured hardware encoder; no container
carries a CPU, memory or PID limit; all database traffic goes through PgBouncer with
separate application and control pools.
[System Requirements](docs-book/part-1-operator-guide/getting-started/system-requirements.md)
publishes the per-job costs and the reference margins.

Open items:

- At the default 8 software video slots, the reference host keeps about two 1080p
  `libx264` encodes in real time, not eight, so operators who convert video in
  software must lower the slots. The default was the user's choice and is unchanged.
- Not run: the controls drill at 8 + 12 (the default drills keep the 3 + 1 fixture),
  and NVENC, QSV and AMF on real hardware. The NVENC device-request shape that
  containment verification compares was not checked against a Docker NVIDIA runtime.
- Existing PostgreSQL volumes need `REINDEX` and
  `ALTER DATABASE … REFRESH COLLATION VERSION` on the Debian 13 database image;
  `baander-database:latest` and the persistent instance were not touched.
- Transcode execution ownership renewal runs on the application pool, where a client
  can wait up to PgBouncer's default 120 s; the ownership lease is 15 s. A burst of
  long application transactions could delay renewals past the lease.
- The control pool fails a media authority query that waits more than one second;
  slot counts above 8 + 12 are unproven against it.
- The pooler's `admin_users` is the application role, so web and worker processes
  could pause or stop the pooler; the development checks need only `stats_users`.
- When a progress report races the write that records a failed encode, that write is
  refused and the failure stays unrecorded on that path.
- The governor's warm-up encodes predict about 40% of the host per 1080p stream,
  against about 21% measured for full encodes.
- Test gaps: the functional suite connects to PostgreSQL directly, so
  transaction-pooling incompatibilities surface only in the worker drills, and
  `HostCpus` and the boot-row fingerprint check constraint have no direct tests.
- The development `docker-compose.yml` sets no `oom_score_adj`.

## Playback destinations and notifications

The user confirmed the
[playback destinations and notifications plan](docs/plans/2026-11-01-1840-feat-playback-destinations-and-notifications-plan.md).
It covers player-owned destination selection, sidebar notifications, music and video transfer, and AirPlay, Chromecast and DLNA alongside Bånder devices.
Local and remotely hosted servers are included, with a local casting companion where needed.

Receiver grants, the companion, protocol adapters and physical receiver qualification remain open,
as do Cast registration and hosting.

Available hardware is now confirmed as an iPhone 13 and Apple TV 4K, alongside
Linux, with no Android device. AirPlay hardware qualification remains outstanding; physical Chromecast
and DLNA receiver checks remain open because suitable receivers have not been identified.

Queue authorization currently checks tracks individually; the receiver
authorization work will assess a bounded Catalog batch contract without weakening
per-item access checks.

Safari automatic local recovery remains unavailable; ordinary playback is retained.

The browser owns its local music/video
execution independently of companion presence, pairing or connectivity. The companion
plan explicitly prohibits claiming or acknowledging browser endpoints.

The first reload-recovery run retained the correct duration but did not resume
after Play. One instrumented rerun passed reload, tab-close and renderer-crash
recovery with the duration assertions. The first failure remains an unexplained
intermittent resume issue; the rerun does not establish its cause.

Follow-up: the separate retry path can retain a pending control after a network
failure followed by a rejected retry. Initial rejected requests are cleared; the
retry-after-network-failure case remains outside this recovery check.

## Bånder Audio Core in the server transcoder

Delivered and merged on 2026-11-07: the project's static FFmpeg 8.1 build carries
Bånder Audio Core as the `libbac` encoder and decoder (`libbaac` until the rename)
beside fdk-aac and FFmpeg's built-in AAC codec, and the application image runs it
at `/usr/bin`. The
`transcode.aac_implementation` setting selects the implementation (see the
[transcoding guide](docs-book/part-1-operator-guide/media/transcoding.md#aac-implementation)).
The `ffmpeg` role of the tooling image catalog pins the published image by digest.
VAAPI is qualified on the RX 9070 XT.

Open items:

- Decide whether `ffmpeg-baander-static` stays public. The image is built with
  fdk-aac under `--enable-nonfree`, which FFmpeg marks as not redistributable, and
  GHCR serves it anonymously. The user allowed the public package (2026-11-07) before
  this was raised. The alternatives are making the package private again (CI pulls it
  with its workflow token) or dropping fdk-aac, which removes the `fdk_aac` position
  of the setting.
- Check that `ffmpeg.yaml` ran and published on Forgejo. Forgejo CI reads
  `ffmpeg-baander-static:latest` from the Forgejo registry and fails at
  "Build CI image" without it.
- NVENC and QSV were not run on hardware; the capability gate only proves their
  components are compiled in.
- Not run: the stream-copy pacing re-check that
  `docs/solutions/performance-issues/pace-a-stream-copy-with-a-read-rate-burst-counted-from-the-seek-boundary.md`
  asks for at the next FFmpeg major. The testing guide's statement that `-shortest`
  buffers up to ten seconds is unverified on FFmpeg 8.
- Found and not fixed: the NVENC HDR path emits `tonemap_cuda`, a filter neither
  Debian's FFmpeg nor this build has;
  `MediaWorkerRunnerTest::testSerialAudioJobsReuseOneSlotWithIncreasingLaunchGenerations`
  failed once under CPU load and passes alone; GitHub reports 178 Dependabot alerts
  on the default branch (3 critical, 78 high).
- Review advisories left for a decision: a rendition whose source codec ffprobe
  cannot report decodes with FFmpeg's default decoder instead of the selected one;
  the libbac decoder labels 7- and 8-channel output with an unspecified layout unless
  the container gives one; it neither trims nor drains decoder delay, which matters
  only for MPEG Surround sources; the unused `FFmpegAdapter` per-segment encoders read
  the live setting rather than a session's.

## Learning and plan maintenance

The shared durable-consumer heartbeat can mask an individual consumer's outage;
that limitation is recorded for follow-up, with no runtime change in the 2026-10-31 learnings refresh.

## Resume here

### State on 2026-11-17

Pushed on 2026-11-20 to `master` on GitHub and Forgejo: `fix/auth-security-hardening`,
merged with Forgejo's `master` (`3473a20c3`), which brought the TypePHP packaging work
(`281a07a4c` to `cfc297b54`) to GitHub as well. It carries the OAuth pushed
authorization requests and the auth hardening; see
[OAuth pushed authorization requests and auth hardening](#oauth-pushed-authorization-requests-and-auth-hardening).
Every user signs in again and re-enrols TOTP after its migrations. The persistent
instance's `env.json` gained its `AUTH_TOTP_ENCRYPTION_KEY` before the push, through
the `Bootstrap::configure` back-fill, because its copied update controller predates
that back-fill.

`master` on GitHub and Forgejo carries, on top of `6912eaaf6`, the work pushed on
2026-11-15: the CI fixes (`d1504daa3` to `77bb3c8e4`), two web fixes (`9ecf84a3d`,
`052e8684d`) and the roadmap cleanup with its plan retirement. Landed on 2026-11-14:
scan artist credits and MusicBrainz matching. The persistent instance ran `6912eaaf6`
before that push and updates itself from `master`. The website was last published on
2026-11-18: a local build of `1dec6bcc5`, deployed to production as
`baander-2vuoa3grt` and aliased to `https://baander.app`.
Pushed later on 2026-11-17, on top of `0660fb943`: party invitations (`3a666d381` to
their roadmap record).
Pushed later on 2026-11-15, on top of `23f899976`: the matching follow-ups
(`a7d1c7466` to `6991a9f9a` and their roadmap record), with the fix for the CI cleanup
limit. Pushed on 2026-11-16, on top of `8d119f5df`: the matching leftovers
(`a822bf362` to `98a89db8a`). Pushed later on 2026-11-16, on top of `44e315ee3`: the
PGroonga cleanup race fix, `7c2deaa24` first and the rest up to this record once
GitHub had published the PGroonga 4.1.0 database image. Pushed later on 2026-11-16,
on top of `e99e748a6`: the prose-fix presentation register and the homepage hero
(`ba5cbce6b` to `7fe638cec` and their roadmap record). Pushed later on 2026-11-16, on
top of `c47af96fb`: the docs-book rewrite (`20be3f1df` to `0d017641f` and its roadmap
record). Pushed on 2026-11-17, on top of `f5065dcfe`: the docs-book rewrite follow-ups
(`9b72c2185` to their roadmap record).

- **Website feature pages.** On `master` since 2026-11-18, merged from
  `feat/site-feature-pages` in `464fad883`, pushed to both forges and published to
  `https://baander.app`: the `/features/` overview and nine feature pages, and the
  homepage's pictures retaken from the sample library. The merged local branches
  were deleted the same day; `fix/notification-pane-well-inset` stays because its
  worktree still has it checked out. Next: the user reviews the pictures, and a plan
  makes the lyrics panel show stored `.lrc` lyrics so the lyrics page can follow
  (user decision, 2026-11-18); see the "Still to do" list under
  [Website feature pages](#website-feature-pages).
- **Master CI.** No `CI` run on `master` has been green since 2026-11-05. Run
  43826408058 on `44e315ee3` passed the whole quality gate on 2026-11-16, step 41 in
  2,276 of its 3,600 seconds, and failed the `build-and-push` job at its check that
  the application image cannot be pulled anonymously. The user decided the same day
  that every image is public, and `9e7d164bd` removes that check. Run 43826369096 on
  `98a89db8a` failed step 39 on a DPoP key with a short coordinate, fixed by
  `41bc3d439`. The runs on the PGroonga push have not been checked yet; the user
  checks in on CI from time to time rather than waiting for it. See
  [Master CI repair](#master-ci-repair). The first green run is the point to write the
  CI-repair learning with `ce-compound`, which the user confirmed on 2026-11-13.
- **Quiet live-update status.** On `master` since 2026-11-16, merged from
  `feat/quiet-live-update-status` (`5e7db8e96` to this record): the web shell no longer shows the
  top-right live-update label, and lasting outages raise a toast and a bell entry;
  see [Quiet live-update status](#quiet-live-update-status).
- **Presentation register and homepage hero.** On `master` since 2026-11-16,
  fast-forwarded from `feat/prose-fix-presentation-register` (`ba5cbce6b` to this
  record) and published to `https://baander.app`: prose-fix gains a Jobs register for
  website feature introductions, and the homepage hero uses it; see
  [Presentation register and homepage hero](#presentation-register-and-homepage-hero).
- **docs-book rewrite follow-ups.** On `master` since 2026-11-17, fast-forwarded from
  `fix/docs-rewrite-follow-ups`: invite-only watch parties with the host's playback
  broadcast to the room, the unread configuration removed, `VIDEO_ENCODER` passed to
  the workers, and the stale text outside the book corrected; see
  [docs-book rewrite follow-ups](#docs-book-rewrite-follow-ups).
- **docs-book rewrite.** On `master` since 2026-11-16 and published to
  `https://baander.app`: every manual page passed through prose-fix and outdated claims
  were corrected against the code; see [docs-book rewrite](#docs-book-rewrite).
- **Web fixes.** With the same push, a click on the active lyrics effect turns effects
  off, and the songs view's genre, artist and album panes scroll; see
  [Lyrics effect toggle and song browser panes](#lyrics-effect-toggle-and-song-browser-panes).
- **Matching follow-ups.** On `master` since 2026-11-15: the twelve units of the plan,
  the code review's fixes and the fix for the CI cleanup limit; see
  [Matching follow-ups](#matching-follow-ups). The persistent instance takes them
  with its next update. A lyrics fetch queued before that update fails to decode, and
  "Ten Thousand Fists" stays on its review list until an admin applies a candidate
  from the match dialog.
- **Matching leftovers.** On `master` since 2026-11-16: hand changes to credits and the album merge under the catalog
  lock order, the duplicate fold, artist sync by stored MusicBrainz ID and the fill of
  an artist a match or an import identifies; see
  [Matching leftovers](#matching-leftovers).
- **Rename landing, remaining steps.** Done: `master`, the FFmpeg pin, the website,
  the tag `audio-core-v0.2.0` and the persistent instance. Remaining, each checked
  again on 2026-11-14:
  1. The `audio-core-v0.2.0` release does not exist yet. In its run (43478502093) the
     Linux x64, Windows x64 and macOS x64 prebuilds passed three times, and GitHub
     never assigned a macOS arm64 runner ("The job was not acquired by Runner of type
     hosted even after multiple attempts"), so the release job was skipped. Re-run the
     failed job, then check the four prebuilds, `SHA256SUMS` and a clean desktop
     install that fetches its prebuild.
  2. Move `packages/baander-aac/bench/work/` from
     `/home/martin/dev/baander-baac-lossless` into `packages/baander-audio-core/bench/`.
     Copy `work/heldout/runs.json` first, then run
     `./rename_ledger.py work/heldout/runs.json` and `./setup-refs.sh` without
     `--lossless-only`, so `env.sh` loses the old absolute paths.
  3. Forgejo CI is set aside by the user; re-run its `ci.yaml` once Forgejo's FFmpeg
     image carries `libbac`.
- **Capability and size.** No host size is qualified yet (user decision,
  2026-11-12). Next: the 4 GB remux qualification in the VM (its plan's U9), after
  rebuilding the images from `master`. The qualification harness follows the album
  match queue since the matching follow-ups; see the qualification items under
  [Matching follow-ups](#matching-follow-ups).
- **Next plans.** Better compression of material like the failed held-out recording
  (lossless plan KD12) follows the rename. Its fourth held-out set may be read only
  after the benchmark data has moved and its ledger is rewritten.
- **PGroonga cleanup race.** On `master` since 2026-11-16: the item the user chose
  that day as "PGroonga isolation of throwaway test schemas". Its cause was PGroonga
  4.0.5, not the schema drops. The database image and the persistent instance run
  PGroonga 4.1.0. See [PGroonga cleanup race](#pgroonga-cleanup-race).
- **Cleanup.** GitHub and Forgejo carry only `master`, and so does the local checkout.
  Left locally on 2026-11-16: the worktree `~/.codex/worktrees/ba59/baander` (detached
  at `ba5750533`, which is in `master`), and the local gate images
  `baander-ci-prewarm:local` and `baander-ci-firefox:gate`.

  Removed on 2026-11-16 with the user's approval: the merged branches
  `fix/scan-artist-credits`, `fix/matching-follow-ups`, `fix/matching-leftovers` and
  `fix/pgroonga-cleanup-race`; the worktrees `baander-ml-verify`, `baander-verify`,
  `baander-pgrn-diag` and `baander-pgrn-verify`; the images `baander-ci:mf`, `mci`,
  `mci-worker`, `mci-proxy` and `u10`, and `baander-database:pgroonga-4.0.5` and
  `:pgroonga-4.1.0`; and the persistent instance's pre-move dump and saved 4.0.5
  image. The worktrees' test-run receipts are in
  `~/.local/state/baander/receipts/2026-11-16-pgroonga.tar.zst` and
  `2026-11-16-verify-worktrees.tar.zst`.

  `/home/martin/dev/baander-baac-lossless` stays, detached at `a97ae0058`, until its
  `bench/work/` has moved. The receipts of the 2026-11-13 cleanup are in
  `~/.local/state/baander/receipts/2026-11-13/`.

Continue the Back-to-next-movie investigation through a newly reviewed, bounded
scope. Capture the browser failure and the next movie's actual startup outcome,
including its mounted/retry state, then complete authenticated cancellation and
recovery controls. The evidence below confirms retained producer ownership but
does not yet select a production remedy or another automatic test run.

Select other work for a concrete user workflow, demonstrated runtime gap or named
release decision. Historical unchecked items, old plan limitations and incomplete
experiments do not authorize implementation or indefinite reruns. Reconcile the
[retained plans](docs/plans/) with current callers before using them. Release gates
become actionable when their named environment, access or decision is available.

Read [AGENTS.md](AGENTS.md) for workflow, [CONCEPTS.md](CONCEPTS.md) for shared terms
and the [testing guide](docs-book/part-2-developer-guide/testing/README.md) for current
runners and verification boundaries. Historical suite counts apply to their own
trees; they are not acceptance evidence for a new change.

## OAuth pushed authorization requests and auth hardening

Delivered on `master` on 2026-11-20 from `fix/auth-security-hardening`, merged with
Forgejo's `master` in `3473a20c3`. The branch implements two plans:
`docs/plans/2026-11-19-1148-feat-oauth-pushed-authorization-requests-plan.md`
(`ea416708d` to `9cf1c6499`) and
`docs/plans/2026-11-19-2119-fix-auth-security-hardening-plan.md` (`5a80d108c` to
this record). The hardening plan fixes every confirmed finding of a five-reviewer
security review of the auth and OAuth code, which the user asked for on 2026-11-19.

Pushed authorization requests and `private_key_jwt`:

- Each OAuth client stores its authentication method and key source (`6b6b6c013`).
  Confidential clients authenticate with `client_secret_basic` or with
  `private_key_jwt` client assertions (`9f61ef0be`), whose keys come from the client
  record or from a `jwks_uri` (`689f64161`).
- Third-party authorization starts with a pushed authorization request bound to a
  DPoP key (`7f891d6b9`, `12b95f0d1`). The authorization code carries that key, and
  the consent page reads the pushed request (`1ef91019b`).
- Operators register key clients and replace their keys from the admin API, the
  console and the admin UI (`4bd74c926`).

Hardening, by area:

- Delegated tokens. A token from any client other than the first-party web client
  holds `ROLE_USER` only. Its scopes (`profile`, `email`, `library`, `playlist`) map to
  the routes it may call; any other route answers 403 `insufficient_scope`
  (`d790a24e2`, `248996846`). A delegated grant with nothing grantable is refused,
  and the consent page describes each scope (`1857be595`, `07ed9a4e6`).
  `/api/auth/refresh` refreshes only first-party tokens (`a2217a9bc`).
- Code and client flows. DPoP `htu` is checked against the deployment's public
  origins (`0a9e00aaf`). PS256 is accepted for client assertions and DPoP proofs
  (`7024b7ae4`). The pushed `state` is bounded, a replayed code ends its token chain,
  and access tokens carry `iss`, `typ` and `kid` and are revoked by `jti`
  (`f62c4a42d`, `6fad7f748`). Loopback redirect URIs are matched the way a browser
  parses them (`65694a2e6`).
- Credentials. Each TOTP code works once, and TOTP secrets are encrypted with
  `AUTH_TOTP_ENCRYPTION_KEY` (`05952b12c`). Passkey challenges live only in Redis and
  are used once; accounts with TOTP need user verification on their passkeys
  (`bad685a59`). Login hides which accounts exist and limits failures per account,
  and registration answers the same whether or not the address is taken
  (`ff52e2985`, `6572b588b`).
- Sessions. Each user has a credential epoch, which a password change or an operator
  TOTP reset advances. Codes, device approvals and refresh chains from an older epoch
  issue no more tokens; a password change keeps the session that made it. Refresh
  tokens are stored hashed, and a refresh chain lives at most 90 days (`63d24010e`,
  `75159b020`, `168747cc3`).
- Account changes. Changing the email, registering a passkey and starting TOTP setup
  need step-up re-authentication: the password, plus a TOTP code when TOTP is on
  (`88ce25f7d`, `91577b07e`). A new email address stays pending
  until it is verified, and authenticators need a verified address (`7d4655782`).
  Operators can reset a user's TOTP (`55b53e63c`).
- Production. The application refuses to boot in production with public or weak
  secrets (`a55efcb07`). Signed stream URLs expire at most 24 hours ahead
  (`d36bbe151`). Responses carry a nonce-based CSP, HSTS and static-file headers
  (`00ca99c0a`). Health probes no longer show exception text (`0c06c1985`).
- WebSocket frames are capped, and abusive sockets and those of disabled accounts are
  closed (`1b595b256`). Voters decide admin access through the role hierarchy
  (`0ee9794df`).
- A `ce-code-review` run on 2026-11-20 found seven issues to fix, all applied
  (`b361cd908` to `e7ec54519`). A Redis outage at device authorization and a
  timed-out issuance lock now answer 503. `GET /api/auth/me` documents the delegated
  profile. A reused refresh chain ends through the shared chain revoker.

An instance configured before this branch has no `AUTH_TOTP_ENCRYPTION_KEY` in its
`env.json`, and a production instance refuses to boot without it. `Bootstrap::configure`
adds a fresh key to such an `env.json` and keeps every other value (`f01a6c579`;
`DevelopmentBootstrapTest`, 17 tests).

After these migrations every user signs in again: `Version20261120110000` deletes all
sessions. `Version20261119220000` drops the plaintext TOTP secret, so every user
re-enrols TOTP. Device codes approved before the migration are refused.

Open:

- The CSP, step-up passkey registration and web TOTP login were not checked in a
  browser: the preview could not start from this worktree.
- `/oauth/revoke` is a first-party route, so a delegated token cannot revoke itself;
  RFC 7009 revocation for third-party clients is separate work.

Verification on 2026-11-20:

- `make ci` at `e7ec54519` in the local CI image: composer normalize, PHPStan (no
  errors in 3,928 files) and Deptrac (no violations) passed. Of the backend lanes, the
  ordinary lane (8,896 tests), the native lane (953) and the second stack lane (1,662)
  passed without failures, errors or skips. The first stack lane (174 files) hit the
  runner's one-hour budget with tests still running; `make ci` measures coverage, which
  runs the lanes under Xdebug. Its files, rerun without coverage through
  `scripts/test-functional-container.sh` in two lanes, gave 2,288 tests
  and one failure: `TranscodeCleanupConsoleOutputTest` started a production console
  with the committed default secrets, which production now refuses. `26a7fd0cb` gives
  that process private secrets, and both cases of the test pass.
- On the merge with Forgejo's `master` (`3473a20c3`), `make ci` passed PHPStan,
  Deptrac, the ordinary lane (8,905 tests) and the native lane (954). Both stack lanes
  hit the one-hour budget under coverage. Their 347 files, rerun without coverage
  through `scripts/test-functional-container.sh`, failed four `ApiRateLimitTest` cases:
  `LoginAccountThrottleTest` charged the anonymous API limiter for addresses that test
  expects unused. `233446bc5` moves the throttle test to `198.18.10.x`, and the rerun
  then passed 3,950 tests (2,288 and 1,662) with both tests in one lane. The OpenAPI,
  generated-client and typecheck checks and `DevelopmentBootstrapTest` (17 tests)
  passed on the merge. Coverage was not measured for the stack-lane files, and whether
  CI's coverage step still fits its 3,600 seconds is open; see
  `docs/solutions/developer-experience/coverage-runs-push-the-stack-lanes-past-the-backend-budget.md`.
- Web: 3,661 tests in 293 files passed; the typecheck passed. The OpenAPI document
  and the generated client match the code (`app:export-openapi-spec --check`,
  `generate:check`).

## Web dependency refresh

Checkpoint, 2026-10-31: refreshed the web application, embedded player, public
website, shared packages and test/build tooling. TypeScript 6.0.3 remains in the
web and shared packages because typescript-eslint requires a compiler below 6.1;
the site and embedded player use 7.0.2.

The [maintenance guide](docs-book/part-2-developer-guide/contributing/contributing.md#javascript-dependency-maintenance)
documents offline ownership checks and explicit online dependency, advisory and
patch inspection. Nine exact site resolutions reduce the Vercel dependency tree
from 44 advisory records to 22, removing its critical finding. Remaining fixes
require incompatible transitive releases. The web lint tree retains one moderate
minimatch advisory requiring version 11; its compatible Babel security update is
applied. These findings remain visible in online output. Yarn still reports three
upstream dash.js peer requirements in the `@svta/cml-*` packages; the real DASH
playback check passes for the tested path.

## PHP dependency and runtime refresh

Implementation is retained at the user's wrap-up checkpoint on 2026-10-31.
Qualification is partial; the remaining checks below are recorded follow-up work,
not authorization to continue expanding this upgrade.

Swoole bundle reconciliation: unconfigured session defects are recorded as
deferred defects; readonly proxy support remains unselected.

Five root packages remain behind stable because the latest reli-prof 0.12.0 pins
their shared dependencies: Monolog, Webmozart Assert, Amp, Parallel and
MonologBundle. Exact versions, upstream references and solver blockers are
recorded in the
[dependency guide](docs-book/part-2-developer-guide/getting-started/php-dependencies.md) and tooling
policy.

TSDuck native qualification is blocked, as explicitly accepted by the user on
2026-10-31. Its required PHP 8.5/FFI/PHPUnit 13 probe fails during bootstrap because
the bindings declare `tspyphp*` polling bridge functions absent from upstream
TSDuck v3.43-4549 and the Docker build. Repository history contains no bridge
implementation to recover. Native qualification and automatic CI wiring remain
open for separately scoped C++ bridge work. The probe fails rather than skipping;
no native callback or lifetime pass is claimed.

Six operator-dependent tooling cases and the remaining registry modes still
require their separate qualification. The registry release and sanitizer gates
pass on the final qualification derivative; the thread gate was interrupted at
the user's request.

The complete backend result of 10,966 tests and 120,760 assertions applies to its
recorded earlier tree. No final full backend or reduced-tooling rerun is claimed.
The remaining media matrix and registry thread/fuzz/container/backup gates were
not completed at the wrap-up checkpoint. GitHub `master` at `7aa0172fb` was merged
into the upgrade branch; no full application or AAC suite was rerun for this merge.

## Cohesive live updates

All nine units and the five independently confirmed review fixes were delivered
on 2026-10-31; the recorded qualification leaves these limits open.
Memory reclamation was observed; warm byte counts exceeded cold baselines. This
finite workload does not establish indefinite memory stability. Remote metadata
conflict/deletion and preference conflicts use component/coordinator and hook
proofs; these are not browser conflict tests. The 10,000-album Timeline fixture
has no covers, so it does not measure image fanout. The earlier exited
scan-worker pair's cause remains unidentified; fresh worker health and the
subsequent real scan pass are the recorded qualification.

## Quiet live-update status

Delivered on `master` on 2026-11-16, merged from `feat/quiet-live-update-status`:
all six units of
`docs/plans/2026-11-16-1823-feat-quiet-live-update-status-plan.md`
(`702a825ce`, `46cb8ae33`, `bb1d47485`, `3d20b4230`, `b9f52afa9`, `bf2c45323`), the
code review's fix (`db640bf0b`), two follow-ups from the browser runs
(`aa3aab712`, `9d8f0da36`), and the targeted review's fixes (`34a06d68c`). The web shell no longer shows "Connecting…",
"Updating…" or "Updates unavailable". A live-update outage of 15 seconds of
visible time raises one toast, and one of 2 minutes adds an entry to the app and
admin bells; both clear on recovery. The single toast host moved to the app root,
which also shows admin and OAuth-page toasts where they are raised. The desktop app
reports its window's visibility, so a minimised or tray-hidden window counts as
hidden.

Verification on 2026-11-16:

- Web: `yarn --cwd ui/web test` passed 290 files and 3,622 tests; `typecheck`,
  `build` and `lint` passed, lint with 0 errors and 10 warnings, all in files this
  branch does not touch (generated coverage output and TanStack Virtual call
  sites).
- Desktop: the Electron suite ran 75 tests, 67 passed and none failed; the IPC
  security test passed 6 of 6, including the new visibility channel.
  `tsc -p tsconfig.preload.json` and `tsconfig.renderer.json` passed;
  `tsconfig.main.json` reports only the `@baander/audio-core` native module, which
  is not built on this host.
- Code review: `ce-code-review` at full depth with seven reviewers covered the
  branch up to `3d20b4230` plus the browser-test and docs edits then uncommitted;
  verdict "Ready with fixes". Its one confirmed finding, desktop window visibility,
  is fixed in `db640bf0b`. Of its testing gaps, `db640bf0b` and `aa3aab712` close
  the frozen-page history, a tab hidden mid-outage, a short drop that dismisses
  nothing, and a watcher started on a degraded connection. Two remain: the watcher
  without freshness tracking is covered only by the helper's unit test, and the
  label-absence test checks only the connecting phase. A focused `ce-code-review`
  of the commits after `3d20b4230` (verdict "Ready with fixes") found that the
  frozen-page check could pass on a detached store copy and read the outage
  history too early; `34a06d68c` fixes both and also guards the window visibility
  send and the first visibility answer. The new IPC channel uses the main-window
  ownership check, and the IPC security test's untrusted-caller loop covers it.
- Browser: `recovery.spec.ts` against this worktree's preview. Passed: two
  collaborators, failed authoritative read, revocation with a delivery outage,
  frozen-page wake, independent console producer, managed reload and managed
  restart. After `34a06d68c` the frozen-page and revocation scenarios pass again,
  with the fixtures reading the store under the URL the application loaded. Failed on this branch and on `master` in the same preview: the
  delivery outage with record-play (no play record within 10 seconds), the dirty
  metadata draft with owned audio, and the stalled projection, which never
  reached degraded health on `master` and did not on the last branch run.

Open:

- The stalled-projection scenario is unproven here. In one branch run the outage
  started and the toast showed, but live status did not return to current within
  30 seconds of the release; the old label check would have passed while
  "Updating…" lingered. A preview where the stall degrades reliably is needed to
  tell a lingering refresh from an environment fault.
- The record-play and owned-audio scenarios fail on `master` too; their cause was
  not investigated.
- The admin bell keeps its own relative-time helper, which has no date fallback
  after 30 days, beside the shared one; consolidating it changes old admin rows.

## Website feature pages

Delivered on `master` on 2026-11-18, merged from `feat/site-feature-pages`:
`docs/plans/2026-11-17-1757-feat-site-feature-pages-plan.md`, except the lyrics page. The public website gains `/features/`: an overview in three groups and
nine feature pages. Listeners get the music library, the player, the equalizer,
playlists and playback destinations; operators get libraries and scanning,
administration and the console; developers get one page. Each page opens in the
prose-fix Jobs register and shows the feature in pictures taken from the application
with a fictional sample library, or in blocks the manual renders. It gives a short
guide and lists the feature's limits. Every claim cites `README.md` or a manual page,
and each page's claim ledger is in the commit that adds it. The homepage links into the
pages, and its seven pictures are retaken from the sample library.

Commits, in order: `0ac2a3205` (plan), `4be4a630a` (data validation), `e29a7368c`,
`edc0080f1` and `21f262127` (README and notification guide corrections),
`c7c79881d` (sample library), `38527f87f` (overview and equalizer page), `38596b3c2`
(developers page), `0b3e71733` and `dc601544a` (capture driver, scenes and pictures),
`2a263b2fb` (listener pages), `e9b5c7a8b` (operator pages), `aa601e211` (README:
rearranging the queue), `c95251d0d` (overview completed, homepage links), `0ddb0b2ca`
(capture helpers shared, failure paths hardened), `a0c6ccc7d` (homepage pictures),
`13dad7ed5` (site README, house style, `ui/DESIGN.md`, `CONCEPTS.md`), `8bf62a06f`
(the code review's fixes), `c05c2f684` (this record) and `a2453db0f` (wording after
the writing check).

Verification on 2026-11-18, at `8bf62a06f` unless stated:

- Site: `make website-build` built 243 manual pages. In `ui/site/`,
  `corepack yarn typecheck` was clean, `corepack yarn test:browser` passed 207 tests
  and `node --test scripts/*.test.mjs` passed 35.
- Tooling: `phpunit-tooling.xml.dist` on `scripts/tests/php/Website`,
  `ToolingSourcePolicyTest.php` and `ToolingArchitectureTest.php` passed 172 tests and
  1,551 assertions. PHPStan with `phpstan-tooling.neon` reported no errors,
  `php-cs-fixer check` with `.php-cs-fixer.tooling.php` found nothing to fix, and
  `scripts/check-tooling-source-policy.php` exited 0. The full tooling suite was not
  run.
- Pictures: 32 pairs, each with a capture record in `ui/site/features/captures.json`,
  taken against the worktree preview at application commit `0326c6acb` on 2026-11-17
  and 2026-11-18. The playback destinations pictures show a real move between
  Chromium and Firefox signed in to one account; nothing in the browser was staged.
  The capture scripts ran in full twice into scratch directories, leaving the site's
  pictures as they were. At `0ddb0b2ca` the run took all 32 pairs. At `8bf62a06f`
  it took 31; it refused the administration picture because the Jobs tab changed
  between its two variants, and it kept the other pairs. That scene, run alone
  afterwards, took its four pairs at the second attempt. The first attempt stopped
  at sign-in: the login page's Email field did not appear within 30 seconds.
- Fonts: the browser suite ran on this host, which resolves Arial to Liberation
  Sans. Several page sentences were reworded until both faces broke them into the
  same lines. The closest case is a link label whose width differs by 2.14 % between
  the faces, against a tolerance of 2.5 %.
  The CI image's fallback faces were not checked, so the `Public website` workflow
  is the authoritative run of these checks. That workflow also runs
  `node --test scripts/*.test.mjs`, including `pictures.test.mjs`.
- Code review: `ce-code-review` at full depth with correctness, project standards,
  testing, maintainability, reliability, adversarial (in-process; cross-model review
  is off in this checkout) and learnings; verdict "Ready with fixes". An independent
  validator confirmed both findings: a library grant that was never retried, and a
  transient check that ended the whole capture run. Both are fixed in `8bf62a06f`,
  together with the review's README suggestion to switch the provider lookups off
  before the scan.
- Writing check: the plan's ce-noslop detect pass over the ten feature data files and
  the homepage's new lines found 25 points; `a2453db0f` rewords the pages for them
  without changing a claim. At `a2453db0f`, the line and wrap comparisons of both
  fonts found no difference on any feature page or the homepage,
  `node --test scripts/*.test.mjs` passed 35, and `corepack yarn test:browser`
  passed 205 of 207. Both failures came from the environment and were not rerun:
  one page load ended with `net::ERR_NETWORK_CHANGED`, and a manual page's diagram
  image (`rendering.spec.ts`, untouched by this branch) did not load within 8
  seconds.

- Master and publication: the merge commit `464fad883` on `master` built 243 manual
  pages, passed 172 tooling tests with 1,551 assertions, 35 site script tests and
  all 207 tests of `corepack yarn test:browser`, and was pushed to GitHub and
  Forgejo. A local build of `464fad883` was deployed as the preview
  `baander-5zlw24mxu` and then to production as `baander-1xz9pz3lx`, aliased to
  `https://baander.app`. On both, the homepage, `/features/`, two feature pages, two
  pictures, a nested manual page, the search script, the sitemap and the social card
  returned 200, and an unknown URL returned 404. The live site serves the overview
  over HTTPS with HSTS.

- Republication (2026-11-18): a local build of `1dec6bcc5` brings the site up to
  `master`. It differs from the `464fad883` build only in
  `docs-book/part-2-developer-guide/testing/frontend-and-browser.md`, changed in
  `d4a78bf9b`; no `ui/site` file changed in between. The build made 243 manual
  pages and passed all 207 tests of `corepack yarn test:browser`. It went to the
  preview `baander-1pawf1v95` and then, unchanged, to production as
  `baander-2vuoa3grt`, aliased to `https://baander.app`. On both, the homepage,
  `/features/`, `/features/library/`, the changed manual page, the sitemap,
  `robots.txt`, the search script and the social card returned 200, and an unknown
  URL returned 404. The live site serves HTTPS with HSTS, and its copy of the
  changed manual page is byte-identical to the build.

Still to do:

- Check the `Public website` and `CI` workflow runs of `464fad883` on both forges.
- The user has not reviewed the pictures; the plan's Definition of Done requires it.
  They are live on `https://baander.app/` and `https://baander.app/features/`. The
  contact sheet in `var/site-capture/` shows the last capture run, not the
  committed pictures.
- No lyrics page. `README.md:46` and
  `docs-book/part-1-operator-guide/media/library-management.md:64` say lyrics come
  from `.lrc` files beside the tracks, and the scan stores them on the song. The
  lyrics panel reads only lyrics fetched from LRCLIB
  (`src/Lyrics/Interface/Controller/LyricsController.php:71-87`), so `.lrc` lyrics
  never reach it. User decision, 2026-11-18: the panel is to show the stored lyrics
  too, and the two documents stay as they are. Next: plan that application change,
  then add the lyrics feature page with its scene and pictures.
- Application defects seen while taking the pictures, none fixed here:
  - Player: while music plays, the player bar has no shuffle or repeat button; the S
    and R keys work and show no state. Crossfade is a stored preference with no
    control. The player bar's cover request is answered 403, so it shows a
    placeholder.
  - Playlists: the web application cannot remove a song or change the order. An
    album's "Add to Playlist" adds only the first track. Rows are numbered from 0.
    After a create on the Playlists page, the list stays blank until the page is
    opened again. Collaborators are listed by public id, not name. The share
    dialog's switch thumb overhangs its track when on.
  - Library: the album details panel lists tracks out of order for 5 of the 15
    sample albums. The home page's "Recently Added" row is sorted by title, not
    date. A double-click on an album card fails while the side panel is closed.
  - Destinations: the menu names a browser only as it registers itself, and "Rename
    device" does not change that name. A further window of one account raises a
    "Settings conflict" dialog at sign-in.
  - Administration: the AAC select in the Media settings group is cut off, job rows
    have seven cells in a six-column grid, and the job panel prints durations such as
    `0.208213ms`. The Add Library form offers types whose scanning is not
    implemented.
- Documents and the application disagree:
  - Passkey sign-in: `docs-book/part-1-operator-guide/administration/user-management.md:83,88`
    state it, and the web login form has no control for it. The overview no longer
    mentions passkeys.
  - The homepage says movies move between devices like music. A test shows it
    (`ui/web/tests/e2e/playback-destinations/video-transfer.spec.ts`), and no
    maintained document states it.
  - `app:radio:sync` syncs only countries a user subscribes to, and the web
    application never calls the refresh route.
  - `library-management.md:131-139` presents cover extraction as a step after each
    scan, but ingestion already queues it.
- Capture tooling: sign-in occasionally times out waiting for the login form; a
  second run gets past it. The playlists scene's create branch has not run in its
  final form, because the preview already holds its playlists. The destinations scene expects
  the names "Chrome on Linux" and "Firefox on Linux", and WebKit does not start on
  this host. The preview checks run through `make exec`, which honours `INSTANCE`,
  while the browser follows `--base-url`; nothing ties the two together.
- The homepage's font-arrival stability test at 1440 px failed its own precondition
  (Geist had not arrived) once in eleven local runs.
- The plan still says ten pages (R2, U7); the lyrics page is the difference.
- Smaller copy follow-ups from the claim and writing checks:
  - The overview's Internet radio entry could say that stations come from the
    countries listeners subscribe to (`commands/app-radio-sync.md:3,39`).
  - Two "More in Bånder" links point at pages that state only part of their entry.
    For Internet radio, `/docs/developer-guide/contexts/radio/` states all of it;
    for accounts, `/docs/operator-guide/commands/app-library-member-grant/` states
    who grants library access.
  - The homepage's fourth equalizer slide speaks of device profiles, which its new
    picture does not show; the first slide's picture shows them.

## Compact website navigation and footer

Delivered locally on 2026-11-19 from
`docs/plans/2026-11-19-1833-fix-compact-site-navigation-footer-plan.md`.
Product places its feature links in three audience columns, with descriptions
retained on the overview and other entries. Every Product link fits without
scrolling at 1024×768 and 1440×900; the panel measures 353px high at 1440×900.
The footer aligns the brand, Product and Resources, with attribution and Source
on a separate bottom row. Every existing destination remains available. Phone
layouts stack the sections and retain scrolling for the menu.

Verification on 2026-11-19:

- `make website-build` built 243 manual pages. `corepack yarn typecheck`, PHP
  syntax checks for both templates and `git diff --check` passed.
- `corepack yarn test:browser tests/menu.spec.ts` passed all 12 tests, covering
  desktop fit, footer alignment, shared destinations, keyboard navigation,
  current-page markers, 320px and 390px layouts, short screens and no JavaScript.
- The full site browser suite passed 219 of 221 initially. One test still looked
  for Source inside the footer columns; its locator now follows the bottom-row
  placement, retaining the exact link and destination assertions. The other test
  encountered `net::ERR_NETWORK_CHANGED` on both Geist font requests before it
  could check layout stability. Both tests passed with `--last-failed` afterward.
- In `ghcr.io/baander-app/baander-runner:latest`, a fresh build produced 243
  manual pages, TypeScript and both PHP syntax checks passed, and all 13 menu
  and source-installation browser checks passed. The container used disposable
  build output and the browser dependencies installed by the website CI job.
- Desktop and phone screenshots were inspected in light and dark themes.
- Plan review found no blocking issues. Simplification found no changes to make;
  code review's sole finding was resolved by updating the static website contract
  in `ui/DESIGN.md` to describe compact feature links and the footer layout.

## Website reference pages and fold-out menu

Delivered on `master` on 2026-11-18, fast-forwarded from
`feat/site-reference-pages-and-menu` and pushed, from
`docs/plans/2026-11-18-1647-feat-site-reference-pages-and-menu-plan.md`. The public website publishes four repository documents as reference pages:
`STRATEGY.md` at `/strategy/`, `CONCEPTS.md` at `/concepts/`, `THIRD_PARTY.md` at
`/licensing/` and `deploy/preset/README.md` at `/deploy/`. Each opens with a short
website introduction kept in `ui/site/reference/`, and the deploy page adds a table of
the six example presets' size settings. Links in the manuals and these documents to a
manual page or to one of the four documents stay on the site with checked fragments.
Search results carry their page's label. The top menu has Product and Resources
fold-out panels whose entries each have a one-line description, a Menu disclosure at
phone width, and a footer with the same groups.

The user decided on 2026-11-18 to drop the package inventory page from this work. It
needs a per-package snapshot refreshed with the shipped images, and a rule for Debian
license declarations that are copyright text rather than license names.

Commits, in order: `d6b00cdf4` (plan), `1d2a1946a` (reference pages, link resolution,
search labels), `914bce1fd` (menu), `4de66bee5` (site README, `ui/DESIGN.md`,
`CONCEPTS.md`), `b980d19e3` (simplification) and `ad1c246f1` (the code review's fixes).

Verification on 2026-11-18, at `ad1c246f1` unless stated:

- Site: `make website-build` built 243 manual pages, and the sitemap dates the four
  reference pages from their sources. In `ui/site/`, `corepack yarn typecheck` was
  clean and `node --test scripts/*.test.mjs` passed 35. `corepack yarn test:browser` ran
  at `b980d19e3`, after which only tooling code changed: 218 of 219 passed. The failure
  was a layout-shift check on the operator manual's index table at 1440px. A rerun of
  `stability.spec.ts` three times failed once, on a different check (the equalizer page
  at 390px when the fonts arrive), and both checks passed 12 of 12 when repeated on
  their own. Neither page's layout changed in this work; the cause is not established.
  The menu worker's earlier full run at `914bce1fd` passed 219 of 219.
- Tooling: `phpunit-tooling.xml.dist` on `scripts/tests/php/Website` passed 164 tests
  and 1,520 assertions; `ToolingSourcePolicyTest.php` and `ToolingArchitectureTest.php`
  passed 35 tests and 123 assertions. PHPStan with `phpstan-tooling.neon` on the website
  tooling and its tests reported no errors, `php-cs-fixer` with
  `.php-cs-fixer.tooling.php` found nothing to fix, and
  `scripts/check-tooling-source-policy.php` exited 0. The full tooling suite was not run.
- Review: `ce-code-review` ran a full review with six reviewers (verdict "Ready with
  fixes"). Both findings were confirmed and fixed in `ad1c246f1`. The new test for a
  non-numeric preset size exposed a type error that stopped the build before naming the
  file, and the fix makes the build report the file and the setting.

Published on 2026-11-18: a local build of `c1873a134` (243 manual pages; `corepack yarn
test:browser` passed 219 of 219 on the same content) went to the preview
`baander-74l0fr40r` and then, unchanged, to production as `baander-5p4na31ks`, aliased to
`https://baander.app`. On both, the homepage, `/features/`, the operator guide, the four
reference pages, the sitemap, `robots.txt`, the search and menu scripts, the Pagefind
index and the social card returned 200, and an unknown URL returned 404. The live site
serves HTTPS with HSTS, and its `/concepts/` page is byte-identical to the build.

Republished on 2026-11-18 from `ca22fd7b6`, which stops the desktop menu panels at
30rem so the Product panel shows about six entries and scrolls; it had listed every
feature page down a tall window. `corepack yarn test:browser` passed 220 of 220 on the
build. It went to the preview `baander-2cr9xfg7n` and then, unchanged, to production as
`baander-g3bu8azg4`. On both, the homepage, `/features/` and the reference pages
returned 200 and an unknown URL returned 404; the live `site.css` is byte-identical to
the build.

Open items:

- Resolved on 2026-11-18 in the commit after this record's first version: the
  release build's browser run failed a layout-shift check at 390px on
  `/features/console/`, and repeating it alone failed 4 of 25. The header's actions
  grew from 52px to 112px after first paint: on a slow load the browser painted after
  parsing the Menu disclosure but before the theme toggle, which follows the long
  menu markup. The head now holds the first paint with `<link rel="expect"
  href="#header-end" blocking="render">` until a marker after the header has parsed.
  The same check then passed 25 of 25 and the full browser suite passed 219 of 219.
  The other intermittent failures recorded above are not explained by this.
- The Strategy and Concepts pages publish their source documents as they stand,
  including the competitor table in `STRATEGY.md` and the maintenance note at the top
  of `CONCEPTS.md`.

## Presentation register and homepage hero

Delivered on `master` on 2026-11-16, fast-forwarded from
`feat/prose-fix-presentation-register`: all four units of
`docs/plans/2026-11-16-2100-feat-prose-fix-presentation-register-plan.md`
(`37ec73ce1`, `6cf261d39`, `c8843d801`, `b7f7f3f7d`) and the code review's fixes
(`7fe638cec`). prose-fix has a fourth register, Jobs, built from the 2001 iPod, 2007
iPhone and 2008 MacBook Air introductions. It presents a feature in the opening of a
website template page and applies elsewhere only on request. It can rewrite or draft;
every claim it adds goes into a claim ledger with its source, planned features read as
planned, no competing product is named, and limits move out of the lead but stay on
the page. Its first use rewrote the homepage hero description to "Point Bånder at your
music and movies. Browse and play them in your browser, from a server you run.", and
the homepage search description and the social card carry the new text.

Verification on 2026-11-16:

- Sheet: all 16 quotes match the English caption tracks of the three recordings
  (YouTube `utlT57WK8a8`, `7l-Pa0uCod0`, `i9XU6wk_mWY`), ignoring case and
  punctuation.
- Site: `make website-build` built 243 manual pages. `corepack yarn test:browser` in
  `ui/site/` passed 93 tests. Among them, the 18 stability tests check across several
  pages, the homepage hero included, that text keeps its line heights in the fallback
  faces and in Geist at 1440 px and 390 px. `node --test
  scripts/*.test.mjs` passed 11.
- Tooling: `phpunit-tooling.xml.dist` on `scripts/tests/php/Website` passed 105 tests
  and 1,312 assertions, including the new homepage title and description length
  checks. `composer analyse:tooling` reported no errors and
  `composer check:tooling-format` found nothing to fix. The full tooling suite was not
  run.
- Code review: `ce-code-review` at full depth (forced by the regenerated social-card
  image) with correctness, project-standards, testing and agent-native reviewers;
  verdict "Ready with fixes". Its two confirmed findings, the playback example's
  missing signed-in qualifier and this record, and the agent-native reviewer's three
  warnings (where the ledger goes, framing sentences, where to look for limits) are
  fixed in `7fe638cec` and here.
- Published: a local build of `7fe638cec`, previewed as `baander-cpycalj30` (the
  homepage, a nested manual page, the search index, the sitemap and the social card
  returned 200, an unknown URL 404) and deployed to production as `baander-mbw74s9r5`,
  aliased to `https://baander.app`, which serves the new hero and search description
  with valid HTTPS.

Open:

- The hero's copies in the homepage template, the search description and the social
  card are paraphrases kept in step by hand; no test compares their content.
- The user chose the hero from three candidates. No other page uses the register yet;
  the Bånder Audio Core lead, the `README.md` opening and the docs-book introductions
  can follow on request.

## docs-book rewrite

Delivered on `master` on 2026-11-16, fast-forwarded from `docs/prose-fix-docs-book`
(`20be3f1df` to `0d017641f`): all 243 pages of `docs-book/` went through prose-fix in
the house registers, eight parallel workers each owning one chapter group, and 131
pages changed. Each worker checked every page's claims against the code and fixed the
outdated ones with `path:line` evidence; each group's commit message lists them. The
larger corrections: the removed Swoole task-worker transport, the 24-hour cap on
signed video URLs, health alerts as in-app rows only, public IDs instead of UUIDs on
admin routes and commands, the pooler in the production `.env` template, the six health
components, `debug:hw-transcode` reading the worker's published observation, the
real-time patterns and live families, domain events no code raises, and code examples
that no longer compiled. The pages also say where configuration is not read: the
`SCANNER_*`, `DISCOVERY_*`, `ARGON2ID_*` and `TOKEN_BINDING_*` variables in
`.env.example` reach no code.

Verification on 2026-11-16:

- Every rewritten page was audited against its baseline with `audit_rewrite.php`;
  every relative link and anchor in the 243 pages resolves; no plan identifiers or
  placeholder domains.
- `CommandDocsCoverageTest` passed (4 tests); the commands index lists all 153
  commands once.
- `make website-build` built 243 pages; `corepack yarn test:browser` in `ui/site/`
  passed 93 tests on the build of `0d017641f`, after updating the one test that pins
  the `app:user:enable` page's opening sentence; `node --test scripts/*.test.mjs`
  passed 11.
- Code review: `ce-code-review` at full depth with correctness, adversarial
  (in-process; cross-model is off in this checkout), project-standards and testing
  reviewers; verdict "Ready with fixes". Its one confirmed finding, a promised
  `APP_SECRET` length check that does not exist, and two residual risks on the
  database credential steps are fixed in `0d017641f`. Reviewers sampled the changed
  claims; the transcoding slot arithmetic and some test counts were not re-derived.
- Published: a local build of `0d017641f`, previewed as `baander-ljsgl7olj` and
  deployed to production as `baander-cqu4pnh8z`, aliased to `https://baander.app`.

Open (found during the rewrite; code and configuration, not docs). The party,
configuration, worker environment, console and stale-text items were fixed on
2026-11-17; see [docs-book rewrite follow-ups](#docs-book-rewrite-follow-ups). Still
open:

- Four Prometheus gauges are never emitted; `app:album:duplicates` takes a library
  UUID that no command prints.
- Domain events mapped to notification categories (`AlbumCreated`,
  `TranscodeJobCompleted`, `TranscodeJobFailed`) are never raised.
- Policy questions the workers left open: the playback snapshot exposes UUID
  primary keys although public payloads use public IDs; the Electron tests run on Node
  24 in CI while pages say 26; the database rotation step that reruns
  `pooler-credentials` is derived from the script and has not been drilled.

## docs-book rewrite follow-ups

Delivered on `master` on 2026-11-17, fast-forwarded from `fix/docs-rewrite-follow-ups`
(`9b72c2185` to this record) from the plan
`docs/plans/2026-11-17-0817-fix-docs-rewrite-follow-ups-plan.md`. It fixes the code and
configuration items the docs-book rewrite left open:

- Watch parties are invite-only, as the user decided on 2026-11-17. The session list,
  a session's details and its member list answer only the party's host and members;
  anyone else gets 404, and a caller who is not signed in gets 401. Joining with the
  public ID stayed open in this delivery; the party invitations work below replaced
  that later the same day, and joining now needs an invitation. The unauthenticated `GET /ws/party/{sessionPublicId}/` route is
  deleted without a replacement, also by the user's decision (`45f76d17d`).
- The host's play, pause and seek reach every member as `party.playback_state`; a
  leaving host hands the session's host role to the promoted member (`7cb1cfc6a`). A
  member who drops can rejoin a full party (`02ef0f5ea`).
- `.env` and `.env.example` lose 32 variables nothing reads, plus `APP_NAME`,
  `MUSICBRAINZ_APP_NAME` and `MAIL_FROM_NAME`. Compose no longer publishes UDP 41234.
  `VIDEO_ENCODER` passes all three worker environment allowlists, so the transcoding
  guide's encoder setting reaches the media worker (`af93988b4`).
- Comments, `.agents/rules/admin-cli-parity.md`, the `src/*/README.md` files,
  `dev-docs/`, and the command and OpenAPI descriptions say what the code does; the
  dead `^/api/docs` firewall rule is gone. `app:monitor:prune --days` and
  `POST /api/monitor/prune` refuse a value that is not a whole number, and
  `app:watch-files` without `--path` watches the current directory (`9412174c7`). The
  `docs/solutions` entries that mention `swoole_task` stay as history.
- From the code review (`61588c430`): a paused party syncs members to where the host
  paused, not to the last play or seek anchor, and a second play while playing no
  longer rewinds the party. Both date from the initial commit and became visible once
  the broadcast pushed them to every member. A prune accepts at most 36500 days,
  because a much larger value overflowed the cutoff into the future and deleted every
  record. A functional test pins that an ended party leaves its host's and members'
  lists.

Verification on 2026-11-17:

- Unit: on the final tree, `tests/Unit/Party` with `SwooleLivePartyRoomsTest` and
  `WebSocketControllerTest` pass (256 tests), and the prune use case, command and API
  tests pass; each new case was red before its fix. A wider run of 508 tests across Party, the
  Swoole rooms, the worker environment, the Shared console commands, Filesystem and
  Docs had one error: `FileWatcherInitializationTest` calls `posix_getrlimit()`, which
  the host PHP lacks; the test predates the branch.
- Tooling: `DeploymentRuntimeEnvironmentTest` (42), `PresetDeploymentTest` (29) and
  `DevelopmentRuntimeTest` (13) pass; the tooling formatter is clean.
- Functional and integration on the final tree: `PartySessionAccessTest` (8),
  `JobMonitorControllerTest` (9), and `PartyLiveRoomWiringTest`,
  `PartyOwnershipPersistenceTest` and `ReferentialIntegrityTest` (23 together) pass.
  `ProductNameConfigurationTest` passed on `af93988b4`, the commit that changed `.env`.
- Static: PHPStan reports no errors on the final tree; Deptrac reports 0 violations,
  warnings and errors (443 skipped by its baseline). The OpenAPI export check matches,
  and the web client's `generate:check` and typecheck pass.
- Site: `make website-build` built 243 pages; every relative link and anchor resolves;
  `corepack yarn test:browser` in `ui/site/` passed 93 tests and
  `node --test scripts/*.test.mjs` passed 11.
- Published: a local build of `31d1f8a81`, previewed as `baander-m189h9zq0` and
  deployed to production as `baander-h0mkq4o71`, aliased to `https://baander.app`; the
  home page and the watch-parties, `app:monitor:prune`, real-time patterns and
  environment variables pages answer 200 with the new text.
- Code review: `ce-code-review` at full depth with correctness, security, adversarial
  (in-process; cross-model review is off in this checkout), API contract, testing and
  project-standards reviewers; verdict "Ready with fixes", with no P0 or P1. Its one
  confirmed finding, the untested active-only filter on the session list, is fixed
  with the functional test above. The validator rejected the prune overflow as
  pre-existing; it is fixed anyway under the rule for defects beside touched code,
  together with the two playback defects the correctness reviewer reported.

Open (residual risks from the review; not fixed). The concurrent-leave race listed
here on 2026-11-17 is fixed by the party invitations work below.

- The transcode steer runs before the broadcast; if it throws, the party state is saved
  but the room receives no `party.playback_state`.
- `PlaybackStateChanged` is a domain event, so every host play, pause and seek also
  writes an outbox row that no durable consumer reads.
- `HW_BITRATE_MULTIPLIER` reaches `SegmentEncoder` and the hardware diagnostics, but
  `SegmentEncoder::applyBitrateMultiplier()` has no caller, so encodes never apply it.

## Party invitations

Delivered on `master` on 2026-11-17, fast-forwarded from `feat/party-invitations`
(`3a666d381` to this record) from the plan
`docs/plans/2026-11-17-1229-feat-party-invitations-plan.md`. The user decided on
2026-11-17 that the host invites specific users, over a secret invite link.

- Invitations (`e2a7dd773`, `fbe662ed4`). `party_invitations` holds one row per
  invited user and party. The host invites with
  `PUT /api/party/sessions/{publicId}/invitations/{userPublicId}`, revokes with
  `DELETE` on the same path and lists with `GET .../invitations/`. A revoke also
  removes a user who had joined and takes their sockets out of the party's room. A
  party's creator holds an invitation from creation. An invitation lasts until it is
  revoked or its party or user is deleted.
- Joining (`0928065ce`). `POST .../join` and `party.join` admit only a member or an
  invited user. Anyone else gets the answer a party that does not exist gets, whether
  the party is active, ended or full. A member or invited user gets 409 from an ended
  party, and an invited user gets 409 from a full one; before, both answered 500.
- Members only (`50961a18e`). Leave, sync, end, transfer-host, the invitation routes,
  `party.leave`, `party.playback` and `party.sync` answer a signed-in user without a
  member row as a missing party and change nothing. `party.playback` checks its
  payload before it looks the party up, so malformed input no longer reveals a party.
- Serialisation (`fa0c7e6c5`). Join, invite, revoke, leave and transfer-host run in
  one transaction each, starting with a `FOR UPDATE` read of the `party_sessions` row
  that refreshes an already loaded session; the member finders refresh loaded rows
  too. Party events are dispatched after the commit. This fixes the race in which two
  members leaving at once handed the host role to a leaver. It also fixes three
  defects beside the join path: a transfer to oneself demoted the host while the
  session still named them, a transfer to a non-member answered 500, and ending a
  party as a non-host answered 200 without doing anything.
- The OpenAPI document and the generated web client carry the three routes and the
  changed answers. The success bodies of leave, sync, end and transfer-host were
  documented at the top level while the routes return them under `data`; the schemas
  now match.
- Documentation (`9f6fb8c9e`): the operator guide, the developer guide's Party and
  real-time pages, `src/Party/README.md`, `dev-docs/context-map.md` and `CONCEPTS.md`.
- Simplification (`0326c6acb`): four functional tests share `PartyFixtures`.

Not built, by the plan: a library-access check on invite or join, a way for an invited
user to see or decline invitations, a notice to a revoked socket, a cap on invitations
per party, invitation events and a console command.

Verification on 2026-11-17:

- Full backend suite at `0326c6acb` in the local CI image: 13,247 tests in 1,193
  files. The ordinary lane (8,487 tests) and the first stack lane (1,622) passed. The
  native lane (948) had one failure and the second stack lane (2,190) one error and
  one failure, all three outside the change, which touches no transcode or HTTP
  streaming code:
  - `StreamedResponseProcessorTest` ("The probe server did not start") and
    `AudioRenditionWorkerHandoffTest` passed when rerun on their own; the suite ran
    while other container runs loaded the host.
  - `AudioTranscodeStreamTest::testEachSupportedFormatProducesItsCodec` fails the same
    way on `master` at `0660fb943` with the same image: the `format=aac` request
    answers 500. The cause is not investigated.
- Party tests at `50961a18e`: 648 unit tests across `tests/Unit/Party`,
  `tests/Unit/Shared/Interface`, the security tests and `CaptureCoverageTest` pass, and
  196 database-backed tests in 13 files pass, among them `PartySessionAccessTest`,
  `PartyHostActionsTest`, `PartyInvitationTest`, `PartyLiveRoomWiringTest`,
  `PartyMembershipSerializationTest`, `WebSocketIdentityLookupTest`,
  `PartyOwnershipPersistenceTest`, `ReferentialIntegrityTest`,
  `InitialSchemaMigrationTest` and `OpenApiSpecTest`. Each unit was also verified at
  its own commit from a separate worktree. Every cell of the plan's answers-by-caller
  table has a test, and each non-member answer is compared with the missing-party
  answer in status, body and headers. The leave, transfer, end, join and non-member
  cases were seen failing before their fix. The invitation routes' functional tests
  passed on their first run and were then proven by mutating the code: five mutations
  (no lock, user resolved before the host check, `MemberLeft` always dispatched, no
  member check, no host check) failed twelve tests. `PartyHostActionsTest` pins
  behaviour that was already built, so it has no red of its own.
- After the simplification the four functional party files hold the same 98 tests and
  2,535 assertions as before it.
- Static at `0326c6acb`: PHPStan reports no errors on 3,807 files. Deptrac reports 0
  violations, warnings and errors; 452 are skipped by its baseline, 9 more than
  before, all further references from `WebSocketController` to `PlaybackAction`, an
  edge the baseline already held. The OpenAPI export check matches and the web
  client's `generate:check` passes at `50961a18e`; no route changed after it.
- Site at `9f6fb8c9e`: `make website-build` built 243 pages; every relative link and
  anchor resolves; `corepack yarn test:browser` in `ui/site/` passed 93 tests and
  `node --test scripts/*.test.mjs` passed 11. A first browser run, made while
  container runs loaded the host, failed one homepage font-stability test that passes
  on its own and in the full rerun. The web client's typecheck passes.
- Published: a local build of `7a3460fef` (243 pages, every link resolving, 93 browser
  tests and 11 script tests passing), previewed as `baander-f8ucvjp1y` and deployed to
  production as `baander-ht5ywd4ja`, aliased to `https://baander.app`; the home page
  and the watch-parties and Party context pages answer 200, and the watch-parties page
  carries the Invitations section.
- Code review: `ce-code-review` at full depth with correctness, security, adversarial
  (in-process; cross-model review is off in this checkout), reliability, API contract,
  data migration, testing and project-standards reviewers. No findings; verdict "Ready
  to merge". Three of the reviewers (data migration, API contract, reliability)
  returned within 30 seconds, so their coverage is thin. The review ran no gate itself.

Open:

- An uninvited join takes the party's row lock and runs two more queries before it is
  refused, while an unknown ID is refused before dispatch. The answers match; the
  response times differ, and a holder of the ID can briefly queue on the lock.
- A worker that dies between a revoke's or a leave's commit and its event leaves the
  user's sockets on other workers in the room until they close. The member row is
  gone either way, so the user cannot re-enter.
- When the room listener fails after the commit, the caller gets an error although
  the change is stored; the listener closes the whole room first.
- Ending a party reads the host without the row lock, so a host demoted during the
  same request can still end it.
- Deleting the user who hosts a party deletes the party by cascade without
  `PartySessionEnded` and without closing its room. The fix belongs to the user
  deletion path in Auth and needs a decision on whether the party ends or passes to
  another member.
- Nothing prunes the rows of ended parties, and no retention rule says when they
  should go.
- An invite whose user is deleted between the lookup and the insert fails on the
  foreign key and answers 500.
- `handlePartyJoin` treats any `NotFoundException` from the join handler as a hidden
  party, not only `PartyNotFoundException`: Deptrac forbids the WebSocket controller
  that Party class. The join handler throws no other not-found outcome today.
- The plan lists as a risk that a long-lived WebSocket worker can hold party entities
  from an earlier message. The review traced that each socket message runs in its own
  coroutine whose entity manager is cleared on release, so the exposure is one
  message, not the worker's lifetime. That trace was read from the code, not run.
- `AudioTranscodeStreamTest::testEachSupportedFormatProducesItsCodec` fails on
  `master` in the local CI image (see the verification above).

## Notification pane well

Delivered on `master` on 2026-11-17, fast-forwarded from `feat/notification-pane-well`
(`182ea0948` to this record) from the plan
`docs/plans/2026-11-17-1705-feat-notification-pane-well-plan.md`. The user chose the
fast-forward on 2026-11-17; nothing is pushed.

- The handoff (`7f357281a`, `02292d5f7`). While the sidebar notification pane is open
  it holds the well: shading toward its upper edge, an inset upper edge and a lower
  lip over its `--color-popover` face. For that time the footer player's central
  display is flat on `--color-muted` with no edge or lip, and it takes the well back
  when the pane closes. The pane fades in and out and the display's shading fades out
  and back over the same 120ms `ease-out`, from one constant; with reduced motion
  requested both are immediate.
- The shared definition (`5aabfc463`, `b66b5acaa`). `ui/web/src/shared/theme/well.ts`
  holds the well's measures, its shading and the handoff timing. A number registered
  with `@property`, `--well-presence`, sits inside each shading colour's own
  expression: 1 computes to the declarations the footer had, 0 to a flat face. It does
  not inherit, so a fade restyles nothing inside the display.
- Pane text (`7f357281a`). Muted text in the pane uses `--well-secondary-text`. With
  `--color-muted-foreground` the header label measured 4.49:1 over the warm mood's
  shade.
- Escape (`7f357281a`). The pane stays mounted for its 120ms exit, so it stops its own
  Escape from reaching the sidebar's Escape handler, which would move focus to the
  navigation toggle. Recorded before and after the change at 390px with the navigation
  collapsed and expanded: the pane closes, focus returns to the bell and the navigation
  keeps its state.
- Documents (`c1634ae13`, `caaa7f0f4`). `ui/DESIGN.md` states the one-well rule, the
  notification pane and the handoff's Motion row; `CONCEPTS.md` defines the well; the
  browser-testing guide names the handoff spec. The plan states the mechanism that
  shipped, and its risk about the exit animation under jsdom is corrected
  (`adf70a575`): the notification suite passes with the animation written in longhand
  too. What the footer's fade taught about gradients and resting pixels is in
  `docs/solutions/best-practices/fade-a-gradient-surface-through-a-number-inside-its-own-colour-expressions.md`
  (`6ecc80373`).
- Footer material spec (`c249e5d4e`). The listening session answers 422 to the styling
  suite's catalog fixture song, so the spec could not put a track in the footer. It
  now plays a server fixture's song through `tests/e2e/styling/footer-playback.ts`.
  Its lip-symmetry check also skips a footer border that covers two pixel rows; with
  only the fixture change the 768px and 1280px cases failed that check by 6px against
  the unchanged source.

Four calls were shown to the user before planning and made without an answer: the pane
also fades out over 120ms; the display keeps its flat face while it yields; the admin
bell is out of scope, because the admin shell has no footer player; and `ui/DESIGN.md`
changes from "the footer well never animates and is the only surface with depth" to
"one surface holds the well at a time".

The plan first put the footer's shading on a layer faded by opacity. Measured on the
preview, the layer changed the footer at rest: the display ends between pixels, two
stacked boxes anti-alias differently from one, and the lip came out weaker (243 to 239
over a 235 card in the balanced mood). Registering the colours, or mixing each finished
colour with its flat value, left one-level differences in the warm mood's gradient.

Verification on 2026-11-17 at `caaa7f0f4`:

- `yarn --cwd ui/web typecheck` and `yarn --cwd ui/web build` pass. `yarn --cwd ui/web
  lint` reports 0 errors and 10 warnings, none in a changed file.
- Vitest: 3,624 tests in 290 files pass, including the player's render-count tests.
- Chromium on the worktree preview: `tests/e2e/styling/well-handoff.spec.ts` 10 of 10
  and `tests/e2e/styling/footer-material.spec.ts` 4 of 4. The handoff spec reads
  painted pixels at 390px and 1280px in the four moods, label contrast in the pane and
  on the flat display, the reported durations and easing, reduced motion including the
  close, and Escape.
- Footer at rest, original source against the shipped code, at 1280px with a track
  paused and the four moods switched through in turn. The whole footer image is
  byte-identical in dark, cool and balanced in every capture. In warm the captures
  vary: of five, one matched the original and four differed from it by one level in
  one channel on 2,476 to 3,536 of 117,760 pixels. With the warm mood set without the
  animated switch, three captures each differed on 11 pixels by one level, as did one
  capture after a forced repaint. Two captures of the original source do not differ
  from each other. The plan's samples
  (four columns through the display, from two rows above it to two below the lip,
  1,296 in the four moods) were identical in the capture at `5aabfc463` and differed
  on 33 in the capture at `caaa7f0f4`. The registration's `inherits` value makes no
  difference to this.
- Guard checks: with the yield's styling disabled the handoff test fails (the face
  spans 7 levels in the dark mood); with the header label on `--color-muted-foreground`
  the contrast test fails in the warm mood; with the pane's Escape handler removed
  both 390px Escape tests fail, because focus does not return to the bell.
- Production-style injection: with styled-components made to insert rules through the
  CSSOM, as a production build does, the display's fade still ran on `--well-presence`
  in three runs on the preview. A production build was not served.
- Code review, run `20261117-182724-92ce9f41`: one finding, applied in `77a2dc40a`.
  The contrast test located a timestamp by its "ago" text while its fixture
  notification had a fixed date, so it would have failed once that date was 30 days
  old.

Open:

- The warm mood's one-level differences at rest are unexplained. The computed
  declarations are the same as before. Most of the differences follow an animated mood
  switch and vary from run to run, which points at how the browser rasterises partial
  repaints; 11 pixels differ without one. The plan made a changed rest state a reason
  to stop and ask, and the user accepted this difference on 2026-11-17.
- No CI job runs `tests/e2e/styling`, and its specs now all need the preview's fixture
  executor. The other open items of this delivery were closed on 2026-11-18 by
  "Styling specs and sign-in settings conflict" below: the sidebar notification spec's
  dialog was a defect in the app, the playback cases play again, the admin bell's
  fallback has a browser check, and Firefox passes the handoff spec.

## Styling specs and sign-in settings conflict

Delivered on `master` on 2026-11-18, rebased onto the website feature pages and
fast-forwarded from `fix/styling-specs-and-login-settings-conflict` (`ecf82e931` to this
record) from the plan
`docs/plans/2026-11-18-0004-fix-styling-specs-and-login-settings-conflict-plan.md`, and
pushed. The user asked on 2026-11-17 to repair the playback specs and close the test
gaps the notification pane well left, and decided on 2026-11-18 to fix the volume path
in this branch, to run the sidebar notification spec as a fixture user, and to merge and
push. Commit IDs below are from before the rebase; the subjects are unchanged.

- Sign-in conflict (`a01a62d12`). A fresh page knows no preference version. When the
  listening session's track or volume arrived before a preference family's first read,
  the change counted as an edit at version 0, and the read, or the server refusing the
  version-0 write with 409, raised "Settings conflict" although no other device had
  changed anything. The preference sync now reports when a family's first read has
  finished. Until then, the playing track is selected and the context panel opened
  without switching to the Details tab, and a change of only the volume or mute is not
  saved; the read then applies the saved settings. Every volume change reaches the store
  as the session reports it, so a volume change the user makes in that moment is not
  saved either. The conflict rules themselves are unchanged.
- Panel reopening (`a01a62d12`). The playing track's selection followed the track
  object, and a session's track arrives as a placeholder that is replaced when its
  details load, so the second object reopened a panel the user had just closed. It now
  follows the track's ID.
- Styling fixtures (`703cfc27e`). The styling specs sign in as a fresh fixture run's
  admin, a super admin through the fixture's new `superAdmin` option, and the populated
  catalog keeps its long and non-Latin titles on three 30-second fixture songs, whose
  IDs the fixture's `catalog` receipt now returns. `sidebar-notifications.spec.ts`
  signs in as a fresh fixture user.
- Spec drift (`446ae8d01`). Three checks in `browsing-playback.spec.ts` described the
  interface before later changes: the volume slider shows a dragged value once the
  session reports it, a song title's parent no longer holds the playing indicator, and
  the sidebar is a complementary landmark too. No expected value changed.
- Admin bell (`60c35b152`). A browser check shows the admin bell's outage entry keeps
  the muted text colour. `interruptDelivery` imported the live transport by its bare
  path, which loads a second copy on a preview whose dev server re-versioned the module,
  so no outage began; it now imports the URL the page loaded.

The plan first deferred the playing track's selection until the layout read had
finished. When the read came late, the selection opened the panel after the user had
closed it, and the notification pane closed with it at 390px; the tab-keeping rule
replaced it.

Verification on 2026-11-18 on the worktree preview, Chromium, without retries:

- `tests/e2e/settings/sign-in-conflict.spec.ts`: the layout and volume cases failed on
  the unchanged source with the dialog visible and pass now; the album-page reload case
  passes either way.
- `browsing-playback.spec.ts` 20 of 20 in two consecutive runs, up from 8 or 9 of 20
  on 2026-11-17.
- `sidebar-notifications.spec.ts` 2 of 2 in three runs.
- `layout.spec.ts`, `forms-admin.spec.ts`, `visualizer-calibration.spec.ts` and
  `characterization/visual-baseline.spec.ts`, two runs before and after the fixture
  move: 26 of 33 passed both runs before, 31 of 33 after. The two that fail in all four
  runs are the `visualizer-calibration.spec.ts` tests.
- `well-handoff.spec.ts` 11 of 11 in Chromium and in Firefox. With the outage entry's
  fallback removed, the admin bell check fails.
- Final run of the sign-in, sidebar notification and whole `tests/e2e/styling` set at
  `bfb074d2c`: 91 of 105 pass. The 14 failures are the two
  `visualizer-calibration.spec.ts` tests, and 4 `controls.spec.ts` and 8
  `appearance-matrix.spec.ts` cases, both specs signing in as the preview's admin
  account. On the code this branch started from (`8b1a251fa`), the same two specs fail
  31 of 50 on the same preview, against 12 now.
- Code review, run `20261118-015022-149e89e7`: one finding, applied in `bfb074d2c`: a
  first read cancelled before it answered counted as finished. The simplification pass
  merged four copies of the fixture-run wrapper into `withFixtureRun`.
- `yarn --cwd ui/web test`: 3,641 tests pass. The preference conflict suites
  (`preference-conflict-lifecycle.test.ts`, `preferences-consumers.test.tsx`) are
  unchanged.
- `tests/Integration/LiveUpdates/CapacityFixtureTest.php` passes in a CI image built
  locally with `php scripts/build-application.php --buildx --target ci --tag
  baander-ci:local`.

Open:

- On a narrow screen, a playing track that arrives after sign-in opens the context
  panel as an overlay, which takes focus and closes an open popover such as the
  notification pane. This predates the work; the user chose to record it as a product
  question.
- After a volume drag, the footer slider shows the previous value until the listening
  session reports the new one. The spec now waits for the value; how long the snap-back
  shows was not measured.
- Both `visualizer-calibration.spec.ts` tests fail before and after this work; their
  cause was not investigated.
- `tests/e2e/live-updates/recovery.spec.ts` was not usable as a check on this preview:
  its managed-restart case restarted the preview, whose release preparation then
  failed, and every later test failed against it until `make preview-up`. Its delivery
  outage case did start an outage through the repaired `interruptDelivery`.
- `controls.spec.ts` "admin library tabs" and parts of `appearance-matrix.spec.ts` fail
  on the preview's admin account before and after this work; they were not attributed.
- If a preference family's first read never runs in a session, for example while live
  updates never become ready, the playing track keeps the panel's tab and volume
  changes are not saved for that session.
- WebKit could not launch on this host: Playwright reports missing `libicu74`,
  `libxml2` and `libflite1`. The handoff spec did not run in WebKit, and no container
  route exists for preview-backed specs.

## Cohesive operator manual

Delivered, 2026-10-30: the [operator manual](docs-book/part-1-operator-guide/README.md)
now connects component responsibilities, storage, setup, daily tasks, monitoring
and recovery.

Qualification limits remain explicit: its verification did not deploy a new
instance, scan user media, rotate secrets, replace workers or restore data. The
current worker credentials contract cannot provision private Discogs tokens for
queued enrichment; documentation now identifies that runtime gap. Coordinated
application restore, actual registry destination/RPO/RTO and whole-host capacity
remain unqualified. The revised source-build procedure is source-verified, without
a new bootstrap run.

## Local development workflow

The implementation plan
defines a persistent development instance at `baander.test` that updates
automatically when master advances, with isolated worktree previews for feature
verification. Make remains the entry point. Libraries, accounts, settings and
source media survive ordinary updates; incompatible database changes stop without
an automatic reset.

The controller, command workflow and integrated runtime acceptance are complete
on the supported Linux host; all nine acceptance examples passed. Evidence is
retained under
`~/.local/state/baander/qualification/delivery-20261026-05/workspace`, including
`final-runtime-evidence.json`, the backend results and each reviewed continuation.
The pinned history under [Completed work and evidence](#completed-work-and-evidence)
retains the checkpoint narratives and suite counts.

The final runtime receipt preserves the interrupted run's strict cleanup failure
rather than claiming an uninterrupted pass. Seven older anonymous volumes remain
untouched; six could not be attributed to a deleted container.

Open: in a linked worktree the PHP tooling suite fails up to three
`ApplicationInventoryTest` cases (the two `testInterruptedCollection…` cases always,
`testShellRecoversUncertainCreationWhenCollectorIsInterrupted` sometimes) because a
marker does not appear within its window. `RepositoryConfiguration::local` reads the
primary checkout's `dev.local.json`, which slows configuration loading there. All
three pass in a standalone clone and with `CI=1`.

Open: through `scripts/check-backend-container.php` the tooling suite fails four
`DevelopmentBrowserCleanupTest` cases. `StaticCheck::runCheck` runs the command with
`docker exec` under umask 0000, the test creates its `artifacts` directory with
`mkdir()` and no mode, and `PrivateDirectory::ensure` refuses the world-writable
result. The class passes under umask 022, which CI's `docker run` path uses. Set the
umask in `StaticCheck::runCheck` or create the test directory with mode 0700.

Open: automatic updates run the controller tooling installed by the last
`make dev-up`, which can be older than the images they deploy. On 2026-11-13 the
persistent instance's update to `60fc26b63` (the capability and size merge) started
the new application container, then failed while resolving the worker profile: the
installed tooling, from 2026-11-09, passed the settings to `DeploymentProfile` in the
constructor's old argument order, and the image rejected them ("Argument #2
($webWorkers) must be of type int, null given"). No database change had started.
The instance stayed blocked, refusing every later update, until `make dev-recover`
and `make dev-up` ran from a current checkout. A contract change between the tooling
and the image needs either a controller generation that updates with `master` or a
versioned profile request the image can refuse with a reason.

## Playback investigation and evidence limits

**Movie departure:** Back closed the player, but three original producers retained
live ownership and renewed leases at a fixed cursor across 31 database samples
spanning 30.111 seconds. Native snapshots before and after that window showed the same three
stopped encoders. The next movie began admission, but the browser test exited
before its startup outcome, cancellation of the first movie or recovery could be
captured. Both diagnostic attempts remain nonpassing; both cleaned up successfully.

The detailed investigation remains in [Git history](#completed-work-and-evidence),
including the observer-timeout hypothesis and missing browser error evidence.
Receipts are under `var/test-runs/movie-departure-investigation`. Any later design
must resolve departure ownership, in-flight startup, rapid reopening and shared
sessions. Unconditional cancellation from React effect cleanup is not prescribed.

**Frame capture:** the historical native timestamp of 24.64 seconds versus a
24.60-second callback remains unexplained. Failure diagnostics are delivered and
the mismatch did not recur in the prescribed runs; these results do not prove its
cause or universal capture stability. The
[sparse playback guide](docs-book/part-2-developer-guide/testing/media-and-playback.md#sparse-movie-playback)
retains the contract and evidence. No tolerance change or further implementation
is selected by this historical failure.

**Qualification boundaries:** bounded adaptation and endpoint proofs do not
establish uninterrupted full-source playback or universal format compatibility.
Keep these limits separate from selected work:

- Safari/native HLS, DASH browser playback and subtitles, full live FFmpeg DASH
  EOF, formal CMAF conformance, interactive audio switching, native caption-menu
  interaction and caption pixels remain unqualified. The
  [testing guide](docs-book/part-2-developer-guide/testing/media-and-playback.md) distinguishes tested
  HLS/Chromium/Firefox workflows from these paths. The separate native pressure
  observer still expects the old HLS muxer output and is not qualified for the
  current demuxed output.
- General variable-rate gaps, timestamp discontinuities, additional origins,
  arbitrary containers, hardware and untested browser/format combinations remain
  outside the measured cases. A [Selected video endpoint](docs-book/part-2-developer-guide/testing/media-and-playback.md#selected-video-endpoint-playback)
  is qualified only for its calibrated metadata paths; missing or stale metadata
  still uses an estimate. Existing checks do not establish general cache or
  performance qualification.
- Software colour checks qualify BT.2020 SDR handling and PQ/HLG tone mapping;
  they do not establish general HDR browser acceptance, complete reference-vector
  coverage, display-gamut certification or Dolby Vision support. See the
  [testing guide](docs-book/part-2-developer-guide/testing/media-and-playback.md) for the measured cases.
- Native codec acceptance remains conditional on the selected release path.
  [AAC reference coverage](packages/baander-audio-core/docs/README.md) does not
  exercise the 34-band and phase decoder paths; listening tests and the HE-AAC
  IPC/bridge suites were not run in that checkpoint. The original 5% HE-AAC CBR
  target was not established by the delivered 6% acceptance gate. These limits
  do not select unused codec work or reopen intentional encoder design decisions.
- Other existing limits belong in the [scan guide](docs-book/part-1-operator-guide/commands/app-library-scan.md),
  [Transcode context](docs-book/part-2-developer-guide/contexts/transcode.md) and
  [messaging guide](docs-book/part-2-developer-guide/development/cqrs-and-messaging.md).
  Delivered directory publication does not complete the broader event-producer
  transaction-boundary audit. None of these boundaries selects a new task here.

## Registry release acceptance

The [Registry guide](relay/README.md) documents implemented contracts, deployment,
encrypted backup and recovery, packaged dependency inventory, and existing
qualification runners. Its [release acceptance](relay/README.md#release-acceptance)
section retains the full whole-host budgets and workload thresholds.

- **Constrained deployment:** qualify the agreed five-host optimized profile,
  including snapshots, compaction, catch-up and backup upload. Each whole host has
  1 CPU and 512 MiB; API-container limits and short local load smoke do not establish
  whole-host acceptance. Supply target hosts or an explicitly agreed equivalent
  measurement environment before selecting this work. Sanitizer workers may be larger.
- **Performance baseline:** select a stable runner and accepted baseline, then add
  comparison enforcing confirmed regressions above 10%. Existing bounded smoke
  measurements do not establish that baseline.
- **Long soak:** reserve a 24-hour workload window on the intended deployment
  profile. After warm-up, compare equivalent quiescent windows; fail sustained RSS
  growth over 1 MiB/hour or 10% of baseline.
- **Destination recovery:** qualify encrypted, versioned five-minute S3-compatible
  backups at the actual destination and rehearse isolated restoration with a
  five-minute RPO and 30-minute RTO. Disposable TLS S3 upload and offline restore
  are covered; actual-destination access is unavailable.
- **Regional deployment:** obtain access and inventory for `de`, `ca`, `sg`, `au`
  and `fi` (unavailable, user confirmed 2026-10-04). Validate the actual five-voter
  topology, rolling upgrades preserving volumes and bootstrap guards, no
  acknowledged-data loss after two-voter failure, and leader recovery within
  30 seconds. Region names alone do not supply deployment access.

The nightly workflow is on the default branch and locally verified. Hosted
scheduler activation and a scheduled run are not verified by the evidence here.
Use the documented
[nightly procedure](relay/README.md#nightly-qualification-and-retained-diagnostics)
when checking that deployment decision.

## Application release acceptance

- **Recovery:** rehearse application backup restoration before migration rollout.
  Follow the [upgrade guide](docs-book/part-1-operator-guide/maintenance/upgrading.md); this
  prerequisite does not reopen covered fresh-install or migration behavior.
- **Redistribution:** resolve the application's observed notice, provenance and
  embedded-dependency gaps before qualifying a release image. The
  [application review record](third_party/inventory/application-review.md) retains
  the inventory evidence and verification checkpoint; follow the
  [collection procedure](dev-docs/application-packaged-inventory.md) for each new
  artifact. Preserve the boundaries in [THIRD_PARTY.md](THIRD_PARTY.md).
  The separate Registry inventory is outside this remainder.
- **Selected native media path:** when a release explicitly includes an active
  codec/DSP path, resolve its remaining reproducibility and reference-vector
  acceptance before claiming certification. Use the
  [DSP qualification guide](packages/dsp/README.md) to identify the current evidence
  and limits. Browser graph checks alone do not certify native codec behavior;
  an unwired module's incomplete qualification does not create a release task.
- **First production release:** record acceptance evidence before cutting it.
  There are no production deployments. Remove the temporary pre-release policy in
  [AGENTS.md](AGENTS.md) only when the user explicitly announces the first release;
  a tag, build or deployment preparation does not make that announcement.

## Deferred scope

React Native work, including the Android and Android TV targets in `ui/rn`,
remains deferred at the user's request (2026-10-05). The separate `ui/android`
application was removed at the user's request on 2026-10-26. Resume feature, API
and tooling reconciliation for `ui/rn` only when the user resumes that work.
Historical mobile failures do not select a task or a new CI lane.

## Completed work and evidence

The roadmap before the 2026-11-03 cleanup is preserved at commit `5b74a1f44`.
Read it with `git show 5b74a1f44:ROADMAP.md` for the delivered checkpoints that
cleanup removed, including the fixed product name, local development workflow,
outage status reconciliation and AAC codec checkpoints.

The complete pre-cleanup roadmap is preserved at
`f31c3ef3ff10fc9f7fe9b7c472bf1c9e4a3ad2e0`. Read it with
`git show f31c3ef3ff10fc9f7fe9b7c472bf1c9e4a3ad2e0:ROADMAP.md` for historical
suite counts, failed attempts, source revisions and receipt paths. Completed
checkpoints are omitted here; their earlier failed stages do not reopen work
closed by later evidence.

Implemented plans remain recoverable at
`ef388275de15f5d63af5a7bb1a056ccf20183841`. List them with
`git ls-tree --name-only ef388275de15f5d63af5a7bb1a056ccf20183841 docs/plans/`,
then read one with `git show ef388275de15f5d63af5a7bb1a056ccf20183841:<path>`.
The six plans retained by that cleanup contain unfinished or partly superseded commitments;
their retention does not resume deferred work or validate historical assumptions.

The roadmap and plans before the 2026-11-07 cleanup are preserved at
`b9a67726f`. Read the delivered checkpoints of forge-neutral CI, the system overview
pages, interactive diagrams, configurable media capacity, bAAC and the website
typography work with `git show b9a67726f:ROADMAP.md`, and the plans retired then with
`git show b9a67726f:docs/plans/<file>`.

The roadmap before the 2026-11-15 cleanup is preserved at `d4a938b74`. Read the
delivered checkpoints of the capability and size settings, the development controller
image identities, movie playback on growing presentations, the browser runner release,
bAAC exact arithmetic and lossless mode, the master CI repair, the playback session
entities, the Bånder Audio Core rename, scan artist credits, MusicBrainz matching and
the web fixes of 2026-11-14 with `git show d4a938b74:ROADMAP.md`. The eight plans
retired with that cleanup are preserved there too: list them with
`git ls-tree --name-only d4a938b74 docs/plans/`, then read one with
`git show d4a938b74:docs/plans/<file>`.

The entries below preserve the installation guides' linked checkpoint and the
open items left by delivered work.

### Geist and Noto typography

Geist and Geist Mono with self-hosted Noto fallbacks are delivered in the web app,
Electron and the public site. Open:

- The real-screen styling specs (`tests/e2e/styling`) and a first-paint measurement
  have not run. The main stylesheet measures 456 kB raw and 157 kB compressed, of
  which the `@font-face` rules take about 412 kB raw.
- Considered and not done: song resources still split the album title and language
  into two maps; radio genre chips render genre names without a content language;
  `input` and `textarea` use a 2.0 line-height inside the fixed 2rem field height,
  which WebKit may render with the caret off-centre.

### Website typography page

The `/typography/` page is delivered and published. Open: the Korean samples need a
native-speaker check, and the header's `↗` renders from Noto Sans SC because Geist's
Latin slice lacks it.

### Website layout stability

The public website no longer shifts its layout after first paint; the layout shift
browser spec guards it. Open: one scaling factor per weight range cannot match every
line, so a long page dense with inline code still wraps a few paragraphs differently
in the fallback, and headings that fill their column to the pixel can wrap in the
bold fallback; the medium weight renders in the regular face and runs 1.5 to 2.4
percent narrow. Linux Chromium with full font hinting can still gain a line in the
fallback on the swap. Diagram pages are not in the shift check, and Firefox is not
measured.

### Source-installation documentation

Disposable installation verified frontend loading, administrator login, worker
admission, sample music ingestion and catalog display. Original FLAC and transcoded
Opus played to completion, with exact byte-range delivery. These are historical
results, not new checks.

The installation used a named checkout volume for the remote Docker daemon,
a supported controller container for host PHP extensions, an accepted disposable
self-signed certificate and internal networks. It did not qualify the literal
host-PHP procedure, external metadata providers, other media workflows or host
hardware. The [source-installation guide](docs-book/part-1-operator-guide/getting-started/installation.md)
contains the current procedure; the pinned history above retains detailed evidence,
suite counts and environment adaptations.

### AAC codec gap closure against the fdk-aac upstream

The transport, low-delay, USAC, MPEG Surround, xHE-AAC encoder, player-control
and encoder-tuning tiers are delivered in `packages/baander-aac`
(2026-10-23 to 2026-10-31). These items remain open:

- **Low delay and dynamics:** the LD-SBR windows and frame grid, the RVLC escape
  codebook and the HCR reordering rules were written without the standard's
  tables and round-trip within the codec only; the DRC metadata path is not
  available for the low-delay types. Not run: interop for ELD with LD-SBR and
  for the RVLC and HCR tools.
- **USAC decoder:** the harmonic transposer, PVC and inter-TES are implemented
  but unverified, since the FDK encoder emits none and no stream at hand uses
  them; stereoConfigIndex 2 is verified by construction only.
- **MPEG Surround decoder:** TTT prediction mode 0 and the centre-subtraction
  modes 2 and 3 are verified by construction only, since libxaac's own output
  breaks there; energy modes whose two level differences exceed about 48 dB
  cannot be matched against libxaac's fixed-point weights; the object type 30
  form is checked against the in-band form only (40 dB or better); SBR input
  (HE-AAC plus surround) is wired but exercised by no stream; LD-SBR with the
  spatial payload, temporal shaping in the low-delay path and ELD frame lengths
  other than 512 are not covered; the 7.1 trees, the HRTF and binaural modes
  and the 3D mode are refused. Streams from a third-party MPEG Surround encoder
  were not run; none exists as free software.
- **xHE-AAC encoder:** the LPD core, the live loudness leveler, the 8:3 and 4:1
  SBR ratios, residual and phase coding in MPS 212 and surround input to the
  USAC encoder are out of scope as planned; the one-pass figures cannot enter
  the stream they were measured from, since a configuration cannot change
  mid-stream; the meter takes up to six channels, so 6.1 and 7.1 input has no
  in-encoder measurement; FFmpeg master's LATM path refuses USAC, so its gate
  runs on the MP4 form.
- **Player-grade controls:** the night set's gain codes stop at -31.875 dB, so
  a programme anchored near -47 LUFS or lower cannot hold -1 dBTP; the FDK
  decoder's front end still loses frames where a chunk boundary lands just
  after a configuration (its own fault, fed frame by frame it decodes whole);
  on corrupt speech frames the stabilised synthesis filters keep the output
  finite, but it can still peak near 1e13. Not run: the ten-minute fuzz runs
  per harness under libFuzzer (no clang runtime here).
- **Encoder quality and speed:** encode speed with the afterburner on (36×
  realtime against FDK's 83× and Apple's 79× in the same run), xHE-AAC at 24 to
  32 kbit/s, Apple's true VBR (0.17 to 0.19 PEAQ ahead at 128 to 160 kbit/s;
  true VBR on sparse tonal items such as glockenspiel), the TVBR quality scale
  (quality 50 gives about 230 kbit/s), bandwidth at 192 kbit/s and up (PEAQ and
  ViSQOL disagree), Apple's coupled stereo SBR and additional sinusoids,
  xHE-AAC rate accounting and the louder low band of low-rate xHE-AAC decodes,
  and the decoder's loudness default. The
  [codec comparison](packages/baander-audio-core/docs/codec-comparison.md) keeps the
  prioritized list and the [Apple probe note](packages/baander-audio-core/docs/apple-aac-probe.md)
  the remaining gaps.
- **Not run across the tiers:** listening tests, the ISO conformance suite, and
  speech, 44.1 kHz, multichannel and low-delay codec comparisons.

Figures and limits are in the
[reference comparison](packages/baander-audio-core/docs/reference-comparison.md) and the
[codec README](packages/baander-audio-core/docs/README.md).

### Development controller image identities

Two image-identity defects are fixed on the branch `fix/database-image-double-export`
(`788bc012d`, `bd7c8f926`); both trace to the pooler change (`d13b4ba77`, 2026-11-03)
combined with the daemon's containerd image store. The persistent instance reached
readiness at `https://baander.test` on 2026-11-09. The rules for retained identities
were delivered with the movie playback merge (`5957de556`), and the rules for a pruned
release were accepted by the user on 2026-11-11.

Open:

- **Instances that need a data reset:** the preflight refuses a changed applied
  migration; the preview `preview-fd790a887b6899cb` had applied five migrations that
  `de7942d73` later rewrote. An instance that was running when its release was pruned
  also stops at a named refusal, because only the lost operator image could retire
  its worker registration. Both need a data reset, which needs the owner's approval.
- **No reset command:** the tooling has none. The preview's reset on 2026-11-11,
  approved by the owner, was done with `docker volume rm`, `docker network rm` and by
  removing the instance's directory under the controller's state root.

### Movie playback on growing presentations

Delivered from the
retired plan `2026-11-09-1635-fix-movie-playback-growing-presentations-plan.md`
on the branch `movie-playback-growing-presentations`, merged to `master` as
`5957de556` on 2026-11-10 and pushed to both remotes. The defect: on the persistent
instance a 1080p movie cut forward every few seconds while its encode ladder ran ahead
of playback, wedged after a failed transfer and left eight sessions holding jobs,
slots and 361 MB of output. The website was built from that revision and published to
`baander.app` the same day; the visual checks were not repeated.

Open, known limits:

- **Stop, close, play elsewhere:** a closing tab now reports its close and the next
  tab plays at once. A tab that crashed, was killed or lost its network sends
  nothing: the session stays held until that tab's 30-second registration lapses,
  and then "Play here anyway" ends it.
- **Join lead:** a sibling that encodes slower than playback is not offered, so on a
  host where 1080p encodes at about real time it never appears; each join still
  replaces the attachment once.
- **Ended-session reason:** it is inferred from job, execution and session state. A
  cancel that leaves an in-progress job still referenced by another session reads as
  expired, and the player restarts it.
- **End-of-stream landing:** the restore holds 250 ms on the landing and keeps a
  one-second backstop, because the hook cannot see hls.js end the stream.
- **Registration proof in Chromium:** local recovery releases the runner before
  registration, so only the Firefox run binds the proof to the server change. The
  page identity now also releases a reloaded page's runner in the registration
  itself, in every engine.
- **Command disclosure by another incarnation:** a WebSocket transfer or registration
  payload carrying another of the same user's incarnation ids is returned that
  incarnation's commands and marks them shown. It can only block a settlement.
- **Player hook size:** `useBaanderPlayer.ts` passed 1,100 lines; its session restart
  resets about twenty closure variables by hand.
- **Finished presentations:** these keep their sessions and are reclaimed by the
  cache sweep's 24-hour TTL, not by job cleanup.

### Releasing a browser runner that went away

Delivered on the branch `session-runner-fence-release` from the
retired plan `2026-11-11-1737-feat-session-runner-fence-release-plan.md`, with its
checkpoint on 2026-11-12 at `956ddaa3c`. A tab that was the runner and then reloaded,
closed or lost its network left the session unknown, and every other tab was refused
with "Previous playback is unconfirmed" until it came back. The delivery adds one
identity per loaded document, a close report (`POST /api/session/close`) and "Play
here anyway" (`POST /api/session/override`), accepted only when the runner, or an
uncertain transfer's target, is a browser whose registration lapsed. Nothing is
released on a timer.

Open, known limits:

- **Stale close-report proof:** the prepared proof is renewed with each presence
  report; a hidden tab whose timers are throttled to about once a minute can close
  with a proof older than the server's 60 seconds, which is refused, and the session
  stays held until "Play here anyway".
- **Crash, killed browser, discarded mobile page:** no close report is sent; the
  override after the 30-second lapse is the way back.
- **Browser proof:** the close report and the override are proved in Chromium only.
  In Firefox, 2 of the 5 cases pass (closing a tab released the session in 74 ms);
  after navigating away, the session was not released within 8 seconds, and whether
  Firefox did not send the keepalive report or the request was lost is not
  established (the next step is to log it on the server). No browser spec drives a
  duplicated tab or a back/forward-cache restore; the unit and PostgreSQL tests
  cover them.
- **Settings conflict between tabs (older, separate defect):** every tab that shows
  the session's track writes the layout preference, so a second tab of the same
  account gets a 409 and a "Settings conflict" dialog when the first plays. The new
  specs answer those writes themselves; `browser-transfer.spec.ts` does not and can
  fail on it.

### bAAC exact arithmetic

Delivered from the retired plan `2026-11-11-1916-fix-baac-exact-arithmetic-plan.md`
on the branch `fix-usac-encoder-vector`, by the user's decision of 2026-11-11: every
compiler writes the same bytes, and nothing on the transcoder's path gets slower
(`bd175794f`, `4039dff18`, `768802054`, `5210f3e7c`). The Release build had put
`-fassociative-math`, `-freciprocal-math`, `-fno-signed-zeros` and
`-ffinite-math-only` on every file; it now keeps
`-O3 -fno-math-errno -fno-trapping-math`, `-ffp-contract=off` applies in every
configuration, and 32-bit x86 builds with GCC add `-mfpmath=sse`.

Open:

- CI writes the committed streams and checks against them with the same compiler; a
  second compiler in the Linux job is the requirement that catches a regression.
- The published speed tables and per-stream decoder figures in the package's
  documents were measured with the fast-math build; FDK and libxaac are not on this
  host to measure again.
- MSVC and toolchains other than GCC and Clang get none of the options, and other C
  libraries are unverified.

### Master CI repair

No `CI` run on `master` has been green on GitHub since 2026-11-05. The fixes up to
2026-11-13 are on `master`. Those of 2026-11-14, for the defects found at steps 28,
37, 45 and 53 of the quality gate, are commits `d1504daa3` to `77bb3c8e4`, pushed on
2026-11-15; the pinned revision above gives each cause and its evidence.

Open:

- Run 43695574134 on the pushed tip `23f899976`:
  - The first attempt passed steps 1 to 38, among them the repaired steps 28 and 37
    and the Firefox two-command gate (step 30). It failed step 39, "Development
    instance safety and source admission", after the step's 371 tests and both of its
    drills had passed: the operator drill's cleanup removed its work directory under
    a five-second limit, the log says `rm timed out.`, and a cleanup error turns a
    passing drill into exit 1. The same step passed in run 43584904626. The removal
    now has the configured cleanup budget (`5eb1de941`, pushed with the matching
    follow-ups; `WorkerCleanupTest`, 41 tests, passes with it). That the message belongs
    to this removal is read from the code, where it is the drill's only `rm` under a
    time limit; the log names no path.
  - The second attempt failed at "Build CI image" after 12 minutes with
    `docker exited with status 1`. Its build log stayed on the runner, so the cause
    is unknown; the first attempt had built the same revision.
  - The third attempt passed steps 1 to 40 and failed step 41, "PHPUnit with
    coverage": of 12,702 tests, one errored.
    `NewAlbumMetadataSyncTest::testIngestRequestsAMatchOfTheAlbumAScanCreatedOnceTheSettlingDelayPasses`
    got `pgroonga: PGrnLookupWithSize: object isn't found` while an import saved an
    album. The missing index was the one the test's own setup had just built, while
    replaying every migration in one transaction; the three migration tests that ran
    earlier in the lane (`AacImplementationValueMigrationTest`,
    `ArtistCreditedNameMigrationTest`, `JobMonitorInstantPrecisionMigrationTest`) did
    not cause it. See [PGroonga cleanup race](#pgroonga-cleanup-race).
- Run 43724380366 on `8d119f5df` passed steps 1 to 40, step 39 among them, and failed
  step 41 with exit 124: the step's time budget of 3,600 seconds ran out while lane
  `stack-1` was still running. Every lane ran slower than in the third attempt above
  (ordinary 248 s against 153, native 689 s against 496, `stack-2` 2,609 s against
  1,579), and `stack-1`'s output was lost with the lane. Its PostgreSQL log shows the
  same PGroonga error in an artist insert about a minute before the budget ran out,
  in the session of `MusicBrainzMatchApplyTest`, against the index its own setup had
  built.
- Run 43826408058 on `44e315ee3` (2026-11-16) passed the whole quality gate, the
  repaired steps 45 and 53 and steps 42 onward among them. Step 41 ran 13,028 tests in
  2,276 seconds: ordinary 167.6 s, native 489.7 s, `stack-1` 1,559.3 s, `stack-2`
  1,524.4 s, back to the third attempt's pace, so the slowdown on `8d119f5df` was the
  runner's. Its PostgreSQL logs hold no PGroonga error. The run failed in the
  `build-and-push` job at "Application image is not publicly pullable":
  `scripts/ghcr-private-probe.sh` fetched the manifest of
  `ghcr.io/baander-app/baander:latest` with an empty Docker configuration. A manifest
  fetch with an empty configuration from the development host succeeded for that
  image and for `ghcr.io/baander-app/baander-postgres:latest` the same day. The user
  decided on 2026-11-16 that every image is public, so the check had become wrong;
  `9e7d164bd` removes it from the application and Nginx workflows.
- Run 43826369096 on `98a89db8a` failed step 39 in
  `DevelopmentAcceptanceTest::testDpopProofHasVerifiableEs256SignatureAndBoundClaims`
  (`openssl_verify(): Supplied key param cannot be coerced into a public key`). The
  development acceptance client's DPoP key took OpenSSL's coordinates without their
  leading zero bytes, which about one key in 128 has; `41bc3d439` fixes it.
- Each run draws a new test-order seed, so another order-dependent test can still
  surface in step 41.
- The registry release job failed once on a load-smoke lookup that answered 503; it
  had passed on the two runs before.
- The first green run is the point to write the CI-repair learning with `ce-compound`,
  which the user confirmed on 2026-11-13.

### Playback session entities

Delivered from the
retired plan `2026-11-10-2252-refactor-playback-session-entities-plan.md` on the
branch `playback-session-entities` and merged to `master` on 2026-11-11. Four
`readOnly` entities map the four playback coordination tables, which left the ORM
`schema_filter`; `PlaybackStore` reads with DQL, and five statements stay SQL.
`.agents/rules/ddd-repositories.md` states when a table or a statement may stay
outside the ORM. The same branch fixed job cleanup after an expiry (`e7420b119`,
`101cc31e4`, `3ef843e27`, `b364cd7f3`).

Open:

- **Unreviewed baseline:** the eleven tables still in `schema_filter` and the
  existing stores that run DBAL statements have not been reviewed against the new
  rule, which neither approves them nor requires converting them.
- **Query cache:** the measurement's entity manager uses an in-memory query cache;
  production configures `cache.system`. The cost of a DQL statement there is
  unmeasured.
- **Control cost:** a control that issues a command costs two more statements than
  the SQL store and 1.26 times its time, inside the bound of 1.5.
- **Associations with a database cascade, audited 2026-11-11 by reading:** 51 to-one
  join columns carry `onDelete` (39 `CASCADE`, 12 `SET NULL`) in 33 entity files; the
  earlier count of 29 files left out four that carry only `SET NULL`. No schema
  listener adds one on an association column, and no mapping uses `cascade` or
  `orphanRemoval`. None is reachable today: the failure needs managed children, the
  parent removed through the ORM, and a later flush with something to write, and for
  every pair at least one is missing. Three places are one change away and have no
  test that would catch it:
  - `PruneMissingImagesHandler` removes images in a loop with a flush each, and is
    safe only while the owning albums and artists are uninitialized references;
  - `AlbumRepository::delete` detaches songs without skipping uninitialized ones, and
    covers neither credits, genre links nor activities (`MovieRepository::delete` has
    the same gaps);
  - `UserRepository::delete` has eleven child classes, and an administrator deleting
    their own account already holds managed token entities, so any write added after
    the delete in that request would fail.
  Not covered: `tests/`, and the long-lived runners were not traced line by line.
- **Not run:** the media-control gate, whose code this branch does not touch.
- **Store preconditions:** `register()`, `saveTransfer()` and `insertCommand()` look
  a row up and then insert it, which is safe only under the account lock that
  `lockSession()` takes inside the transaction port. The coordinator is their only
  caller. The store states this in docblocks and does not assert it: the contract
  tests call the three outside a transaction, and an open transaction would not prove
  the lock.
- **A job another run removed:** when two cleanup runs overlap and the other one
  deleted a whole job first, this run still counts that job as cleaned.

### bAAC lossless mode

Delivered from the retired plan `2026-11-10-2313-feat-baac-lossless-flac-plan.md`
on the branch `baac-lossless`, by the user's request of 2026-11-10. bAAC writes and
reads standard FLAC with its own encoder and decoder, written from RFC 9639; the
package version is 0.2.0.

Known limits:

- **Failed gate:** in the recorded run (run 6, 2026-11-12, AMD Ryzen 7 5800X3D, GCC
  16.2.1, `flac` 1.4.3, FFmpeg 8.1.3) 37 of 38 gates passed and every stream is
  bit-exact. The failed gate is the held-out size gate on the 96 kHz 24-bit piano
  recording: 9,821,712 bytes against 9,795,735 for `flac -5`, 0.27 % larger. By the
  user's decision of 2026-11-12 the mode lands with the failure disclosed in the
  results, the comparison and the website (KD12).
- **Frame search behind damage:** the decoder's search has a work budget, which on
  input crafted to overdraw it may pass over a frame right behind the damage (KD10).

Open:

- Better compression of material like the failed held-out recording is its own plan
  (KD12). It counts as tuning in answer to the third set, so it ends with a fourth
  fresh held-out set and a new recorded run.
- The server's lossless renditions and a FLAC codec in the FFmpeg wrapper are their
  own plans (KD3). The wrapper plan decides how the C structs are versioned.
- No speed was measured on ARM or with MSVC, whose build writes Rice codes with the
  shared loop and not the AVX2 kernel.
- FLAC in fragmented MP4, the Electron bridge and a browser playback matrix belong to
  the feature that delivers FLAC to clients.
- The harness selftest runs in no CI job; it needs numpy on the runner.
- The library was renamed to Bånder Audio Core (`bac`) by the user's decision of
  2026-11-12 (KD13); "Bånder Audio Core rename" below records it. The
  `aac-native-v0.2.0` tag stayed held: the first release is `audio-core-v0.2.0`, and
  until it is published installs build the binding from source.

### Bånder Audio Core rename

Delivered from the retired plan `2026-11-12-1934-refactor-rename-baac-to-bac-plan.md`
on the branch `rename-audio-core`, by the user's decision of 2026-11-12, and landed
on `master` on 2026-11-13. bAAC is now Bånder Audio Core, short name `bac`, with no
aliases; the package lives at `packages/baander-audio-core`. The user chose to land
before the dispatched FFmpeg image was ready, so `master` moved without the pin at
`cbc5540f4`, and the next commit pins the image that the `ffmpeg.yaml` dispatch
published from `489926f68`. The `aac-native-v0.2.0` tag stayed held, so the first
release is `audio-core-v0.2.0`; the tag points at `cbc5540f4`.

Open:

- **Remote:** the release's four prebuilds and `SHA256SUMS`, a clean desktop install
  that fetches its prebuild, and Forgejo `ci.yaml` after Forgejo's FFmpeg image
  publishes (the user set Forgejo CI aside).
- **Not run anywhere before the landing:** the macOS and NEON kernel step, the
  Windows MSVC binding and the first CI run after the cache keys changed. Only the
  binding workflow covers them, and it first runs on `master`.
- **Desktop:** in clean copies, the immutable install, `tsc` and the tests passed
  (U2 to U5, U12), and the packaging check passed after U12. After U10, only the
  immutable install was rerun.
- **Website:** a local build of `57fc9e1e3` was deployed to production on 2026-11-13
  (`baander-nl52trcat`, aliased to `https://baander.app`). The visual checks were not
  repeated on the live site.
- **Held-out recordings disclosure (2026-11-13):** on `/bac/`, the long "Held-out
  recordings" text in the lossless section now sits in a disclosure that starts
  closed. Published with the website release of 2026-11-16.
- **Website release (2026-11-16):** a local build of `65a1d6d95` (243 manual pages)
  went to a preview (`baander-6xfxjrv7k`) and then, unchanged, to production
  (`baander-ke69f0cpd`, aliased to `https://baander.app`). On the preview, through
  `vercel curl`, and again on `https://baander.app`: the homepage and a nested manual
  page answer 200, an unknown URL 404, the search index and sitemap load, canonical
  links name `https://baander.app`, and the new testing-conventions section is
  present. On production, HTTPS verifies, a source link to `65a1d6d95` answers 200 on
  GitHub, and `www.baander.app` redirects. The site's browser suite passed 92 of 93
  against the artifact; the one failure was `net::ERR_NETWORK_CHANGED` while loading
  the typography page, and its four typography cases passed on a rerun. Search,
  themes and narrow-screen layout were checked by that suite, not by hand on the
  live site.

### Scan artist credits, song lengths and lyrics outcomes

Delivered from the retired plan `2026-11-13-2034-fix-scan-artist-credits-plan.md`
and on `master` since `a67ed57e4` (2026-11-14): the library scan credits songs and
albums to their tagged artists, song lengths come from the audio probe, and a lyrics
fetch reports a missing artist, a missing length and an LRCLIB rejection each as its
own outcome.

Open:

- A single artist value that names several artists stays whole in the scan (R7);
  matching the album splits it. On 2026-11-14 the user chose matching over splitting
  on an album-artist prefix in the scan.
- Deferred, each separate work:
  - album identity across directories: albums have no uniqueness on title, and
    duplicate detection with `app:album:merge` covers the outcome until album identity
    is planned;
  - a file's song found by hash across libraries (`findByHash` is catalog-wide): a
    file shared by two libraries needs its own identity design;
  - genre slugs collapsing non-ASCII names, and Unicode (NFC and NFD) spellings of one
    name becoming two artists: both predate the branch, and no real library has shown
    them yet;
  - M4A tags: the ID3 reader finds none, so an MP4 atom reader is needed; the
    persistent library has no M4A files;
  - a forced-sync rename followed by a wrong ID: no code path reaches it today.
- From review, not fixed: the import handler's size and its `PreparedSong` array shape
  (a split into a tag-credit reader and a rescan repair was suggested); genre creation
  by the metadata enrichers takes no import lock, so an enricher that creates a genre
  while a running import creates the same slug fails on `uniq_genres_slug`, and the
  album enricher logs that genre failure and goes on (locking it needs a transaction
  around the enricher's flush, which is separate work); a retag is any byte change at a
  song's path, so a library-wide ReplayGain pass replaces hand-made credits on the next
  scan (the settled retag decision).
- Housekeeping: the postgres-remediation `doctrine.md` version list is stale;
  `composer normalize` fails on `master`. The PGroonga part of this item is done; see
  [PGroonga cleanup race](#pgroonga-cleanup-race).

### MusicBrainz matching

Delivered from the retired plan `2026-11-14-1219-feat-musicbrainz-matching-plan.md`
and on `master` since `435a11639` (2026-11-14): an admin matches an album, song or
artist to MusicBrainz from a dialog or the console, and a queue matches confident
albums on its own. The plan's "Implementation Amendments" section records where the
build departs from the plan text.

Its review leftovers were delivered by [Matching follow-ups](#matching-follow-ups)
and [Matching leftovers](#matching-leftovers).

### Matching follow-ups

Delivered from
[the matching follow-ups plan](docs/plans/2026-11-15-1038-fix-matching-follow-ups-plan.md)
and on `master` since 2026-11-15 (`a7d1c7466` to `6991a9f9a` and this record, on top
of `23f899976`):

- **Lyrics** (U1 to U3). Every LRCLIB request, from any process, takes a turn under one
  shared pace of one request every two seconds. A 429, or a 503 with `Retry-After`,
  pauses every process for the time LRCLIB names, an hour at most, and the request is
  sent again afterwards. A call waits at most 10 seconds, a queued fetch 30. A fetch
  sends one request, where it sent two and the first went to a route LRCLIB no longer
  serves. Queued fetches are spaced 3,000 ms, which is also the minimum. A worker's
  waits last their full time although its keepalive alarm arrives. The lyrics dialog
  searches on submit, and a failed search is sent once.
- **Album match queue** (U4 to U7). A run's claim is an ID and a 15-minute expiry in
  columns of their own, so a request for an album that a run is matching starts no
  second run and takes effect when the run ends. A cover the Cover Art Archive refuses
  no longer pauses the queue: the match is applied without it, and a manual apply
  answers 409 `cover_refused`. An album search that finds nothing is repeated without
  a trailing comma-separated list in square brackets. A match's 404 names its cause,
  and the dialog says what to do for a 404 and a 422.
- **Smaller corrections** (U8, U9). A merge clears `match_checked_at` on the songs it
  moves and requests a match of the target while automatic matching is on; a song that
  joins a matched album takes a track's recording ID and credits only within the
  length tolerance. The per-user MusicBrainz allowance is keyed by user ID.
- **Qualification harness** (U10, U11). The metadata transport answers the queue's
  release search and lookup, queue runs count as metadata work, and a scenario's
  window requires song identities, with the count of 100 albums holding a MusicBrainz
  ID as the proof of the album matches (user decision, 2026-11-15).
- **Documentation** (U12). The operator pages, context guides, READMEs and
  `CONCEPTS.md` describe the above, and no page says an artist sync runs.
- **From the code review**, decided by the user on 2026-11-15: `GET /api/lyrics/search`
  requires the administrator role, because each search takes a turn of the shared
  pace, and a pause LRCLIB names is capped at one hour.

Verification on 2026-11-15:

- Full backend suite in disposable containers at `5eb1de941`: 12,822 tests in four
  lanes, 12,821 passed. The one failure was the referential integrity guard, which
  did not list the new `claim_id` column; `2f59f6ccf` lists it, and the guard's 10
  tests pass. The run at `85bfb293b` selected 12,833 tests and passed all but two,
  both in tests the branch does not touch. `StreamedResponseProcessorTest` once got
  curl's exit 52 where it expects 18, and passed three reruns on its own.
  `CoveredIndexPersistenceTest` failed on `pgroonga: failed to remove column` during
  an `ANALYZE` and passed when run directly after `AlbumMatchClaimMigrationTest`. That
  error is two PGroonga cleanups colliding over the leftover objects of dropped
  indexes, not the "object isn't found" failure; see
  [PGroonga cleanup race](#pgroonga-cleanup-race).
- Static gates at `85bfb293b`: PHPStan reports no errors, Deptrac 0 violations,
  `lint:container` passes, and the OpenAPI specification and the generated web client
  match the application.
- Tooling in a CI image built at `4077229f3`: 1,595 tests, 13,553 assertions, 7
  skipped, with the tooling analysis, architecture and format checks clean. After the
  later tooling change (`5eb1de941`) the three static checks pass on the host.
- Web at `6991a9f9a`: typecheck passes, lint reports 0 errors and 10 warnings, and
  all 3,580 tests in 285 files pass. The full run found four cases of a permissions
  test that pinned the lyrics search's query options; the test now expects the search
  without a retry.
- The match claim's race test fails with a `DELETE` and an `UPDATE` as parts of one
  statement and passes with the `MERGE` that was kept (two sessions, PostgreSQL 18.4).
- The preview, at `2f59f6ccf`, against LRCLIB and MusicBrainz themselves:
  - one `app:lyrics:fetch` with default settings queued 77 fetches and finished in
    about four minutes with 61 found, 16 without a match, none rejected and none
    unavailable. LRCLIB answered no 429, so no pause was stored;
  - in the lyrics dialog, typing 18 characters sent no request and Enter sent one;
  - `app:metadata:match:search` lists five releases of "Ten Thousand Fists" by
    Disturbed for "Ten Thousand Fists [Reprise Records,9362-49433-2,EU]", each scoring
    100, where the album found no candidate before;
  - a queue run claimed that album's request and within about ten seconds left the
    album for review with `close_runner_up` and a best score of 100. For this check
    the album's earlier review mark was turned into a due request by hand.
- Simplification pass: three reviewers, ten findings, none applied; the ones worth
  keeping are listed below.
- Code review: twelve reviewers, verdict "Ready with fixes", no finding above
  moderate. Applied: the two decisions above, this record, and the retry of a failed
  lyrics search.

Open:

- On an instance that updates: a lyrics fetch queued before the update carries no
  queued flag and fails to decode, and its song stays marked for a day. The
  persistent instance updates itself from `master`, so a bulk run in flight at that
  moment loses its remaining fetches until a later run. A match request a run held at
  the update keeps its due time and is matched within the hour.
- A metadata sync does not request again an album that is on the review list with an
  unchanged song count. "Ten Thousand Fists" therefore keeps its `no_candidates`
  review on the persistent instance until an admin opens its match dialog, which now
  lists candidates, and applies one. Several 14-track editions tie at score 100.
- The pause has met no real 429. A `Retry-After` in date form takes the fallback of 5
  and then 15 seconds, so the request could be sent again inside the time LRCLIB
  named; the warning logged with the first real pause shows which form LRCLIB sends.
  A stored pause ends early only when its Redis key `lyrics:lrclib:pause` is deleted.
- The claim's lease is not renewed. A match that takes longer than 15 minutes lets a
  second run take the album: MusicBrainz is asked twice, and the apply's row lock and
  release recheck keep a second write out.
- Not run on the preview: an album merge, because its five albums hold no pair to
  merge. `AlbumMergeTest` (14 tests) runs a merge through the real matcher and apply.
- Waits that a worker's keepalive alarm can still cut short: the webhook delivery
  back-off (`WebhookDeliveryService`), and the `Retry-After` waits of the Discogs,
  Spotify and TMDB adapters, which no worker reaches today.
- From the code review, not built: a test that fails when the MusicBrainz adapter
  goes back to the interruptible sleep. The other test leftovers were delivered by
  [Matching leftovers](#matching-leftovers).
- From the simplification pass, left as they are: the same User-Agent string in the
  LRCLIB, MusicBrainz and Cover Art Archive adapters; the ranking of candidates
  written twice, in the dialog's search handler and the matcher (predates the branch);
  `nextRequestDueAt()` reads every requested row since it counts a claimed row from
  its claim's expiry; `LrclibClient` takes a sleep closure where the Transcode context
  uses `SleeperInterface`.
- Qualification: the 4 GB run is still to do. Its cold start gets its album matches
  from the admin sync, since automatic matching is off by default. An album the queue
  leaves for review keeps the count below 100 until the two-hour maintenance deadline,
  and that failure names no album.
- The plan file stays until the next cleanup retires it.

### Matching leftovers

Delivered from
[the matching leftovers plan](docs/plans/2026-11-15-1624-fix-matching-leftovers-plan.md)
and on `master` since 2026-11-16 (`a822bf362` to `0405c6142` and this record, on top
of `8d119f5df`). The user chose this scope on 2026-11-15 and answered the
plan's questions the same day: plays as well as favorites follow a merged album, a
merge folds a duplicate song into the song it keeps, artist sync is rebuilt as a fill
by stored ID, an artist is filled as soon as it gets an ID, an asked artist is
remembered for 30 days, an import fills nothing while automatic matching is off, and
MusicBrainz's redirect for a merged artist ID is reported, not followed.

- **Hand changes** (U2). Adding, removing or changing an artist's credit runs in one
  transaction under the locks a match apply takes, in the same order: the album's row,
  the import keys of the artist's name and MusicBrainz ID, the artist's row, the song's
  row. It writes the credit and drops the song's `credits` mark together. An artist,
  song or album gone by then is "not found"; a deadlock, or an artist that took another
  name or ID while the change waited, answers 409 `credit_busy`, exit code 1 on the
  console. A song edit locks the song's row and edits the song as read under it.
- **Album merge** (U3, U4, U14). A merge runs in one transaction: the source row
  `FOR UPDATE`, the target `FOR NO KEY UPDATE`, then every song row of both albums. It
  moves every source song, so a song an import adds to the source meanwhile waits and
  fails instead of being deleted with it. The cover goes after the commit; a deadlock
  answers 409 `merge_busy`, and both web merge dialogs show the API's message. A
  user's favorite of the source and the source's plays follow the target. A duplicate
  (a source song whose file hash the target holds) is folded into the target's oldest
  song of that hash: its playlist entries, lyrics when the kept song has none, plays
  (counts added, the later play kept, loved when either was) and favorites move, and
  its row goes while its file stays on disk.
- **An apply beside the queue** (U5, U6). Each effect was reproduced by a failing test
  before its repair. An automatic match of a matched album's new songs no longer
  replaces the album's own credits (effect C), and it writes nothing when another
  match compared one of those songs (B) or a retag paired them with other tracks (D)
  since the matcher read the album. A manual album apply requests the album's match
  after its commit, so a song an import added meanwhile is matched (A).
- **Artist sync** (U11, U12, U15). An admin metadata sync also queues one
  `SyncArtistsMessage`, which queues a fill for every artist with a MusicBrainz ID and
  an empty detail field, two seconds apart on one pace shared with every other
  producer. A match apply that identifies an artist queues its fill after the commit,
  and so does an import batch while automatic matching is on. The fill looks the
  artist up by its stored ID only, fills empty unlocked fields under the artist's row
  lock, and is not asked again about that ID for 30 days. The name-search enricher,
  the `includeArtists` flag and the searched-ID assigner are removed.
- **Tests and harness** (U1, U7, U8, U9, U13). The in-memory match states and the SQL
  repository pass one set of 19 cases; a queue run of a replaced chain is pinned on
  every path; the match dialog's refused cover has its test; the qualification
  transport answers artist lookups and counts artist syncs as metadata work.
- **Documentation** (U10). The operator pages, context guides, READMEs, the context
  map, the worker inventory and `CONCEPTS.md` describe the above.
- **From the code review**: an artist whose fill the message bus refused is no longer
  left marked as queued for a day (`0405c6142`).

MusicBrainz and a merged artist ID: on 2026-11-15 at 18:28 UTC, one artist comparison
on the preview with an ID that Wikidata lists as a MusicBrainz redirect
(`3ec87112-9081-467b-8327-e18286f1b020`) answered "MusicBrainz is unavailable". The
MusicBrainz server answers a lookup of a merged ID with 301 to the surviving ID
(`Controller/Role/Load.pm`), and the HTTP client refused every redirect, so the adapter
reported an outage. `fetchArtist` now asks the client for the redirect and reports
`MusicBrainzMerged` with both IDs; the 301 and its `Location` were not observed live.

Verification on 2026-11-15:

- Each unit's race, functional and firewall tests ran in disposable containers before
  its commit; from U4 on, against the commit in a separate worktree. The race classes
  `HandChangeRaceTest` (9), `AlbumMergeRaceTest` (11), `QueueRunBesideWriterRaceTest`
  (4) and `ArtistSyncRaceTest` (3) ran, none skipped.
- The reproductions failed before their repairs: C with the album credits
  `Post Malone` where the admin had set `Austin Post`; B with the duet credited to Post
  Malone and Luke Combs although the admin left its credits unticked; D with each
  song taking the recording of the track it paired with before the retag; A with no
  match state for the album after the apply.
- Full backend suite in disposable containers at `e02d023de`: 13,027 tests in 1,179
  files and four lanes, all passed.
- PHPStan over the whole project in the CI image at `e02d023de`: no errors. Deptrac 0
  violations and `lint:container` pass at `b83a12da2`. The OpenAPI specification and
  the generated web client match the application.
- Web at `e02d023de`: typecheck passes, lint reports 0 errors and 10 warnings, and all
  3,583 tests in 286 files pass.
- Tooling: the qualification transport and qualification tests (12 and 38 cases) and
  the tooling format, analysis and architecture checks pass on the host. The full
  tooling suite was not run.
- Documentation: the command documentation coverage test passes and
  `make website-build` builds 243 pages.
- The preview at `afa740518`: `app:artist:song:add` credited a producer on a song
  whose credits a match had set, and the song's `matched_fields` went from
  `["credits"]` to `[]`; `app:artist:song:remove` removed the credit again. Not run on
  the preview: the API half, because the credit routes take a DPoP-bound token and no
  web screen calls them (the functional `ArtistCreditCommandsTest` runs both entry
  points), and an album merge, because the preview's five albums hold no pair.
- Simplification: two passes. Applied: one factory for a stored artist's identity and
  one source for the pairing tolerance of new songs (`b62a1cd22`), and one helper for
  releasing loaded holders before a delete (`e02d023de`).
- Code review: nine reviewers, verdict "Ready with fixes", one moderate finding,
  applied (`0405c6142`). At that commit the artist sync, merge and metadata admin
  tests (71, among them `ArtistSyncRaceTest`, `AlbumMergeRaceTest` and
  `ArtistMergeRaceTest`) pass in disposable containers, the Catalog and Metadata unit
  tests pass on the host apart from `VideoServiceTest`, which needs `finfo`, and
  PHPStan over the whole project reports no errors.

Open:

- Writes beside a merge: a play read before the merge and saved after it can
  overwrite the folded play count (`RecordPlayHandler` reads and writes without a
  lock), and an existing play saved after the merge loses its album
  (`MediaActivityRepository::syncToEntity` looks up the deleted source). A favorite of
  the target added after the merge's statement waits and then fails with 23505, which
  the add maps to nothing.
- Artist sync:
  - an artist whose only empty detail fields are locked is queued by every admin sync;
    it makes no request but takes a two-second step. The six fields are listed three
    times (`SyncArtistHandler::DETAILS`, its `missesDetails()` and the query in
    `ArtistRepository::identifiedWithMissingDetailsAfter()`);
  - an album artist that only an import's album credit creates is filled by the next
    admin sync, not by the import;
  - one outage moves the shared due time of every later fill by its pause of 5, 15 or
    60 minutes, and a 301 to anything but another artist lookup counts as an outage,
    tried again three times at every admin sync;
  - after a cancel, a new admin sync queues none of the cancelled run's artists until
    their cancelled fills fall due;
  - a fill whose requeue after an outage could not be sent keeps its queued mark for
    up to a day once its retries are spent;
  - `InMemoryQueuedArtistSyncs` shares no contract cases with
    `RedisQueuedArtistSyncs`;
  - release and recording lookups of a merged ID still answer as outages; the adapter
    can report them the same way.
- Deferred by the plan, each separate work: the other hand edits that save a copy read
  before a concurrent commit (`UpdateAlbumHandler`, `SetCoverHandler`,
  `RemoveCoverHandler`); the import's repair of a known song without a row lock;
  deleting an artist leaves the `credits` mark on the songs it uncredits; one failed
  match request ends the other requests of the same import; favorites of an album or
  song deleted outright dangle; `MovieRepository::delete` has the gaps the album
  delete no longer has.
- From the simplification passes, left as they are: the credit lock order in a static
  method of `ArtistCreditInput` with eight parameters; the release loop in each of the
  four album merge adapters; the job-run records of the artist sync store and the
  lyrics store; `ArtistMatchWriter::text()` and `ApplyMatchHandler::text()`.
- Point 2 of
  `docs/solutions/architecture-patterns/concurrent-imports-find-or-create-identities-under-sorted-advisory-locks.md`
  cites the removed assigner; it is due at the next learnings refresh.
- `dev-docs/worker-runtime-inventory.md` holds 15 links that were broken before this
  branch.
- The plan file stays until the next cleanup retires it.

### PGroonga cleanup race

Delivered from
[the PGroonga cleanup race plan](docs/plans/2026-11-16-0919-fix-pgroonga-cleanup-race-plan.md)
and on `master` since 2026-11-16 (`8a3cd708b` to this record, on top of `44e315ee3`).
`7c2deaa24` went first, so that GitHub published the 4.1.0 image as
`ghcr.io/baander-app/baander-postgres:latest` (16:36 UTC) before the CI run that
carries the new version check. The user chose the item on 2026-11-16 as "PGroonga
isolation of throwaway test schemas" and answered the plan's questions the same day:
the database image moves to PGroonga 4.1.0, and the persistent instance moves to it
with its data in this work, after the push.

The cause was not the schema drops. PGroonga keeps an index's data in Groonga objects
outside PostgreSQL's catalog, and its cleanup of objects whose index no longer exists
runs in every `VACUUM` and `ANALYZE` of a table with a PGroonga index, autovacuum's
included, and in `pgroonga_vacuum()`. In 4.0.5 that cleanup looked each index up
under the cleaning session's snapshot, so it deleted the objects of an index another
session had built and not yet committed (upstream GH-1013, fixed in 4.1.0 on 2026-10-09). A test that builds
its schema with `FinalSchemaBootstrap` replays all 28 migrations, five PGroonga indexes
among them, in one transaction; an autovacuum during the replay left the test with an
index that answered "object isn't found". Each of the six such failures on record,
from 2026-10-22 to 2026-11-15, hit the index the failing test had just built. The
"failed to remove column ... failed to stat" error during `ANALYZE` is a second
symptom: two cleanups colliding over the leftover objects of dropped indexes, which
4.1.0 still allows.

- **Image** (U2, `7c2deaa24`). `docker/postgres/Dockerfile` and `docker-compose.yml`
  pin `postgresql-18-pgdg-pgroonga` 4.1.0-1, and the developer guide names the
  version and why it is the minimum. No migration.
- **Leftovers before a test's cleanup** (U3, `ba62eb577`). `PgroongaLeftovers::clear()`
  takes SHARE UPDATE EXCLUSIVE on every table with a PGroonga index, which autovacuum
  skips rather than waits for, and runs `pgroonga_vacuum()` as the only cleanup in the
  database; inside a transaction the locks last until it ends.
  `CoveredIndexPersistenceTest` and the image read-scope plan fixture call it before
  their `ANALYZE`.
- **Regression test** (U4, `7c62a6a42`). `PgroongaUncommittedIndexTest` builds an index
  on a new table in one session's open transaction while another session runs
  `ANALYZE`, `VACUUM` or `pgroonga_vacuum()`, then commits and searches.
  `PgroongaSearchCompatibilityTest` requires PGroonga 4.1.0 in a case of its own, so
  the messaging gate, which runs it, needs 4.1.0 too.
- **Guard** (U5, `120088fe6`). `PgroongaCleanupConventionTest` fails when a file under
  `tests/` runs `ANALYZE`, `VACUUM` or `pgroonga_vacuum()` without calling the helper,
  unless the guard's reviewed list holds the file with its count and a reason.
- **Documentation** (U6). The extension inventory, the solution page, renamed to
  `docs/solutions/developer-experience/pgroonga-4-0-cleanup-deletes-indexes-of-uncommitted-transactions.md`, the testing conventions, the backend runner page and the
  development environment guide describe the above.
- **Beside the plan**, both on the user's word of 2026-11-16: the development
  acceptance client's DPoP key always carries 32-byte coordinates (`41bc3d439`), the
  cause of the step 39 failure in run 43826369096; and every image is public on GHCR,
  so the check that failed a push when the application or Nginx image could be pulled
  anonymously is removed, with its script and test cases, and the CI guide lists every
  image as public (`9e7d164bd`).

Verification on 2026-11-16:

- Two database images built the same day from the Dockerfile, differing only in the
  PGroonga package (Debian 13.7, PostgreSQL 18.7-1.pgdg13+2, Groonga 16.1.5):
  `baander-database:pgroonga-4.0.5` (`cbcbb462e4f1`) and
  `baander-database:pgroonga-4.1.0` (`181e00534c51`).
- Probes on disposable containers of both images, with PostgreSQL's default
  configuration:
  - dropping a schema with all five PGroonga tables broke no later insert or search,
    and `pgroonga_vacuum()` removed its leftovers;
  - with autovacuum off, a second session's `ANALYZE`, `VACUUM` or `pgroonga_vacuum()`
    during an uncommitted index build made the index fail after the commit on 4.0.5,
    in all three cases, with `22023 ... object isn't found`, and `REINDEX` repaired
    it; on 4.1.0 all three passed;
  - with autovacuum alone at a one-second naptime, a ten-second build lost its
    objects on 4.0.5 and kept them on 4.1.0 through ten autovacuum runs;
  - two cleanups released at once into 50 leftover indexes collided in 30 of 30 tries
    on both versions (one failed with "failed to remove column", or on 4.1.0 twice with
    "nonexistent object"); the other finished, and a cleanup run alone afterwards
    left no orphan;
  - on 4.1.0, autovacuum skipped every table held in SHARE UPDATE EXCLUSIVE and logged
    "skipping vacuum ... lock not available"; in 20 tries the helper's lock request
    cancelled the autovacuum worker that held `songs`, and its `pgroonga_vacuum()`
    raised no error and left no orphan.
- A full backend run on 4.0.5 with every autovacuum logged (seed 41160, master's code):
  autovacuum processed PGroonga-indexed tables 27 times in `stack-1` and 38 times in
  `stack-2`, each a PGroonga cleanup. No PGroonga error came up that run; its one
  failure is the audio rendition test below.
- The branch's tests: on 4.0.5 the three regression cases failed with
  `object isn't found` and the version floor with its 4.1.0 message, and the tests run
  after them in the same lane passed; on 4.1.0 all 19 tests of the targeted set passed, none
  skipped, with no PGroonga error in the PostgreSQL log.
- Five full backend runs on 4.1.0 at `dfbd5684d`, 13,028 tests in 1,179 files each, all
  passed with no `pgroonga:` error in any lane's PostgreSQL log:

  | Seed | ordinary | native | `stack-1` | `stack-2` |
  |---|---|---|---|---|
  | 20261116 | 98.7 s | 344.9 s | 1,420.0 s | 1,382.3 s |
  | 7341 | 89.6 s | 341.4 s | 1,408.3 s | 1,360.1 s |
  | 99881 | 94.8 s | 340.3 s | 1,409.3 s | 1,376.0 s |
  | 424242 | 95.9 s | 341.0 s | 1,390.3 s | 1,377.0 s |
  | 31337 | 93.2 s | 340.9 s | 1,403.3 s | 1,370.5 s |

  The run on 4.0.5 above took 1,444.0 s and 1,405.6 s in its stack lanes, so 4.1.0's
  extra catalog check showed no cost in lane time.
- PHPStan over the whole project reports no errors and Deptrac 0 violations at
  `dfbd5684d`. The tooling tests that read `docker/postgres` pass
  (`DevelopmentImagePinsTest` 11, `PresetDeploymentTest` 28,
  `PresetPostgresSettingsTest` 10, `PythonBoundaryTest` 8), and so do the 6 tests of
  `DevelopmentAcceptanceTest` at `41bc3d439`; its new case failed first on a key with a
  31-byte coordinate.
- Simplification: three reviewers; applied the removal of single-use connection
  aliases (`dfbd5684d`). Left as they are: the new scanner repeats
  `PoolSafeSqlScanner`'s literal reading and line counting, which only a shared helper
  that edits that scanner would remove; the two new integration tests each define the
  project's PGroonga index options and their own throwaway schema.
- Code review of `44e315ee3..9e7d164bd`, focused depth, the correctness pass and one
  adversarial reviewer (the checkout turns the cross-model pass off): verdict "Ready
  to merge", no findings. Its residual risks are under Open.
- The persistent instance (U7), 16:43 to 16:57 UTC: a dump taken in one snapshot
  restored with `--exit-on-error --single-transaction` into a scratch container of
  its 4.0.5 image, with the six row counts (users 2, libraries 2, artists 19, albums
  5, songs 77, playlists 0) equal to the snapshot's; the same update passed first on
  a copy of the stopped instance's volume. After `make dev-down`, removal of the
  retained 4.0.5 image (`c7edb4291502`) and `make dev-up`, the instance runs image
  `971daf64233b` (PGroonga 4.1.0-1, PostgreSQL 18.7) on its own cluster
  (`system_identifier` unchanged); `ALTER EXTENSION pgroonga UPDATE` moved
  `extversion` from 4.0.5 to 4.1.0, `pgroonga_list_broken_indexes()` returned no rows,
  so no `REINDEX` ran, and each of the five PGroonga indexes answered a search through
  the index. The server log shows no collation, PGroonga or other error. The new image
  was built by the updater's timer, which `make dev-up` enables before its own update.

Open:

- From the code review: `PgroongaLeftovers::clear()` sets no `lock_timeout`, so a
  lock holder that PostgreSQL does not cancel (an anti-wraparound autovacuum, or a
  connection of the same test process holding uncommitted DDL on a PGroonga table)
  would hang the lane until the step's budget instead of failing at once; and the
  guard exempts a whole file once it calls the helper, so a later cleanup in that file
  after new leftovers would pass it.
- `pgroonga_list_broken_indexes()` was not tried against a broken index on 4.1.0;
  on 4.0.5 it raises "object isn't found" instead of listing the index.
- `AudioRenditionWorkerHandoffTest` failed in the 4.0.5 run above with
  `Audio transcoding failed: Cannot safely resolve filesystem path` on the `.part` path
  of a rendition (`AudioRenditionCache::validatePaths`), as another of its tests did on
  2026-11-07. Separate work.
- Deferred by the plan, each separate work: a template database instead of about 500
  migration replays per run; the test stacks start PostgreSQL with its defaults, not
  `docker/postgres/conf/postgresql.conf`; nothing checks that the local default test
  image `baander-database:latest` is current, and on the development host it was a
  Debian 12 build until 2026-11-16; an upstream report of the cleanup collision.
- The plan file stays until the next cleanup retires it.

### Lyrics effect toggle and song browser panes

Fixed in `9ecf84a3d` and `052e8684d`, pushed on 2026-11-15: in the fullscreen lyrics
view a click on the active effect turns effects off, and the songs view's genre,
artist and album panes scroll.

Open: the pane fix has no automated test, because jsdom computes no layout; a
Playwright check of the songs view would cover it. Not run: `yarn build`, and Firefox
or Safari.

## Roadmap maintenance

Cleanup, 2026-11-15: removed the delivered checkpoints and resolved items of the
small-host deployment, the development controller image identities, movie playback,
the browser runner release, bAAC exact arithmetic and lossless mode, the master CI
repair, the playback session entities, the Bånder Audio Core rename, scan artist
credits, MusicBrainz matching and the web fixes of 2026-11-14, reducing the roadmap
from 2,098 to 1,105 lines; open items, limits and release gates stay. Later evidence
closed these items, which went with the rest: the hosted runs of the movie and
two-command gates, full-tree PHPStan and Deptrac (GitHub run 43638221630 passed the
quality gate's steps 14 to 36 on `435a11639`), the full tooling suite on `master`
(step 37 passed in run 43584904626 on `a67ed57e4`), the full backend suite after the
rename merge (12,700 tests at `bc10bbe05`), and the two tests the Audio Core section
listed as failing. The note that a review ran without a cross-model pass went from
each section, because the checkout configuration sets that. Eight delivered plans
were retired: movie playback, the playback session entities, the browser runner
release, bAAC exact arithmetic, bAAC lossless mode, the Audio Core rename, scan artist
credits and MusicBrainz matching; only the roadmap linked them, and it now names each
file. Nine plans remain: the six July plans and the three with open work (playback
destinations, the small-host deployment, and the capability and size settings). The
roadmap and the plans before this cleanup are preserved at `d4a938b74`. All 43 local
links resolve, and the four incoming anchors (`#application-release-acceptance`,
`#completed-work-and-evidence`, `#local-development-workflow`,
`#source-installation-documentation`) are kept.

Cleanup, 2026-11-07: removed the delivered checkpoints and resolved items of
forge-neutral CI, the system overview pages, interactive diagrams, configurable media
capacity, bAAC in the server transcoder, web dependency refresh and the website
typography, font stack and layout stability work; open items, limits and release
gates stay. Twelve delivered plans were retired and the two solution docs that cited
them now point at the pinned revision. Eight plans remain, each with undelivered
work. The roadmap and plans before this cleanup are preserved at `b9a67726f`. The four
incoming anchors (`#application-release-acceptance`, `#completed-work-and-evidence`,
`#local-development-workflow`, `#source-installation-documentation`) are kept.

Cleanup, 2026-11-03: removed implemented work from every section, reducing the
roadmap from 3,789 to 432 lines. Fully delivered sections were removed; partly
open sections keep only their open items, release gates, deferred scope and limits
still in force. The roadmap before this cleanup is preserved at `5b74a1f44`. All 34
local links resolve, and the three incoming anchors (`#source-installation-documentation`,
`#local-development-workflow`, `#completed-work-and-evidence`) are kept.

Cleanup, 2026-10-26: removed completed checkpoint narratives and superseded
follow-ups while preserving unresolved scope, release budgets and incoming links.
The full history remains at the pinned revision above. Link, incoming-anchor,
prose and whitespace checks passed.

Plan cleanup, 2026-10-26: retired 36 delivered plans, including stale completion
labels and this cleanup's own plan. Six unfinished or partly superseded July plans
were retained byte-identical. Repaired all five incoming references to retired plans and
retained the AAC bitrate acceptance gap. Completed history remains recoverable
from the pinned revisions; no surviving file references a deleted plan. No
application code changed or application suites ran.

Android app removal, 2026-10-26: removed the standalone `ui/android` application
and its 52 tracked files, and dropped `ui/android/**` from both frontend CI event
filters. The older styling plan now treats the removed app and its proposed
shared-theme adoption as historical scope. All 1,394 tracked files in `ui/rn`,
`ui/shared`, `ui/web` and `ui/electron` were preserved, including the surviving
Android and Android TV targets, and no active consumer of the removed package
remained. React Native work remains deferred.
