# TypePHP application packaging

This setup packages Baander's PHP application into one `baander` executable that runs embedded PHP bytecode through ZendVM.
Use `baander console ...` for the existing HTTP server, worker and administration commands, or `baander operator ...` for the independent deployment operator. The operator route keeps its own composition root and does not boot Symfony.
Keep the physical application tree during qualification; use the ordinary CI image for baseline comparisons.
Symfony scans resource directories, and the coroutine container reads PHP source.

The [runtime performance comparison](benchmarks/README.md) reports measured startup
and sustained application-code timings against ordinary PHP, with raw samples,
runtime identities and reproduction instructions.

## Install the compiler

Run from the repository root with PHP 8.5, Composer 2 and Docker available:

```sh
make typephp-setup
make typephp-version
make typephp-toolchain
```

The tool-only lock selects TypePHP `41d91fe69d2f7ec99dffe252a95244793000c6d2` and PHPX `90e6d22a9c3f4d5dbd2c75d62e90bf5adfe58c89`.
The pinned source reports `TypePHP Compiler (AOT) v0.10.0`; that display string does not identify the source revision.
Compiler installation disables Composer plugins and scripts and leaves the application manifests unchanged.
Use `composer install`, not `composer update`, to reproduce the locked compiler.
Upstream's manifest also names an absent native `bin/tpc`; Composer warns about it, but the installed `vendor/bin/tpc.php` source entry is usable.

The optional builder image uses PHP 8.5.12 ZTS and OPcache, matching the application's current PHP ABI.
It contains the compiler and native build prerequisites, and runs as the invoking host UID/GID.
It applies local patches to the locked compiler and PHPX before use.
`php-builder-object-directories.patch` creates the object directories named by PHP's generated Makefile; igbinary's nested output paths were missing in the unpatched separate-directory build.
`php-builder-swoole-sources.patch` gives the copied Swoole configuration an absolute source path before `buildconf` and changes the derived-source cache identity.
Without it, the generated Makefile omitted Swoole's main sources and linking failed on `swoole_module_entry` and coroutine symbols.
`php-builder-redis-lzf.patch` corrects the copied Redis configuration's bundled LZF header path, which otherwise resolves from the PHP source root and fails on `lzf.h`.
`php-builder-runtime-lock.patch` selects the source versions and extension features in `runtime-lock.json`.
`php-builder-swoole-pdo-symbols.patch` isolates Swoole's copied PDO PostgreSQL and SQLite symbols from the native PDO drivers during static linking. It prefixes only Swoole's C symbols; PHP method names stay unchanged.
`php-builder-swoole-copy-result.patch` returns PostgreSQL COPY statuses to the caller before draining further results. Without it, a COPY input operation waits for data that its caller has not yet had a chance to send.
Both PDO patches change the derived source-cache identity and leave the downloaded archives unchanged.
`phpx-cli-script-dispatch.patch` recognizes existing regular script files before applying the default application entry, preserving bare script calls made through `PHP_BINARY`. Exact first arguments `console` and `operator` select the application entry even when same-named files exist in the current directory; use `./console`, `./operator` or `-f` to execute those files as scripts. With no arguments, piped, socket or regular-file stdin selects interpreter input; terminal and `/dev/null` input retain the default application entry.
`php-builder-qualification-bootstrap.patch` adds the qualification-only direct-evaluation bootstrap described below.
`phpx-file-access.patch` restores fresh physical existence and permission checks with `open_basedir` enforcement while delegating embedded paths and custom stream wrappers to their existing handlers. TypePHP compiles `typephp_opcode_table.cc` as a project object, so this correction is picked up by package compilation without rebuilding the cached PHPX archive.
`phpx-source-stream.patch` routes raw archived files through the existing memory-backed file wrapper so PHP's source scanners can read their bytes. Composer needs those bytes when scanning class declarations during optimized autoload generation. The previous synthetic handle returned empty source to `php_strip_whitespace`, even when `file_get_contents` returned the full archived file. Compilation still selects embedded opcodes first; the synthetic fallback remains for entries that have opcodes but no raw source. This patch also changes only the project object, not the cached PHPX archive.
`phpx-process-cache.patch` removes two optional class-name cache allocations from decoded opcodes and initializes deferred callable caches to NULL for direct storage. Those decoded objects belong to one process; allocating their cache slots without reserving the shared OPcache range let a child reuse a slot assigned by the master. Physical PHP files continue to use ordinary OPcache.
The original PHP and PECL source caches are unchanged.
The host-installed compiler stays unpatched and serves setup/version inspection.
`TYPEPHP_IMAGE` overrides the local image tag; use a distinct tag when changing the toolchain in concurrent worktrees.

## Prepare application dependencies

Use a dedicated worktree with production dependencies:

```sh
composer install --no-dev --no-scripts --prefer-dist --no-interaction
```

Keep the application's configured Composer plugins enabled: they apply its existing dependency patches and generate the Symfony runtime loader.
This command removes development dependencies from this worktree's vendor directory.
The packaging commands reject a missing runtime loader or a vendor tree installed with development dependencies.

If the host lacks the application's PHP extensions, run that install in an existing Baander application image with Composer.
Set `APP_IMAGE` to that image before running:

```sh
docker run --rm --user "$(id -u):$(id -g)" \
  --entrypoint composer --env COMPOSER_CACHE_DIR=/tmp/composer-cache \
  --volume "$PWD:/var/www/html:z" --workdir /var/www/html "${APP_IMAGE:?Set a Baander application image}" \
  install --no-dev --no-scripts --prefer-dist --no-interaction
```

This invokes Composer without starting the application or its services.
The `z` mount label supports hosts enforcing SELinux.

## Build the executable

```sh
make typephp-build
```

The only application output is `var/typephp/baander`.
The build uses an ephemeral toolchain container with the application tree mounted read-only.
The mount and embedded filenames use the application's canonical root, `/var/www/html`.
Generated files and the private runtime cache are writable under this worktree's ignored `var/typephp` directory.
`TYPEPHP_JOBS=2` is the default native compilation parallelism.
A first build downloads PHP and PECL sources and compiles its own runtime; later builds reuse that cache.

After a successful application build has populated the private runtime cache, run the focused process-cache check:

```sh
make typephp-test-process-cache
```

This builds a disposable validation executable under ignored `var/typephp/process-cache-fixture`, reusing the application configuration and cached private PHP. It removes the staged fixture source before checking embedded global-function and static-method callable constants, class/interface identity and typed calls across a fork. It checks both inherited warm callables and separate constants first resolved in the child and then in the parent after the child exits. Both CLI OPcache modes must pass 252 checks each: 240 callable results and 12 identity checks. The validation artifact is separate from the sole application output, `var/typephp/baander`.

`application.yml` is the complete project configuration. It uses `application-entry.php` because TypePHP requires its entry filename to end in `.php`.
The adapter consumes the `console` or `operator` selector, preserves the remaining arguments, and sets the selected script's CLI metadata before requiring it. Setting `SCRIPT_FILENAME` to `bin/console` lets Symfony Runtime re-enter that script directly.
The project selects the adapter explicitly as its only `sources` item; TypePHP embeds it and removes it from native translation. An empty source list would instead scan the tool directory. Both existing composition-root scripts are explicitly embedded.

`application.yml` embeds application source, production dependencies, the two local runtime packages and explicitly selected configuration, templates and translations.
It does not select `.env` files, `config/secrets`, logs, databases or the compiler's own vendor directory.
The build output must still be checked for skipped executable PHP files before package acceptance.
The initial two-artifact design built on 2026-11-19, before runtime feature qualification and the later PDO corrections, with each package containing 13,217 files and 12,191 opcode blobs.
The nine skipped PHP files were IDE-helper declarations and Swoole library copies under `vendor/swoole/ide-helper`, outside Composer's autoload paths; no application PHP files were skipped.
Those two artifacts later rebuilt with the locked runtime and PDO patches, retaining the same file, opcode and skipped-file counts. They are historical evidence; the single-artifact build must establish its own counts and runtime acceptance.

## Runtime versions and features

The compiler and PHPX are locked, and the builder image's PHP base and Composer are pinned by digest.
The private builder reads `runtime-lock.json` through `TYPEPHP_RUNTIME_LOCK`.
The lock selects PHP 8.5.12 and exact PECL releases, verifies downloaded and cached source archive hashes, and adds the application's GD, Redis and Swoole configure options.
Lock changes select new source and runtime caches.
Upstream's `php-version` setting still accepts the language version `8.5`; the local patch resolves it against the exact locked release instead of consulting the latest release or PECL stable-version endpoints.
Verify the generated PHP patch version, extension versions and enabled features before any distribution or runtime replacement.
The image's fixed PHP version alone does not establish the generated runtime's version.

The explicit extension list supplements root Composer's extension requirements, including FFI for ZEngine and PDO PostgreSQL for persistence.
It includes igbinary and msgpack because the pinned builder's static scan treats Redis's conditional dependency declarations as required inputs.
The supplemental extension list includes the application's Imagick, mailparse, Excimer, YAML and UV modules, plus mysqlnd, PDO SQLite, SQLite3 and readline.
The lock selects Swoole 6.2.4 to match the current application image; the initial packages used Swoole 6.2.6.
The GD, Redis and Swoole feature flags are recorded in [runtime-lock.json](runtime-lock.json); Swoole keeps its existing thread backend with io_uring disabled.
The private runtime loads 58 extensions. The `baander` package adds the generated `typephp_baander` module, for a total of 59.
Qualification must compare the resulting extension inventory and features with the current application image; source selection alone does not prove the generated runtime behaves correctly.

The [upstream embedding contract](https://github.com/swoole/typephp/blob/41d91fe69d2f7ec99dffe252a95244793000c6d2/docs/en/EMBEDDED_FILES.md) describes bytecode/runtime compatibility and filesystem limits.
The [PHP builder implementation](https://github.com/swoole/typephp/blob/41d91fe69d2f7ec99dffe252a95244793000c6d2/src/Build/SapiPhpBuilder.php) owns runtime construction.
TypePHP's package metadata identifies its license as `GPL-3.0-only`.

## Qualification

A successful package build is the prerequisite for runtime checks, not evidence that those checks passed.
Run the package in a disposable environment with the physical application tree at `/var/www/html`, its required native libraries and external writable state.
Do not start the deployment operator against a real Docker socket as a smoke check.

The first application argument selects `console` or `operator`. A missing or unknown selector prints usage and exits with status 2.
Native PHP options retain interpreter semantics. When using them with the application router, select that script explicitly, for example with `-f`, and separate its arguments with `--`.
For example, with the application environment already configured:

```sh
/var/www/html/var/typephp/baander console list --raw
/var/www/html/var/typephp/baander console --version
/var/www/html/var/typephp/baander operator --help
/var/www/html/var/typephp/baander -v
/var/www/html/var/typephp/baander -m
/var/www/html/var/typephp/baander -d ffi.enable=1 -d memory_limit=2048M \
  -f /var/www/html/tools/typephp/application-entry.php -- console list
```

Bare `--version` selects PHP's version.
Using `-d ... list` without `-f` treats `list` as a PHP filename.
The private runtime does not inherit the application image's INI settings.
During the initial package checks, its default 128 MiB memory limit exhausted memory during container compilation; the console listing passed with `memory_limit=2048M`, matching `docker/dev/php.ini`, and FFI enabled.
Supply deployment settings through an external INI file or explicit interpreter options.
With those settings in an external INI file, the earlier console-only default entry passed `baander list --raw`; the unified entry uses `baander console list --raw`.
The patched adapter recognizes a bare `bin/console` or another existing script filename, including in `PHP_BINARY` child processes.
The focused CLI checks verify argument boundaries, exit statuses, interpreter options, no-argument stdin and nested script dispatch.

Build the qualification image after the executable, using a CI base with the same Composer lock:

```sh
make typephp-qualification-image TYPEPHP_QUALIFICATION_BASE=baander-ci:local
export BAANDER_TEST_IMAGE=baander-typephp-qualification:local
export BAANDER_TEST_CHECKOUT_IN_IMAGE=1
export BAANDER_TEST_POSTGRES_IMAGE=baander-database:latest
bash scripts/test-backend-container.sh --no-coverage
bash scripts/test-web-runtime-container.sh
bash scripts/test-web-shutdown-container.sh
bash scripts/test-outbox-runtime-container.sh
bash scripts/test-producer-runtime-container.sh
bash scripts/test-worker-command-container.sh
BAANDER_TEST_OPERATOR_IN_CONTAINER=1 BAANDER_TEST_USE_IMAGE=0 \
  BAANDER_TEST_DOCKER_SOCKET_PATH=/var/run/docker.sock \
  bash scripts/test-worker-operator-container.sh
BAANDER_TEST_OPERATOR_IN_CONTAINER=1 BAANDER_TEST_USE_IMAGE=0 \
  BAANDER_TEST_DOCKER_SOCKET_PATH=/var/run/docker.sock \
  bash scripts/test-worker-lifecycle-container.sh
```

The image keeps the executable outside the application tree and gives it an isolated INI configuration.
`/usr/local/bin/php` is a symlink to `/usr/local/lib/typephp/baander`. Existing console and operator script invocations, including leading PHP settings, execute through that same ELF without a shell dispatcher.
The external test loader adds missing development autoload entries while preserving the embedded production mappings and Symfony Runtime's first-autoload contract.
Its generated metadata also supplies missing development package records to Composer's installed-version inventory. Without those records, the web profiler can autoload but console boot fails when it asks Composer for its package version.
The loader merges only missing records marked as development requirements, preserving production package versions and root metadata; [dump-test-support.php](dump-test-support.php) and [test-support.php](test-support.php) implement that boundary.
The qualification image sets `BAANDER_TYPEPHP_QUALIFICATION=1`. In that environment, the generated CLI adapter executes the effective `auto_prepend_file` before `-r` code; ordinary PHP skips that prepend for direct evaluation.
The hook honors `-n` by leaving configuration unloaded, while an explicit `-d auto_prepend_file=...` still selects a prepend. It preserves explicit overrides and prepend exit statuses, and stops the supplied code when the prepend exits or fails. Without the qualification marker, direct evaluation keeps upstream behavior.
The candidate INI sets `register_argc_argv=0`, matching CI. Enabling it emits a PHP 8.5 deprecation before local HTTP router code, contaminating response bodies and committing headers before redirects or media headers. Real CLI arguments remain available with this setting disabled.
Development dependencies remain outside the executable.
The candidate removes the ordinary PHP binary after generating test metadata. Baseline comparisons use the separate ordinary CI image; executable-identity checks must prove each candidate runner uses `baander`.
The paired worker drills create and remove their own Docker resources. They require the documented host resources and a controlled Docker endpoint; operator help alone does not qualify deployment behavior.
The worker-command drill keeps its disposable checkout at the configured application root. With image checkout enabled, Docker populates its named volume from the image; host checkout mode disables that population and stages the selected files explicitly. Relocating the console to `/workspace` caused its physical Composer loader to redeclare the embedded initializer.

Run the focused interpreter, filesystem and source-stream regressions after building the qualification image:

```sh
docker run --rm --network none --entrypoint php baander-typephp-qualification:local \
  tools/typephp/tests/cli.php /usr/local/lib/typephp/baander
docker run --rm --network none --entrypoint php baander-typephp-qualification:local \
  tools/typephp/tests/bootstrap.php /usr/local/lib/typephp/baander
docker run --rm --network none --entrypoint php baander-typephp-qualification:local \
  tools/typephp/tests/filesystem.php
docker run --rm --network none --entrypoint php baander-typephp-qualification:local \
  tools/typephp/tests/entrypoints.php /usr/local/lib/typephp/baander
docker run --rm --network none --entrypoint php baander-typephp-qualification:local \
  tools/typephp/tests/source-streams.php
```

The source-stream fixture compares stripped PHP tokens and highlighted output with the archived bytes, checks physical files and a custom stream wrapper, and then loads the archived enum and console factory. Its mutation mode also replaces and removes the physical enum and console files to prove that scanning and execution still use the archive.
Run that mode only in a fresh disposable container without bind mounts or shared application volumes:

```sh
docker run --rm --network none --user 0 --env BAANDER_TYPEPHP_DISPOSABLE=1 \
  --entrypoint php baander-typephp-qualification:local \
  tools/typephp/tests/source-streams.php --mutate-disposable
```

The fixture refuses mutation unless both `--mutate-disposable` and `BAANDER_TYPEPHP_DISPOSABLE=1` are set and a container marker is present. It uses external commands to alter the physical files because the archive streams are read-only; root access is needed for the vendor fixture. It attempts to restore the physical files in `finally`; the command above confines writes to the container's disposable layer.

The PDO fixture requires an explicit disposable PostgreSQL connection through `TYPEPHP_TEST_PG_DSN`, `TYPEPHP_TEST_PG_USER` and `TYPEPHP_TEST_PG_PASSWORD`. It does not infer a connection from the application's database settings.

Before consolidation into one binary, the rebuilt private runtime and packaged console passed the synchronous and coroutine PostgreSQL COPY, LOB, PID and notification checks, SQLite queries and coroutine-yield check in [the PDO fixture](tests/pdo.php).
Both earlier executables passed [the CLI regression checks](tests/cli.php), and the earlier binary-selection dispatcher passed its four executable-identity cases. The unified image replaces that dispatcher with a direct symlink; [entry-point checks](tests/entrypoints.php) verify selector behavior and common executable identity.
The console booted through its default entry and explicit script entry, listed commands and warmed the production cache. It also booted with the qualification loader after the development package metadata correction.
Production cache warmup also passed with `APP_ENV=prod`, `APP_DEBUG=0`, `BAANDER_TYPEPHP_QUALIFICATION=0` and `-d auto_prepend_file=`, disabling the external test loader.
In a disposable copied tree, ordinary PHP threw the marker inserted into the physical kernel while the packaged runtime loaded its embedded kernel, proving that the check executed embedded application code.
The focused native tests passed: [ContextualDoctrineRegistryTest](../../tests/Unit/Shared/Infrastructure/Swoole/ContextualDoctrineRegistryTest.php) ran 2 tests and 16 assertions, and [CoroutineSafeLoggingTest](../../tests/Unit/Shared/Infrastructure/Swoole/CoroutineSafeLoggingTest.php) ran 2 tests and 6 assertions.
Both earlier executables passed all 11 [qualification bootstrap checks](tests/bootstrap.php), including explicit prepend exits and failure statuses, and all 142 [filesystem checks](tests/filesystem.php). Embedded-file and directory access and kernel autoload also passed after removing the physical source tree in a disposable container.
The initial full run used the earlier candidate: its ordinary lane ran 8,487 tests and 49,813 assertions with 2 errors and 12 failures; its native lane ran 948 tests and 26,410 assertions with 43 errors, 3 failures and 1 warning. Investigation traced those failures to the stdin, direct-evaluation loader, HTTP INI and filesystem boundaries described above.
The remaining database lanes were stopped because that candidate had been superseded. A focused rerun of ten affected files passed 71 tests and 5,315 assertions, including ZEngine, isolated heap checks, worker subprocesses, socket cleanup and progressive media publication.
The next full run executed 13,247 tests with 145,806 assertions and two failures. Both came from child-process retry fixtures leaving committed job-monitor rows behind; ordinary CI reproduced the same failures. Those fixtures now assign and clean up their own job IDs, with assertions that the child records the intended ID and status. Both affected controller files pass under the repaired candidate in transport-before-monitor order: 21 tests and 201 assertions. A further two-artifact run passed its ordinary and native lanes before the user requested one binary; it was stopped during database setup. Those results do not qualify the unified artifact.

The first unified build contained 13,218 files and 12,192 opcode blobs, with the same nine IDE-helper skips and no application PHP skipped. It passed all 106 entry-point assertions, the interpreter regressions, 11 bootstrap cases, 142 filesystem checks and the PostgreSQL/SQLite fixture. Both selectors continued to work after replacing the physical entry scripts with throwing markers in a disposable container, proving that they execute the embedded copies. Production cache warmup passed with the external test loader disabled. These results belong to that earlier artifact; the roadmap records each build identity and its evidence.

The operator-derived image subsequently failed its unchanged `composer dump-autoload --dev --optimize --no-interaction --no-scripts --no-plugins` command because the source scanner received an empty stream. The rebuilt executable with `phpx-source-stream.patch` passed that command with and without the qualification prepend. It also passed 40 source-stream checks, expanded to 76 with physical copies changed and removed, and repeated the interpreter, 11 bootstrap, 142 filesystem and 106 entry-point checks successfully. The PostgreSQL/SQLite fixture also passed on that artifact, including synchronous and coroutine operations and scheduler yielding. Before the storage-boundary correction, the full run completed 13,247 tests with 145,839 assertions and two audio handoff errors. Deterministic probes reproduce the storage-boundary creation/rename race in ordinary PHP as well as the candidate. The boundary now retries canonical resolution once, keeping the existing containment and symlink checks. The subsequent storage-boundary build passed 284 focused boundary and direct-consumer tests with 718 assertions and the complete audio handoff file with six tests and 73 assertions. The three changed PHP files passed targeted PHPStan level 6. That artifact also passed the outbox, producer and web runtime drills, but failed the web shutdown drill. These checks do not qualify the current rebuild.

The shutdown failure exposed the decoded class-cache collision described above: a shared parent-class lookup resolved to an unrelated interface after a child reused its slot. The decoder rebuild included `phpx-process-cache.patch`, retained 13,218 files and 12,192 opcode blobs with nine IDE-helper skips, and passed the process-cache fixture with the staged source removed: 252 checks with CLI OPcache disabled and 252 with it enabled. The patch sequence applies with zero fuzz, and the decoder passes syntax checking against the matching PHP headers. Shared CLI OPcache remains enabled in the canonical qualification INI. On that executable, the interpreter checks, 11 bootstrap cases, 142 filesystem checks, 106 entry-point assertions and 40 source-stream checks passed; source mutation mode passed 76 checks. The focused boundary and direct-consumer tests passed with 284 tests and 718 assertions. In a network-isolated disposable container, both selectors and production cache warmup also passed with shared CLI OPcache enabled, `APP_ENV=prod`, `APP_DEBUG=0` and the external prepend disabled, after the physical entry scripts are replaced with throwing markers. These checks established the embedded entry paths and production loader for that build. The roadmap retains its exact executable and image identities.

That decoder build failed the complete shutdown drill at the stalled-client case after 139.1 seconds; the stop-command and preceding cases passed. Native tracing shows cancellation resuming a sender blocked in `Response::write()`, then waiting 5.638 seconds while the audio generator keeps reading and yielding chunks before reaching `Async::sleep()`. Dropping further output in the buffer callback does not stop that producer. A temporary post-yield cancellation checkpoint passes a real blocked-write probe in both ordinary PHP and the candidate: each unchecked generator produces 981 extra 64 KiB chunks, while the checked generator produces none, releases its request/stream state and leaks no media payload to stdout or stderr. The probe uses a representative generator, not `AudioRenditionCache`. The application now calls `Async::throwIfCanceled()` immediately after the yield in `AudioRenditionCache::follow()`, before another lookup or read. Its actual-generator regression checks that cancellation closes the exact file handle and prevents further lookups or chunks. The complete audio-cache file passes 23 tests and 79 assertions in both ordinary PHP and the candidate after correcting its fixed-size read assumption: application reads return up to 64 KiB, while the synthetic probe deliberately yields fixed 64 KiB chunks.

The shutdown-qualified application executable has SHA-256 `fbef8d92218d62ec93dfa774de5879e170609f782667851b3ab3d247261d7452` and keeps the same file/blob counts. It passes 319 focused tests with 865 assertions, the interpreter checks, 11 bootstrap cases, 142 filesystem checks, 106 entry-point assertions, 40 source-stream checks and 76 source-mutation checks. The unchanged full shutdown drill passes in 212.7 seconds, including the formerly failing stalled client in 4.3 seconds, drain readiness/liveness, stopped-worker and manager watchdog handling, partial transfers, descendant cleanup and PostgreSQL-outage cases. Both selectors and production cache warmup also pass in a network-isolated disposable container with `APP_ENV=prod`, `APP_DEBUG=0`, the external prepend disabled and shared CLI OPcache enabled, after both physical entry scripts are replaced with throwing markers. The synchronous and coroutine PostgreSQL/SQLite fixture also passes, including COPY, large objects, backend PID, notifications, transactions and scheduler yielding. The final backend gate passes with four workers and coverage disabled: 13,257 tests and 145,892 assertions across six lanes, with zero errors, failures or skipped tests. All expected test identities match the observed results, and owned-resource cleanup passes. Executable inspection records 26 representative category/lane captures matching the same ELF: the discovery runner and its PHPUnit child, plus each lane's runner, ParaTest, PHPUnit wrapper and a nested `PHP_BINARY` child. These captures establish representative process identity, not observation of every child.

The same artifact passes the outbox drill in 40.5 seconds, producer drill in 35.5 seconds, web runtime drill in 94.6 seconds and worker-command drill in 470.6 seconds. The unchanged operator drill passes in 165.7 seconds and the two-phase lifecycle drill in 512.2 seconds. Executable inspection verifies five boot groups: the operator boot and the predecessor/successor boots in each Redis and PostgreSQL phase. Each group has an observed operator process, control and execution worker processes, and an explicit `baander operator status` invocation matching the current ELF hash. Lifecycle proofs match the fixture's exact predecessor/successor container IDs, and both phases verify cleanup.

Static checks use the ordinary CI interpreter against the final source and locked dependencies. Composer validation and PHPStan pass; Deptrac reports zero violations with 452 existing baseline skips. The Composer normalization check exits 1 because the existing root `composer.json` orders its script entries differently from the normalizer. The root Composer files are unchanged from `9542816c9`; normalization remains a separate pre-existing metadata issue.

The documented Linux/PHP 8.5.12 ZTS profile now passes application qualification, including kernel/container boot, ZEngine source reflection and mutation, database-backed HTTP, worker processing, coroutine isolation, drain/reload and the independent operator path. The matching physical resource tree, native libraries and external writable state remain required. Repeat the backend suites and runtime drills in the [testing guide](../../docs-book/part-2-developer-guide/testing/README.md) when changing the packaged application or runtime. The [roadmap](../../ROADMAP.md) records the results and remaining limits.
