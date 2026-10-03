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
and static-analysis-rule suite passes 3,436 tests with 12,359 assertions; focused
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
dependencies must be resolved first. Legacy console jobs still dispatch into the
CPU pool booted by the web server; starting the old poller in an ordinary CLI child
does not provide that pool. The occurrence path now has a separate CLI executor. Its Redis lock-before-dispatch also has no durable occurrence
intent, so a crash can lose a due minute. Establish a unique job/scheduled-instant
occurrence with atomic dispatch intent under the current poller and qualify
execution admission and deployment containment before transferring ownership.
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

Scheduled-job parameter storage now also uses native PostgreSQL `json`, so the
source schedule snapshot preserves argument order, integral exponent floats and signed
zero before an occurrence is recorded. Installed and locked DBAL 4.4.3 already
encode with `JSON_PRESERVE_ZERO_FRACTION`; no custom serializer is needed. The
fresh-install schedule migration and ORM mapping agree. This history rewrite does
not convert existing local JSONB data or recover information it already lost.
The source-parameter, occurrence, guard and schema checks pass 26 tests with
237 assertions after fresh and repeat migrations. They include an independent
observer, cleared ORM state, type/order updates, immutable intent retention and
parameter-column schema comparison.

Schedule persistence now uses a UUID revision carried by each domain snapshot.
New snapshots insert only; saves and deletes compare the expected revision in the
same PostgreSQL statement. Every successful save rotates the token, including an
otherwise unchanged save. Deleted records cannot be recreated by stale execution
saves, and recreating an identifier does not revive an old token. Administrative
conflicts return HTTP 409 and require reloading before retrying.

The repository reads and writes through the entity manager's DBAL connection,
bypassing its identity map and array dirty checking. Explicit JSON encoding retains
signed-zero-only parameter edits. Entity metadata remains for schema tooling;
schedule writes belong to this repository. A save inside a caller transaction is
provisional until commit. After rollback or uncertain commit, discard affected
snapshots and reload; the repository does not commit the caller's transaction.

Revision persistence, signed-zero edits, existing repository behavior and occurrence
execution pass 108 integration/functional tests with 943 assertions on disposable
PostgreSQL/Redis after fresh and repeat migrations. The handler race test edits and
pauses through an independent repository during console execution, then verifies
that the final stale result cannot overwrite the newer row. The unit suite passes
3,449 tests with 12,455 assertions, including HTTP conflict mapping; focused PHPStan
and web/shared TypeScript checks also pass.

A per-job recovery primitive now records missed occurrences and advances
`scheduled_jobs.evaluated_through` in the same PostgreSQL transaction. It locks the
active schedule with `FOR UPDATE SKIP LOCKED`, captures the database UTC minute
and examines `(evaluated_through, current minute]`, up to the requested limit.
Calls default to 60 scanned minutes and accept 1–1,000; sparse cron expressions
consume that budget even when no intent is due. A return value counts new committed
intents, not scanned minutes. Missing, inactive or busy schedules return zero.
Backward clock movement never rewinds the cursor. Remaining backlog is retained
for subsequent calls rather than silently discarded.

New schedules and changes to cron, command, type, exact parameters or status clear
the cursor to NULL. The first locked observation of a valid committed active
configuration initializes it to the current UTC minute and emits nothing; the next
minute is its first eligible minute. This explicitly omits historical bootstrap
work, including time between an edit's commit and its first observation. Sampling
the clock inside the editing transaction would incorrectly backdate configurations
held uncommitted for minutes. Name, description, execution-result and no-op saves
preserve the cursor. Cursor advancement does not rotate the snapshot revision or
invalidate an otherwise current execution result.

The existing occurrence store and recovery adapter share a transaction-local
immutable-insert primitive. An identical existing intent retains its original UUID
and dispatch/execution state; a conflicting snapshot rolls back the entire window,
including earlier inserts and cursor advancement. A retry after an uncertain commit
uses the persisted cursor and unique job/minute keys. Invalid cron or unsupported
parameters also leave the cursor unchanged. SQL projections bound configuration
transfer before JSON decoding: expression 1,024 bytes, command 512 bytes and
parameters 16 KiB, with the occurrence DTO's existing eight-array nesting bound.
The database cursor constraint rejects non-finite and non-minute timestamps.

Already-recorded occurrences retain the existing execution policy: current active
status, command, type and exact parameters are checked before execution. This slice
does not add generation cancellation for cron-only edits or pause/resume cycles.
The recovery API neither publishes messages nor authorizes effects. Its dedicated
connection rejects caller transactions. Statement/lock timeouts and scanned-minute
limits do not certify a total wall-clock deadline for PHP cron evaluation, connection
setup, network I/O or commit. Poison schedules must be isolated by the future
caller rather than having their cursors silently advanced.

Recovery passes 40 PostgreSQL cases with 417 assertions, including exceptions before
and after an actual commit followed by a fresh-adapter retry. Independent observers
verify both cursor and every intent, not merely an exception or return value. The
108 existing scheduler integration/functional cases also pass with 943 assertions;
all runs use fresh and repeat migrations. The full unit suite remains green at
3,449 tests and 12,455 assertions, and baseline-free focused PHPStan passes with
Doctrine's mapping extension enabled. No production poller or new queue route was
enabled by these checks.

`SchedulerRecoveryPoller` now composes a durable candidate selector with the
per-job materializer. Selection locks at most 100 eligible active schedules using
`SKIP LOCKED`, ordered by `recovery_after` and UUID, and commits a future retry time
before returning their IDs. A partial active-schedule index supports this ordering.
Both a poisoned schedule and a large backlog take one bounded turn before other
eligible schedules. Selection state survives process restarts; it is not an
in-memory round-robin cursor. Current/future evaluated cursors are not selected.

Every selected schedule is deferred, including one whose materialization fails or
whose caller crashes before doing any work. Retry delays are 1–3,600 seconds. There
is no early release or ownership token: the deadline only throttles selection,
and the materializer still locks a fresh schedule and commits each window atomically.
Expired selections may cause further safe recovery calls; they never grant execution
permission. An uncertain selection commit returns no IDs, so the caller does no work
until a later selection can establish a committed result. Schedule edits and results
preserve the selector deadline, and selection leaves the schedule revision and
recovery cursor unchanged.

A pass defaults to ten jobs, sixty scanned minutes per job and a sixty-second retry
delay. It rejects a requested product above 1,000 minutes before reserving anything,
then attempts every selected job before reporting aggregate failures. The reported
insertion count covers acknowledged commits; failures may have uncertain outcomes.
These work limits do not bound connection, commit, PHP or network wall time. Invalid
schedules remain visible errors without continually taking the first selection slot.
Both adapters have dedicated connection factories and application-port aliases;
the container resolves the callable pass without starting a producer loop.

The legacy web poller still executes schedules outside the occurrence guard. Running
a durable producer beside it would retain intents that could later duplicate that
legacy work when the relay is enabled. Cutover therefore needs a deliberate boundary
between the two paths, not shadow history that is later treated as executable.
The new producer also needs its own supervised child reservation. Occurrence console
execution adds a 128 MiB PHP child. `app:worker --scheduled-console-mib` now reserves
that child explicitly, defaulting to zero (disabled). A positive reservation must be
at least 192 MiB: the fixed 128 MiB PHP heap plus 64 MiB of declared native headroom.
It is additional to management, consumer and relay reservations and must fit the
existing total admission ceiling. For example, 128/320/320/192 MiB reservations
fit a 1,024 MiB ceiling; this is arithmetic, not a qualified capacity recommendation.

The consumer's role budget includes the child once, and the process ceiling reserves
one descendant slot in addition to consumer, relay and lease helper. Generic worker
definitions now declare descendant process reservations, retained with their role
through pending containment. The runner overrides inherited console grants with
zero and grants a positive budget only to the admitted consumer. The executor's
container factory rejects malformed, insufficient or wrong-role grants, and a zero
grant rejects execution before spawning. Console children receive a zero grant and
cleared Dotenv provenance so normal configuration reload cannot authorize another
scheduled console child. Existing direct executor callers must supply a reservation.

These are declared admission budgets. They do not measure native memory or account
for arbitrary descendants created by commands. Deployment cgroup memory/CPU/PID
limits, containment qualification and downstream workload admission remain required.
The reservation change passes 3,479 unit tests and 77 targeted integration tests
(636 assertions), plus focused PHPStan without suppressions. A focused 28-test
rerun passes after removing redundant test fixture lifetime storage. The real PID-1
`app:worker` acceptance harness also passes outbox delivery/replay, consumer crash,
same-boot rejection, TERM shutdown and lease-renewal loss with the default disabled
console budget. These checks do not establish capacity under a scheduled workload.
No command, timer or queue route was enabled by the recovery pass wiring.

The combined scheduler checks pass 167 integration/functional tests with 1,525
assertions after fresh and repeat migrations. Selection tests isolate their schedules
in an owned schema built from production migrations; schema-drift checks use the
actual configured Doctrine schema manager. They verify PostgreSQL's canonical
partial-index predicate, native finite deadlines and database-clock defaults, as
well as poison/backlog fairness, lock skipping, commit uncertainty and preservation
of reserved deadlines across schedule saves. The unit suite passes 3,460 tests with
12,499 assertions; baseline-free focused PHPStan also passes.

Parameters use bounded native PostgreSQL `json`, deliberately preserving lexical
numbers and argument order rather than normalizing them through `jsonb`. The
application snapshot rejects non-JSON values and detaches nested references.
The adapter requires a dedicated idle autocommit connection, commits before
returning success and discards a failed connection, including an uncertain commit.
Statement and lock timeouts do not bound connection setup or network I/O.

Occurrence snapshots now distinguish `scheduled` from `manual` origin. Scheduled
snapshots retain one intent per job/UTC minute through a partial unique index.
Manual snapshots use their occurrence UUID as request identity, so separate manual
requests in the same minute coexist with each other and the scheduled occurrence.
An identical request retry preserves the original snapshot and delivery/execution
state; reusing its UUID for another origin, job, minute or payload is rejected.
The existing job/minute lookup returns scheduled occurrences only; ID lookup returns
either retained snapshot. A manual retry must reuse its original ID and snapshot,
including its minute, rather than constructing a new timestamp after uncertainty.

The execution guard reads origin from PostgreSQL, never from the queued command.
Manual invocations may run paused or disabled schedules without changing that status,
matching existing manual-trigger behavior. They still require a present schedule,
an unchanged execution snapshot and a registered command. Scheduled invocations
still require active status. Both origins share permanent one-attempt admission and
the same unresolved-job exclusion; origin cannot bypass an uncertain prior attempt.

This is the durable contract for a later cutover. HTTP/CLI manual entrypoints and the
legacy web producer still use their existing path. No manual intents are written
alongside legacy execution, and no new producer or route is enabled. Cutover still
requires the supervised producer/relay role and an exclusive boundary that handles
queued legacy wrappers before activating the replacement.
The manual-origin slice passes 3,489 unit tests and 211 combined PostgreSQL/Redis
integration/functional tests (1,977 assertions), with fresh and repeat migrations.
Tests cover coexistence, immutable identity conflicts, origin constraints, commit
uncertainty, cross-origin job admission and a real persisted manual invocation of a
paused schedule. Focused PHPStan passes without suppressions.

`scheduler.execute_occurrence` now carries only an occurrence UUID through the
explicit JSON codec. Its registered handler loads the stored snapshot while
committing a one-shot attempt in `scheduler_occurrence_executions`, using a
dedicated connection. Admission requires the supervised child’s exact deployment
namespace, boot ID and lease epoch. The supervisor supplies the committed epoch
at each child launch, overriding any inherited value. The store locks the lease
row, then checks active ownership and expiry using the database clock, and checks
again after inserting and loading the snapshot. Denial rolls back the attempt.
Missing or malformed authority fails closed; missing and future occurrences grant
no attempt to an otherwise authorized caller. Due-time admission uses the database clock. A duplicate never grants another invocation,
even with the same attempt ID or after an uncertain commit. No timeout reclaims
an attempt. A foreign key prevents deletion of an already consumed intent.

The occurrence entrypoint rechecks that the current schedule is active and that
its command, type and ordered, typed parameters match the snapshot. Deleted,
paused, disabled or changed schedules are skipped. It rechecks the command
registry and never releases the legacy `scheduler:lock:<jobId>` lock. Legacy
messages retain their existing execution and lock behavior. These are point-in-time
schedule checks, not a deployment-lease fence or protection against a concurrent
schedule edit after the check.

The existing fresh-install execution migration now requires the deployment tuple
on every attempt. Historical ownership has no foreign key to the mutable lease
row. No local database is reset or automatically upgraded by this history rewrite.

Only the exact attempt and deployment owner can record `returned_at`, including
after its lease expires or is replaced. This records a normal
handler return, including cancellation or caught failure; it does not certify
successful effects or stopped descendants. Exceptions and failed receipts leave
the attempt consumed. In particular, an unknown console result may leave a child
running. Inspection and explicit outcome reconciliation remain necessary; no
automatic reset or retry is authorized by either receipt state.

A bounded occurrence relay now reserves due unpublished intents in PostgreSQL
before publishing their UUIDs through the explicit Redis sender. Reservations use
`FOR UPDATE SKIP LOCKED`, a token and a retry deadline; independent claimers do not
wait on already reserved rows. Only an exact-token receipt records transport
acceptance. An accepted receipt stops further publication even while consumers are
stopped. This is not an execution or success receipt.

A crash before sending, an uncertain send, or a failed receipt leaves the delivery
reservation recoverable after expiry. A stale token cannot acknowledge a replacement
reservation. Unknown sends may enqueue the same occurrence more than once; the
execution guard still admits only one attempt. The relay attempts every item in its
bounded batch before reporting failures, so one failed handoff does not starve the
rest. Batch size is limited to 100 and retry delay to 1–3,600 seconds. Database
statement/lock timeouts do not bound transport I/O or an entire relay call.

The existing fresh-install occurrence migration now includes delivery state and a
partial pending index. Snapshot fields and execution attempts are retained. A
successful sender return relies on the broker's durability configuration; this
slice does not establish Redis crash durability or automatically recover failed
execution messages. Delivery reservation expiry never resets execution admission.

Console occurrences now call `ScheduledConsoleExecutorInterface` after the existing
snapshot and command-registry checks. The CLI adapter starts `bin/console` through
a shell-free argument vector, with stdin closed, a 128 MiB PHP heap limit, a
300-second monotonic deadline and a 10,000-byte combined stdout/stderr cap by
default. Both pipes are drained in bounded chunks. Named boolean flags use the
console convention: true emits the flag and false omits it. Arguments are scalar,
validated before spawn and limited to 16 KiB in total. Positional values follow a
`--` separator. Known nonzero exits are recorded as failures; successful output
must be valid UTF-8.

Timeout, output overflow, unexpected signals and unconfirmed completion raise
`ScheduledConsoleCompletionUnknown`. The adapter attempts TERM, then KILL, and
reaps a signalable direct child. The occurrence handler records a paused schedule
and rethrows the uncertainty, leaving its consumed attempt without a return
receipt. Failure to save the paused state retains the uncertainty classification.
The Messenger subscriber stops this consumer before another queued job is handled;
the supervisor's existing child-exit policy then drains the deployment. Legacy
messages retain the web CPU-pool path and its existing pause behavior.

A PHP heap limit does not bound native or total deployment memory. Direct-child
reaping does not certify descendant containment. Kernel-stalled system calls and
PHP resource teardown can exceed userspace deadlines. The executor rejects active
Swoole coroutines and is intended for a supervised CLI child. Deployment containment
and resource admission still need qualification before ownership cutover.

No poller, relay loop or queue route has been enabled for occurrence messages.
The per-job materializer now has a bounded, durable fair recovery pass.
A supervised loop, resource admission and deployment wiring remain before cutover. Lease validation controls admission; it
cannot stop effects already running after expiry.
The guard prevents repeated wrapper invocation, not duplicate side effects from
retries of downstream messages or exactly-once external delivery. Execution rows
also reserve one unresolved wrapper invocation per job across deployment namespaces.
A composite occurrence/job foreign key prevents claiming a different job's slot,
and a partial unique index rejects overlapping unresolved invocations. Admission
derives job identity from the stored occurrence; a busy job does not consume the
waiting occurrence. Previously consumed occurrences remain no-ops.

A busy invocation follows the existing three retries and then the failure transport.
It is not acknowledged as completed or retried indefinitely. After the blocker has
returned, an operator can explicitly retry the retained command with its original
occurrence ID. Publication receipts are not cleared to manufacture automatic retries.
An exact-owner normal-return receipt releases only the per-job wrapper slot, never
the permanent occurrence claim. Missing receipts, uncertain commits, lease expiry
and deployment replacement do not authorize another invocation of that job.

This serializes scheduler adapters, not downstream work: a Messenger adapter can
return after sending an asynchronous command, before its effects complete. A console
adapter's return does not prove descendant containment. Shared resource admission,
downstream execution ownership and the legacy scheduler cutover remain prerequisites
for enabling the producer and relay loops.

The per-job admission change passes 177 combined integration/functional tests with
1,689 assertions on disposable PostgreSQL/Redis, including fresh migrations and a
repeat no-op migration run. Coverage includes independent uncommitted contention,
cross-namespace blocking, wrong/stale receipts, lease replacement, uncertain
admission/return commits, physical foreign-key/unique constraints and real Redis
retry exhaustion followed by explicit retry. All 3,460 unit tests and focused
baseline-free PHPStan level 6 also pass.

The occurrence, dispatch, console-process, worker-stop and schema-introspection
checks pass 89 tests with 758 assertions on disposable PostgreSQL/Redis after all 18 migrations and a repeat
no-op migration run. They cover actual unique-key contention, independent
visibility, failed and uncertain commits, immutable retry snapshots, future intent
rejection, physical schema constraints and real Messenger redelivery after an
effect. Lease admission checks cover stale and expired authority, contention,
rollback when authority is lost during insertion, and historical-owner receipts.
The configured Kernel resolves the guard and its dedicated store without granting
authority to an unsupervised process. Real CLI fixtures cover argument handling,
pipe draining, ignored TERM, output overflow, signals and direct-child reaping.
Actual Messenger worker tests stop before the next message on an uncertain result.
Baseline-free PHPStan level 6 passes for the new production code, migration and
tests.

The shared message codec now preserves integral float tokens as well as signed
zero when encoding JSON. Without this, Redis deliveries could turn a stored
`1.0` parameter into integer `1`, changing invocation types or conflicting with
an immutable occurrence snapshot. Argument order is retained. The wire format and
existing field schemas remain version 1. The separate occurrence message type
adds only `occurrence_id`; payload overrides and invalid UUIDs are rejected.

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
