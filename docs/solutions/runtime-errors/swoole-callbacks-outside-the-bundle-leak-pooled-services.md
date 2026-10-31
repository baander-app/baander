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
last_updated: 2026-10-31
tags: [swoole, coroutine, service-pool, cowrapper, entity-manager, control-channel, resource-leak]
---

# Swoole callbacks the bundle does not run leak pooled services unless they call CoWrapper::defer()

## Problem

With coroutines enabled, the swoole bundle gives each coroutine its own instance of every stateful service (the EntityManager, and services tagged `kernel.reset`). It takes the instance from a per-worker pool and gives it back when the coroutine ends, but only for coroutines the bundle knows about. The server control channel's callbacks (`ControlSocketConfigurator`'s `Receive` listener and `ControlPipeMessageHandler` on `PipeMessage`) run in coroutines that Swoole starts directly. Any pooled service an operation used there was never given back.

## Symptoms

- No error at the time. Each control request that touched a pooled service, for example the EntityManager, left one pool slot taken for good.
- The failure comes later. `BaseServicePool::get()` waits on a mutex once the pool is at its limit (`packages/swoole-bundle/src/Bridge/Symfony/Container/ServicePool/BaseServicePool.php:64-79`). After enough leaked slots, every request in that worker that needs the service hangs.
- The EntityManager pool is small. `doctrine_processor_config.global_limit: 10` (`config/packages/swoole.yaml:122`) sets the pool limit of each Doctrine connection, and each EntityManager takes its connection's limit (`packages/swoole-bundle/src/Bridge/Doctrine/DoctrineProcessor.php:64-67, 108-119`). Other pooled services use `max_service_instances: 2500` (`swoole.yaml:117`).

## What Didn't Work

- Expecting a later request to clean up. A slot is released by coroutine ID (`releaseFromCoroutine($cId)`), and Swoole hands out coroutine IDs from an increasing per-process counter, so no later coroutine releases a leaked one. The slot stays taken until the worker restarts, and `worker_max_request: 0` (`swoole.yaml:66`) turns off worker recycling.

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
- Prefer `CoWrapper::go()` when starting a coroutine that uses pooled services. If native coroutine creation is needed, call `CoWrapper::defer()` at its start. `LiveFrameSender` does this for its send coroutine and timeout callback (`src/Shared/Infrastructure/LiveUpdates/LiveFrameSender.php:59-60,137-138`).
- Do not drop the `CoWrapper` argument from the two control classes. It is nullable only for the probe tests. If it is ever not injected, the leak comes back silently. The compiled container passes it to both (checked in `var/cache/dev/App_KernelDevDebugContainer.xml` on 2026-10-08).
- Pool use is visible in diagnostics. `SwoolePoolStatsProvider` reports `active`, `free` and `limit` per pool, and both `DebugStatsOperation` and `PrometheusMetricsController` use it. An `active` count that grows while the server is idle points to a leak.

Timers count as callbacks the bundle does not run. On Swoole 6.2.1 every tick of
`Swoole\Timer::tick()` and `after()` runs in a new coroutine, in the server's master
process as well as in its workers, so a pooled service a tick uses keeps its slot.
Every `monolog.logger.*` channel is pooled (the bundle's `MonologProcessor` proxies
them), and so are the EntityManager and services tagged `kernel.reset`. The app's own
Redis pool (`RedisClientFactory::borrow()`) gives its connection back by itself.

The following inventory records the callbacks inspected on 2026-10-12. It is
historical: web no longer owns the CPU pool or media execution, and the transcode
renewal path now renews execution ownership rather than the original Redis loop
lock. For current HTTP worker examples, `LiveUpdateRuntime::project()` and
`reconcile()` and both `LiveFrameSender` callbacks register coroutine cleanup.

| Callback | Pooled services | Release |
|---|---|---|
| `src/QoL/Infrastructure/Swoole/CpuGpuSampler.php:72` | None by design; its docblock says it uses `error_log()` only. | None needed. |
| `src/QoL/Infrastructure/Swoole/MidStreamMonitor.php` | None. It reads the governor, the sampler and the algorithm profile table, none of them pooled, and logs with `error_log()`. | None needed; its docblock says to add `defer()` before the tick uses a logger or repository. |
| `src/Transcode/Infrastructure/Swoole/SwooleLoopLockRenewalTimer.php` (loop lock renewal for `TranscodeSessionSubscriber`) | Renews the Redis lock through `RedisClientFactory::borrow()`. On lost ownership it stops the encoder, and both the subscriber and `TranscodeStreamManager` log through pooled loggers. | Each tick calls `CoWrapper::defer()` (optional `?CoWrapper`, autowired). |
| `src/Shared/Infrastructure/Swoole/ProcessPool/CpuProcessPool.php` (pool health check, master process) | Reaps exited pool workers and logs through the pooled `monolog.logger` when one exited. | Each tick calls `CoWrapper::defer()` (optional `?CoWrapper`). In the original web-server design the drain stopped this master timer at shutdown. The current web server does not register this pool. |
| `src/Shared/Infrastructure/Swoole/Drain/DrainCoordinator.php:122` (drain window, master process, only during a stop) | None by design; it reads the drain state table and the server's connections and writes its lines to stderr. | None needed; its docblock says the master steps touch no pooled service. |
| `src/Shared/Infrastructure/Swoole/Drain/WorkerDrain.php:90` (drain poll, every HTTP worker) | Once the drain starts: closes WebSockets through `WebSocketPusher`, dispatches `WorkerDrainStartedEvent` to listeners that may use pooled services, and logs failures through a pooled logger. | Calls `CoWrapper::defer()` once the drain flag is set (`WorkerDrain.php:109`); before that each tick only reads the drain state table. |
| `src/Shared/Infrastructure/Health/HealthMonitorSubscriber.php` (health monitor, HTTP worker 0) | Runs the health checks and delivers admin alerts: the database connection, the entity manager and the pooled logger. | Each tick calls `CoWrapper::defer()` (optional `?CoWrapper`, autowired). |
| `src/Shared/Infrastructure/Swoole/WorkerMemoryReporter.php` (memory report, every HTTP worker) | None by design. It writes `memory_get_usage(true)` into `WorkerMemoryTable` and reports problems with `error_log()` only. | None needed; its docblock says so. |

Probe tests that fail without the release: `SwooleLoopLockRenewalTimerTest`,
`CpuProcessPoolHealthTest::testEachHealthCheckTickReleasesThePooledServicesItsCoroutineTook`
and `HealthMonitorTimerProbeTest::testEachTickReleasesThePooledServicesItsCoroutineTook`.

## Related Issues

- `docs/solutions/runtime-errors/swoole-sigterm-waits-for-master-timers-and-closes-connections-before-workers-stop.md`: the original CPU pool health timer also blocked SIGTERM. The shutdown investigation explains why timers in a web-server master must end during its drain.
