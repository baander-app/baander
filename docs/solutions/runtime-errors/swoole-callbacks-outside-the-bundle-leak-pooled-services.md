---
title: Swoole callbacks the bundle does not run leak pooled services unless they call CoWrapper::defer()
date: 2026-10-09
category: runtime-errors
module: Shared Swoole infrastructure (server control channel)
problem_type: runtime_error
component: infrastructure
symptoms:
  - "Each server control operation that used a pooled service, such as the EntityManager, kept its pool slot after it finished"
  - "Once a worker's pool for that service is full, every coroutine in the worker that asks for it waits forever"
root_cause: memory_leak
resolution_type: code_fix
severity: high
tags: [swoole, coroutine, service-pool, cowrapper, entity-manager, control-channel, resource-leak]
---

# Swoole callbacks the bundle does not run leak pooled services unless they call CoWrapper::defer()

## Problem

With coroutines enabled, the swoole bundle gives each coroutine its own instance of every stateful service (the EntityManager, and services tagged `kernel.reset`). It takes the instance from a per-worker pool and gives it back when the coroutine ends, but only for coroutines the bundle knows about. The server control channel's callbacks (`ControlSocketConfigurator`'s `Receive` listener and `ControlPipeMessageHandler` on `PipeMessage`) run in coroutines that Swoole starts directly. Any pooled service an operation used there was never given back.

## Symptoms

- No error at the time. Each control request that touched a pooled service, for example the EntityManager, left one pool slot taken for good.
- The failure comes later. `BaseServicePool::get()` waits on a mutex once the pool is at its limit (`packages/swoole-bundle/src/Bridge/Symfony/Container/ServicePool/BaseServicePool.php:59-73`). After enough leaked slots, every request in that worker that needs the service hangs.
- The EntityManager pool is small. `doctrine_processor_config.global_limit: 10` (`config/packages/swoole.yaml:121`) sets the pool limit of each Doctrine connection, and each EntityManager takes its connection's limit (`packages/swoole-bundle/src/Bridge/Doctrine/DoctrineProcessor.php:57-61, 113-123`). Other pooled services use `max_service_instances: 2500` (`swoole.yaml:116`).

## What Didn't Work

- Expecting a later request to clean up. A slot is released by coroutine ID (`releaseFromCoroutine($cId)`), and Swoole hands out coroutine IDs from an increasing per-process counter, so no later coroutine releases a leaked one. The slot stays taken until the worker restarts, and `worker_max_request: 0` (`swoole.yaml:60`) turns off worker recycling.

## Solution

Fixed on master by commit "fix(shared): release pooled services after server control operations". Each callback calls `CoWrapper::defer()` first:

```php
// src/Shared/Infrastructure/Swoole/Control/ControlSocketConfigurator.php:72-74
private function receive(Server $server, int $fd, int $reactorId, string $line): void
{
    $this->coWrapper?->defer();
    $server->send($fd, $this->respond($line));
}

// src/Shared/Infrastructure/Swoole/Control/ControlPipeMessageHandler.php:21-23
public function __invoke(Server $server, int $fromWorkerId, mixed $message): void
{
    $this->coWrapper?->defer();
    // ...
}
```

Both classes take `?CoWrapper $coWrapper = null`. The container autowires the real `CoWrapper`. The real-server probe tests construct the classes without one.

## Why This Works

`CoWrapper` (`packages/swoole-bundle/src/Bridge/Symfony/Container/CoWrapper.php`) has two methods:

- `defer()` registers a `Coroutine::defer()` callback that calls `ServicePoolContainer::releaseFromCoroutine()` for the current coroutine ID. It must run inside the coroutine whose services should be released, so it goes at the start of the callback.
- `go($fn)` starts a new coroutine that calls `defer()` before running `$fn`. Use it instead of `Coroutine::create()` or `go()`.

The bundle already does this for the entry points it owns:

- HTTP requests: `ContextReleasingHttpKernelRequestHandler`.
- Swoole task workers (the `swoole_task` transport): `ContextReleasingTransportHandler`.
- WebSocket handshake (which calls the controller's `onOpen`), message and close: `WithWebSocketHandler` runs each through `CoWrapper::go()`.

Anything else that Swoole calls in a coroutine has no release unless the app adds one.

## Prevention

- When you register a Swoole callback yourself (`$server->on(...)`, `$port->on(...)`, `Swoole\Timer::tick()`/`after()`, a `Swoole\Process` callback), either call `CoWrapper::defer()` at its start or keep pooled services out of it.
- Start coroutines with `CoWrapper::go()`, not `Coroutine::create()`. `ImageController`, `StreamCompletionListener` and `TranscodeSessionSubscriber` already do.
- Do not drop the `CoWrapper` argument from the two control classes. It is nullable only for the probe tests. If it is ever not injected, the leak comes back silently. The compiled container passes it to both (checked in `var/cache/dev/App_KernelDevDebugContainer.xml` on 2026-10-08).
- Pool use is visible in diagnostics. `SwoolePoolStatsProvider` reports `active`, `free` and `limit` per pool, and both `DebugStatsOperation` and `PrometheusMetricsController` use it. An `active` count that grows while the server is idle points to a leak.

Timer callbacks in `src/` as of this writing:

| Callback | Pooled services |
|---|---|
| `src/QoL/Infrastructure/Swoole/CpuGpuSampler.php:71` | None by design; its docblock says it uses `error_log()` only. |
| `src/QoL/Infrastructure/Swoole/MidStreamMonitor.php:42` | Uses the governor and sampler and logs with `error_log()`. Not audited further. |
| `src/Transcode/Infrastructure/Swoole/TranscodeSessionSubscriber.php:397-402` (loop lock renewal, via `SwooleLoopLockRenewalTimer`) | Renews a Redis lock. Logs through the injected logger only when ownership is lost. Not audited for pooling. |
| `src/Shared/Infrastructure/Swoole/SwooleWorkerEventSubscriber.php:70` | Shutdown force-kill; uses `printf` and `posix_kill` only. |
| `src/Shared/Infrastructure/Swoole/ProcessPool/CpuProcessPool.php:426` (pool health check) | Reaps exited pool workers and logs through the injected logger when one exited. Not audited for pooling. |

Audit the three marked "not audited" before adding pooled services (a repository, the EntityManager, a logger) to them.

## Related Issues

- None in `docs/solutions/` yet.
