# HTTP worker idle memory

Measurements on 2026-11-20 identified two reclaimable allocations: spare service
instances retained after a burst, and unreachable container-compilation objects
retained after cold startup. HTTP span capture is now optional. These changes
preserve worker count, concurrency limits, playback and long-lived connections.

## Deployment baseline

The persistent instance was inspected without restarting or changing it. Its four
workers reported 173, 195, 173 and 173 MiB of PHP allocator reservation, and 162.60,
177.36, 162.57 and 162.57 MiB of used PHP heap. Three workers held at most one free
pooled instance; pool trimming therefore cannot explain most of their initial
footprint. See the [worker snapshot](http-worker-idle-memory/persistent-baseline.json).

The container reported 349.8 MiB in Docker. Across six PHP processes, proportional
resident memory was about 322 MiB; the four HTTP workers accounted for about
221 MiB. The [process snapshots](http-worker-idle-memory/persistent-smaps.txt)
show the shared mappings. PHP allocator figures and per-process RSS include memory
inherited from the parent, so adding them does not give the physical memory charged
to the container. Linux documents the distinction between RSS and proportional
sharing in its [/proc reference](https://www.kernel.org/doc/html/latest/filesystems/proc.html).

## Burst and idle reclamation

The [probe](../../tests/Unit/Shared/Infrastructure/Swoole/pool-memory-probe.php)
creates 32 concurrent Swoole borrowers, each holding a one-MiB payload in a real
service pool. It records memory while borrowed, after release, after collection,
after expiry, and after borrowing again. The probe uses zero idle age to measure
reclamation without a minute-long wait; deployed maintenance uses a 60-second age
and runs every 30 seconds, retaining one warm free instance per pool.

Three paired runs compared the pool implementations at `8ad1061bf` with the changed
implementations in the same CI image. Each pair used a fresh network-disabled
container and the same probe. [Raw paired results](http-worker-idle-memory/pool-confirmation.json)
contain both variants and all phases.

| Measurement | Original pools | Expired spare reclamation |
|---|---:|---:|
| PHP allocation after collection | 70 MiB in all three runs | 8 MiB in all three runs |
| Free instances retained | 32 | 1 |
| Assigned instances at idle | 0 | 0 |

The reduction is 62 MiB, or 88.6%, for this synthetic workload. This confirms the
predicted recovery of its unused payloads; it is not an estimate of whole-server
savings. Native coroutine tests separately cover active borrowers, renewed use,
yielding destructors and concurrent shutdown. Retained live objects, loaded classes
and shared diagnostics tables still consume memory.

The image was `baander-ci:local`, digest
`sha256:8a34fca638e539984be403d1e8b644180d50ace4a9712a7ff7df272e1b849a70`,
with PHP 8.5.12 and Swoole 6.2.4. Set `XDEBUG_MODE=off` for this native probe, as
`tests/Fixtures/Swoole/CoroutineProbe.php` does. Two exploratory invocations with
Xdebug enabled crashed while testing the original pool implementation; they are
excluded from the confirmation runs. All six confirmation processes exited
successfully with instrumentation disabled.

## Cold startup

A separate disposable production-kernel probe found dependency-injection
definitions, references and compiler graph edges still reachable only through
cycles after cold compilation. Immediately before collection it measured
50.51 MiB of used PHP heap and 28,261 objects. Collecting 60,321 cyclic values left
25.89 MiB and 853 objects, a 24.62 MiB reduction. A second process using the warm
container cache collected no cycles.

The [raw cold/warm observations](http-worker-idle-memory/kernel-prod-cold-warm.jsonl)
and [attribution probe](http-worker-idle-memory/kernel-boot-attribution.php) retain
the evidence. Histogram enumeration changes temporary memory use, so the comparison
uses the immediately adjacent `before_gc` and `after_cycle_collection` snapshots.
The probe used disposable writable cache, synthetic secrets, dummy `baander.app`
connection targets and disabled networking; it made no database or Redis connection.

Server startup now collects cycles and allocator caches immediately before
forking, after boot services and configuration have finished. The periodic HTTP
worker maintenance also collects them. This removes unreachable objects without
resetting services still in use. PHP allocated memory stayed at 70 MiB in the cold
kernel probe, despite reduced live heap; this result alone establishes no RSS
reduction. [`gc_mem_caches()`](https://www.php.net/manual/en/function.gc-mem-caches.php)
reclaims memory held by PHP's allocator but cannot free live objects.

## Capture controls and verification

`diagnostics.spans_enabled` defaults to true only in `dev`. The persistent instance
already uses `prod` and is treated as staging, so its default is false. Super
administrators can use **Admin → Settings → Diagnostics → HTTP request spans**;
the existing `app:settings:set` and `app:settings:reset` commands expose the same
setting. Workers refresh their decision on requests with a five-second cache.
Lookup failure skips capture, and a request overlapping a refresh also skips it.
Already-open spans finish; disabling capture retains previous spans. OTLP export
still requires `OTEL_ENABLED` in addition to capture being enabled.

The shared span ring remains allocated so capture can be enabled without restarting
workers. No standalone memory saving is attributed to turning spans off. Capture
tests cover defaults, overrides/reset, refresh concurrency, lookup failures,
privacy and completion of spans opened before a toggle.

The CI-image focused unit run passes 53 tests and 248 assertions across ordinary
and native lanes. The settings functional suite passes 14 tests and 90 assertions.
An initial maintenance test failed under the ordinary runner because it slept in
a native coroutine after its synchronous work had finished; removing that unnecessary
sleep resolved the runner failure without changing the production implementation.
PHPStan passes with no errors and Deptrac reports zero violations, warnings or
errors. The isolated preview starts successfully. Browser checks at 1,440 × 1,000
and 390 × 844 verify the generated switch, successful off/on/reset requests and no
mobile overflow; reset restores the implicit development default. The final
supplementary test checkpoint is recorded in [the roadmap](../../ROADMAP.md).

The experiment stops after the two measured reclamation mechanisms and requested
capture control. No dependencies or paid services were added. Broad allocator,
class-loading and container rewrites remain unmeasured. The persistent deployment
has not been updated; these results do not claim its post-deployment idle footprint.
