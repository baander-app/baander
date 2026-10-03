# Worker runtime inventory

Verified against source on 2026-10-03 for stage 1 of the
[web and worker runtime redesign](../docs/plans/2026-07-17-001-feat-messenger-enterprise-hardening-plan.md).
`app:serve` now names the existing foreground web server, and `app:worker` runs a
fixed consumer/outbox-relay/scheduler supervisor. Broader queue families, autoscaling,
media ownership transfers and external deployment orchestration remain planned. This inventory
does not certify runtime resource defaults.

Scheduler activation verification passes 3,510 unit tests and 132 combined
PostgreSQL/Redis integration and firewall tests. The actual PID-1 Docker drill
passes committed-lease startup, manual dry-run execution, duplicate delivery,
consumer and scheduler crash draining, TERM, lease expiry and denied admission.
Focused baseline-free PHPStan, web/shared typechecks, ten API/auth retry tests
and lint on the changed web files pass. OpenAPI and the generated web client
were regenerated; source schema fixes remove invalid empty required lists and
an invalid float type. A fresh export matches the checked-in specification.

## Current asynchronous routes

[messenger.yaml](../config/packages/messenger.yaml) declares **13 routes**: ten
to `swoole_task`, two to Redis `async`, and one to Redis `scheduler`. The table records current handler
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
| `Scheduler/Application/Command/ExecuteScheduledOccurrenceCommand.php` | `scheduler` | [ExecuteScheduledOccurrenceHandler](../src/Scheduler/Application/CommandHandler/ExecuteScheduledOccurrenceHandler.php), Any; requires deployment authority | `scheduler.execute_occurrence` | Fixed scheduler records and publishes immutable occurrences |
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

The supervisor's scheduler child recovers cron minutes into immutable database
occurrences and publishes their UUIDs to stream `scheduler_occurrences`, group
`baander`. Its explicit Redis DSN names that stream because a DSN path overrides
transport stream options. Only the admitted consumer listens to this receiver in
addition to `async`. The former web timer, shutdown handler and scheduled console
pool worker have been removed. Retained `scheduler.execute_job` payloads remain
decodable but their handler rejects them as unrecoverable before any effects.

Manual HTTP requests return `202` with `{data: {occurrenceId, jobId}}`. An optional
`Idempotency-Key` UUID identifies retries; the browser supplies it before sending
and authentication retries retain it. If omitted, the server generates and echoes
one, which cannot be recovered by a client that loses that first response. The
one-shot CLI accepts `--request-id` and prints its UUID before recording. Reusing
the identity recovers the original snapshot, even after the schedule is deleted.
A fresh request is a new intent. Acceptance does not mean execution succeeded.

New or changed cron configurations initialize at their first observed database
minute and become eligible the following minute. Existing cursors retain bounded
catch-up. Ordinary job result saves do not reset the cursor. The recorder does not
backfill historical work before initial observation.

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

Console occurrences use the bounded CLI executor under the admitted consumer.
`--scheduled-console-mib=0` disables console execution and accepted console intents
record a failure; provide at least 192 MiB to enable one synchronous child. Unknown
completion pauses an active schedule and stops the consumer, draining the whole
deployment. Permanent execution receipts prevent the wrapper from running twice;
these do not provide exactly-once effects for downstream asynchronous messages.

## Swoole lifecycle and background ownership

All five application `Bootable` implementations are explicitly tagged in
[services.yaml](../config/services.yaml). Tables created before fork are shared
only inside the existing process ancestry; they are not independent-service IPC.

| Current hook / service | Current work and state | Proposed lifetime |
| --- | --- | --- |
| [HardwareCapabilitiesProber](../src/Transcode/Infrastructure/FFmpeg/HardwareCapabilitiesProber.php) `boot()` | Probes encoder/device support; lazily boots from `getProfile()` too | Worker owns probing/device reservations; publish admission/profile state needed by web |
| [SegmentAvailabilityTable](../src/Transcode/Infrastructure/Swoole/SegmentAvailabilityTable.php) `boot()` | Pre-fork Swoole table, configured 16384 rows; readiness with file-stat fallback | Worker publishes shared readiness by session generation; web reads it |
| [CpuProcessPool](../src/Shared/Infrastructure/Swoole/ProcessPool/CpuProcessPool.php) `boot()` | Six configured Swoole pipe children; 8192-row result table plus result files under `/tmp/baander_cpu_pool_results`; JSON registry instantiates handlers without container arguments | Worker owns all CPU children and IPC/results; resource reservations include their subprocesses |
| Scheduler child | Private CLI recovery/relay loop with TERM/INT handling | Fixed admitted role under worker supervisor |
| [CpuGpuSampler](../src/QoL/Infrastructure/Swoole/CpuGpuSampler.php) `boot()` | Pre-fork sampling table; timer deferred to HTTP worker 0 | Fixed worker sampling/control service |
| [SwooleWorkerEventSubscriber](../src/Shared/Infrastructure/Swoole/SwooleWorkerEventSubscriber.php) | HTTP worker 0 starts pool health, sampler and [MidStreamMonitor](../src/QoL/Infrastructure/Swoole/MidStreamMonitor.php); imports governor learning state; server SIGINT hook stops pool and server | Worker owns health/sampling/governor timers. Web keeps connection registry/pusher and HTTP lifecycle |
| [CpuProcessPoolShutdownHandler](../src/Shared/Infrastructure/Swoole/ProcessPool/CpuProcessPoolShutdownHandler.php) | Web/server shutdown stops background children | Replace with worker-owned drain/reaping; no competing shutdown owner |
| [TranscodeSessionSubscriber](../src/Transcode/Infrastructure/Swoole/TranscodeSessionSubscriber.php) | Explicit starter port accepts an acquired Redis lease and starts a CoWrapper coroutine (inline fallback) with renewal; drives encodes/completion/failure | Worker media session service; durable intent and recoverable control |
| [TranscodeStreamManager](../src/Transcode/Infrastructure/Swoole/TranscodeStreamManager.php) | Long-lived FFmpeg through ProcOpenSpawner, outside one-shot CPU pool; four-stream limit per manager instance; poll/drain output and STOP/CONT/KILL control | Worker media descendants with global deployment budget and cancellation |
| [SeekSignalBroker](../src/Transcode/Infrastructure/Swoole/SeekSignalBroker.php) | In-process per-job Coroutine Channels, capacity 16; missing channels drop signals | Worker-owned session control with explicit delivery/ownership semantics |
| [ImageController](../src/Media/Interface/Controller/ImageController.php) | Missing preset/WebP starts fire-and-forget conversion coroutine; serves original image immediately | Scaled `media` jobs; preserve original-response fallback |
| [LearningEngineSubscriber](../src/QoL/Infrastructure/Swoole/LearningEngineSubscriber.php) | Completion starts coroutine (inline fallback), loads job/sample, updates governor, releases allocation and sometimes persists learning | Worker-owned learning persistence; preserve sample provenance and reservation release |
| [SessionBudgetSubscriber](../src/QoL/Infrastructure/Swoole/SessionBudgetSubscriber.php) | Priority-1 synchronous attachment listener can veto then allocate a stream before encoding listener | Keep synchronous admission behavior through shared worker admission state; do not defer veto until after response |

CPU pool handlers are [TranscodePoolWorker](../src/Transcode/Infrastructure/Swoole/TranscodePoolWorker.php)
(`encode_segment`, `encode_init_segment`, `analyze_loudness`, `extract_subtitles`),
[RecommendationPoolWorker](../src/Recommendation/Infrastructure/Swoole/RecommendationPoolWorker.php)
(`generate_recommendations`); the former scheduled console pool worker is retired.
[GenerateRecommendationsHandler](../src/Recommendation/Application/CommandHandler/GenerateRecommendationsHandler.php)
also uses this pool and has inline fallback; moving the pool affects recommendation
execution as well as media. These internal JSON job payloads are distinct from the
versioned Messenger codec and need an explicit compatibility contract when IPC moves.

## Deployment and migration traps

[start-web.sh](../docker/general/start-web.sh) starts only `app:serve`, replacing
the mixed Supervisor startup. The `worker` image target starts `app:worker`
directly as PID 1 and requires explicit identity and memory reservations.
Compose currently starts only the web role; background delivery requires a
separate admitted worker. Do not add an automatically restarting Compose worker:
the host-only `bin/worker-deployment.php` controller now handles creation, start,
status and explicit recovery through immutable deployment records.
Use an immutable image, no automatic
restart, and a stop grace period longer than the 35-second worker drain deadline.
The worker image disables the inherited web/dependency health check; readiness
requires the committed lease and child-role evidence.

Creation recipes now bind explicit runtime configuration to the committed hash.
The initial allowlist is `APP_ENV` (only `prod`), `APP_DEBUG` (only `0`),
`APP_SECRET`, `DATABASE_URL`, `REDIS_URL`, `REDIS_PASSWORD`,
`MESSENGER_TRANSPORT_DSN` and `MAILER_DSN`. Values are bounded printable UTF-8
without line breaks; the complete environment file is limited to 4 KiB.
Worker identity and control variables remain controller-owned. Docker receives
a private temporary env-file, removed before inspection, rather than credential
values in command arguments. Reconciliation checks each admitted value exactly
once. Trusted Docker daemon operators can still inspect the container environment.
This is configuration transport, not encrypted secret storage.

Registered recovery now verifies isolation and commits an irreversible retirement
record before removal. Lease acquisition and start claims share its transaction
lock and reject that boot after the record commits, including when no lease was
ever acquired. Transactions explicitly use Read Committed so admission checks
after lock waits see the committed retirement record. Docker work runs outside
database transactions.

After a lost removal reply, recovery may establish absence using a successful
exact-ID listing on the recorded daemon, but only with the prior verified record.
Missing containers without that record, ambiguous listings and unavailable daemons
remain errors. Completion records the removal and releases only that boot's lease
in one transaction. Repeated completion cannot release a replacement owner. The
forward migration preserves all earlier ownership and creation history.

Real Docker/PostgreSQL drills cover lost removal replies and retirement before
lease acquisition. Database tests cover acquisition/start races, an in-flight
acquisition that commits before retirement, and lost intent/completion commit
acknowledgments. The host entrypoint now has a passing real-worker drill covering
outbox delivery, scheduler execution and inventory-backed recovery. It uses an
isolated fixture image and does not certify the production worker image or readiness.

Production source and local Composer packages are copied before dependency
installation. Dependency installation runs as the application user and does not
boot Symfony with build-time secrets. Run `php bin/console assets:install public`
with runtime configuration before serving bundle assets. Symfony generates its
cache on first boot. Local caches, logs and local dotenv overrides are excluded
from the image context.

Swoole configures four HTTP workers and two task workers; there is no current queue
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

The following records incremental implementation evidence. Earlier stage boundaries
and counts describe those historical checks; the current scheduler activation
contract is summarized above. Production capacity and broader media ownership
still require qualification.

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
The combined Docker/PostgreSQL recovery runner checks real adapter removal and
committed lease transitions together. Its controlled container fixture does not
run `LeasedWorkerRuntime`; the separate operator drill now exercises the actual
worker through the host entrypoint.

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
ceilings, and a named isolated network. It does not yet support media mounts or device
access. Runtime configuration is bound into the recipe fingerprint and passed
through a temporary private env-file. The combined acceptance fixture leaves the
predecessor unstarted and uses the startup adapter; an independent PostgreSQL
connection verifies the committed claim before the real Docker start. It then
exercises inventory-backed retirement. This validates external startup/recovery,
not the full application runtime. `bin/worker-deployment.php` now supplies the host
composition root without booting Symfony or loading host dotenv files. Its strict
version-1 manifest fixes the worker argv and binds Docker resource ceilings to
validated admission reservations. A separate canonical private credentials file
provides the controller PostgreSQL URL and admitted production environment; host
and worker database addresses must identify the same database. Host PHP requires
`posix` and `pdo_pgsql`. Every action needs an external deadline because native
database I/O has no hard DBAL connection bound. See the
[operator workflow](../docs-book/part-1-operator-guide/commands/README.md#worker-deployment)
for exact fields, credentials, actions and exit codes.

`scripts/test-worker-operator-container.sh` passes real host-entrypoint create,
reconcile-create, start, status and recovery against disposable PostgreSQL/Redis.
It observes actual outbox delivery and scheduler execution, verifies the worker
lease and child roles, retires the exact container and rejects reuse of its boot.
The fixture image contains the checkout and generated test keys; this is not
production-build certification. The runner also supports
`BAANDER_TEST_OPERATOR_IN_CONTAINER=1`: a separate trusted controller container
provides PHP and a verified static Docker CLI, receives the daemon socket and
reaches PostgreSQL through its own network without publishing a database port.
The worker retains its private network and receives no mounts or Docker authority.
Forgejo now has a blocking operator lifecycle step using this mode and
`BAANDER_TEST_CHECKOUT_IN_IMAGE=1`; this records the configured gate, not a passing
Forgejo run. Status returns committed observations with `readiness: not_checked`,
not a readiness grant.

The production-image check is now `scripts/test-worker-production-container.sh`,
run with `BAANDER_TEST_IMAGE` after building `Dockerfile --target worker`. Its
300-second deadline excludes the separate build. It checks actual worker metadata,
production Composer platform requirements and the absence of development
dependencies, baked OAuth keys and agent artifacts. It then runs the operator
lifecycle with `BAANDER_TEST_USE_IMAGE=1`, using the immutable worker image directly
without fixture source, key, dependency or cache injection. Forgejo has blocking
context-exclusion, production-worker build and production-lifecycle steps. A clean
full worker build passes, and context exclusions reduce the transferred checkout
from 6.86 GB to 188.30 MB. Native Swoole is pinned to 6.2.0 to match Composer's
exact requirement, and the extension-configuration fallback cannot hide a failed
PIE installation. The corrected production build, platform checks and direct-image
lifecycle drill pass locally. This does not establish a passing Forgejo run.

The production target now uses `php.ini-production` and `APP_DEBUG=0`; the image
check rejects displayed runtime or startup errors. Development images retain
their existing PHP configuration.

This qualifies the tested worker startup, delivery, scheduler execution and
retirement paths; it does not certify the authenticated web workflow. OAuth key
paths and encryption now resolve from the documented `OAUTH_*` environment variables.
The authorization-server factory uses the kernel environment and rejects missing
or malformed production encryption keys. Independent production-container tests
verify encryption interoperability with a shared secret and externally provisioned
RSA keys. Health diagnostics use the injected secret rather than requiring a second
copy in `getenv()`. Non-production fallback keys remain unsuitable across workers.
The strict worker recipe currently admits no key mounts, so authenticated web/OAuth
deployment remains a separate configuration and acceptance task. Secret rotation
now prepares a separate validated bundle without overwriting active files. Explicit
`invalidate --offline` deletes all five OAuth grant/metadata tables transactionally,
then clears the same tagged cache used by token repositories. The flag is an
operator assertion: every issuer, resource server and worker must remain stopped
through retries and configuration installation. Cache failures and uncertain
commits require offline recovery; there is no automatic global fence or online
atomic cutover. See the operator rotation runbook for installation and recovery.

The active `/api/oauth/token` refresh path now uses a top-level transaction through
response signing and serialization. It locks the original refresh/access rows,
requires the original DPoP key binding, and conditionally consumes the refresh token
before issuing its replacement. Replay retains League's reject-only policy; it does
not revoke a winner's replacement family. Lock and statement timeouts bound database
contention. Failure clears aborted ORM state and closes the captured connection.
Transport delivery and uncertain commit acknowledgments remain outside that guarantee.

PostgreSQL regressions exercise concurrent independent issuers, replacement persistence
and signing failures, reusable replacements, missing/wrong DPoP bindings, persisted
user identity, and client UUID round-trips. The returned JWT is validated through the
resource-server factory. These tests supply the already-validated proof attribute;
full firewall, cryptographic proof/replay, browser retry and logout acceptance remain
open. Issuance also now preserves client/user UUIDs and DPoP bindings, signs access
JWTs, and emits a complete DPoP JSON response without stale trailing stream bytes.
Non-refresh grants are not covered by the new transaction boundary.

The browser API client now fences requests, responses, nonce updates and refresh
queues by the current DPoP key-pair identity. Delayed requests from a replaced
session cannot replay under the next account's credentials, and completion of an
old refresh cannot clear a new session's pending refresh. Vitest regressions cover
those races and preserve retries after rotation within the same session. This is
not yet a multi-tab or full browser authentication acceptance result.

Native protected image/stream forwarding now explicitly uses CORS mode and omits
cookies. Preserving a native image's original `no-cors` mode stripped Authorization
and DPoP headers before network delivery. The isolated Chromium transport suite
uses the actual compiled worker, separate loopback-routed baander.app origins,
mock signing replies and a disposable HTTP server. It checks native image loading,
credential delivery, cookie omission, Range/HEAD preservation, foreign-origin
isolation and redirect rejection. The production build regenerates the checked-in
worker asset. CI runs this browser suite separately from Vitest.

These browser checks do not qualify backend proof verification or deployed CORS
policy. Separate production-container listener regressions now cover literal web
origin matching, denial for unconfigured paths, protected media HEAD/Range
preflights and response-header exposure. OAuth authorization denies CORS even for
encoded paths and trailing-slash redirects. Partial media responses vary by Origin,
including requests without that header; Nelmio's cache listener alone skips 206.
These checks exercise real compiled listener wiring, not full firewall dispatch.
Track streaming now validates single byte ranges before response preparation,
including overflowing decimal offsets, suffixes, empty files and unsatisfiable
ranges. Malformed and multipart ranges fall back to the full response. If-Range
uses the response validator; stale or weak validators fall back to the full file.
The Swoole adapter emits only the prepared offset and length and suppresses bodies
for HEAD and unsatisfiable ranges. Protected streams use private, no-store caching.
The deprecated raw-path endpoint has been removed; streaming by public ID checks
library access first. The specification and generated web client reflect removal.
Other callers of plain BinaryFileResponse still use the old Swoole emission branch
and need separate audit; this change does not certify all binary responses.
Contract checks also corrected the transcode init route description and webhook
rotation response schema. Transcode readiness now clears PHP's stat cache before
each observation and compares file identity, size and timestamps. Replacement,
growth, deletion and empty/nonregular files reset or fail the stability check;
polling uses a monotonic deadline. Final response construction also refreshes
metadata before setting Content-Length.
Readiness-table hints can accelerate delivery only when their path exactly matches
the segment path resolved for the request. A stale or unrelated path is ignored;
delivery waits for the expected file instead. Regression tests use distinct file
contents and verify the streamed bytes, including missing expected files.

The continuous encoder now uses FFmpeg's `hls_flags temp_file`: new media fragments
are closed under temporary names and atomically renamed to final `.m4s` names.
The scanner ignores temporary files and accepts only the configured rendition's
video/audio identity, tier and representable segment index, using fresh file
metadata. Completion counting uses the same eligibility rules. A nonzero or unavailable
encoder exit status fails the job even if some fragments exist, and an init file
without eligible media output cannot count as completion. Seek restarts use
FFmpeg's supported `start_number` option. The real encoder regression captures
filesystem publication events and decodes the resulting fragments, including a
seeked encode; it does not rely on catching a brief temporary file by polling.

This does not make all cached filenames authoritative for the current job.
Continuous attempts still share video/tier output directories, and existing final
files remain cache entries. FFmpeg writes `init.mp4` separately, outside this
atomic-fragment policy. Its quiet-window check remains a heuristic: a paused writer
or same-size edit within timestamp precision can escape observation, and the file
can change after the last check. Init publication and encoding-attempt isolation
remain open. The separate pool encoder already waits for completion and atomically
publishes its output.

The attempt-isolation audit found that changing producer directories alone would
not close this gap: delivery recomputes video/tier paths, signed URLs identify only
the job and segment, and availability/cache keys omit attempt identity. Retry keeps
old segment state, and the seek loop ignores the restarted encoder's returned
directory. The next change needs an immutable attempt identity in publication,
manifest URLs, delivery and cache keys; a persisted ownership fence must reject
late worker results. Init and media must belong to the same attempt. The existing
job output-directory column can store the selected path, but switching it alone
would still allow separate init/media requests to observe different attempts.

Redis loop-lock renewal and release now compare the owner token and mutate the
key in a single parameterized Lua script. Renewal changes only the matching key's
expiry; it cannot recreate a missing key. Real Redis regressions force a successor
to take ownership at the old read/write boundary and verify that stale renewal
and release leave its token and expiry intact.

Session startup now rejects failed lock acquisition if no live session appears
during its bounded wait. It does not change retry state, save sessions or dispatch
encoding work on that path. Retry and audio-language changes occur only after
acquisition and the second live-session check. Both session creation and stream URL
signing return a documented 503 with Retry-After when this refusal reaches HTTP,
including through a single Messenger handler-failure chain. Mixed handler failures
retain generic error handling. Signing starts tiers sequentially, so this does not
roll back tiers already started before another tier refuses startup.
Job lookup/creation still precedes lock acquisition and may persist a new pending
job. Session creation and both list endpoints now reject absent users or users
without a UUID accessor before invoking their application operations.

The subscriber now checks the lease before starting the loop. A refused or throwing
renewal permanently marks that loop as lost and stops its local encoder. Guards
after waits and between persistence calls prevent further work after observed loss;
even an ordinary FFmpeg error raised during shutdown bypasses the job-failure write.
Captured loop identities make obsolete timer callbacks inert. Cleanup closes the
context first, attempts remaining cleanup if one step fails, and does not release a
lease after observing its loss. The manager gives each local process a distinct
identity so an interrupted poll cannot restore a stopped process entry, publish a
further readiness hint after detecting shutdown, or remove a replacement process
during cleanup.

Each successful Redis acquisition now returns a separate runtime lease handle.
Renewal and release use that handle's immutable token, including after the same
lock service acquires a successor for the same job. A failed renewal permanently
retires the handle. Tokens cannot be serialized and are omitted from debug output.
Session creation passes the handle through an application starter port; domain
notifications carry no lease. Before handoff, any failure releases the captured
handle. Pool unavailability, a duplicate local loop, or coroutine scheduling
failure rejects the handoff. The shared coroutine wrapper now throws when native
coroutine creation fails instead of silently accepting work.

Recovery also acquires a fresh handle and chooses a resumable session, preserving
state files when acquisition is denied. Existing session-attached notifications
still run budget admission before startup. This event's combined notification and
admission role, allocation rollback after rejected startup, and actual lifecycle
wiring for `GracefulRestartHandler` remain separate work; its resume/persist methods
currently have no source callers. Recovery tests exercise the service directly.

These local identities are not persisted encoding-attempt identities. A database
write already in flight can still complete after loss, dispatched pool work can
continue, and shared output directories remain unisolated. Conditional persistence
and attempt-qualified output paths remain required. The timer does not independently
fence a lease when its event loop stalls past expiry.

The CPU pool now sends a nonempty shutdown control message and drains direct
children for up to two seconds before killing stragglers and allowing one further
second to reap them. It waits for specific owned PIDs, leaves unrelated children
alone, and destroys the result table only after all workers are accounted for.
If exit remains unconfirmed, shutdown reports failure and retains pending state.
Only the process that booted the pool may shut it down. Boot requires the project's
PHP 8.4+ `pcntl_waitid` support and cleans up partially started workers.

Shutdown clears only its own health timer and emits lifecycle logs through the
logger. A completed shutdown permits reboot of the same instance, including
round-robin indexes and dead-worker state. The pool's unused Symfony serializer
dependency has been removed; its existing JSON wire format is unchanged.
Real-process regressions cover idle shutdown, cooperative work, forced termination,
reboot and dispatch, full IPC queues, unrelated resources (including an exited
sibling), disabled reaping support, and inherited shutdown rejection. These tests qualify direct children, not FFmpeg descendants.
The boot-owning server process now starts the five-second health monitor from
`ServerStartedEvent`; HTTP workers cannot start it. Nonblocking, PID-specific
`pcntl_waitid` works while Swoole's coroutine reactor is active. An exit already
collected by Swoole is also treated as unavailable, without signalling its PID.
Shared health rows publish worker availability and a boot generation. Inherited
HTTP workers skip dead slots, reject stopped pools, and remain unavailable after
the owner reboots. Missing health rows fail closed. Shutdown publishes admission
closure before draining, even when every worker has already exited. The shared
health table stays allocated for the pool instance lifetime so inherited readers
can observe shutdown and cannot accept a later generation as their own.

Real-process health tests cover inherited dispatch after a crash, all-dead state,
stopped and stale generations, and native process-mode server lifecycle wiring.
Health is observed on the timer cadence; a worker can still die between a health
read and dispatch. A failed or incomplete pipe write now reports failure, but a
successful write is not a completion acknowledgement or a durable queue.

Native media now requests refresh from its own window after an authenticated 401,
shares that window's refresh queue with Axios, and retries once with a new proof.
A second 401 or failed refresh ends recovery. Complete credential snapshots carry
session IDs and increasing revisions; publication and reply channels may deliver
in either order without replacing newer credentials. Proof and refresh channels
have bounded capacity, deadlines and cleanup. Logout or account replacement cannot
substitute another session's credentials for the retry.

The browser recovery suite bundles the actual worker, bridge and Axios client with
tab-local auth/proof fixtures and a disposable HTTP refresh endpoint. It exercises
success, repeated 401s, refresh failure, overlapping requests, logout, account
replacement and isolation between independently authenticated tabs. Proof generation
is mocked; this does not qualify backend cryptography or shared persistent login
state across tabs. Same-account cross-tab refresh coordination, persistence races,
and complete worker-restart acceptance remain open.

Filesystem acceptance also remains open: the general
`LocalFilesystem`/`ReadOnlyFilesystem` wrappers have separate symlink-boundary gaps
beyond the media storage adapter's tests. These findings block a claim that
authentication or storage remediation is complete.

Transcode directory deletion now rejects root/outside/traversal paths and symlink
ancestors, and removes terminal or nested links without following their targets.
Fourteen regression cases cover retained outside files, directory/dangling links,
root aliases, and ordinary cleanup. These pathname checks do not provide a fence
against hostile concurrent filesystem renames; the broader storage boundary and
execution-ownership work remains necessary.

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
The host creation/start/recovery entrypoint is implemented; deployment automation
and production-image qualification remain open. Its low-level lease acknowledgment
does not itself verify cleanup, and
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

A dedicated manual recorder now accepts a job UUID and a stable request UUID.
It returns an existing matching manual snapshot before reading the current schedule,
so retries retain their original minute and arguments even after an edit or deletion.
For a new request it locks the schedule row, rechecks request identity, captures the
database UTC minute and commits the snapshot in one transaction. Separate request
UUIDs remain separate requests. An ID already used for another job or for a scheduled
occurrence is rejected. Missing schedules return no snapshot, and recording does
not change schedule status, advance recovery or dispatch work.

The caller must retain the same request UUID across uncertain responses. Successful
recording means durable intent, not completed execution; the execution guard still
checks whether the schedule exists and matches before invoking it. Authorization
is enforced at HTTP ingress; the one-shot CLI is an operator command. Both
entrypoints now use the recorder.

The recorder and existing occurrence, materializer, recovery and guard checks pass
119 integration tests with 1,130 assertions on disposable PostgreSQL/Redis. These
include real concurrent processes, a retry blocked behind a deleting transaction,
commit uncertainty and transaction-local isolation. All 3,506 unit tests and
baseline-free PHPStan level 6 for this slice pass.

The manual recorder is now used by both HTTP and CLI ingress. The legacy producer
has been removed, and retained legacy wrappers fail without invoking work.
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
messages now fail unrecoverably without using the removed web CPU-pool path.

A PHP heap limit does not bound native or total deployment memory. Direct-child
reaping does not certify descendant containment. Kernel-stalled system calls and
PHP resource teardown can exceed userspace deadlines. The executor rejects active
Swoole coroutines and is intended for a supervised CLI child. Deployment containment
and production resource limits still need load qualification.

The poller and relay loop now run in the admitted scheduler child, using the
dedicated occurrence receiver.
The per-job materializer now has a bounded, durable fair recovery pass.
A callable CLI worker loop now combines recovery and relay with independent
completion-based one-second intervals. Each pass retains its existing batch limits
and durable retry deadlines. Recovery errors do not starve publication; either
phase reports a static diagnostic and retries on its next interval. No exception
payload or connection credential is copied into those diagnostics.

The loop checks shutdown and fresh deployment authority before each phase, then
checks shutdown again after the authority query. Authority loss or check failure
exits the loop. The dedicated PostgreSQL checker requires the scheduler role and
exact active, unexpired deployment token, without acquiring, renewing, releasing
or locking that lease row. This is a committed preflight observation, not a fence
against ownership changing after the check or while a batch runs. The loop waits
in slices of at most 100 ms; database and transport I/O still have no hard wall-time
bound here and require external process containment.

The signal-owning entry method blocks TERM and INT while the loop runs and polls
them synchronously between operations. Signals received during a native database
wait remain pending until the next stop check, preventing the following phase.
The original signal mask is restored on normal return and exceptions. A real
child-process test holds a PostgreSQL table lock, sends TERM during recovery, and
verifies normal exit without publishing a pending occurrence. This does not make
blocked I/O interruptible or replace the supervisor shutdown deadline.

The combined authority, worker-loop, recovery and relay integration checks pass
55 tests with 366 assertions against disposable PostgreSQL and Redis. The full
unit suite passes 3,506 tests with 12,763 assertions, including stale PCNTL error
and signal-mask restoration regressions. Baseline-free PHPStan level 6 passes
for this slice.

The loop runs through the private `bin/worker-scheduler.php` entrypoint under
`app:worker`; no separate public daemon command was added. The mandatory scheduler
reservation is at least 320 MiB. Total admission starts at 1088 MiB for management
and three roles, plus at least 192 MiB if console execution is enabled. These are
reservation policies, not measured capacity. Lease validation cannot stop effects
already running after expiry.
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
downstream execution ownership and production capacity remain broader remediation
work even though the scheduler producer and relay now run under the supervisor.

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
