---
title: State set when work is queued on swoole_task must expire or be fenced
date: 2026-10-09
category: architecture-patterns
module: Library scan claims (pattern applies to every swoole_task route)
problem_type: architecture_pattern
component: background_job
severity: high
applies_when:
  - A handler or controller writes a status, claim or lock and then dispatches a message routed to swoole_task
  - Adding a "running" or "scanning" flag that blocks new work until the queued job clears it
  - Moving a message route to swoole_task from a durable transport
  - A retry or re-dispatch can deliver the same job twice
symptoms:
  - "A library stayed at scan_status='scanning' after a server restart and refused every later scan until an operator ran app:library:scan --release"
root_cause: async_timing
resolution_type: migration
tags: [swoole-task, messenger, lease, fencing-token, scan-claim, restart, library-scan]
---

# State set when work is queued on swoole_task must expire or be fenced

## Context

`StartLibraryScanHandler` (the admin panel's Scan) and `ScanAllLibrariesHandler` claim a library (`scan_status = 'scanning'`) and then dispatch `ScanLibraryCommand`. That message is routed to the `swoole_task` transport (`config/packages/messenger.yaml:79`), whose DSN is `swoole://task` (`messenger.yaml:13`). The task is held in the Swoole server's memory until a task worker takes it. A server restart drops every queued task.

Before the fix, nothing cleared the claim except the scan itself. After a restart the library kept `scanning`, refused every new scan, and the web panel kept Scan disabled and polled forever. The same claim was also left behind when the handler threw before discovery started, or when the process was killed mid-scan. The only recovery was `app:library:scan --release`.

`swoole_task` also gives no Messenger retry. The task handler stamps the message received and calls `bus->dispatch` once, with no retry logic (`packages/swoole-bundle/src/Bridge/Symfony/Messenger/SwooleServerTaskTransportHandler.php`). A job-monitor retry dispatches the stored message again under a new job ID (`src/Shared/Infrastructure/Messenger/JobMonitorAdministration.php:125-132`).

## Guidance

Anything written when work is queued on `swoole_task`, and cleared only when that work finishes, must stop holding on its own. Give it two properties:

1. **It expires.** Store an expiry, not just a flag. A holder that is still working renews it; a holder that is gone stops renewing, and the state lapses.
2. **It is fenced.** Give each holder an ID and carry that ID in the message. Renew, complete and fail only `WHERE claim_id = :claim`. A holder whose claim was taken over then fails its next renewal and stops, instead of overwriting the new holder's state.

The library scan claim now does both (migration `migrations/Version20261008200000.php`):

- `libraries.claim_id`, `claim_expires_at` and `claim_kind` hold the claim (renamed from `scan_claim_*` on 2026-10-10, when deletes with files began to hold the library too). A check constraint ties `scan_status = 'scanning'` to a `scan` claim; a `delete` claim leaves the scan status alone.
- The lease is 15 minutes (`LibraryScanClaims::DEFAULT_LEASE_SECONDS = 900`), measured on the database clock: `claim_expires_at = clock_timestamp() + make_interval(secs => :lease)` (`LibraryRepository`'s claim and renew statements).
- `MusicScanner` and `MovieScanner` call `LibraryScanLease::renew()` at each directory, file hash and publish. `renew()` writes to the database at most once a minute (`RENEWAL_INTERVAL_SECONDS = 60`), so frequent calls are cheap.
- One `UPDATE` takes the claim when it is free, already this holder's, or lapsed (`LibraryRepository.php:166`). Of two concurrent takeovers, exactly one updates a row.
- The claim ID travels in `ScanLibraryCommand`. When the scan starts, `ScanLibraryHandler` calls `acquire($library, $claimId)`. A retried job therefore re-claims the free library under its old ID, and stops with a conflict if a newer scan holds a live claim.
- A lapsed claim reads as `failed` (`LibraryRepository::discoveryStatus`, `LibraryRepository.php:291`), so the UI stops showing a scan that no one is running.
- `app:library:scan --release` refuses a live claim unless `--force` is given. A forced release fences the old scan: it stops at its next renewal.

## Why This Matters

- A flag that only the queued job can clear becomes permanent the first time the job is lost. On `swoole_task` that happens on every restart with work in the queue, which includes every deploy.
- Expiry alone is not enough. Once a claim can lapse, a slow scan can lose its claim to a new one and both scan the same library. The claim ID makes the old scan notice and stop.
- Use the database clock for the lease. Every web and worker process then agrees on when a claim lapsed, whatever their own clocks say. The read-side "lapsed" check uses the application clock (`ClockInterface`); it only affects what the UI shows, not who may take the claim.
- The lease length has to cover the longest pause between renewals. For scans that is walking a large directory tree, or hashing one large video file on network storage. A lost scan therefore blocks its library for at most 15 minutes after its last renewal.

## When to Apply

- Any new route to `swoole_task` whose sender writes state first. The current `swoole_task` routes are in `config/packages/messenger.yaml:79-105`.
- A dispatch that falls back to Redis. `SwooleTaskWithRedisFallbackSender` sends to the durable `async` transport when the task queue is unavailable. The same message can then wait in Redis much longer than it would in the task queue, so the lease must also tolerate a queued wait. The scan handles this: a claim that lapsed while queued reads `failed`, and the scan renews it on start unless another scan took it.
- State set before work runs on any transport, if the work can die without running its cleanup (a killed process, an exception before the `try`).

As of this writing, the library scan claim is the only queue-time claim found on a `swoole_task` route. The other senders (metadata sync, e-mail and push, image pruning, radio station sync, outbox relay, transcode position) write no state that waits on the task.

## Examples

Before: the sender took a claim with no expiry and no holder, then queued the scan without it.

```php
// StartLibraryScanHandler, before the fix
$library = $this->claims->claim($command->library);   // scan_status = 'scanning'
try {
    $this->bus->dispatch(new ScanLibraryCommand($library->getSlug(), $command->rescan));
} catch (Throwable $exception) {
    // Covers a failed dispatch only. A task lost after it was queued kept the claim.
    $this->claims->release($library->getId()->toString());
    throw $exception;
}
```

After: the claim is a lease with an ID, and the message carries the ID.

```php
// src/Library/Application/CommandHandler/StartLibraryScanHandler.php
$claim = $this->claims->claim($command->library);
try {
    $this->bus->dispatch(new ScanLibraryCommand($claim->library->getSlug(), $command->rescan, $claim->claimId));
} catch (Throwable $exception) {
    // A scan that was never queued must not hold the claim.
    $this->claims->end($claim->claimId);
    throw $exception;
}
```

An older, weaker version of this pattern is `RecommendationJobCleanupService` (`src/Recommendation/Infrastructure/Swoole/RecommendationJobCleanupService.php`). It marks old `pending` and stale `in_progress` jobs as `failed` when the server starts. A startup sweep only runs when the web server restarts. It does not free a job whose task worker died while the server stayed up, and it guesses from timestamps instead of from a holder that stopped renewing. Prefer a lease for new work.

Tests: `tests/Integration/LibraryScanClaimTest.php` covers concurrent claims and takeovers, release with and without `--force`, and a lapsed claim reading as failed. `tests/Unit/Library/Application/CommandHandler/ScanLibraryHandlerTest.php` covers the handler side.

## Related

- `src/Library/README.md` describes the scan claim from the Library context's side.
