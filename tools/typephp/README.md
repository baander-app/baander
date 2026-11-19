# TypePHP application packaging

This setup packages Baander's PHP application into CLI executables that run embedded PHP bytecode through ZendVM.
The console package serves the existing HTTP server, worker and administration commands; the operator package keeps its separate entry point.
Keep the physical application tree and the existing PHP runtime during qualification.
Symfony scans resource directories, the coroutine container reads PHP source, and some subprocesses still invoke ordinary PHP.

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
It applies two patches to the locked compiler before use.
`php-builder-object-directories.patch` creates the object directories named by PHP's generated Makefile; igbinary's nested output paths were missing in the unpatched separate-directory build.
`php-builder-swoole-sources.patch` gives the copied Swoole configuration an absolute source path before `buildconf` and changes the derived-source cache identity.
Without it, the generated Makefile omitted Swoole's main sources and linking failed on `swoole_module_entry` and coroutine symbols.
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
  --volume "$PWD:/app:z" --workdir /app "${APP_IMAGE:?Set a Baander application image}" \
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
Both packages built on 2026-11-19, each containing 13,217 files and 12,191 opcode blobs.
The nine skipped PHP files were IDE-helper declarations and Swoole library copies under `vendor/swoole/ide-helper`, outside Composer's autoload paths; no application PHP files were skipped.

## Runtime and reproducibility limits

The compiler and PHPX are locked, and the builder image's PHP base and Composer are pinned by digest.
The private runtime is not yet a fully pinned release recipe.
The `php-version` setting accepts the language version `8.5`, not a patch version such as `8.5.12`; upstream selects a cached or current patch from that branch.
Its PECL resolver also reads each extension's current stable version.
Verify the generated PHP patch version, extension versions and enabled features before any distribution or runtime replacement.
The image's fixed PHP version alone does not establish the generated runtime's version.

The explicit extension list supplements root Composer's extension requirements, including FFI for ZEngine and PDO PostgreSQL for persistence.
It includes igbinary and msgpack because the pinned builder's static scan treats Redis's conditional dependency declarations as required inputs.
Extension presence does not establish feature parity: the current application image enables additional Swoole, Redis and GD options that this builder does not expose as arbitrary configuration flags.

The [upstream embedding contract](https://github.com/swoole/typephp/blob/41d91fe69d2f7ec99dffe252a95244793000c6d2/docs/en/EMBEDDED_FILES.md) describes bytecode/runtime compatibility and filesystem limits.
The [PHP builder implementation](https://github.com/swoole/typephp/blob/41d91fe69d2f7ec99dffe252a95244793000c6d2/src/Build/SapiPhpBuilder.php) owns runtime construction.
TypePHP's package metadata identifies its license as `GPL-3.0-only`.

## Qualification

A successful package build is the prerequisite for runtime checks, not evidence that those checks passed.
Run the package in a disposable environment with the physical application tree at `/app`, its required native libraries and external writable state.
Do not start the deployment operator against a real Docker socket as a smoke check.

The packaged CLI handles interpreter options before application arguments.
When supplying PHP options, select the script with `-f` and separate its arguments with `--`.
For example, with the application environment already configured:

```sh
/app/var/typephp/baander -d ffi.enable=1 -d memory_limit=2048M -f /app/bin/console -- list
/app/var/typephp/baander -v
/app/var/typephp/baander -m
/app/var/typephp/baander -d ffi.enable=1 -d memory_limit=2048M -f /app/bin/console -- --version
/app/var/typephp/baander-operator -f /app/bin/worker-deployment.php -- --help
```

Bare `--version` selects PHP's version.
Using `-d ... list` without `-f` treats `list` as a PHP filename.
The private runtime does not inherit the application image's INI settings.
Its default 128 MiB memory limit exhausted memory during container compilation; the console listing passed with `memory_limit=2048M`, matching `docker/dev/php.ini`, and FFI enabled.
Supply deployment settings through an external INI file or explicit interpreter options.
With those settings in an external INI file, the default entry also passed `baander list --raw`.
Inventory and test `PHP_BINARY` child invocations: a bare `bin/console` argument can be interpreted as an application argument after the default entry is prepended.
Keep existing ordinary-PHP subprocess paths until this behavior is resolved.

Application acceptance requires kernel/container boot, ZEngine source reflection and mutation, a database-backed HTTP request, worker message processing, coroutine isolation, drain/reload and the independent operator path.
Then run the backend suites and runtime drills named in the [testing guide](../../docs-book/part-2-developer-guide/testing/README.md) against the candidate image.
The [roadmap](../../ROADMAP.md) records the observed setup and build results and remaining acceptance.
