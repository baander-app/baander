---
title: An eager WebPush client dead-letters every push message without VAPID keys
date: 2026-11-26
category: runtime-errors
module: Notification push delivery (WebPushDelivery, SendPushHandler, SendPushToSubscriptionHandler)
problem_type: runtime_error
component: messaging
symptoms:
  - "GitHub CI's \"Production worker lifecycle and dependencies\" step fails with \"Actual application worker check verify-outbox failed\""
  - "The drill's check throws \"Confirmed handoffs have not both been consumed and acknowledged without retries/dead letters.\""
  - "The worker log shows SendPushCommand retried three times with \"[VAPID] Public key should be 65 bytes long when decoded.\" and then sent to the failure transport"
root_cause: config_error
resolution_type: code_fix
severity: high
tags: [vapid, web-push, env-example, lazy-service, autowire, dead-letter, worker-drill, eager-constructor]
---

# An eager WebPush client dead-letters every push message without VAPID keys

## Problem

VAPID keys are optional configuration, but the `Minishlink\WebPush\WebPush` client validates them in its constructor and was injected eagerly into `WebPushDelivery`. On a server without keys, every `SendPushCommand` failed while its handler was being constructed, was retried three times and was dead-lettered, even with push turned off.

## Symptoms

- From `227948b34` (2026-11-21) until the fix, GitHub CI's "Production worker lifecycle and dependencies" step failed on every run that reached it with "Actual application worker check verify-outbox failed".
- The drill's check throws at `tests/Fixtures/Worker/worker-command-check.php:635`: "Confirmed handoffs have not both been consumed and acknowledged without retries/dead letters." The projection, the receipt and both deliveries were committed; only push consumption failed.
- The cause appears only in the drill's worker log artifact (`verify-outbox-worker.log`), not in the step output: `SendPushCommand` is sent for retry with "[VAPID] Public key should be 65 bytes long when decoded.", then removed from the transport after three retries and sent to the failure transport.

## What Didn't Work

- Blaming the commit where the failure was first noticed. The investigation started from the red run of `44085b755`, a transcode change, but the step had failed on every run since `227948b34`.
- Reading the CI step output. CI was already red in other steps, and the step output names only the failed check; the VAPID error is in the uploaded worker log.
- Relying on PHPUnit. `tests/Integration/PushRetryDeliveryTest.php` builds `WebPushDelivery` around a stubbed `WebPush`, so no PHPUnit test ever built the real client from configuration.
- Fixing a configuration default in `.env.test`, which is how the [storage-path instance](../developer-experience/test-containers-inherit-production-storage-paths-from-env-example.md) of this class was fixed on 2026-11-22. Here a test override would have hidden the defect again, as the development key pair in the old tracked `.env` did: an empty VAPID pair is a state production must handle.

## Solution

`WebPushDelivery` receives the client lazily and reports whether a key pair is configured (`src/Notification/Infrastructure/Push/WebPushDelivery.php:21-35`):

```php
public function __construct(
    private PushSubscriptionRepositoryInterface $subscriptions,
    #[Autowire(lazy: true)]
    private WebPush $webPush,
    #[Autowire(env: 'VAPID_PUBLIC_KEY')]
    private string $vapidPublicKey,
    #[Autowire(env: 'VAPID_PRIVATE_KEY')]
    private string $vapidPrivateKey,
) {
}

public function isConfigured(): bool
{
    return $this->vapidPublicKey !== '' && $this->vapidPrivateKey !== '';
}
```

`PushDeliveryPortInterface` gains `isConfigured()`. `SendPushHandler` (`src/Notification/Application/Handler/SendPushHandler.php:50`) and `SendPushToSubscriptionHandler` (`src/Notification/Application/Handler/SendPushToSubscriptionHandler.php:45`) call it before delivering. When it returns false, each writes a `notification.push` info log entry and returns, as `SendPushHandler` already did when the server-wide push setting is off. Requiring both keys matches the health check, which accepts both keys present or both absent (`src/Shared/Infrastructure/Health/HealthCheckService.php:537-545`).

`tests/Integration/PushWithoutVapidKeysTest.php` boots the kernel with both keys empty and asserts that the port resolves and reports push as unconfigured. Before the fix it failed with the production error.

## Why This Works

The image's `.env` is a copy of `.env.example` (`Dockerfile:631` in the production stage, which the worker stage inherits; the ci and dev-runtime stages repeat it at lines 690 and 717), which leaves both keys empty (`.env.example:45-46`). The `WebPush` service comes from `WebPushFactory::create` with the keys as `%env(...)%` arguments (`config/services.yaml:997-1004`), and its constructor rejects an empty public key (`vendor/minishlink/web-push/src/VAPID.php:81-82`). With an eager argument, the container builds that client whenever it builds `WebPushDelivery`. It builds `WebPushDelivery` when Messenger resolves either push handler, before the handler's own push-setting and preference checks run. Messenger therefore records the message as failed, retries it and finally moves it to the failure transport.

A lazy argument defers construction to the first `sendOneNotification` call, which no handler reaches while `isConfigured()` is false. With keys present nothing changes: the first send builds the client as before.

An empty pair is a legitimate production state. The production secrets guard refuses only a private key whose digest matches a key the repository once committed and accepts an empty one (`src/Shared/Infrastructure/Security/ProductionSecrets.php:87-97`).

The defect existed since the first commit. It surfaced on 2026-11-21 because `cd1b02c05` ("fix(build): keep local environment files out of source and image builds") deleted the tracked `.env`, which had carried a development key pair into every image, and made the Dockerfile copy `.env.example` instead. The 2026-11-20 security review had flagged that committed key pair (`ROADMAP.md`, "Security review findings").

## Prevention

- Treat `.env.example` as a configuration the product must start and run on. Since `cd1b02c05`, both the production image and the backend test containers start from it. A service that validates or acts on an optional value in its constructor fails there, and it fails while some unrelated service is being constructed. The same commit exposed the storage-path coupling described in [Test containers inherit production storage paths from .env.example](../developer-experience/test-containers-inherit-production-storage-paths-from-env-example.md).
- Separate required values from optional ones. A required value with a production default can be overridden in `.env.test`. An optional value whose empty default is legitimate must be tolerated by the code: build the dependent client lazily (`#[Autowire(lazy: true)]`, as `WorkerHeartbeatSubscriber` and `EncodePresentationAdapter` already do) and check the configuration explicitly before using it.
- When a test stubs such a client, add one kernel test that resolves the real service with the optional values empty. Force the empty values in `$_ENV`, `$_SERVER` and the process environment: `.env.test` sets no VAPID keys, so without that step a local run inherits the developer's untracked `.env` while CI gets `.env.example`.
- A broader check would catch the whole class: a kernel test that boots with `.env.example`'s optional values and resolves every Messenger handler. It was not written with this fix, because the storage-path coupling in `LearningDataPersister` still fails under production paths and would need an exclusion first.
- When the production worker drill fails a check, read that check's worker log artifact before suspecting the newest commit; the step output names only the check.

## Related Issues

- [Test containers inherit production storage paths from .env.example](../developer-experience/test-containers-inherit-production-storage-paths-from-env-example.md): the first instance of this class, in the test containers.
