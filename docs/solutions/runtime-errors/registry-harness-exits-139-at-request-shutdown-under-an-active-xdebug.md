---
title: The registry harness exits 139 at request shutdown under an active Xdebug
date: 2026-11-27
category: runtime-errors
module: Registry qualification harness
problem_type: runtime_error
component: infrastructure
symptoms:
  - "A harness child exits 139 after printing `runtime-returned`, and the lifecycle driver stops with `success: unexpected lifecycle exit 139`"
  - "Every lifecycle case crashes in the ZTS CI gate image, so the crash was recorded as a limitation of that image"
  - "`ini_get('xdebug.mode')` still reports `debug,develop` while `XDEBUG_MODE=off` is set in the environment"
root_cause: config_error
resolution_type: code_fix
severity: medium
framework_version: "PHP 8.5.12 ZTS, Swoole 6.2.4, Xdebug 3.5.0"
retire_when: "An Xdebug or Swoole release states a fix for Xdebug faulting in its observer end handlers at request shutdown after Swoole coroutines ran; check the Xdebug changelog and the Swoole release notes"
tags: [xdebug, swoole, registry, segfault, exit-139, zts, request-shutdown, native-tests]
---

# The registry harness exits 139 at request shutdown under an active Xdebug

## Problem

The registry qualification harness crashed in the local ZTS CI gate image
`baander-ci-gate:local` (PHP 8.5.12 ZTS, Swoole 6.2.4, Xdebug 3.5.0 with
`xdebug.mode=debug,develop`). Only that image crashed, so the gap was recorded as "the
ZTS CI gate image cannot run the registry harness". The cause is the active Xdebug, not
ZTS.

## Symptoms

- `bash scripts/test-registry.sh release` stopped at `relay/tests/test_runtime_lifecycle.php`
  with `success: unexpected lifecycle exit 139`. The success child had already printed
  `runtime-returned`, the last line it writes after `Runtime::main()` returns
  (`relay/tests/test_runtime_lifecycle.php:68`), so the work and the harness cleanup had
  finished before the crash.
- Every lifecycle case exited 139, not only success.
- CI, which runs the harness without Xdebug, kept passing.

## What Didn't Work

- **Blaming ZTS.** The gate image is a ZTS build and the crash appeared nowhere else, so
  ZTS took the blame. Varying Xdebug in that same image settled it. Every lifecycle case
  exits 139 with `XDEBUG_MODE` set to `develop`, `debug`, `debug,develop` or `coverage`,
  and passes with `XDEBUG_MODE=off` or with the Xdebug ini removed. With
  `XDEBUG_MODE=off`, the whole `scripts/test-registry.sh release` passes in the ZTS image.
  NTS PHP with Xdebug was not tried, so the evidence shows that ZTS alone does not crash,
  not that NTS with Xdebug is safe.
- **Reaching for the reactor diagnosis.** The
  [signal-reactor learning](registry-coroutine-work-must-preserve-the-signal-reactor.md)
  records an earlier 139 at teardown in this harness. Its fix,
  `Runtime::runCoroutines()`, was already in place, and the harness passes as soon as
  Xdebug is off, so reactor ownership did not cause this crash.
- **Treating Xdebug as a coverage-only hazard.** `tests/Fixtures/Swoole/CoroutineProbe.php:15-17`
  turns Xdebug off in its Swoole children, and its comment names coverage. Here `debug`
  and `develop` crash too. Dropping coverage alone does not help; the mode must be `off`.
- **Checking `ini_get('xdebug.mode')`.** `XDEBUG_MODE` in the environment overrides the
  ini setting, but `ini_get()` keeps returning the ini value. With `XDEBUG_MODE=off` set,
  it still reports `debug,develop`, so a guard built on it refuses a correctly configured
  run. `xdebug_info('mode')` returns the modes in effect.
- **Refusing in `relay/tests/bootstrap.php`** (rejected, not tried). That file already
  checks PHP 8.5 and Swoole and exits on failure, which makes it look like the natural
  home. Tooling PHPUnit tests load it in-process, though
  (`packages/baander-dev-registry/tests/BackupContractTest.php:11`,
  `packages/baander-dev-registry/tests/EnrollmentIssuerTest.php:11`,
  `packages/baander-dev-registry/tests/RegistryQualificationTest.php:223`), and an `exit`
  there under an active Xdebug would end the whole PHPUnit run.

## Solution

Fixed on master by 286614af9 ("fix(registry): build on GCC 16 and refuse the harness under
an active Xdebug").

`Runtime::main()` refuses to start while any Xdebug mode is active. The check runs right
after the PHP and Swoole baseline (`relay/tests/Support/Runtime.php:25-37`, called at
`:330`):

```php
private static function requireXdebugOff(): void
{
    $modes = extension_loaded('xdebug') ? xdebug_info('mode') : [];
    self::requireThat(
        $modes === [],
        'Registry harnesses cannot run with Xdebug active (mode ' . implode(',', $modes) . '); set XDEBUG_MODE=off.',
    );
}
```

In the gate image the lifecycle driver now exits 1 with `Registry harnesses cannot run
with Xdebug active (mode debug,develop); set XDEBUG_MODE=off.`

`main()` removes its SIGINT and SIGTERM handlers only after it has registered them
(`$signals`, `relay/tests/Support/Runtime.php:327`, `:337`, `:345-351`). The `finally`
block used to remove them whenever Swoole was loaded, registered or not. Once the refusal
could throw before registration, Swoole printed "unable to find the callback" warnings
beside it.

CI was not affected. The registry jobs, such as `registry-quality`
(`.github/workflows/ci.yaml:668-704`), run in the `baander-runner` image, and
`docker/runner/Dockerfile` installs no Xdebug. The tooling PHPUnit steps that load the
harness bootstrap pass `-e XDEBUG_MODE=off` (`.github/workflows/ci.yaml:312-315`). `relay/README.md:64-66` states the requirement.

The `baander-dev-registry` tooling tests also pass in the gate image with
`XDEBUG_MODE=off` (80 tests, 1 skipped).

## Why This Works

gdb places the fault in `xdebug.so`, called from `zend_observer_fcall_end_all` during
`php_request_shutdown`. It needs an active Xdebug, and the guard's docblock and the relay
README tie it to a process that has run Swoole coroutines
(`relay/tests/Support/Runtime.php:25-29`, `relay/README.md:64-66`). Every mode tried
crashes, so the fault is not tied to coverage collection. Whether Xdebug or Swoole leaves
the state that the end handlers fault on was not established.

The refusal turns a crash after a complete run into an immediate failure that names the
fix. Reading `xdebug_info('mode')` sees the effective modes, so an image that loads
Xdebug runs the harness as soon as `XDEBUG_MODE=off` is set.

## Prevention

When a registry harness, or another standalone PHP process that runs Swoole coroutines,
exits 139, check the two known causes in this order:

1. **Xdebug first; it is cheap to rule out.** In the failing run's environment, print the
   effective modes:
   `php -r 'var_dump(extension_loaded("xdebug") ? xdebug_info("mode") : []);'`. If any
   mode is listed, rerun with `XDEBUG_MODE=off`. Drivers that go through
   `Runtime::main()` now refuse instead of crashing. A crash of this kind can still come
   from code that loads `relay/tests/bootstrap.php` without `main()`, such as the tooling
   PHPUnit tests and their fixtures, or from another Swoole CLI process.
2. **Reactor ownership.** If the crash persists while `xdebug_info('mode')` is empty,
   follow the [signal-reactor learning](registry-coroutine-work-must-preserve-the-signal-reactor.md)
   and isolate reactor creation, task completion, signal removal and process exit.

- Do not record a native crash as a thread-model or image limitation until it
  reproduces with Xdebug off.
- Confirm that Xdebug is off with `xdebug_info('mode')`, never with
  `ini_get('xdebug.mode')`.
- Keep the refusal in `Runtime::main()`. In `relay/tests/bootstrap.php` it would end
  every PHPUnit run that loads the bootstrap under an active Xdebug.
- Coverage runs keep Xdebug active: `docker/dev/entrypoint.sh:4-6` disables it because
  it "conflicts with Swoole coroutines", except when `XDEBUG_MODE=coverage` is set. Start
  Swoole children of a coverage run with `XDEBUG_MODE=off`, as `CoroutineProbe` does.

## Related Issues

- [Registry coroutine work must preserve the harness signal reactor](registry-coroutine-work-must-preserve-the-signal-reactor.md):
  the other cause of a 139 at teardown in this harness, reproduced without Xdebug.
- [Coverage runs push the stack lanes past the backend budget](../developer-experience/coverage-runs-push-the-stack-lanes-past-the-backend-budget.md):
  a lane log's `Runtime:` line shows whether that run loaded Xdebug.
