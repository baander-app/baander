---
title: HTTP worker idle memory - Plan
type: perf
date: 2026-11-20
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
---

# HTTP worker idle memory - Plan

## Goal Capsule

Reduce memory retained by idle HTTP workers while preserving playback, concurrent requests and diagnostics when requested. Deliver one local branch with measured results and regression coverage; do not push or change the persistent checkout.

## Product Contract

### Problem Frame

The reported deployment uses about 700 MB across four HTTP workers. Process memory includes shared pages, so the sum alone does not establish the physical cost. The persistent instance also records spans continuously. `TracerProviderFactory` always registers its diagnostics processor, and `HttpServerSpanSubscriber` opens every main request's span.

### Requirements

- R1. Preserve usable features and existing concurrency capacity while reducing unused retained memory.
- R2. Default HTTP span capture to enabled in development and disabled in staging and production; treat the persistent instance as staging.
- R3. Let an authorized administrator enable and disable capture through the admin settings panel without restarting workers.
- R4. Return unused pool capacity and reclaimable PHP allocator memory after traffic subsides, without interrupting requests or long-lived connections.
- R5. Report measured memory using both PHP heap allocation and operating-system physical-memory accounting, with remaining limits stated.

### Scope Boundaries

HTTP tracing, HTTP worker pool retention, lifecycle maintenance and their documentation are in scope. Worker count, transcode capacity, playback features, `ui/rn`, Essentia, database schema changes and deployment publication are outside this work.

## Planning Contract

### Evidence and Decisions

- KTD1. Use the existing system-setting definition and persistence mechanism for `diagnostics.spans_enabled`. The generated settings panel and existing settings commands provide UI and CLI parity. `Bootstrap.php` already sets persistent `APP_ENV=prod`; `Runtime.php` overrides previews to `dev`, so no new Symfony environment is necessary. Default true only for `dev`.
- KTD2. Resolve the capture decision for each new request with a short bounded worker-local cache; configuration lookup failures disable new capture and must not fail the request. Existing spans finish normally. Keep `OTEL_ENABLED` as the independent export switch. Retain prior spans until normal overwrite or explicit clearing.
- KTD3. Preserve the pre-fork shared span buffer so enabling capture reaches every worker. Do not shrink its ring or conflict reserve: `docs/solutions/runtime-errors/swoole-table-default-conflict-proportion-drops-keys.md` demonstrates why those preserve correctness.
- KTD4. Age only released service instances. Retain one warm free instance per pool, prune older spare instances, and never touch assigned, resetting or prepared services. Preserve pool limits and reuse behavior during bursts. The current `BaseServicePool` stores every stable returned object indefinitely; `ServicePoolContainer::drain()` is an exit operation, not idle maintenance.
- KTD5. Run bounded periodic maintenance in each HTTP worker, in a coroutine with normal pooled-service cleanup, and stop it through the existing worker timer lifecycle. Collect cycles and release PHP allocator caches after pruning. Do not recycle workers: WebSocket and stream connections must survive. Follow the cleanup rule established in `docs/solutions/runtime-errors/swoole-callbacks-outside-the-bundle-leak-pooled-services.md`.
- KTD6. Measure existing persistent state read-only and controlled workloads in disposable runtimes or the isolated preview. Compare the same runtime and workload before and after; report RSS, PSS and private memory separately. Avoid claiming that shared mappings are four independent allocations.
- KTD7. Collect unreachable cycles and allocator caches after server bootstrap and before forking. A disposable production kernel boot retained 28,261 objects before collection and 853 afterwards, reducing used heap from 50.51 MiB to 25.89 MiB. The discarded graph contains container definitions, references and compiler edges; keep live bootstrap services intact. Warm-cache boot collected no cycles, so this optimization targets cold compilation.

### Assumptions

A short delay (at most five seconds) for a setting change to reach each worker is acceptable. A 60-second spare-instance idle age and a 30-second maintenance cadence balance reuse with idle reclamation; measurements may tune these bounded values. No new dependency is needed.

### High-Level Technical Design

Directional flow:

```text
Admin panel / settings command -> persisted system setting
HTTP request -> bounded policy refresh -> capture enabled? -> request-owned span
HTTP terminate -> finish any span already opened -> shared diagnostics / optional export

Worker start -> periodic coroutine -> prune expired free services -> collect/free heap
Worker exit/stop -> cancel timer; active services remain owned by their coroutines
```

| Environment | No override | Stored true | Stored false |
|---|---|---|---|
| dev / preview | capture | capture | skip |
| prod / persistent staging / staging / test | skip | capture | skip |

Reset removes the override and restores the environment default. Capture disabled does not remove the shared diagnostics buffer.

## Implementation Units

### U1. Establish the memory baseline

Measure the persistent container without changing it and create a reproducible disposable pool-burst measurement. Record PHP used/allocated memory, RSS/PSS/private memory where available, request counts and pool free/assigned counts. Test both active borrowing and quiescence. This unit supplies the comparison used by U3 and U4.

Files: a focused probe beside `tests/Unit/Shared/Infrastructure/Swoole/ServicePoolLifecycleTest.php`; tracked measurement results in `ROADMAP.md`.

### U2. Make HTTP span capture configurable

Implement KTD1–KTD3 in `src/Shared/Application/Settings/`, `src/Shared/Infrastructure/OpenTelemetry/`, `config/services.yaml` and `config/packages/opentelemetry.yaml`. Inject the kernel environment into the setting definition provider so the generated panel, reset operation and capture policy use the same default. Read the effective value through `SystemSettingsPortInterface`; keep repository access out of the HTTP subscriber. Share one capture-policy cache per worker across request coroutines. Set its refresh deadline and in-progress guard before the settings read can yield, and throttle retries after failures as well as successful reads. Requests encountering an in-progress refresh skip new capture rather than starting another lookup. Keep the request-keyed WeakMap and avoid ambient trace context. `SystemSettings::get()` already resolves stored values against definition defaults; `SettingDefinitionRegistry` retains those definitions after first use.

Tests: `tests/Unit/Shared/Infrastructure/OpenTelemetry/HttpServerSpanSubscriberTest.php`, focused capture-policy/default tests, and `tests/Functional/Controller/SystemSettingsControllerTest.php`. Prove defaults for dev/prod/staging/test; stored true/false and reset; disabled requests do not create spans; requests already started finish after disable; subsequent requests observe a toggle within the refresh interval; lookup failure does not break serving or trigger a lookup on every request; concurrent requests remain isolated. Count setting reads across overlapping requests and simulate a yielding refresh to prove the bound and retry behavior. Check that panel metadata and reset expose the same environment default as request capture. Exercise the generated admin switch on the isolated preview and retain existing CLI tests.

### U3. Release expired spare service instances

Implement KTD4–KTD5 in `packages/swoole-bundle/src/Bridge/Symfony/Container/ServicePool/` and a dedicated worker maintenance subscriber under `src/Shared/Infrastructure/Swoole/`. Timestamp instances when they enter the free pool. Use a monotonic clock for age checks, detach expired spare candidates and publish the retained free pool before dropping any candidate references, because destructors can yield and other coroutines can borrow or release services meanwhile. Deduplicate registered pools during each maintenance pass. Run maintenance through `CoWrapper::go()` with an in-progress guard set before spawning, cleared on spawn failure and after completion; skip overlapping ticks. Isolate failures so one pool does not prevent the remaining pools from being maintained. Use the existing `WorkerTimerInterface` registration for drain cleanup and handle `WorkerStoppedEvent` for stop/reload. `DrainWorkerExitSubscriber` clears tagged timers only during drain; `CoWrapper` registers coroutine cleanup before running work. U1 precedes this unit; U2 is independent.

Tests: extend `tests/Unit/Shared/Infrastructure/Swoole/ServicePoolLifecycleTest.php` and add focused maintenance tests. Prove expiration releases spare objects but retains a warm instance; recent free objects survive; active borrowers survive pruning and can release/reborrow normally; pool limits stay unchanged; cleanup remains correct when destructors yield and other coroutines borrow or release meanwhile; overlapping ticks do not start a second pass; spawn and pool failures leave maintenance usable; task workers do not start the timer; stop/reload/drain leave no timer behind. Re-run `ServicePoolDrainTest`, `ProxifierPoolLifetimeTest` and `CoWrapperSchedulingTest`.

Also apply KTD7 at the start boundary in `packages/swoole-bundle/src/Bridge/Symfony/Bundle/Command/ServerExecutionCommand.php`. Verify startup and readiness in the isolated preview.

### U4. Verify the integrated behavior and record results

Run focused suites and static analysis in the CI image, compare U1 measurements, and exercise idle recovery plus a new burst. Keep only improvements supported by measurements without lifecycle regressions. Document limitations, defaults, toggle delay and CLI use in operator settings/span docs; clarify the persistent staging role in the development guide and `CONCEPTS.md`. Record delivered items and exact verification counts in `ROADMAP.md`.

## Verification Contract

Required checks are focused CI-image PHPUnit unit and functional coverage, PHPStan and Deptrac for affected boundaries, a real Swoole burst/idle/reborrow probe, and the generated admin control in the isolated preview. Memory comparisons must identify the runtime, workload and elapsed idle period. A synthetic pool result proves reclamation behavior; it does not establish the idle footprint of every installation.

## Risks and Open Questions

PHP may retain live classes and allocator arenas after free objects disappear; the implementation must not equate object release with a guaranteed RSS reduction. A shared span table remains allocated to permit runtime activation. Current settings reads hit PostgreSQL, so the capture policy needs bounded refresh and failure isolation. Destructors can yield, so pruning must detach candidates before releasing references. Runtime measurements will determine the attributable savings; no absolute memory target is promised before that evidence exists.

## Definition of Done

Environment defaults and admin/CLI control work, idle reclamation preserves active work, focused gates pass, before/after evidence and limits are recorded, and code review findings are resolved. Changes remain on the local branch until publication is requested.
