---
title: "Web and worker runtime redesign"
type: feat
date: 2026-07-17
updated: 2026-10-02
topic: messenger-enterprise-hardening
artifact_contract: ce-unified-plan/v1
artifact_readiness: design-for-implementation
execution: code
---

# Web and worker runtime redesign

## Deployment contract

Baander has two long-running deployment commands:

```bash
php bin/console app:serve
php bin/console app:worker
```

These names are proposed interfaces, not commands implemented by this document.
`app:serve` starts the HTTP/SSE/streaming server. `app:worker` starts an independent
supervisor that owns every background service and its children, including queue
autoscaling. Operators do not assemble a list of consumers, scheduler daemons,
outbox relays or media workers. Maintenance commands such as migrations, retry,
inspection and a one-shot scheduler trigger remain available; they are not extra
deployment daemons.

The commands run in the foreground, log to stdout/stderr, accept termination
signals and return meaningful exit codes. Container deployments run one command
per service. A host service manager may restart these two top-level processes;
it does not also manage their children. A web restart must not restart background
work. The worker command must run without a live Swoole HTTP server.

This redesign supersedes the previous proposal's Swoole-owned autoscaler, mandatory
workflow engine, payload-hash dedup middleware, and prohibition on process or metrics
libraries. Autoscaling remains required, but has one owner outside the web runtime.
The registry's rqlite architecture and resource budget are unrelated to this design.

## Verified baseline

The current implementation already has more reliability machinery than the original
proposal described:

| Current mechanism | Evidence | Change required |
|---|---|---|
| Supervisor runs Swoole, one Redis consumer and an outbox consumer | [supervisord.conf](../../docker/general/supervisord.conf) | Replace the deployment surface with the two commands; remove competing child supervision |
| Redis directly receives discovered-file and cover-extraction work; other routed work uses Swoole tasks | [messenger.yaml](../../config/packages/messenger.yaml) | Move background routing behind the worker runtime and update transport-restricted handlers |
| Outbox records have leases, retries and dead-letter handling | [RelayOutboxHandler](../../src/Shared/Domain/Event/Outbox/RelayOutboxHandler.php) | Reuse delivery semantics; supervise its consumer independently of web |
| Explicit JSON message and envelope codecs exist | [JsonMessageCodec](../../src/Shared/Infrastructure/Messaging/JsonMessageCodec.php), [JsonTransportSerializer](../../src/Shared/Infrastructure/Messenger/JsonTransportSerializer.php) | Preserve framework-independent wire formats and explicitly version new metadata |
| Scheduler and CPU/media pool depend on Swoole lifecycle | [SchedulerProcess](../../src/Scheduler/Infrastructure/Swoole/SchedulerProcess.php), [CpuProcessPool](../../src/Shared/Infrastructure/Swoole/ProcessPool/CpuProcessPool.php) | Separate lifecycle and IPC before removing web-owned processes |
| Worker recycling is disabled because child-pool lifetimes are coupled | [swoole.yaml](../../config/packages/swoole.yaml) | Move child ownership, then test recycling and restore bounded lifetimes |
| Encoding loops and FFmpeg streams are started from HTTP coroutines | [TranscodeSessionSubscriber](../../src/Transcode/Infrastructure/Swoole/TranscodeSessionSubscriber.php), [TranscodeStreamManager](../../src/Transcode/Infrastructure/Swoole/TranscodeStreamManager.php) | Queue durable session intent; worker owns encoders and control |
| Segment readiness and seek channels are process-local | [SegmentAvailabilityTable](../../src/Transcode/Infrastructure/Swoole/SegmentAvailabilityTable.php), [SeekSignalBroker](../../src/Transcode/Infrastructure/Swoole/SeekSignalBroker.php) | Shared readiness/control with session generation and ownership checks |
| Image conversion and learning persistence start background coroutines from web | [ImageController](../../src/Media/Interface/Controller/ImageController.php), [LearningEngineSubscriber](../../src/QoL/Infrastructure/Swoole/LearningEngineSubscriber.php) | Queue background work; preserve current response/fallback behavior |
| Sampling and governor timers depend on HTTP-worker boot | [CpuGpuSampler](../../src/QoL/Infrastructure/Swoole/CpuGpuSampler.php), [SwooleWorkerEventSubscriber](../../src/Shared/Infrastructure/Swoole/SwooleWorkerEventSubscriber.php) | Worker owns device sampling/reservations; web reads shared admission state |

This is a source baseline, not production capacity certification. GitNexus returned
malformed metadata during review; source inspection supplied the evidence. Repair
and refresh the index before implementation symbol impact analysis.

## Runtime ownership

`app:serve` owns HTTP workers, request handling, streaming responses and connection
subscriptions. It may submit work and read status/results. It must not fork background
queue consumers, a scheduler, an outbox relay, encoders, or a CPU worker pool.
Request authorization and synchronous domain operations remain synchronous where
their contracts require a result; moving background work does not make every command
asynchronous.

`app:worker` owns one process tree:

- A supervisor event loop that handles signals, child exit/reaping, health and
  bounded restart backoff. It does not execute application handlers itself.
- A scheduler service and an outbox relay service, each with a configured singleton
  count initially. Their loops run in children so slow database operations cannot
  block supervision.
- Queue consumers whose counts are controlled by one autoscaling policy.
- Media execution capacity and its session/control service. Encoder subprocesses
  belong to this tree and count toward its resource limits.

The existing Messenger and outbox commands may remain internal child entrypoints.
They are hidden from normal deployment instructions, not duplicated by a second
implementation. `app:scheduler:run` remains a one-shot maintenance action. There
must be no path where both Swoole boot hooks and the supervisor start the same
service. Role selection must happen before booting role-specific services.

Use an existing supported child-process API where it provides signal, pipe and exit
handling. Do not fork a booted Doctrine connection into handler children. Spawn
fresh CLI processes with explicit environment and unique consumer identities.
Implementation language/runtime choices must satisfy this lifecycle contract;
Swoole HTTP server state is not an acceptable dependency of supervision.

### Media boundary

Moving the pool requires more than changing command names. Replace inherited pipes,
Swoole tables and worker-local session ownership with an explicit cross-process
contract. Use durable queued requests with stable operation/session identifiers,
authoritative persisted session state, and bounded notifications for completion and
control. Notifications are wake-ups; reconnecting readers reconcile persisted state.
Keep control messages responsive when encoder capacity is exhausted.

For the first supported topology, web and worker services share authorized media
and derived-file volumes on one host. Web handles delivery; worker handles encoding,
probing and background media work. Reuse existing media storage and session contracts
where suitable; inventory any process-local state before selecting its replacement.
Pause, resume, cancellation, seeking, live delivery and worker restart must pass
integration tests before this boundary is declared complete. Do not leave an
undocumented web-owned pool as a permanent exception to the two-command contract.

Publish segment readiness with a session generation so a seek/restart cannot expose
stale segments. Controls target the leased session owner and carry an ordered
revision; coalesce superseded position updates without discarding cancellation.
Submit transcode intent through the same commit boundary as its session state.
Reattaching a client must not create a second encoder. Hardware probing and device
reservations belong to the worker host. Existing WebSocket/SSE connections stay in
web and consume shared notifications; workers do not instantiate an HTTP server.

## Queue policy

Define queue routing and consumer limits in one validated configuration, consumed
by both dispatch and supervision. Each asynchronous message type has exactly one
documented route, a handler, a wire-codec entry, retry policy and resource class.
Transport-specific handler attributes must agree with the route. Explicit synchronous
messages remain outside this inventory; reject accidental unhandled async routes.

Initial queue families are functional, not one queue per PHP class:

| Family | Initial candidates | Capacity policy |
|---|---|---|
| `control` | Transcode/session control and short coordination work | Reserved service capacity; never wait behind bulk media |
| `notifications` | Email, push and asynchronous webhook delivery | I/O bound; provider limits applied at execution |
| `catalog` | ScanLibraryCommand, FilesDiscovered, missing-image pruning | Bounded batches; independent of interactive work |
| `metadata` | Song/album/library metadata sync and country-station sync | I/O bound with provider/account budgets |
| `media` | ExtractAlbumCoverCommand and queued encode/probe operations | Explicit CPU, RAM and device-slot reservations |

Scheduler dispatch and outbox relay are supervised services, not recursively queued
copies of themselves. Classify scheduled payloads by actual work; a generic scheduled
command must not bypass media limits or execute an arbitrary long task in `control`.
The implementation inventory must include currently synchronous event consumers and
nested dispatches when deciding which side effects move to these queues.

Scheduler occurrences need stable identities and atomic claim/dispatch intent so
restarts do not silently skip or double-submit a due occurrence. Recover expired
claims and renew ownership for long executions. Scheduled console jobs must use
registered, allowlisted commands and inherit resource and process-tree limits.

Every queue declares minimum/maximum consumers, wait target, assumed job duration
for cold start, memory/CPU reservation, restart policy and shutdown deadline.
Validate all minimum reservations against the deployment budget at startup. The
release ships a measured default profile, not guessed concurrency derived solely
from host CPU count. Operators configure budgets and limits, not extra commands.

## Autoscaling controller

### Signals

Measure ready-but-unclaimed backlog, oldest ready enqueue age, active jobs, successful
completion rate, recent service duration, retries and available resource budget.
Keep delayed retries, executing messages and dead-letter entries separate.
`XPENDING` describes delivered but unacknowledged work; idle time there is a recovery
signal, not queue wait time. `XINFO GROUPS` lag can be unknown after history changes;
unknown is never interpreted as zero. See the official [pending-entry](https://redis.io/docs/latest/commands/xpending/)
and [consumer-group](https://redis.io/docs/latest/commands/xinfo-groups/) references.

The transport adapter supplies bounded samples and reports confidence. Use stream
admission order/identifiers to locate the oldest unread entry and explicit admission
metadata where delayed delivery changes eligibility. Preserve original dispatch time
separately for end-to-end latency. Do not scan an entire stream each interval or use
stream length as backlog when retained or pending entries exist. Detect trimmed or
missing entries and report degradation rather than inventing a rate.
Reset rate estimates after counter resets or sampling gaps. Bootstrap streams and
groups before sampling; an absent group is not an empty configured queue.

### Decisions

Start with a configurable five-second sampling interval, two consecutive overload
samples before ordinary scale-up, and a sixty-second scale-down cooldown. These are
initial policy values to test, not measured throughput guarantees.

1. Maintain mandatory service counts and configured consumer minima. When a queue
   at zero has ready work, start one eligible consumer immediately. Reclaimable
   pending work must also wake a recovery consumer, even with no unread entries.
2. Estimate required consumers from arrival rate plus backlog to clear within the
   queue's wait target, divided by measured per-busy-consumer completion capacity.
   Use a configured service-duration estimate before enough samples exist. Clamp
   the estimate to queue limits and the global resource budget.
3. Scale up when backlog/wait pressure persists and a resource reservation fits.
   Add at most one consumer per queue per tick initially. An old executing job by
   itself never justifies more consumers. Use bounded fair allocation across
   eligible queues while retaining reserved control/service capacity.
4. Scale down one idle consumer after sustained excess capacity or an empty queue,
   honoring minima and cooldown. An empty queue has its own idle rule: zero arrivals
   and zero completions must not prevent scale-down. A busy consumer selected for
   retirement finishes its current delivery before exiting where possible.
5. Stop scale-up under memory/CPU/device pressure; retire idle excess capacity first.
   Reaching a cap while missing the wait target reports saturation. It does not
   permit breaching the cap or dropping messages.
6. On stale/unavailable queue metrics, retain bounded last-known desired counts,
   keep required services alive, and stop metric-driven scaling. Apply restart
   backoff and dependency health rules so outages cannot cause a spawn storm.

Account for supervisor, scheduler, relay, consumers and their descendant processes
in the worker budget. Reserve web/database headroom when sharing a host; never claim
all host memory as worker capacity. A process's PHP memory limit is not a hard
limit on native allocations or descendants. Use container/cgroup limits plus
conservative admission reservations; calibrate these with measured RSS/CPU.

V1 supports one active worker supervisor per deployment namespace. Enforce a local
exclusive lock for duplicate starts and a renewable, fenced deployment lease for
cross-container duplicates; another supervisor fails clearly rather than becoming
an unplanned second scaler. Lease loss stops admission and triggers bounded draining.
Before takeover starts children, prove predecessor containment or fence overlapping
operations; lease expiry alone cannot kill an old process or undo an external send.
Automatic multi-host active/active scaling and unfenced failover are outside v1.
Each consumer identity includes the deployment, supervisor boot ID, queue and child
ID. Reclaim abandoned pending entries before deleting consumer metadata.

## Delivery, shutdown and restart

Preserve at-least-once delivery. Handler success means its promised durable work
succeeded; logging an exception and returning must not acknowledge failed cover
extraction or other required effects. Fix these paths before tuning scaling.
Domain changes and outbox insertion share a database transaction. Replayed events
retain stable IDs, retry/dead-letter semantics and idempotent consumer effects.
No exactly-once claim is made for email, webhooks or other external effects.

Long-running deliveries renew their transport ownership/keepalive before the
redelivery timeout; test that a healthy long job is not reclaimed concurrently.
Remove unsafe length-only stream trimming, including the current unconditional
size cap, or replace it with a proven acknowledgement-aware retention policy.
Disk/Redis pressure must apply explicit admission backpressure and alerts rather
than evict accepted unread or pending work. Configure Redis persistence and memory
policy for the stated durability requirement and test crash restoration; a worker
acknowledgement is not proof that a Redis write survived host loss.

Do not add generic payload-hash deduplication. Where duplicate submission is costly,
define a business operation key and durable state, with retry/reclaim semantics
that survive failure before enqueue, after side effects and before acknowledgement.
Coalescing requests for a changing resource must retain newer revisions. Provider
rate limits apply to actual attempts, including retries, using provider/account
keys; an entity ID must not multiply an account's allowance. Throttling schedules
a bounded delayed retry without blocking supervision or silently dropping work.

On SIGTERM, mark the supervisor unready, stop scheduling/new child admission, tell
consumers to stop receiving, and drain active jobs. Keep required lease/health
renewal active while draining. Use per-class deadlines followed by process-group
termination and reaping. Interrupted work remains recoverable; never acknowledge
it just to shut down. A shutdown deadline can require killing a job: promise safe
recovery, not that jobs can never be interrupted. Container/service-manager grace
periods must exceed the supervisor's complete shutdown budget.

Supervisor death must not leave uncontrolled consumers or encoders. Define and test
process-group/cgroup containment and parent-death behavior for both container and
host deployments. Restart with bounded exponential backoff and jitter; repeated
crashes make the affected capability unready and observable. Cap restart attempts
per window. Do not create a tight retry loop for invalid configuration or poison work.

## Health, message contracts and visibility

Report web liveness/readiness separately from worker health. Starting the web
command does not start workers as a side effect. A worker outage makes affected
operations unavailable or visibly queued according to their API contract, without
falsely declaring the web process dead. Worker readiness requires valid configuration,
working dependencies, required child heartbeats and a functioning supervisor loop.
A legitimately idle, zero-consumer queue is healthy.

Expose worker status through the existing authenticated admin surface: desired and
actual counts, queue wait/backlog, active work, retries/dead letters, saturation,
restart history and dependency/lease state. Use bounded-cardinality metrics and
existing monitoring where possible; select a maintained compatible exporter if
needed. Do not build a Redis histogram subsystem merely to avoid a dependency.
Telemetry failure must not alter delivery acknowledgement semantics.

Keep message bodies and metadata explicit, bounded and independent of Symfony
serialization. Correlation/operation IDs cross HTTP, CLI, scheduler, retry and outbox
boundaries without leaking between jobs or coroutines. Any new envelope field must
have encode/decode compatibility tests and an upgrade policy; the current serializer
rejects unknown sendable stamps. A PipelineStamp alone is not a wire contract.

Job duration and start/end RSS are useful separate measurements. Per-job peak memory
requires interval sampling or an isolated job process; process-lifetime peak values
must not be labeled per-job peaks. Defer a workflow engine until a concrete workflow
needs persisted transitions or recovery. If added, transition state and dispatch
intent commit together through the outbox.

## Implementation sequence and release gates

Each stage is a reviewable change with regression coverage. Intermediate commands
may exist during development; the two-command contract is complete only after the
last web-owned background service has moved.

1. **Inventory and delivery correctness.** Enumerate routing, transport restrictions,
   nested dispatch, all Swoole boot hooks and child processes. Fix acknowledged
   failures; retain poison/retry/outbox tests. Record runtime/resource measurements.
2. **Independent supervisor and command facade.** Add `app:serve` and `app:worker`,
   role-specific boot, child lifecycle, locks, budgets, health, signals and containment.
   Initially supervise existing consumers/relay through tested adapters; extract
   scheduler lifetime from HTTP. Test startup failure and duplicate invocation.
3. **Queue separation and autoscaling.** Change routing and handler registration
   together, preserve wire compatibility, implement the bounded policy above, and
   remove background Swoole task routes as their consumers migrate. Drain legacy
   queues with an internal supervised compatibility consumer until empty; do not
   abandon already accepted messages during deployment.
4. **Media ownership.** Replace web-owned pool/session IPC with the worker service
   contract. Test playback/control, cancellation, reconnect and recovery. Enable
   HTTP-worker recycling only after child-lifetime regressions pass.
5. **Deployment cutover.** Compose, images, host service examples, startup scripts,
   probes and operator documentation use only the two long-running commands.
   Remove old competing Supervisor programs/boot hooks. Test clean installation,
   upgrade with pending work, rollback compatibility and shutdown under load.

Cutover includes `Dockerfile`, `docker-compose.yml`, `docker/general/supervisord.conf`,
`docker/general/start-supervisor.sh`, `bin/dev-server` and `bin/queue-worker`.
Do not clear a shared cache or logs when only one role restarts; use release-scoped
cache preparation before either process starts. GPU access belongs to the worker
service. No extra cron daemon is required for application scheduling.

Required acceptance scenarios:

| Scenario | Required result |
|---|---|
| Web alone, worker alone, both | Roles boot independently; no hidden duplicate services; dependency status is accurate |
| Backlog with zero consumers | Within two sample intervals, start a consumer when budget permits, including reclaim-only work |
| One slow job, no unread backlog | No scale-up solely from pending age |
| Burst across queues | Scale within limits; control remains responsive; no queue starvation; configured wait targets met or saturation reported |
| Idle after burst | Excess idle workers retire after cooldown, including when both measured rates are zero |
| Redis/DB outage or unknown lag | No false empty queue, unbounded retries, spawn storm or acknowledgement of unsuccessful work |
| Worker/encoder crash and poison message | Leased work recovers, retry/dead-letter policy applies, web remains available within its own resource budget |
| Healthy job exceeds redelivery interval | Keepalive prevents concurrent reclaim; abandoned ownership still recovers after a crash |
| Retention pressure and Redis restart | Accepted unread/pending entries survive the configured durability boundary; admission fails explicitly when safe retention is impossible |
| Supervisor death, duplicate start, lease loss | No uncontrolled descendants or competing admission; interrupted work remains recoverable |
| Graceful drain and forced deadline | Deadlines are honored; process tree is reaped; no acknowledged work is lost |
| Web restart during playback and background jobs | Background ownership survives; delivery/control reconnect follows explicit API behavior |
| Minimum and saturated deployment profiles | All descendants fit measured budgets; reservations account for native allocations and device slots |
| Upgrade with queued/retrying old messages | Codec/routing compatibility and legacy drain are verified; no missing-handler failures |

Use a fake monotonic clock and deterministic samples for policy tests, real Redis
consumer groups for queue semantics, and disposable PostgreSQL for transaction and
recovery tests. Run process/signal/resource tests against the deployment image with
a load generator outside its budget. Test identities use `baander.app` with local
routing or mocks. Publish measured defaults and acceptance results before release;
this document does not certify capacity or claim these commands already exist.
