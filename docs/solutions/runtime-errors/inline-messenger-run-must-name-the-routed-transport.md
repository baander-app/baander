---
title: An inline Messenger run must be received from the transport the message is routed to
date: 2026-10-09
category: runtime-errors
module: Shared Messenger job monitor (inline console runs)
problem_type: runtime_error
component: messaging
symptoms:
  - "A console command that runs a job inline failed with NoHandlerForMessageException (\"No handler for message\")"
  - "The same message was handled normally when a worker received it from its transport"
root_cause: wrong_api
resolution_type: code_fix
severity: high
tags: [messenger, received-stamp, from-transport, inline-run, console, job-monitor, no-handler]
---

# An inline Messenger run must be received from the transport the message is routed to

## Problem

`JobMonitorAdministration::runInline()` runs a queued job's message in the current process, so console commands can do the work themselves instead of queueing it. It stamped every envelope `new ReceivedStamp('sync')`. Handlers registered with `#[AsMessageHandler(fromTransport: ...)]` then did not match, and the dispatch failed with "No handler for message".

## Symptoms

- `NoHandlerForMessageException` from console commands that call `runInline()` for a message whose handler is bound to a transport: cover extraction (`async`), the metadata sync handlers and radio station sync (`swoole_task` and `async`).
- Workers handled the same messages without error, because a worker stamps the real transport name.

## What Didn't Work

- `ReceivedStamp('sync')` looks right, because Symfony's own sync transport uses that name. But no handler in this project is bound to `sync`, and a transport-bound handler matches only its own transport name.
- Dropping the `ReceivedStamp` is not an option either. Without it, `SendMessageMiddleware` sends the message to its routed transport instead of handling it here (`vendor/symfony/messenger/Middleware/SendMessageMiddleware.php:47` skips sending only for received envelopes). The inline run would quietly turn back into a queued job.

## Solution

`runInline()` now asks Messenger's senders locator where the message is routed and stamps that transport name. A message with no route is still `sync` (`src/Shared/Infrastructure/Messenger/JobMonitorAdministration.php:202-211, 247-255`):

```php
$envelope = new Envelope($message, [
    new JobIdStamp(PublicId::fromString($jobId)),
    new ReceivedStamp($this->routedTransport($message)),
]);

/** The first transport the message is routed to, or 'sync' when it is handled synchronously. */
private function routedTransport(object $message): string
{
    foreach ($this->sendersLocator->getSenders(new Envelope($message)) as $transport => $sender) {
        return (string) $transport;
    }

    return 'sync';
}
```

`messenger.senders_locator` is a private framework service, so `config/services.yaml` injects it explicitly as `$sendersLocator`.

Regression test: `testAnInlineRunReachesAHandlerBoundToTheTransportTheMessageIsRoutedTo` in `tests/Unit/Shared/Infrastructure/Messenger/JobMonitorAdministrationTest.php`.

## Why This Works

`ReceivedStamp` does two separate jobs in Symfony Messenger:

1. Any `ReceivedStamp` stops `SendMessageMiddleware` from sending the envelope, so the bus handles it in this process.
2. Its transport name picks the handlers. `HandlersLocator::shouldHandle()` runs every handler when there is no `ReceivedStamp`. When there is one, a handler with a `from_transport` option runs only if the names match (`vendor/symfony/messenger/Handler/HandlersLocator.php:85-96`).

An inline run needs the first effect, so it needs the stamp, and then the name must be the one a worker would stamp. The routed transport is that name.

## Prevention

- Any code that handles a routed message in-process by adding a `ReceivedStamp` must use the transport name the message is routed to, not a fixed name. Reuse `JobMonitorAdministration::runInline()` instead of building another inline runner.
- This project binds handlers with `fromTransport` on purpose: the `swoole_task` handlers list both `swoole_task` and `async`, because `SwooleTaskWithRedisFallbackSender` falls back to Redis. `tests/Unit/Shared/Infrastructure/Messenger/SwooleFallbackHandlerRegistrationTest.php` checks which transports each handler accepts. When you add a handler with `fromTransport`, check that inline console runs still find it.
- `routedTransport()` returns the first routed transport. Every message in `config/packages/messenger.yaml` routes to a single transport. A message routed to several transports, with handlers bound to a later one, would need a different rule.
- Tests that dispatch a received envelope by hand should stamp the transport the handler is bound to, as `tests/Functional/CoverBatchRoutingTest.php` does with `async`.

## Related Issues

- None in `docs/solutions/` yet.
