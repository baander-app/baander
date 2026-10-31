---
title: Registry coroutine work must preserve the harness signal reactor
date: 2026-10-31
category: runtime-errors
module: Registry qualification harness
problem_type: runtime_error
component: infrastructure
symptoms:
  - Load assertions passed, but the PHP process exited with status 139 during teardown.
  - The crash reproduced in native NTS PHP without Xdebug.
root_cause: wrong_api
resolution_type: code_fix
severity: high
framework_version: "PHP 8.5.12, Swoole 6.2.4"
tags: [swoole, registry, reactor, signals, teardown, native-tests]
---

# Registry coroutine work must preserve the harness signal reactor

## Problem

During the PHP runtime refresh, the registry release suite completed its load
assertions and then exited with status 139. The passing load report described the
workload; it did not establish that the PHP process could finish cleanup.

The harness registered signal handlers before running coroutine work. In the
reproduction, using `Swoole\Coroutine\run()` inside that lifetime and then
unregistering the handlers crashed. Keeping the existing reactor alive with
`Swoole\Coroutine::create()` and `Swoole\Event::dispatch()` let the process return
normally. This establishes the failing lifetime sequence for the tested runtime;
it does not identify an upstream C-level defect or a failure in every use of
`Swoole\Coroutine\run()`.

## What did not explain it

An earlier backend test failure also produced native child-process crashes, but
those probes failed with Xdebug coverage enabled. Disabling coverage only in those
children resolved that failure while retaining coverage in the PHPUnit parent.
That boundary is explicit in `tests/Fixtures/Swoole/CoroutineProbe.php:15`.

The registry crash reproduced in NTS PHP without Xdebug. Reusing the coverage
workaround could not repair it. Treating every native crash during the upgrade as
one incompatibility would have hidden the separate teardown failure.

## Solution

The standalone harness keeps signal-reactor ownership in `Runtime::main()`.
Coroutine work runs through `Runtime::runCoroutines()`, which requires an idle
coroutine scope, saves the hook flags, creates the task, and dispatches the existing
reactor. It cancels and drains remaining coroutines on failure and restores the
caller’s hooks before returning (`relay/tests/Support/Runtime.php:201`).

Signal callbacks record the signal. The harness raises the interruption after
event dispatch returns to PHP, allowing task cleanup to run. The outer scope then
unregisters its handlers and performs the final event wait when no timers remain
(`relay/tests/Support/Runtime.php:271`, `relay/tests/Support/Runtime.php:291`).

The load driver and observer use this shared scope. This is a contract for the
standalone registry harness, not a replacement for coroutine entry points inside
the application web server.

## Verification and prevention

The regression driver checks four child-process outcomes: success, task failure,
SIGTERM, and deadline expiry. It checks root and descendant cleanup, restored
hooks, no remaining timers or coroutines, child reaping, and the final exit status.
Only success may print the marker after `Runtime::main()` returns
(`relay/tests/test_runtime_lifecycle.php:14`,
`relay/tests/test_runtime_lifecycle.php:61`).

The repaired driver passed all four scenarios. The registry release and sanitizer
gates subsequently completed successfully on the upgraded runtime. Thread and fuzz
qualification were not established by this evidence.

When a future native upgrade produces a similar crash, retain both the last
successful assertion and the process outcome. Reproduce with and without coverage
before selecting a workaround, then isolate reactor creation, task completion,
signal removal, and process exit. A passing workload report cannot substitute for
the final exit status and cleanup result.

The [web-server shutdown investigation](swoole-sigterm-waits-for-master-timers-and-closes-connections-before-workers-stop.md)
documents a different ownership boundary: master timers and client connections in
Swoole process mode.
