# TypePHP application packaging

This setup packages Baander's PHP application into CLI executables that run embedded PHP bytecode through ZendVM.
The console package serves the existing HTTP server, worker and administration commands; the operator package keeps its separate entry point.
Keep the physical application tree and the existing PHP runtime during qualification.
Symfony scans resource directories, and the coroutine container reads PHP source.

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
`phpx-cli-script-dispatch.patch` recognizes existing regular script files before applying the default application entry, preserving bare script calls made through `PHP_BINARY`. With no arguments, piped, socket or regular-file stdin selects interpreter input; terminal and `/dev/null` input retain the default application entry.
`php-builder-qualification-bootstrap.patch` adds the qualification-only direct-evaluation bootstrap described below.
`phpx-file-access.patch` restores fresh physical existence and permission checks with `open_basedir` enforcement while delegating embedded paths and custom stream wrappers to their existing handlers. TypePHP compiles `typephp_opcode_table.cc` as a project object, so this correction is picked up by package compilation without rebuilding the cached PHPX archive.
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

## Build the packages

```sh
make typephp-build
make typephp-build-operator
```

The outputs are `var/typephp/baander` and `var/typephp/baander-operator`.
Each build uses an ephemeral toolchain container with the application tree mounted read-only.
The mount and embedded filenames use the application's canonical root, `/var/www/html`.
Generated files and the private runtime cache are writable under this worktree's ignored `var/typephp` directory.
`TYPEPHP_JOBS=2` is the default native compilation parallelism.
A first build downloads PHP and PECL sources and compiles its own runtime; later builds reuse that cache.

`console.yml` uses `console-entry.php` because TypePHP requires its entry filename to end in `.php`.
The adapter returns the existing console script's value so Symfony Runtime can re-enter it.
Both projects select their entry explicitly as their only `sources` item; TypePHP embeds that entry and removes it from native translation.
An empty source list would instead scan the tool directory.

`application.yml` embeds application source, production dependencies, the two local runtime packages and explicitly selected configuration, templates and translations.
It does not select `.env` files, `config/secrets`, logs, databases or the compiler's own vendor directory.
The build output must still be checked for skipped executable PHP files before package acceptance.
The initial packages built on 2026-11-19, before runtime feature qualification and the later PDO corrections, each containing 13,217 files and 12,191 opcode blobs.
The nine skipped PHP files were IDE-helper declarations and Swoole library copies under `vendor/swoole/ide-helper`, outside Composer's autoload paths; no application PHP files were skipped.
Both packages have since rebuilt with the locked runtime and PDO patches, retaining the same file, opcode and skipped-file counts.

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
The private runtime loads 58 extensions. The console package loads one additional generated module, `typephp_baander`, for a total of 59.
Qualification must compare the resulting extension inventory and features with the current application image; source selection alone does not prove the generated runtime behaves correctly.

The [upstream embedding contract](https://github.com/swoole/typephp/blob/41d91fe69d2f7ec99dffe252a95244793000c6d2/docs/en/EMBEDDED_FILES.md) describes bytecode/runtime compatibility and filesystem limits.
The [PHP builder implementation](https://github.com/swoole/typephp/blob/41d91fe69d2f7ec99dffe252a95244793000c6d2/src/Build/SapiPhpBuilder.php) owns runtime construction.
TypePHP's package metadata identifies its license as `GPL-3.0-only`.

## Qualification

A successful package build is the prerequisite for runtime checks, not evidence that those checks passed.
Run the package in a disposable environment with the physical application tree at `/var/www/html`, its required native libraries and external writable state.
Do not start the deployment operator against a real Docker socket as a smoke check.

The packaged CLI handles interpreter options before application arguments.
When supplying PHP options, select the script with `-f` and separate its arguments with `--`.
For example, with the application environment already configured:

```sh
/var/www/html/var/typephp/baander -d ffi.enable=1 -d memory_limit=2048M -f /var/www/html/bin/console -- list
/var/www/html/var/typephp/baander -v
/var/www/html/var/typephp/baander -m
/var/www/html/var/typephp/baander -d ffi.enable=1 -d memory_limit=2048M -f /var/www/html/bin/console -- --version
/var/www/html/var/typephp/baander-operator -f /var/www/html/bin/worker-deployment.php -- --help
```

Bare `--version` selects PHP's version.
Using `-d ... list` without `-f` treats `list` as a PHP filename.
The private runtime does not inherit the application image's INI settings.
During the initial package checks, its default 128 MiB memory limit exhausted memory during container compilation; the console listing passed with `memory_limit=2048M`, matching `docker/dev/php.ini`, and FFI enabled.
Supply deployment settings through an external INI file or explicit interpreter options.
With those settings in an external INI file, the initial package's default entry also passed `baander list --raw`.
The patched adapter recognizes a bare `bin/console` or another existing script filename, including in `PHP_BINARY` child processes.
The focused CLI checks verify argument boundaries, exit statuses, interpreter options, no-argument stdin and nested script dispatch.

Build the qualification image after both executables, using a CI base with the same Composer lock:

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

The image keeps executables outside the application tree and gives them an isolated INI configuration.
Its `php` dispatcher selects the operator executable for operator scripts, including invocations with leading PHP settings, and selects the console executable for other scripts.
The external test loader adds missing development autoload entries while preserving the embedded production mappings and Symfony Runtime's first-autoload contract.
Its generated metadata also supplies missing development package records to Composer's installed-version inventory. Without those records, the web profiler can autoload but console boot fails when it asks Composer for its package version.
The loader merges only missing records marked as development requirements, preserving production package versions and root metadata; [dump-test-support.php](dump-test-support.php) and [test-support.php](test-support.php) implement that boundary.
The qualification image sets `BAANDER_TYPEPHP_QUALIFICATION=1`. In that environment, the generated CLI adapter executes the effective `auto_prepend_file` before `-r` code; ordinary PHP skips that prepend for direct evaluation.
The hook honors `-n` by leaving configuration unloaded, while an explicit `-d auto_prepend_file=...` still selects a prepend. It preserves explicit overrides and prepend exit statuses, and stops the supplied code when the prepend exits or fails. Without the qualification marker, direct evaluation keeps upstream behavior.
The candidate INI sets `register_argc_argv=0`, matching CI. Enabling it emits a PHP 8.5 deprecation before local HTTP router code, contaminating response bodies and committing headers before redirects or media headers. Real CLI arguments remain available with this setting disabled.
Development dependencies remain outside both packaged executables.
The ordinary CI PHP is retained for diagnostics; executable-identity checks must prove each runner uses the packaged runtime.
The paired worker drills create and remove their own Docker resources. They require the documented host resources and a controlled Docker endpoint; operator help alone does not qualify deployment behavior.
The worker-command drill keeps its disposable checkout at the configured application root. With image checkout enabled, Docker populates its named volume from the image; host checkout mode disables that population and stages the selected files explicitly. Relocating the console to `/workspace` caused its physical Composer loader to redeclare the embedded initializer.

Run the focused interpreter and filesystem regressions after building the qualification image:

```sh
for typephp_executable in baander baander-operator; do
  docker run --rm --network none --entrypoint php baander-typephp-qualification:local \
    tools/typephp/tests/cli.php "/usr/local/lib/typephp/$typephp_executable"
  docker run --rm --network none --entrypoint php baander-typephp-qualification:local \
    tools/typephp/tests/bootstrap.php "/usr/local/lib/typephp/$typephp_executable"
  docker run --rm --network none \
    --entrypoint "/usr/local/lib/typephp/$typephp_executable" baander-typephp-qualification:local \
    tools/typephp/tests/filesystem.php
done
docker run --rm --network none --entrypoint php baander-typephp-qualification:local \
  tools/typephp/tests/dispatch.php
```

The PDO fixture requires an explicit disposable PostgreSQL connection through `TYPEPHP_TEST_PG_DSN`, `TYPEPHP_TEST_PG_USER` and `TYPEPHP_TEST_PG_PASSWORD`. It does not infer a connection from the application's database settings.

The rebuilt private runtime and final packaged console passed the synchronous and coroutine PostgreSQL COPY, LOB, PID and notification checks, SQLite queries and coroutine-yield check in [the PDO fixture](tests/pdo.php).
Both final executables passed [the CLI regression checks](tests/cli.php); [the dispatcher checks](tests/dispatch.php) passed all four executable-identity cases.
The console booted through its default entry and explicit script entry, listed commands and warmed the production cache. It also booted with the qualification loader after the development package metadata correction.
Production cache warmup also passed with `APP_ENV=prod`, `APP_DEBUG=0`, `BAANDER_TYPEPHP_QUALIFICATION=0` and `-d auto_prepend_file=`, disabling the external test loader.
In a disposable copied tree, ordinary PHP threw the marker inserted into the physical kernel while the packaged runtime loaded its embedded kernel, proving that the check executed embedded application code.
The focused native tests passed: [ContextualDoctrineRegistryTest](../../tests/Unit/Shared/Infrastructure/Swoole/ContextualDoctrineRegistryTest.php) ran 2 tests and 16 assertions, and [CoroutineSafeLoggingTest](../../tests/Unit/Shared/Infrastructure/Swoole/CoroutineSafeLoggingTest.php) ran 2 tests and 6 assertions.
Both executables passed all 11 [qualification bootstrap checks](tests/bootstrap.php), including explicit prepend exits and failure statuses, and all 142 [filesystem checks](tests/filesystem.php). Embedded-file and directory access and kernel autoload also passed after removing the physical source tree in a disposable container.
The initial full run used the earlier candidate: its ordinary lane ran 8,487 tests and 49,813 assertions with 2 errors and 12 failures; its native lane ran 948 tests and 26,410 assertions with 43 errors, 3 failures and 1 warning. Investigation traced those failures to the stdin, direct-evaluation loader, HTTP INI and filesystem boundaries described above.
The remaining database lanes were stopped because that candidate had been superseded. A focused rerun of ten affected files passed 71 tests and 5,315 assertions, including ZEngine, isolated heap checks, worker subprocesses, socket cleanup and progressive media publication.
The next full run executed 13,247 tests with 145,806 assertions and two failures. Both came from child-process retry fixtures leaving committed job-monitor rows behind; ordinary CI reproduced the same failures. Those fixtures now assign and clean up their own job IDs, with assertions that the child records the intended ID and status. Both affected controller files pass under the repaired candidate in transport-before-monitor order: 21 tests and 201 assertions. A fresh full backend gate and the remaining runtime drills are pending.

Application acceptance requires kernel/container boot, ZEngine source reflection and mutation, a database-backed HTTP request, worker message processing, coroutine isolation, drain/reload and the independent operator path.
Then run the backend suites and runtime drills named in the [testing guide](../../docs-book/part-2-developer-guide/testing/README.md) against the candidate image.
The [roadmap](../../ROADMAP.md) records the observed setup and build results and remaining acceptance.
