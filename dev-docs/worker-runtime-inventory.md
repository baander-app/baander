# Worker runtime inventory

Verified against source on 2026-10-02 for stage 1 of the
[web and worker runtime redesign](../docs/plans/2026-07-17-001-feat-messenger-enterprise-hardening-plan.md).
`app:serve` now names the existing foreground web server, and `app:worker` runs an
initial fixed Redis-consumer/outbox-relay supervisor. Queue families, autoscaling,
role ownership transfers and deployment cutover below remain planned. This inventory
does not certify runtime resource defaults.

## Current asynchronous routes

[messenger.yaml](../config/packages/messenger.yaml) declares **13 routes**: eleven
to `swoole_task`, two directly to Redis `async`. The table records current handler
registration separately from the proposed family. `Any` means no `fromTransport`
restriction; it does not mean the handler is asynchronously routed everywhere.
All thirteen have explicit version-1 entries in
[JsonMessageCodec](../src/Shared/Infrastructure/Messaging/JsonMessageCodec.php).

| Message (source path) | Current route | Handler and restriction | Wire type | Proposed owner / work class |
| --- | --- | --- | --- | --- |
| `Library/Application/Command/ScanLibraryCommand.php` | `swoole_task` | [ScanLibraryHandler](../src/Library/Application/CommandHandler/ScanLibraryHandler.php), Any | `library.scan` | Scaled `catalog`; filesystem scan, hash/probe work and directory batches |
| `Library/Application/Message/FilesDiscovered.php` | `async` | [FilesDiscoveredHandler](../src/Catalog/Application/CommandHandler/FilesDiscoveredHandler.php), Any | `library.files_discovered` | Scaled `catalog`; ingestion/probing/persistence, cross-context Catalog handler |
| `Metadata/Application/Command/ExtractAlbumCoverCommand.php` | `async` | [ExtractAlbumCoverHandler](../src/Metadata/Application/CommandHandler/ExtractAlbumCoverHandler.php), `async` | `metadata.extract_album_cover` | Scaled `media`; file/image conversion with CPU and memory reservation |
| `Metadata/Application/Message/SyncSongMessage.php` | `swoole_task` | [SyncSongHandler](../src/Metadata/Application/MessageHandler/SyncSongHandler.php), `swoole_task` and `async` | `metadata.sync_song` | Scaled `metadata`; provider I/O |
| `Metadata/Application/Message/SyncAlbumMessage.php` | `swoole_task` | [SyncAlbumHandler](../src/Metadata/Application/MessageHandler/SyncAlbumHandler.php), `swoole_task` and `async` | `metadata.sync_album` | Scaled `metadata`; provider I/O |
| `Metadata/Application/Message/SyncLibraryMessage.php` | `swoole_task` | [SyncLibraryHandler](../src/Metadata/Application/MessageHandler/SyncLibraryHandler.php), `swoole_task` and `async` | `metadata.sync_library` | Scaled `metadata`; bounded fan-out coordination |
| `Notification/Application/DTO/SendEmailCommand.php` | `swoole_task` | [SendEmailHandler](../src/Notification/Application/Handler/SendEmailHandler.php), `swoole_task` and `async` | `notification.send_email` | Scaled `notifications`; provider I/O and delivery limits |
| `Notification/Application/DTO/SendPushCommand.php` | `swoole_task` | [SendPushHandler](../src/Notification/Application/Handler/SendPushHandler.php), `swoole_task` and `async` | `notification.send_push` | Scaled `notifications`; subscription fan-out/provider I/O |
| `Media/Application/Command/PruneMissingImagesCommand.php` | `swoole_task` | [PruneMissingImagesHandler](../src/Media/Application/CommandHandler/PruneMissingImagesHandler.php), Any | `media.prune_missing_images` | Scaled `catalog`; filesystem/persistence maintenance |
| `Radio/Application/Command/SyncCountryStationsCommand.php` | `swoole_task` | [SyncCountryStationsHandler](../src/Radio/Application/CommandHandler/SyncCountryStationsHandler.php), `swoole_task` and `async` | `radio.sync_country_stations` | Scaled `metadata`; remote provider I/O |
| `Scheduler/Application/Command/ExecuteScheduledJobCommand.php` | `swoole_task` | [ExecuteScheduledJobHandler](../src/Scheduler/Application/CommandHandler/ExecuteScheduledJobHandler.php), Any | `scheduler.execute_job` | Fixed scheduler service dispatches occurrences; actual payload selects queue/resource class |
| `Shared/Domain/Event/Outbox/RelayOutboxCommand.php` | `swoole_task` | [RelayOutboxHandler](../src/Shared/Domain/Event/Outbox/RelayOutboxHandler.php), Any | `outbox.relay` | Fixed outbox service; direct bounded relay calls, not recursive relay queue |
| `Transcode/Application/Command/UpdateTranscodePositionCommand.php` | `swoole_task` | [UpdateTranscodePositionHandler](../src/Transcode/Application/CommandHandler/UpdateTranscodePositionHandler.php), Any | `transcode.update_position` | Scaled `control` with reserved capacity; persist position and signal session owner |

Message paths in the first column are relative to `src/`. Queue family names are
design candidates, not existing transport aliases. Every new transport requires a
matching handler registration, explicit retry/failure policy, codec compatibility,
and measured execution budget before activation.

Redis currently uses stream `messages`, group `baander`, per-process consumer names,
`delete_after_ack: true`, `stream_max_entries: 0`, a 3600-second redelivery
timeout, a 60000-ms claim interval and three retries with exponential delay. Failed
messages use a separate `failed_messages` stream/group `failed`. These settings
apply to direct Redis and fallback traffic. Length-based trimming is disabled
because it can remove unread and pending payloads. Existing consumer launchers
request transport keepalive every 30 seconds. Swoole task dispatch does not itself
supply the same durable consumer-group/retry contract. The current local busy
heartbeat still ages out after an hour; renewable long-job health belongs in the
supervisor lifecycle work.

The Redis transport now carries a locked Composer patch for recoverable delayed
promotion. It selects a due member, inserts it into the stream, and only then
removes the exact delayed member. Failed insertion preserves the retry; interrupted
removal or concurrent pollers may duplicate it. Each poll promotes at most 100
messages. See [patch notes](../patches/README.md). Poison delayed members and memory
pressure can still block polling, so quarantine, admission/backpressure and Redis
crash persistence remain release gates.

## Nested dispatch and durable delivery

| Entry | Verified continuation | Migration consequence |
| --- | --- | --- |
| ScanLibraryHandler | Dispatches `FilesDiscovered` per directory; emits `LibraryScanCompleted` after scan dispatch | Scan completion is not downstream ingestion completion; preserve bounded payloads and recover accepted batches |
| FilesDiscoveredHandler | Processes music/movie files and propagates aggregated file failures; dispatches `ExtractAlbumCoverCommand` for coverless albums after successful final song/genre flushes | Dispatch failure reaches Messenger; retry revisits an existing album and deduplicated songs before retrying cover dispatch |
| [MetadataSyncOrchestrator](../src/Metadata/Application/MetadataSyncOrchestrator.php) | Dispatches library/album/song routes plus unrouted `SyncArtistMessage` and `SyncGenresMessage` | Artist/genre work currently executes synchronously through unrestricted handlers; do not silently turn them into unsupported wire messages |
| SyncLibraryHandler / [SyncGenresHandler](../src/Metadata/Application/MessageHandler/SyncGenresHandler.php) | Dispatch album and optionally song messages during iteration | Capacity must account for fan-out, not only outer coordination duration |
| [NotificationBridgeSubscriber](../src/Shared/Infrastructure/Event/NotificationBridgeSubscriber.php) | Private outbox replay dispatches synchronous `CreateNotificationCommand`; channel commands go to [NotificationDeliveryBus](../src/Shared/Infrastructure/Event/NotificationDeliveryBus.php) | Preserve transactional projection and delivery-intent insertion; do not replace the injected bus with immediate external sends |
| [RelayNotificationDeliveriesHandler](../src/Shared/Infrastructure/Event/RelayNotificationDeliveriesHandler.php) | Decodes committed email/push/webhook intents and forces `TransportNamesStamp(['async'])` | Route migration must update this explicit override. Webhook is asynchronous through this path despite having no YAML route |
| UpdateTranscodePositionHandler | Emits `PlaybackPositionChanged`; [PlaybackPositionChangedListener](../src/Transcode/Infrastructure/Swoole/PlaybackPositionChangedListener.php) signals SeekSignalBroker | Delivery to a different process cannot reach the existing process-local encoding channel |

[OutboxEventDispatcher](../src/Shared/Infrastructure/Event/OutboxEventDispatcher.php)
uses a private notification replay dispatcher and transactional receipt
`notifications.v1`; it does not replay every ordinary runtime event listener.
[OutboxSubscriberPass](../src/Shared/Domain/Event/Outbox/OutboxSubscriberPass.php)
and [services.yaml](../config/services.yaml) define that wiring. Existing outbox
leases, renewals, retries, dead letters and replay receipts must survive extraction.
[RelayOutboxWorkerCommand](../src/Shared/Infrastructure/Event/RelayOutboxWorkerCommand.php)
already runs both event relay and delivery-intent relay directly, clears the ORM
between iterations, handles TERM/INT, and has bounded time/memory exit conditions.

## Scheduler payloads

[SchedulerProcess](../src/Scheduler/Infrastructure/Swoole/SchedulerProcess.php)
starts one Swoole child, checks active jobs every 60 seconds, takes per-job Redis
NX/EX locks with a 3600-second default TTL, and dispatches the wrapper containing
`jobId`, `jobType`, `command` and `parameters`. There is no immediate startup tick
or persisted occurrence identity in that loop. Dispatch exceptions can leave the
lock until expiry; lock release is an unconditional delete, not an ownership-token
comparison. These are current recovery limits, not the proposed occurrence model.

[SchedulerRegistry](../src/Scheduler/Domain/Service/SchedulerRegistry.php) collects
tagged implementations of the two schedulable interfaces. Current allowlisted
payloads are:

| Registered payload | Current execution / codec | Proposed resource placement |
| --- | --- | --- |
| [PruneMissingImagesCommand](../src/Media/Application/Command/PruneMissingImagesCommand.php) | Routed Swoole task; codec present | `catalog` |
| [BatchExtractCoversCommand](../src/Catalog/Application/Command/BatchExtractCoversCommand.php) | No route or codec; [handler](../src/Catalog/Application/CommandHandler/BatchExtractCoversHandler.php) requires `swoole_task` and fans out extraction commands | `catalog` coordination then reserved `media` extraction; currently executes synchronously |
| [BulkFetchLyricsCommand](../src/Lyrics/Application/Command/BulkFetchLyricsCommand.php) | No route or codec; unrestricted [handler](../src/Lyrics/Application/CommandHandler/BulkFetchLyricsHandler.php) loops songs and synchronously dispatches unrouted `FetchLyricsCommand`, with a default 500-ms delay | `metadata` provider budget; bounded fan-out if made asynchronous |
| [CleanupOrphanedJobsCommand](../src/Transcode/Application/Command/CleanupOrphanedJobsCommand.php) | No route or codec; unrestricted [handler](../src/Transcode/Application/CommandHandler/CleanupOrphanedJobsHandler.php) calls cleanup synchronously | Bounded maintenance capacity; classify measured cleanup cost before family assignment |
| [SweepTranscodeCacheCommand](../src/Transcode/Interface/Console/SweepTranscodeCacheCommand.php) | Console payload `scheduled_console` via CPU pool, then `proc_open` of `bin/console` | Worker-owned maintenance child with descendant/process and storage-I/O limits |

The wrapper validates the current registry, reconstructs Messenger payloads with
`new ($messageClass)(...$parameters)` and calls the bus. It marks success after
dispatch, not after an asynchronously routed payload completes. The batch-cover
handler executes synchronously: Symfony applies its transport restriction only
when a ReceivedStamp is present. It then dispatches individual cover extractions
to Redis.

Batch cover dispatch stops on the first rejected dispatch and preserves its
original exception if diagnostics also fail. Earlier accepted messages may run
again when the batch is retried. Both the handler and standalone extraction command
now use pages of 500 ordered by UUID, advancing strictly beyond the last UUID.
Completed extractions cannot shift an offset and skip remaining albums, and albums
without embedded art do not prevent cursor advancement. This is a live traversal,
not a snapshot: concurrent inserts or eligibility changes behind the cursor wait
for a later run. The API controller dispatches the actual Catalog batch command;
the coordinator runs synchronously and queues individual extraction jobs.

Console execution uses
[SchedulerConsolePoolWorker](../src/Scheduler/Infrastructure/Swoole/SchedulerConsolePoolWorker.php)
and positional arguments or `--key=value` options. The wrapper polls results for
300 seconds by default and truncates returned output to 10000 characters. It
always reads file-backed results, independently of the optional shared table, and
uses a unique key per execution. Known child failures are recorded as failures.
A timeout or result-read exception records unknown completion and pauses an active
schedule without requesting a Messenger retry. Operators must reconcile the child
process before resuming it; pausing does not cancel a child. Existing queued/manual
commands and failure to persist the pause still need durable execution ownership.
Console execution must remain allowlisted, with bounded output, cancellation,
ownership renewal and descendant cleanup; the generic wrapper cannot bypass media
budgets.

## Swoole lifecycle and background ownership

All five application `Bootable` implementations are explicitly tagged in
[services.yaml](../config/services.yaml). Tables created before fork are shared
only inside the existing process ancestry; they are not independent-service IPC.

| Current hook / service | Current work and state | Proposed lifetime |
| --- | --- | --- |
| [HardwareCapabilitiesProber](../src/Transcode/Infrastructure/FFmpeg/HardwareCapabilitiesProber.php) `boot()` | Probes encoder/device support; lazily boots from `getProfile()` too | Worker owns probing/device reservations; publish admission/profile state needed by web |
| [SegmentAvailabilityTable](../src/Transcode/Infrastructure/Swoole/SegmentAvailabilityTable.php) `boot()` | Pre-fork Swoole table, configured 16384 rows; readiness with file-stat fallback | Worker publishes shared readiness by session generation; web reads it |
| [CpuProcessPool](../src/Shared/Infrastructure/Swoole/ProcessPool/CpuProcessPool.php) `boot()` | Six configured Swoole pipe children; 8192-row result table plus result files under `/tmp/baander_cpu_pool_results`; JSON registry instantiates handlers without container arguments | Worker owns all CPU children and IPC/results; resource reservations include their subprocesses |
| SchedulerProcess `boot()` | One pipe child/timer; two-second shutdown wait before KILL | Fixed scheduler child under worker supervisor |
| [CpuGpuSampler](../src/QoL/Infrastructure/Swoole/CpuGpuSampler.php) `boot()` | Pre-fork sampling table; timer deferred to HTTP worker 0 | Fixed worker sampling/control service |
| [SwooleWorkerEventSubscriber](../src/Shared/Infrastructure/Swoole/SwooleWorkerEventSubscriber.php) | HTTP worker 0 starts pool health, sampler and [MidStreamMonitor](../src/QoL/Infrastructure/Swoole/MidStreamMonitor.php); imports governor learning state; server SIGINT hook stops pool and server | Worker owns health/sampling/governor timers. Web keeps connection registry/pusher and HTTP lifecycle |
| [CpuProcessPoolShutdownHandler](../src/Shared/Infrastructure/Swoole/ProcessPool/CpuProcessPoolShutdownHandler.php) / [SchedulerProcessShutdownHandler](../src/Scheduler/Infrastructure/Swoole/SchedulerProcessShutdownHandler.php) | Web/server shutdown stops background children | Replace with worker-owned drain/reaping; no competing shutdown owner |
| [TranscodeSessionSubscriber](../src/Transcode/Infrastructure/Swoole/TranscodeSessionSubscriber.php) | `TranscodeSessionAttached` starts a CoWrapper coroutine (inline fallback), Redis loop lock and renewal timer; drives encodes/completion/failure | Worker media session service; durable intent and recoverable control |
| [TranscodeStreamManager](../src/Transcode/Infrastructure/Swoole/TranscodeStreamManager.php) | Long-lived FFmpeg through ProcOpenSpawner, outside one-shot CPU pool; four-stream limit per manager instance; poll/drain output and STOP/CONT/KILL control | Worker media descendants with global deployment budget and cancellation |
| [SeekSignalBroker](../src/Transcode/Infrastructure/Swoole/SeekSignalBroker.php) | In-process per-job Coroutine Channels, capacity 16; missing channels drop signals | Worker-owned session control with explicit delivery/ownership semantics |
| [ImageController](../src/Media/Interface/Controller/ImageController.php) | Missing preset/WebP starts fire-and-forget conversion coroutine; serves original image immediately | Scaled `media` jobs; preserve original-response fallback |
| [LearningEngineSubscriber](../src/QoL/Infrastructure/Swoole/LearningEngineSubscriber.php) | Completion starts coroutine (inline fallback), loads job/sample, updates governor, releases allocation and sometimes persists learning | Worker-owned learning persistence; preserve sample provenance and reservation release |
| [SessionBudgetSubscriber](../src/QoL/Infrastructure/Swoole/SessionBudgetSubscriber.php) | Priority-1 synchronous attachment listener can veto then allocate a stream before encoding listener | Keep synchronous admission behavior through shared worker admission state; do not defer veto until after response |

CPU pool handlers are [TranscodePoolWorker](../src/Transcode/Infrastructure/Swoole/TranscodePoolWorker.php)
(`encode_segment`, `encode_init_segment`, `analyze_loudness`, `extract_subtitles`),
[RecommendationPoolWorker](../src/Recommendation/Infrastructure/Swoole/RecommendationPoolWorker.php)
(`generate_recommendations`) and SchedulerConsolePoolWorker (`scheduled_console`).
[GenerateRecommendationsHandler](../src/Recommendation/Application/CommandHandler/GenerateRecommendationsHandler.php)
also uses this pool and has inline fallback; moving the pool affects recommendation
execution as well as media. These internal JSON job payloads are distinct from the
versioned Messenger codec and need an explicit compatibility contract when IPC moves.

## Deployment and migration traps

[supervisord.conf](../docker/general/supervisord.conf) currently owns three daemons:
`swoole:server:run`, one `messenger:consume async` and `app:outbox:consume`. Swoole
configures four HTTP workers and two task workers; there is no current queue
autoscaler. [swoole.yaml](../config/packages/swoole.yaml) disables both HTTP and
task-worker recycling because of child-pool lifetime coupling. The configured
six-child pool must be measured at runtime; comments describing older worker
counts are not a process-tree measurement.

The proposed worker tree has fixed scheduler/outbox/control-service ownership and
scaled queue consumers for `control`, `notifications`, `catalog`, `metadata` and
`media`. Media descendants consume the same reservations as their parent jobs.
Reserved control capacity is required, but its measured minimum and every family
limit remain implementation configuration, not asserted defaults here.

1. Change routing and transport-restricted handlers together. Metadata song/album/
   library and radio handlers now accept both `swoole_task` and `async`, so Redis
   fallback deliveries match their actual received transport.
   Preserve and drain old `messages`, retries and failures during cutover.
2. [SwooleTaskWithRedisFallbackSender](../src/Shared/Infrastructure/Messenger/SwooleTaskWithRedisFallbackSender.php)
   builds a task envelope with `ReceivedStamp` and sends that envelope to Redis if
   dispatch fails. [HttpServerTaskDispatcher](../src/Shared/Infrastructure/Messenger/HttpServerTaskDispatcher.php)
   depends on the live HTTP server. Independent worker boot must avoid that dependency
   and must not acknowledge a missing-handler or failed dispatch as completed work.
3. Email/push have two allowed transports; webhook has an unrestricted handler and
   codec entry but no YAML route. Delivery relay's explicit `async` override is part
   of the accepted-message contract and must move with notification routing.
4. [JsonTransportSerializer](../src/Shared/Infrastructure/Messenger/JsonTransportSerializer.php)
   accepts only explicit stamps: transport ID, bus, correlation, job ID, delay, retry,
   failure receiver, transport names, bounded handled-handler names and error details.
   Unknown sendable stamps are rejected; non-sendable stamps are omitted. Preserve
   version-1 messages, the default 1-MiB payload bound, exact fields and bounded JSON
   depth when introducing operation/resource metadata. Codec support alone does not
   imply a route, and scheduler allowlisting alone does not imply codec support.
5. Preserve outbox transaction/receipt and delivery-intent behavior. Retry and
   dead-letter ownership already exists in both event and notification relays;
   moving supervision must not introduce a second queue-based relay loop.
6. Separate process-local FFmpeg handles, channels, pre-fork tables, governor
   allocations and result files before web recycling or independent role restarts.
   Test seek/pause/resume/reconnect against the actual owning session generation.
7. Review suppress-and-return paths before treating handler return as delivery
   success. Cover fan-out now propagates dispatch failures, and scheduler console
   uncertainty pauses cron dispatch; asynchronous payload completion still needs
   an explicit acknowledgement contract. File-ingestion, email, push and outbox handlers propagate
   their material failures; preserve those semantics.
8. Cut over Dockerfile/Compose, startup scripts, Supervisor and `bin/dev-server` /
   `bin/queue-worker` together. Role restart must not clear shared cache/logs or
   restart the other role; release-scoped preparation precedes both. GPU ownership
   and all encoder/console descendants belong to the worker budget.

## Verification and open measurements

The first delivery correction makes `ExtractAlbumCoverHandler` propagate read,
storage and persistence failures to Messenger. Logging and failed cleanup cannot
replace the original failure or undo a committed cover. Each extraction attempt
uses a distinct stored path; uncertain commits/failed rollbacks retain the file
for reconciliation rather than risk deleting committed data. This does not add
automatic orphan reconciliation or serialize concurrent successful extractions.

Twenty focused unit cases verify these branches. Two real Redis delivery cases
verify retry/recovery and dead-letter behavior after storage failure, using the
production handler and JSON codec. The combined unit/rule suite passes 3,106 tests
with 8,744 assertions; the selected disposable integration runner passes 22 tests
with 331 assertions.

A further production-kernel test with real PostgreSQL flush failure reproduced
the next delivery receiving a closed manager. Swoole removes pooled services from
Symfony's ordinary reset list, so the standard reset subscriber was insufficient.
`WorkerServicePoolResetSubscriber` now releases the current context's pools after
delivery and at stop, before the remaining Symfony reset. Six focused lifecycle
tests pass with 17 assertions. The real PostgreSQL/Redis regression passes with
30 assertions: an independent connection observes rollback and then one committed
image/album link after the same handler retries. DAMA rollback is explicitly disabled
for that case. Focused production PHPStan and syntax checks pass. These checks do
not certify uncertain-commit reconciliation, concurrent extraction serialization,
or the future supervisor's resource and shutdown behavior.

The next delivery correction adds explicit `async` registration to the song,
album, library and radio synchronization handlers while retaining `swoole_task`.
Sixteen registration cases compile their actual attributes through Symfony's
configurator and MessengerPass, checking both transports, unrelated-transport
rejection and synchronous deduplication. Four real Redis fallback cases verify
JSON payload preservation, production-handler invocation and acknowledgement;
metadata ports return missing/empty aggregates to avoid external provider calls.
The combined unit/rule suite now passes 3,122 tests with 8,772 assertions, and the
messaging runner passes 26 tests with 389 assertions. Focused production PHPStan
passes. Provider integration and future queue-family registration remain separate
gates.

The scheduler console correction passes 31 focused tests with 188 assertions.
They cover file-result polling without a table, known and malformed results,
unknown completion with paused/disabled state preservation, the saved pause before
lock release, cron exclusion, unique keys and failures in Redis plus diagnostics.
The combined unit/rule suite passes 3,144 tests with 8,935 assertions; focused
production PHPStan passes. These handler tests double the pool and persistence
ports; they do not certify real child cancellation or crash recovery.

Cover fan-out corrections pass eight ingestion reliability tests (40 assertions)
and three batch-dispatch tests (534 assertions). These verify ordering after final
flushes, retry with an existing album and deduplicated song, original exception
identity, skipping covered albums, and stopping after a rejected batch dispatch.
The full unit/rule runner passes 3,153 tests with 9,504 assertions; focused production
PHPStan passes. The ports and bus are test doubles: these tests do not certify
cross-connection persistence visibility or bulk pagination under concurrent work.

Cursor traversal and the API entry-point correction pass the combined unit/rule
suite: 3,161 tests with 11,564 assertions. Five PostgreSQL tests (34 assertions) use
the production kernel/repository and a separate writer to change cover eligibility
between pages, verify permanent coverless rows terminate, and check covered,
missing/deleted cursor and strict UUID boundaries. The fresh and repeat migration
runs pass; no schema change was needed. Focused production PHPStan passes with a
512-MiB analysis limit. API/client changes outside the import fix are matching
description-only corrections, with response schemas and generated types unchanged.

The retention regression reproduced actual pending-payload loss after 100,500
successful sends with the previous 100,000-entry cap. With trimming disabled, the
canonical messaging runner passes 28 tests with 613 assertions: all accepted IDs,
including unread and pending entries, remain until acknowledgement. The test uses
real production transport options and JSON delivery, then verifies acknowledgement
cleanup. A separate case verifies transport keepalive protects the active owner and
an abandoned delivery can be reclaimed with its original payload. Application-image
probes confirm PCNTL alarm support with Swoole loaded in ordinary CLI mode. These
checks do not certify Redis host-crash persistence, delayed-retry promotion or
full supervisor shutdown and containment.

Delayed-promotion regressions first reproduced an accepted retry disappearing
when Redis rejected stream insertion. With the patch applied, the canonical
messaging runner passes 33 tests with 783 assertions, including interrupted
removal, future due times, the 100-message promotion bound and retained poison
members. The full unit/rule suite passes 3,161 tests with 11,564 assertions.
A clean isolated Composer install of locked Redis Messenger 8.0.8 and Composer
Patches 2.0.0 applies the checksum-locked patch and matches the local patched file.
Dependency versions remain unchanged; production Docker dependency setup now
copies the patch lockfile. Composer validation has only the existing loose-version
constraint warnings. This does not certify the full production image build.

The independent supervisor's lifecycle primitives now live in
`src/Shared/Infrastructure/Worker/`: a direct-child process adapter, a local
duplicate-start lock and a bounded restart policy. Children use fresh argv-based
execution with inherited output descriptors, so logs are not accumulated in the
supervisor. Monotonic polling supports concurrent TERM drains, deadline escalation
to KILL and one-time reaping with preserved exit/signal information. The restart
policy has capped exponential backoff, jitter, a sliding attempt budget and explicit
exhaustion. These objects are excluded from automatic service registration; their
configuration and lifetime belong to the future supervisor.

The lock uses a persistent inode in a trusted directory and close-on-exec handles.
A real regression reproduced a live child retaining the lock after its supervisor
was killed, then passed for both creation and reopening after the handle fix.
The combined unit/rule suite passes 3,211 tests with 11,706 assertions, including
50 new lifecycle/lock/restart cases. Focused production PHPStan passes. Process
tests exercise real children, cooperative and ignored TERM, independent drain
deadlines, signal exits and 32 MiB of output without supervisor buffering.

`WorkerSupervisor` now composes these primitives for a fixed admitted child set.
It validates count and memory reservations before launching, preserves a separate
restart budget per child, exposes sanitized lifecycle snapshots and requires
external PID- and launch-identity-matched heartbeat evidence for readiness. Loss of authority closes
admission permanently and drains every child; a management failure attempts sibling
shutdown before surfacing its original exception. Reservations are accounting,
not enforcement of native memory or descendant limits.
The combined application-image unit/rule suite passes 3,235 tests with 11,859
assertions; focused production PHPStan passes. Twenty-two supervisor cases cover
real child exits/restarts, launch failure, restart exhaustion, admission budgets,
full-identity readiness, authority loss, concurrent drain and a restart-policy
exception that must still stop a live sibling and preserve the original error.

Launch identities now contain the deployment namespace, random supervisor boot
token, worker ID and an increasing attempt generation, represented as explicit
scalar arrays. Every attempted launch, including one whose launcher throws, must
receive a matching external containment acknowledgment before replacement.
Direct-child exit alone leaves `awaiting_containment`; stale or replayed
acknowledgments cannot release another generation. Draining with outstanding
containment cannot report `isStopped()`. `areDirectChildrenReaped()` deliberately
reports the narrower condition so a PID-1 supervisor can exit and let its external
container controller finish namespace containment without falsely releasing a lease.

The migration-managed PostgreSQL `worker_deployment_leases` table preserves a row
and increasing epoch for each deployment namespace. Acquisition requires a new
namespace or explicit predecessor-containment acknowledgment. Expiry alone never
permits takeover. Renewal locks the row before testing expiry against the database
clock; an actual regression reproduced the earlier single-UPDATE approach renewing
after an unchanged row lock delayed it past expiry. Owner boot and epoch qualify
renewal and containment acknowledgment. A committed acquisition with a lost
acknowledgment remains reserved for trusted-controller reconciliation.

The DBAL adapter uses a dedicated connection, commits before returning a token,
rejects caller-owned transactions, and bounds SQL statement and lock waits. Those
limits do not bound connection setup, network I/O or commit. The asynchronous lease
helper described below supplies the parent-side deadline; observing a database
token alone is not permission to launch. A real kernel/introspection check
confirms ORM schema diffs exclude the DBAL-owned lease table, while normal entity
tables remain visible. Fresh installation and repeat migration runs pass.
The canonical messaging/PostgreSQL suite passes 125 tests with 1,413 assertions,
including seven database lease cases, 14 fresh-process lease-helper cases and
nine external-controller database coordination cases, 16 inventory cases, seven registered-recovery cases and
14 startup cases plus 24 creation/reconciliation cases. The schema check passes one
test with nine assertions
after all 16 migrations and a repeat no-op migration run. Two real-process cases
also verify blocked replacement with a surviving descendant and after a launcher
throws following process creation. Both container shutdown scenarios pass.
Failed or uncertain database operations discard the dedicated connection; actual
driver-BEGIN failure and committed-but-unacknowledged acquisition have regressions.

`LeaseAuthority` derives local validity from the monotonic request-start time,
TTL and a positive safety margin. Delayed replies cannot extend that deadline.
Renewal retains the old deadline while pending; expiry, rejected grants and
explicit revocation permanently close authority for that boot. Renewal must retain
the owner identity and epoch, and stale sequence replies cannot restore authority.

`LeaseAgentProcess` launches a fresh PHP CLI helper for acquire/renew operations,
without booting Symfony or serializing application objects. PostgreSQL credentials
travel through the environment, not arguments or response frames. The parent polls
without database I/O, enforces a deadline, sends TERM then KILL, and retains the
process until reaped. Strict JSON framing, identity checks and bounded output reads
reject malformed replies. Private output files have observed size limits, not disk
quotas; deployment containment must enforce resource limits. An uncertain committed
acquisition remains reserved for controller reconciliation.

`LeasedWorkerRuntime` combines the helper, authority and fixed-set supervisor.
Initial acquisition does not launch workers; each subsequent launch checks fresh
local authority. A blocked renewal cannot prevent child polling or draining when
authority expires. One process slot is reserved for the helper, whose memory belongs
in the management reservation. Shutdown cancels the helper and signals workers
without acknowledging containment or releasing the deployment lease. Invalid clock
readings still trigger initial stop attempts, then propagate to the outer owner
for containment. Real-process tests cover delayed acquisition, denied grants,
hung renewal, expiry during sibling launches and clock failure. The combined unit
and static-analysis-rule suite passes 3,416 tests with 12,232 assertions; focused
production PHPStan passes. These are correctness checks, not capacity measurements.

`scripts/test-worker-containment-container.sh` exercises this core as PID 1 in a
dedicated container with a child and a TERM-ignoring descendant. TERM and supervisor
SIGKILL both stop descendant activity and the container within the deadline,
without privileged mode, host mounts or networking. Its one-CPU/256-MiB test limit
is a fixture budget, not measured application capacity. Whole-container cleanup
does not prove descendant cleanup before restarting an individual worker. Host
systemd containment remains untested.

Explicit predecessor recovery now has an external-controller implementation.
`DeploymentContainmentController` checks the expected boot, retires the known full
container ID outside a database transaction, and acknowledges only the fetched
lease epoch. Concurrent recovery cannot release a newer boot or epoch. The
PostgreSQL tests use retirement callbacks to check this coordination; they do not
claim Docker containment from a mock.

`DockerWorkerContainment` requires deployment namespace/boot/role labels and a
restricted private PID/cgroup namespace. It rejects privileged containers, mounts,
devices and added capabilities. It then requires successful force-removal of the
immutable container ID before returning. A stopped-state observation is insufficient:
removal prevents that predecessor from restarting. Missing containers, mismatches,
command errors and uncertain removal outcomes leave ownership reserved. This
strict initial policy does not yet support media mounts or GPU devices.

`DockerWorkerCommand` pins a local Unix daemon endpoint, isolates Docker
configuration, uses argument arrays, and bounds CLI time and observed output. It
runs only in the trusted external controller, never in the supervisor loop. A CLI
timeout cannot cancel an operation already submitted to Docker; it cannot authorize
a lease release. Docker access is not exposed to the application container.
`scripts/test-worker-retirement-container.sh` verifies wrong-owner rejection and
forced retirement of a running restart-always container with a TERM-ignoring
descendant, then checks that the old ID cannot restart and absence is rejected.

The caller must durably associate a fresh deployment boot with its container ID
before starting it. Labels alone do not establish that lifecycle inventory or defend
against a malicious Docker operator. Automatic recovery after a lost removal
response, daemon replacement, and host-systemd containment remain unimplemented.
The combined Docker/PostgreSQL recovery runner now checks real adapter removal
and committed lease transitions together. Its deliberately controlled container
fixture does not run `LeasedWorkerRuntime`; deployment startup wiring remains open.

`worker_deployment_containers` records a deployment namespace and boot against
one Docker daemon ID and full container ID. The DBAL inventory permits exact retries
but rejects rebinding either the boot or the daemon/container pair. Records remain
after retirement. A separate migration and Doctrine schema filter preserve this
coordination table without presenting it as an ORM entity.

`RegisteredDeploymentRecovery` obtains the full container ID from that inventory;
callers cannot substitute another ID. It verifies the recorded daemon identity
before each inspect/removal command and retains the existing exact-epoch lease
acknowledgment. Missing inventory, observed daemon mismatches, invalid labels and
uncertain retirement cannot release ownership. The identity check and following
Docker command are separate calls, so they do not protect against an endpoint
swap between calls; the configured local daemon endpoint must remain trusted and
stable throughout recovery.

`RegisteredDeploymentStart` now accepts an explicitly precreated container. It
requires the deterministic namespace/boot name, matching daemon and labels, private
isolation, `created` state and Docker restart disabled. It commits registration and
a one-shot `start_claimed_at` before issuing Docker start, then rechecks isolation
and state. A conflicting claim or any uncertain result cannot authorize another
start. Binding values remain immutable; only the start timestamp changes, once.
A failed post-claim check burns the attempt and requires explicit recovery. A
successful return confirms Docker acknowledged startup; it does not establish
application readiness or a valid runtime lease. An uncertain create response must not cause a second
container to be created for the same boot. The persistent creation intent and
explicit deterministic-name reconciliation below enforce that rule.
`DockerDeploymentCreate` now performs deterministic creation and explicit
reconciliation. A committed `worker_deployment_creations` row consumes creation
permission for the namespace/boot before Docker is called, recording daemon identity
and a fingerprint of the complete bounded recipe. Neither a lost reply nor an
absent container permits another create. Reconciliation requires the same committed
recipe and verifies the actual immutable image, argv, identity environment,
isolation, network attachments, resource limits and never-started state before
registering the full ID. It never starts or recreates a container.

`DeploymentContainerRecipe` uses a local immutable image ID (`--pull=never`), an
absolute executable and bounded argument vector, explicit CPU/memory/swap/PID
ceilings, and a named isolated network. It does not yet support media mounts, device
access or secret injection. The controller still needs application deployment
configuration and command wiring. The combined acceptance fixture now leaves the
predecessor unstarted and uses the startup adapter; an independent PostgreSQL
connection verifies the committed claim before the real Docker start. It then
exercises inventory-backed retirement. This validates external startup/recovery,
not the full application runtime or deployment command wiring.

The first command facade is now wired. `app:serve` is the canonical name of the
existing `ServerRunCommand`, retaining `swoole:server:run` as an alias and preserving
its options, signal handling and BootManager behavior. `app:worker` uses an
application port to run one Redis `async` consumer with keepalive and one outbox
relay, plus the bounded lease helper. It requires container PID 1, a private local
lock, deployment/boot identity and explicit memory reservations. CLI identity must
match the container environment when provided. Child PHP heaps are limited to
256 MiB; admission requires at least 320 MiB per child and 128 MiB for management.
These are conservative initial policy bounds, not measured capacity guarantees.

Every child exit, authority loss or management failure drains the whole deployment.
The supervisor reaps direct children and exits without acknowledging containment or
releasing the lease. A 35-second drain fallback replaces PHP with an immediate-exit
process to avoid blocking child destructors; external containment must enforce the
final boundary if that replacement fails. The runner does not restart individual
children or infer readiness from process presence. It emits only a best-effort,
nonblocking stopped diagnostic with launch-attempt count and exit code. Consumer
names include the namespace hash, boot, role and generation. Resolved environment
values are forwarded without Symfony's prior Dotenv-loaded-variable marker, which
would otherwise overwrite the injected consumer identity when child kernels boot.

The command acceptance runner uses disposable production-mode PostgreSQL and Redis.
It verifies actual consumer/relay parentage and the registered Redis identity, then
checks child-crash draining, TERM, denial with zero launch attempts, and database
lease-expiry draining. Every case retains the original active lease reservation.
It seeds a real registration event before the supervisor starts, then independently
observes the notification, consumer receipt, two channel handoffs and Redis
acknowledgments. Replaying a lost event acknowledgment must preserve those counts
with no pending, delayed or failed messages. External delivery is prevented by
disabled push preferences, an unverified user and an empty webhook endpoint table.
CI runs the command drill as
a blocking step with source and dependencies copied from the built image.
The functional web-command test resolves both names to the same real command.
These checks establish lifecycle behavior, not end-to-end media or scheduler work.

The worker command is not yet a replacement for every web-owned background role.
Scheduler/media ownership, role-specific boot, queue families, autoscaling,
full-identity health publication and deployment configuration cutover remain open.
The external creation/start/recovery controller must still be wired into deployment
operations. Its low-level lease acknowledgment does not itself verify cleanup, and
host-systemd and per-child containment remain unqualified.

The scheduler poller is the next small lifetime to extract, but its execution
dependencies must be resolved first. Console jobs still dispatch into the CPU pool
booted by the web server; starting the poller in an ordinary CLI child does not
provide that pool. Its Redis lock-before-dispatch also has no durable occurrence
intent, so a crash can lose a due minute. Establish a unique job/scheduled-instant
occurrence with atomic dispatch intent under the current poller, then provide
bounded console execution independent of HTTP boot before transferring ownership.
Keep the web boot/shutdown tags until those prerequisites pass concurrent-claim,
restart-recovery and console-execution checks. A long-lived polling child also
needs explicit Doctrine reset handling between ticks.

The first persistence prerequisite is `SchedulerOccurrenceStoreInterface`, backed
by the migration-owned `scheduler_occurrences` table. One immutable row represents
both a job's UTC due minute and its pending dispatch intent. The job/minute pair
is unique; a retry with the same payload preserves the original occurrence ID,
while a changed snapshot is rejected. No foreign key cascades away this history
when a schedule is deleted. A future dispatcher must recheck execution authority;
the retained snapshot is not permission to run a deleted or paused schedule.

Parameters use bounded native PostgreSQL `json`, deliberately preserving lexical
numbers and argument order rather than normalizing them through `jsonb`. The
application snapshot rejects non-JSON values and detaches nested references.
The adapter requires a dedicated idle autocommit connection, commits before
returning success and discards a failed connection, including an uncertain commit.
Statement and lock timeouts do not bound connection setup or network I/O.

This store is not yet wired into the poller or a relay. Occurrence-aware command
metadata, execution receipts and explicit handling of uncertain side effects must
precede automatic dispatch recovery. It does not resolve missed ticks, authorize
execution, or provide exactly-once effects by itself.

Occurrence execution must also be separated from the legacy handler's lock
cleanup: that handler unconditionally deletes `scheduler:lock:<jobId>`, which an
occurrence consumer would not own. Reusing it directly could unlock unrelated
work. The existing handler checks the command registry, but not paused/disabled
status or deployment authority. These checks need an explicit occurrence execution
policy. A normal return does not prove completion: the handler catches failures,
and an unknown console result can leave a child running. A committed one-shot
attempt must therefore remain consumed after uncertain outcomes; a return receipt
must not be treated as successful effects or permission to retry.

The occurrence and schema-introspection checks pass 19 tests with 91 assertions on
disposable PostgreSQL after all 17 migrations and a repeat no-op migration run.
They cover actual unique-key contention, independent visibility, failed and
uncertain commits, immutable retry snapshots and physical schema constraints.
Baseline-free PHPStan level 6 passes for the new production code, migration and
tests.

The shared message codec now preserves integral float tokens as well as signed
zero when encoding JSON. Without this, Redis deliveries could turn a stored
`1.0` parameter into integer `1`, changing invocation types or conflicting with
an immutable occurrence snapshot. Argument order is retained. The wire format and
field schema remain version 1; this does not add occurrence execution metadata.

This is source inspection of routing, all application Bootable implementations,
timer/coroutine creation sites, tagged CPU handlers, scheduler providers, private
outbox wiring and deployment programs. GitNexus 1.6.12 query/context resolved the
current CpuProcessPool and ExecuteScheduledJobHandler after the lead rebuilt the
index; the index identified commit `48154b8` while HEAD `06a5de5` adds only the plan.
Its scheduler context exposes test references rather than dynamic Messenger wiring;
the handler attributes and actual DI/configuration were therefore verified directly.
No production capacity, delivery latency, queue durability, extension availability,
or process-parentage claim was validated by a runtime load test.

Before selecting defaults, measure idle/active RSS and native memory for each child,
CPU time, FFmpeg/device concurrency, queue wait/service rates, real process ancestry,
result-store limits and shutdown under load in the deployment image. The focused
corrections above do not complete stage 1: durable execution ownership and queue
admission still require implementation. Keep independent-role, failure/retry,
occurrence recovery, shared admission and playback acceptance gates from the
redesign plan. There are no production deployments, so hypothetical legacy-queue
migration machinery is not required.
