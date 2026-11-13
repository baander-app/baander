# Scheduler

The Scheduler context implements a cron-like scheduled job system. Jobs are registered with a cron expression and either a Messenger command (marked `SchedulableCommandInterface`) or a Symfony console command (marked `SchedulableConsoleCommandInterface`). In the worker deployment pair, the control unit runs `SchedulerWorkerRunner` (`SchedulerRecoveryPoller` and `SchedulerOccurrenceRelay`), which materializes due occurrences in PostgreSQL and publishes `ExecuteScheduledOccurrenceCommand` to the `scheduler` Redis stream via `MessengerSchedulerOccurrencePublisher`. The execution unit's `scheduler` consumer invokes `ExecuteScheduledOccurrenceHandler`, which dispatches Messenger commands or runs console commands via `BoundedScheduledConsoleExecutor`.

## Domain Models

### Aggregate Roots

| Model | Purpose |
|-------|---------|
| `ScheduledJob` | A scheduled job: schedule, target command, status, and run metadata |

`ScheduledJobState` holds constructor/create/reconstitute in sync per the project's aggregate root convention.

### Value Objects

| Model | Purpose |
|-------|---------|
| `JobType` | Job kind (enum) |
| `ScheduleStatus` | Job lifecycle status (enum) |

### Marker Interfaces & Traits

| Type | Purpose |
|------|---------|
| `SchedulableCommandInterface` | Marker for Messenger commands that may be scheduled |
| `SchedulableConsoleCommandInterface` | Marker for Symfony console commands that may be scheduled |
| `SchedulerParameterSchema` | Trait that builds parameter schemas from `#[OA\Property]` attributes on a parameter class |

## Commands, Handlers & Application Services

| Command | Handler | Purpose |
|---------|---------|---------|
| `ExecuteScheduledOccurrenceCommand` | `ExecuteScheduledOccurrenceHandler` | Claim and execute a due scheduled or manual occurrence |
| `ExecuteScheduledJobCommand` | `ExecuteScheduledJobHandler` | Retired legacy wrapper; direct invocation throws `UnrecoverableMessageHandlingException`, while `ExecuteScheduledOccurrenceHandler` delegates occurrence execution to `executeOccurrence()` |

Application services coordinate recovery and transport publication:

| Service | Purpose |
|---------|---------|
| `SchedulerRecoveryPoller` | Claims active schedules with pending evaluation and materializes due occurrences |
| `SchedulerOccurrenceRelay` | Claims unpublished occurrences and publishes their IDs through `SchedulerOccurrencePublisherInterface` |

## Ports

| Port | Purpose |
|------|---------|
| `ScheduledJobPortInterface` | Scheduled job lookup and persistence for occurrence execution |
| `ScheduledJobAdministrationInterface` | Scheduled job CRUD, enable/disable, pause/resume, and command catalog |
| `SchedulerManualOccurrenceRecorderInterface` | Commit a durable manual occurrence for a scheduled job |
| `SchedulerOccurrenceMaterializerInterface` | Atomically evaluate due cron minutes and commit immutable occurrences |
| `SchedulerRecoveryScheduleStoreInterface` | Claim active schedules due for occurrence materialization |
| `SchedulerWorkerAuthorityInterface` | Assert the scheduler worker's current deployment authority |
| `ScheduledConsoleExecutorInterface` | Execute an authorized console command in a bounded isolated CLI child |
| `SchedulerOccurrenceExecutionStoreInterface` | Claim due occurrences for execution and record handler return receipts |
| `SchedulerOccurrenceDispatchStoreInterface` | Reserve unpublished occurrences and mark them published after transport acceptance |
| `SchedulerOccurrencePublisherInterface` | Publish an occurrence ID to the durable `scheduler` transport |
| `SchedulerOccurrenceStoreInterface` | Record and look up immutable scheduled and manual occurrences |

## Domain Events

Scheduled jobs do not emit domain events. Job lifecycle is observed through run metadata and status transitions on the `ScheduledJob` aggregate.

## API Endpoints

All endpoints are prefixed with `/api` and live under the admin namespace.

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/admin/scheduler/jobs` | List scheduled jobs |
| GET | `/api/admin/scheduler/jobs/{id}` | Show a single job |
| POST | `/api/admin/scheduler/jobs` | Create a job |
| PUT | `/api/admin/scheduler/jobs/{id}` | Update a job |
| DELETE | `/api/admin/scheduler/jobs/{id}` | Delete a job |
| POST | `/api/admin/scheduler/jobs/{id}/pause` | Pause a job |
| POST | `/api/admin/scheduler/jobs/{id}/resume` | Resume a paused job |
| POST | `/api/admin/scheduler/jobs/{id}/trigger` | Trigger an immediate run |
| POST | `/api/admin/scheduler/jobs/{id}/enable` | Enable a job |
| POST | `/api/admin/scheduler/jobs/{id}/disable` | Disable a job |
| GET | `/api/admin/scheduler/jobs/commands` | List schedulable commands |

## Cross-Context Relationships

| Direction | Context | Details |
|-----------|---------|---------|
| Depends on | Shared | `Uuid`, `PublicId`, `DedicatedConnectionFactory`, `MessagePayloadCodecInterface` |
| Depended on by | All contexts with `SchedulableCommandInterface`/`SchedulableConsoleCommandInterface` markers | Any context may expose schedulable commands |

## Infrastructure

| Component | Type | Purpose |
|-----------|------|---------|
| `SchedulerWorkerRunner` | Process runner | Control-unit loop that runs `SchedulerRecoveryPoller` and `SchedulerOccurrenceRelay` under `SchedulerWorkerAuthorityInterface` |
| `BoundedScheduledConsoleExecutor` | Process executor | Runs scheduled console commands in an isolated CLI child with output, heap, and deadline bounds |
| `MessengerSchedulerOccurrencePublisher` | Messenger publisher | Publishes `ExecuteScheduledOccurrenceCommand` to the `scheduler` transport |
| `StopWorkerOnUnknownScheduledConsole` | Messenger subscriber | Stops the worker when a scheduled console child fails with unknown completion |
| `SchedulerMessagePayloadCodec` | Payload codec | Implements `MessagePayloadCodecInterface` for `ExecuteScheduledOccurrenceCommand` |
| `TransactionalSchedulerOccurrenceWriter` | Doctrine writer | Commits schedule changes and manual occurrences in a single transaction |
| `DoctrineSchedulerWorkerAuthority` | Doctrine adapter | Verifies active scheduler worker deployment authority |
| `DoctrineSchedulerRecoveryScheduleStore` | Doctrine store | Claims active schedules pending occurrence evaluation |
| `DoctrineSchedulerOccurrenceMaterializer` | Doctrine materializer | Evaluates cron minutes from each job's durable cursor and writes due occurrences |
| `DoctrineSchedulerManualOccurrenceRecorder` | Doctrine recorder | Records manual job occurrences keyed by request UUID |
| `DoctrineSchedulerOccurrenceStore` | Doctrine store | Persists and queries immutable scheduled and manual occurrences |
| `DoctrineSchedulerOccurrenceDispatchStore` | Doctrine store | Claims unpublished occurrences and records transport publication receipts |
| `DoctrineSchedulerOccurrenceExecutionStore` | Doctrine store | Claims due occurrences for execution and records return receipts |
| `SchedulerRegistry` | Domain service | Registry of schedulable commands (Messenger + console) |
| `ScheduledJobService` | Infrastructure service | Implements `ScheduledJobPortInterface` and `ScheduledJobAdministrationInterface` |
| `ScheduledJobEntity` | Doctrine entity | Persistence for `ScheduledJob` |
| `ScheduledJobRepository` | Doctrine repository | Repository implementation |
| `CronExpression` validator | Validator | Validates cron expressions on create/update requests |

### Console Commands

| Command | Purpose |
|---------|---------|
| `app:scheduler:list` (`SchedulerListCommand`) | List scheduled jobs |
| `app:scheduler:show` (`SchedulerShowCommand`) | Show a scheduled job with its schedule, parameters and last run |
| `app:scheduler:create` (`SchedulerCreateCommand`) | Create a scheduled job that runs a schedulable command on a cron schedule |
| `app:scheduler:update` (`SchedulerUpdateCommand`) | Change a scheduled job's name, schedule, command, description or parameters |
| `app:scheduler:delete` (`SchedulerDeleteCommand`) | Delete a scheduled job |
| `app:scheduler:pause` (`SchedulerPauseCommand`) | Pause a scheduled job; it stops running until resumed |
| `app:scheduler:resume` (`SchedulerResumeCommand`) | Resume a paused scheduled job |
| `app:scheduler:enable` (`SchedulerEnableCommand`) | Enable a disabled scheduled job |
| `app:scheduler:disable` (`SchedulerDisableCommand`) | Disable a scheduled job; it stops running until enabled |
| `app:scheduler:run` (`SchedulerRunCommand`) | Record a durable manual request for a scheduled job (CLI counterpart of `POST /api/admin/scheduler/jobs/{id}/trigger`) |
| `app:scheduler:commands` (`SchedulerCommandsCommand`) | List the commands a scheduled job can run, with their parameters |

See [Architecture](../architecture/overview.md) for the project's layer and context boundaries.
