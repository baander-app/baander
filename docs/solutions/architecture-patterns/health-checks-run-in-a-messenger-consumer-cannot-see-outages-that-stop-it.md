---
title: Health checks run in a Messenger consumer cannot see outages that stop it
date: 2026-10-08
category: architecture-patterns
module: Shared health alerting
problem_type: architecture_pattern
component: observability
severity: medium
applies_when:
  - Scheduling a health check, watchdog or liveness probe through the Scheduler or Messenger
  - Choosing which process should raise admin alerts for an outage
  - Adding a component check to HealthCheckService that alerting depends on
tags: [health-check, admin-alerts, messenger, scheduler, worker, observability]
---

# Health checks run in a Messenger consumer cannot see outages that stop it

## Context

`notifications.admin_alerts` gates alerts from `HealthAlertService`, but nothing called that service, so no health alert was ever sent. The fix, merged from branch `feat/general-settings-email-language`, seeds a scheduler job that runs `CheckHealthCommand` every five minutes (`migrations/Version20261007140000.php:14-15`). Its handler calls `HealthAlertService::checkAndAlert()`.

A scheduled job does not run in the web server. It runs in the worker's Messenger consumer, `messenger:consume async scheduler --memory-limit=256M` (`src/Shared/Infrastructure/Worker/WorkerSupervisorRunner.php:139`). The code review of that branch (run 20261008-121847-0bad858a, finding #4) traced what the check can see from there, and it is very little.

## Guidance

Do not rely on a check that runs inside a process to report the failures that stop that process, or the failures that stop its alert from being delivered. For each component, ask two questions before wiring a check into alerting:

1. Does an outage of this component stop the check from running? The occurrence that triggers the job arrives on the Redis `scheduler` transport (`config/packages/messenger.yaml:36-38`). A Redis outage therefore stops the trigger.
2. Does an outage of this component stop the alert from being delivered? `AdminAlertService` stores each alert as a `Notification` row (`src/Shared/Infrastructure/Event/AdminAlertService.php:48`). A PostgreSQL outage therefore stops delivery.

Also check what each component check measures in the process that runs it:

- `checkMemory()` always returns `HealthStatus::Healthy` (`src/Shared/Infrastructure/Health/HealthCheckService.php:200-218`).
- `checkSwoole()` reports on the process it runs in (`HealthCheckService.php:169-198`). In the consumer that is the consumer itself, never the HTTP server.
- The messenger check reads the heartbeat that the same consumer writes, per the investigation on that branch. A dead consumer cannot run the check, so the check cannot fail.

Raise alerts for outages from a process that stays up while the component is down, such as the web server's health endpoint, or from outside the application.

## Why This Matters

The scheduled check looks like coverage, but it alerts only for brief PostgreSQL or Redis failures that happen while the job runs. It never alerts for an outage that stops the worker, for a sustained Redis or PostgreSQL outage, or for a problem in the web server.

Two more properties make it weaker:

- `HealthAlertService` keeps each component's previous status in memory, per process (`src/Shared/Infrastructure/Health/HealthAlertService.php:29`). It alerts only on a change from healthy to something else (`HealthAlertService.php:70-75`). The consumer restarts when it reaches its memory limit or on deploy. A component that is already unhealthy at restart is recorded as the new baseline and never alerts.
- Symfony's Worker drains the earlier receiver (`async`) before it polls `scheduler`. A large library scan can therefore delay the five-minute check, per the review's analysis.

## When to Apply

- Scheduling any health, liveness or watchdog job through `scheduled_jobs`.
- Adding a component to `HealthCheckService` whose failure should alert admins.
- Deciding where `HealthAlertService::evaluateAndAlert()` should be called from.

## Examples

One mitigation is already in place on that branch. `HealthAlertService` records a component's new status only after `alertAdmins()` succeeds (`HealthAlertService.php:92-111`). An alert that fails, for example while PostgreSQL is down, is retried on the next check instead of being lost:

```php
try {
    $this->adminAlertPort->alertAdmins(/* ... */);
} catch (\Throwable $e) {
    $this->logger->error('Health degradation alert for {component} failed; the next check retries it.', [/* ... */]);
    continue; // previousState keeps 'healthy', so the next check alerts again
}

$this->previousState[$component] = $currentStatus;
```

The larger decision was still open when this was written: what should alert, and from where. The review recommended calling `evaluateAndAlert()` from the web server's health path. The alternatives are to persist the baseline, or to add checks that can fail while PostgreSQL and Redis are up, such as disk space or FFmpeg presence.

## Resolution (2026-10-10)

The [item 1 open-items plan](../../plans/2026-10-10-1312-fix-item1-open-items-plan.md) moved alerting into the web server, following the guidance above. The sections before this one describe the state on 2026-10-08, and the code under Examples is from then.

- **Where alerts come from.** `HealthMonitorSubscriber` starts a Swoole timer in HTTP worker 0 of the web server that calls `HealthAlertService::checkAndAlert()` every 60 seconds (`HEALTH_MONITOR_INTERVAL_SECONDS`), the first time one interval after the worker starts. Each tick calls `CoWrapper::defer()` first and skips with a logged warning if the worker's previous tick still runs, so a hung check leaves a trace; the timer is cleared when the worker stops. The web server stays up through a worker, Redis or PostgreSQL outage, so it can see them. Worker 0 was chosen over the master process, which has no pooled kernel services and would stall the whole server on a hung check, and over a Swoole user process, which had no precedent here. The PostgreSQL and Redis checks yield under the server's coroutine hooks, so they do not block worker 0's requests.
- **The baseline survives worker reloads.** Each component's alert state (healthy, pending or acknowledged, with the outage window) lives in `HealthAlertTable`, a `Swoole\Table` created before the server forks, so worker reloads keep it. Rows start healthy, so a server that starts during an outage alerts once. `HealthAlertTransition` holds the rules as pure functions.
- **Delivery during a PostgreSQL outage.** A pending alert stays owed until delivered, even after its component recovers. A tick that finds PostgreSQL unhealthy skips delivery; the first tick that can deliver sends the alert, naming the outage window if the component has recovered by then. The `notifications.admin_alerts` read moved inside the delivery's error boundary, where it no longer aborts the remaining components.
- **The worker is visible from outside it.** The consumer's heartbeat moved from a file in the worker container to the Redis key `baander:messenger:worker_heartbeat`, judged by age: 45 seconds idle, 90 seconds busy, with the busy heartbeat refreshed from the `--keepalive=30` console alarm. A heartbeat never seen reads not available, so a stack without a worker does not alert; with Redis unreachable it also reads not available and the Redis check alerts. A `stopped` heartbeat reads not available for the 45-second idle window and unhealthy after it. The consumer writes `stopped` on every routine recycle at `--memory-limit=256M`, so reading it as unhealthy at once would alert whenever a tick landed before the replacement wrote `starting`. The PID check went, because PID namespaces differ across containers. `/ready`, the `/health` status code and `app:health:check`'s exit status leave the worker out, so a worker outage alerts without making the web container unready.
- **The checks measure the web server.** `checkSwoole()` now runs in the server it reports on. `checkMemory()` compares each HTTP worker's real memory, reported every 5 seconds into `WorkerMemoryTable`, against the parsed `memory_limit`, unhealthy at 90 percent. Memory pressure alerts administrators but, like the worker, stays out of `/ready`, the `/health` status code and `app:health:check`'s exit status, so it does not take the web container out of rotation.
- **The scheduled job is gone.** Migration `Version20261010110000` deletes the seeded **Check system health** job; `CheckHealthCommand`, `CheckHealthHandler` and `HealthAlertPortInterface` were removed.

Limits that remain: the web server cannot report its own outage, so external monitoring of the health endpoints still covers that. One web server is assumed; two would each alert. A handler blocked in one C call for longer than 90 seconds cannot refresh the heartbeat and reads unhealthy until it returns. Recovery is logged, not notified.

## Related

- `docs-book/part-1-operator-guide/notifications.md`, section "Health alerts", documents the web server's health monitor and its limits for operators.
- `docs-book/part-2-developer-guide/contexts/notification.md` describes the `notifications.admin_alerts` toggle and the monitor's classes.
