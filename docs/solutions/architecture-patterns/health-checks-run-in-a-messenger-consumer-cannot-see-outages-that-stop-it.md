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

`notifications.admin_alerts` gates alerts from `HealthAlertService`, but nothing called that service, so no health alert was ever sent. The fix on branch `feat/general-settings-email-language` (unmerged as of this writing) seeds a scheduler job that runs `CheckHealthCommand` every five minutes (`migrations/Version20261007140000.php:14-15`). Its handler calls `HealthAlertService::checkAndAlert()`.

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

## Related

- `docs-book/part-1-operator-guide/notifications.md`, section "Health alerts", documents the five-minute job and its limits for operators.
- `docs-book/part-2-developer-guide/contexts/notification.md` describes the `notifications.admin_alerts` toggle.
