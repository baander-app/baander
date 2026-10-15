---
title: Swoole waits for master timers after SIGTERM and closes client connections before it stops any worker
date: 2026-10-12
category: runtime-errors
module: Shared Swoole infrastructure (web server shutdown)
problem_type: runtime_error
component: infrastructure
symptoms:
  - "`docker stop` on the web container waited out its whole grace period and ended in SIGKILL (exit 137); nothing was logged"
  - "With the timer gone, every stop still took up to a minute before any worker stopped"
  - "Drain steps placed in WorkerExit never reached clients: no WebSocket close frame, in-flight responses cut"
  - "A file download in flight was reset as soon as every worker reported idle"
root_cause: wrong_api
resolution_type: code_fix
severity: high
framework_version: "swoole 6.2.0 (process mode), php 8.5"
tags: [swoole, shutdown, sigterm, drain, master-timer, heartbeat, reactor-thread, sendfile, pid-1, websocket]
---

# Swoole waits for master timers after SIGTERM and closes client connections before it stops any worker

## Problem

In Swoole 6.2 process mode, a SIGTERM does not stop the server while any PHP timer is left in the master. Once the master does stop, it closes every client connection before it signals a single worker. So code that tries to drain in the workers on shutdown writes to connections that are already gone. `app:serve` (PID 1 in the web container) ignored `docker stop` entirely, and a first drain design that ran in `WorkerExit` could not have finished a request or sent a WebSocket close frame.

## Symptoms

- Measured in the CI image during this work: `docker stop -t 40` ran the full 40 s and ended in SIGKILL. Ten seconds after SIGTERM every process was unchanged: master, manager, workers and the CPU pool. A bare Swoole server in the same image stopped in about 5 s.
- `Swoole\Process::signal(SIGTERM, ...)` in the master is refused (observed: "signal [15] processor has been registered by the system"), so a userland SIGTERM handler is not an option.
- With the master timer removed, a stop still took about 60 s before any worker exited (the old `heartbeat_check_interval`).
- Drain work done when the workers stop (`WorkerExit`) had no client left to talk to.
- During the drain, a sendfile download was reset the moment every worker reported idle.

## What Didn't Work

- **Registering a SIGTERM handler next to the SIGINT one.** Swoole refuses to replace a signal handler it registered itself; reading the 6.2.0 source, that covers SIGTERM, SIGUSR1 and SIGUSR2 in the master, which leaves SIGINT as the only shutdown signal userland can claim.
- **Suspecting the SIGINT listener.** Removing it one registration at a time showed it does not hold the loop; the CPU pool's health `Timer::tick` in the master does.
- **The old SIGINT handler's self-SIGKILL after `max_wait_time`.** As PID 1 the kernel ignores a SIGKILL the process sends itself, so the container was still running 20 s after SIGINT in the measurement. Its comment also claimed that `Server::shutdown()` from a custom handler bypasses `max_wait_time`. It does not: `shutdown()` takes the same path as a real SIGTERM (in the 6.2.0 source, it sends the master a pipe message and the master raises SIGTERM on itself), and the drill shows `onBeforeShutdown` running once for both.
- **Draining in `WorkerExit`.** A timestamped probe (below) showed client connections close when the master joins its reactor threads, before the manager sends any worker SIGTERM. Writes from `WorkerExit` therefore reach nobody.
- **Letting the window end when every worker reports idle.** Files sent with sendfile do not count in `worker_concurrency`, and `getClientInfo()['send_queued_bytes']` stays 0 while the reactor thread sends them, so a song download in flight looked idle and was reset.
- **Closing a connection whose client stopped reading without a reset.** From the master, `Server::close()` does not close a connection whose reactor thread still holds output for it, and joining that reactor thread never ends. From a worker, a plain `Server::close($fd)` removes the connection from `$server->connections` while its reactor thread keeps flushing, so the master can no longer reset it and the join hangs until the watchdog fires (both observed in the drill while building the drain).

## Solution

On branch `plan/item4-web-shutdown` (ROADMAP item 4; unmerged as of this writing), in the commits "fix(server): SIGTERM stops app:serve; a PID 1 drill guards it" through "fix(review): /live reports a drain; drained workers withdraw while busy":

1. **No master timer may outlive the start of shutdown.** `onBeforeShutdown` runs once in the master before it stops accepting, so the drain coordinator stops the CPU pool's health check there (`src/Shared/Infrastructure/Swoole/Drain/DrainCoordinator.php:77`).
2. **The heartbeat interval is 1 s**, because the master joins Swoole's heartbeat thread, which sleeps `heartbeat_check_interval` with signals blocked, before it stops any worker (`config/packages/swoole.yaml:69-73`).
3. **The drain runs in a window the master holds open.** The coordinator starts its own master `Timer::tick` (`DrainCoordinator.php:118-122`). While it runs, the reactor threads and every client connection stay up, and requests on kept-alive connections still reach workers. Each event worker polls a pre-fork drain flag on its own tick and drains while its connections work (`src/Shared/Infrastructure/Swoole/Drain/WorkerDrain.php:103`). The window ends when every worker is idle (`worker_concurrency === 0`, `WorkerDrain.php:173`) and no connection still has output to send, or when the window time S runs out.
4. **"Still sending" includes the kernel queue.** The master checks `send_queued_bytes` and, for a connection with none, the socket's `tx_queue` in `/proc/net/tcp` and `/proc/net/tcp6` (`DrainCoordinator.php:149-201`).
5. **Connections still open when the window ends are reset, not closed.** From the master the coordinator sets `SO_LINGER 0` and shuts down a duplicate of the socket descriptor before `Server::close($fd, true)` (`DrainCoordinator.php:204-244`). From a worker, `Server::close($fd, true)` drops the queued output (`packages/swoole-bundle/src/Bridge/Symfony/HttpFoundation/InFlightStreams.php:39`).
6. **The backstop never asks PID 1 to kill itself.** A watchdog started through `proc_open` kills the manager and the pool at the deadline, and the master then exits through `pcntl_exec` (`DrainCoordinator.php:114`; the same pattern as `src/Shared/Infrastructure/Worker/WorkerSupervisorRunner.php:105`). A `docker exec` helper that waits for PID 1 to exit is killed together with it, so `swoole:server:stop` returns as soon as the stop is requested when the master is PID 1 (`packages/swoole-bundle/src/Bridge/Symfony/Bundle/Command/ServerStopCommand.php:58`).

The shutdown order, as `scripts/test-web-shutdown-container.sh` records it on a bare probe server running as PID 1. The drill asserts the order; the times are from one run, in seconds after the first SIGTERM:

| t (s) | Event |
|---|---|
| 0.000 | SIGTERM |
| 0.031 | `onBeforeShutdown` runs (once); the master starts a 3 s `Timer::tick` |
| 0.045 | a worker answers a held request, a kept-alive connection is still served, a WebSocket close frame reaches the client |
| 1.03 | a second SIGTERM and a worker's `Server::shutdown()` do not run `onBeforeShutdown` again |
| 3.031 | the master timer ends |
| 3.032 | idle client connections see EOF (reactor-thread join) |
| 3.032 | the first worker stops; the master is in `waitpid`, with one thread and zero connections |
| 3.040 | manager stop, `onShutdown`, `start()` returns |

New connections are not accepted from the moment shutdown starts.

## Why This Works

After SIGTERM, Swoole's master ends its event loop only when the loop has nothing left, and it checks for timers before it checks its own `max_wait_time` deadline. A master timer that never ends therefore keeps the server alive forever; one the application ends deliberately holds the server open for exactly as long as the drain needs. That second property is what makes the drain window possible. The sequence after the loop ends is fixed: join the heartbeat thread, join the reactor threads (which closes every client connection), then signal the manager, which stops event workers, then task workers, each for up to `max_wait_time`, with an alarm at twice that. Anything that must reach a client has to happen before the reactor join, so it has to happen while the master loop is still running. The window is the only such time.

## Prevention

- Treat every `Timer::tick` in the master as a shutdown blocker. End it in `onBeforeShutdown` (or the drain coordinator) and add it to the drain's teardown. The drill's idle-SIGTERM case fails if one is left.
- Never put client-facing drain work in `WorkerExit` or `WorkerStop`. Use the drain flag and the worker tick; `WorkerExit` may only clear the worker's own timers and stop its own child processes.
- Do not treat `worker_concurrency === 0` as "nothing in flight". Sendfile responses are invisible to it and to `send_queued_bytes`.
- Keep `heartbeat_check_interval` short; its value is added to every stop.
- Do not self-signal or wait on PID 1 from inside its namespace. Use `pcntl_exec` to exit and a separate process for any kill backstop.
- `scripts/test-web-shutdown-container.sh` (run in CI) records the shutdown order on a bare probe server and fails if a Swoole upgrade changes it, then runs `app:serve` as PID 1 through every stop route.

## Related Issues

- `docs/solutions/runtime-errors/swoole-callbacks-outside-the-bundle-leak-pooled-services.md`: the CPU pool health check is one of the master timers it lists; this doc explains why that timer also blocked SIGTERM. The drain's own master and worker ticks follow its `CoWrapper::defer()` rule.
- `docs/solutions/runtime-errors/swoole-table-default-conflict-proportion-drops-keys.md`: the drain state table follows its sizing rule (conflict proportion 1.0, checked `set()`).
- [Stopping and restarting the web server](../../../docs-book/part-1-operator-guide/upgrading.md#stopping-and-restarting-the-web-server) documents the drain window, stop routes and restart procedure for operators.
