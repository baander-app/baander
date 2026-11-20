# Messaging and Worker Qualification

Use this page to check Symfony Messenger workload routing and Redis fallback
without Swoole. For the other worker checks, choose the runner that matches the
change:

- [Runner setup and operator qualification](worker-runner-setup.md): wrapper defaults, prerequisites and the operator fixture.
- [Lifecycle and production image](worker-lifecycle-qualification.md): dependency outages, recovery and the immutable worker image.
- [Transport and runtime](worker-runtime-qualification.md): response loss, lease helpers, containment and worker commands.
- [Capacity and autoscaling](worker-capacity-qualification.md): pressure drills, comparison runner and recorded measurements.
- [Scheduler occurrences](scheduler-qualification.md): persistence, dispatch and console execution.

### Validated asynchronous workloads

`config/packages/messenger.php` declares twenty production workloads: eighteen
default routes and explicit-only `FetchLyricsCommand` and `SendWebhookCommand`
entries. The test configuration adds `FailureTransportProbe` and uses in-memory
transports for `async` and `scheduler`. The resource classes `io`, `cpu` and
`coordination` describe work; they do not allocate capacity.

Catalog and compiler-pass unit tests cover invalid workload declarations, codec
registrations, effective default routes, transport-eligible handlers, JSON
serialization and bounded retry policies. `AsyncWorkloadWiringTest` boots the real
container to check the compiled catalog and sender-locator decorator.
`ValidatedSendersLocatorTest` checks actual sender selection, including explicit
transport stamps, interface/wildcard routes, rejected fan-out and unrouted inline
commands. Received deliveries retain normal handling and manual failed-message
retry behavior. Lyrics remain synchronous unless explicitly selected for `async`.
The notification delivery relay decodes committed intents, resolves their channel
and selects `async` explicitly, including webhook delivery. Relay-intent coverage
checks that these produced commands belong to the admitted catalog.

`WorkerTransportConfigurationTest` checks canonical Redis URL forms and rejects
stream, group and consumer overrides, ambiguous query options and alternate
transports. Controller and supervisor tests verify admission before Docker
creation or child launch, preserved catalog receiver order and generated consumer
identity. These checks establish configuration behavior rather than connectivity,
network discovery or measured capacity. The [messaging and worker-command runners](worker-runtime-qualification.md)
exercise the corresponding container and runtime paths.

### Messenger without Swoole

To check Redis fallback with the Swoole extension unavailable, use the same CI
image and disposable coordinator. Set `BAANDER_TEST_IMAGE` to the application CI
image and `BAANDER_TEST_POSTGRES_IMAGE` to the project's PostgreSQL image before
running:

```bash
BAANDER_TEST_DISABLE_SWOOLE=1 bash scripts/test-backend-container.sh \
  tests/Fixtures/MessengerWithoutSwooleTest.php \
  tests/Unit/Shared/Infrastructure/Messenger/SwooleFallbackHandlerRegistrationTest.php \
  tests/Integration/MessengerJsonDeliveryTest.php \
  tests/Integration/MessengerAdmissionLifecycleTest.php
```

The coordinator removes the dedicated Swoole loader inside each disposable app
container before discovery or console boot. It compares PHP configuration hashes
and loaded extensions before and after removal, requiring every other extension
and setting to remain unchanged. Runtime evidence stays beside the lane logs as
`<lane>.runtime-before.json`, `<lane>.runtime-after.json` and `<lane>.runtime.log`.
The option accepts `0` or `1` and defaults to `0`.

The dedicated fixture verifies that the extension is absent in the ParaTest worker
and in a nested PHP process, and checks synchronous execution identity, recursive
locking and service pool reuse without it. The fixture lives outside the default
suites because it deliberately fails when Swoole is loaded. The selected tests
exercise transport-restricted handler registration, real Redis delivery, retry and
dead-letter handling. Service setup also clears the Symfony cache and runs
migrations twice without Swoole. CI runs this selection as a blocking step. It
qualifies the console and Messenger paths exercised here; the web server still
requires Swoole, and the Composer extension requirement remains unchanged.

Run the backend runner regressions before changing orchestration or cleanup:

```bash
composer run-script --timeout=7200 test:tooling
bash scripts/test-worker-operator-cleanup.sh
```

CI runs the PHP tooling suite and the operator cleanup test that the script runs,
`packages/baander-dev-qualification/tests/WorkerCleanupTest.php`, as separate commands. The cleanup test
uses a disposable Docker CLI stub to check enumeration failures, removal failures,
retained resources, browser-hook cleanup and preservation of an earlier failure exit
code. It does not prove cleanup against a real daemon; the production operator drill
must still verify that its containers, networks and volumes are gone.
