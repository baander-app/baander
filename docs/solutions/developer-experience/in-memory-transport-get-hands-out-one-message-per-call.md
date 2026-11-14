---
title: Messenger's InMemoryTransport hands out one message per get(), so "handle the queued messages" helpers miss most of them
date: 2026-11-14
category: developer-experience
module: Kernel tests that run queued Messenger messages by hand (tests/Integration, tests/Functional)
problem_type: test_failure
component: testing_framework
symptoms:
  - "A test that hands queued messages to their handlers handles nothing, and a later assertion on the handler's effect fails (here: Failed asserting that null is identical to an object of class App\\Catalog\\Domain\\ValueObject\\AlbumMatchSource)"
  - "The same helper works in one test and not the next, depending on which message happens to be oldest on the transport"
root_cause: wrong_api
resolution_type: test_fix
severity: medium
framework_version: symfony/messenger v8.1.10
tags: [messenger, in-memory-transport, test-helpers, fetch-size, integration-tests, queued-messages]
---

# Messenger's InMemoryTransport hands out one message per get(), so "handle the queued messages" helpers miss most of them

## Problem

`InMemoryTransport::get()` returns at most one envelope per call unless a fetch size is passed:
`$fetchSize = \func_num_args() > 0 ? max(1, func_get_arg(0)) : 1;`
(`vendor/symfony/messenger/Transport/InMemory/InMemoryTransport.php:64`). It fetches the way a
worker does. A test helper that loops over one `get()` to "handle every queued message of class
X" therefore sees only the oldest waiting message. When an earlier step left other messages on
the transport, the helper handles nothing, and the code under test looks broken although it is
not. This cost time twice on this project: in the scan artist-credits work, and on 2026-11-14 in
`tests/Integration/NewAlbumMetadataSyncTest.php`, where the library sync's `SyncAlbumMessage`
was never produced because `handleQueued(SyncLibraryMessage::class, ...)` received an unrelated
message left by the ingest. The production chain (`SyncLibraryHandler` ->
`SyncAlbumMessage` -> `RequestAlbumMatchHandler`) was correct throughout.

## Solution

List the waiting messages from the transport's own bookkeeping instead of fetching them, and
acknowledge only the messages the helper handles. The fixed test does this
(`tests/Integration/NewAlbumMetadataSyncTest.php`, `queued()` and `handleQueued()`):

```php
private function queued(): array
{
    $async = $this->async();
    $done = array_map(self::transportId(...), [...$async->getAcknowledged(), ...$async->getRejected()]);

    return array_filter($async->getSent(), static fn (Envelope $envelope): bool => !in_array(self::transportId($envelope), $done, true))
        |> array_values(...);
}
```

`getSent()`, `getAcknowledged()` and `getRejected()` keep every envelope the transport saw;
`TransportMessageIdStamp` identifies them across those lists. A message is waiting when it was
sent and is neither acknowledged nor rejected. Verified: all six `NewAlbumMetadataSyncTest` cases
pass in `baander-ci:u10`.

## Prevention

When a kernel test drives queued messages by hand, never treat one `get()` as "the queue". Use a
helper like `queued()` above, or pass a fetch size (`get(PHP_INT_MAX)`), which still leaves out
messages whose delay has not passed yet. Do not call `get()` until it returns nothing: it keeps
handing out the same envelope until that envelope is acknowledged or rejected. Acknowledge each
handled envelope so the next lookup does not see it again.
