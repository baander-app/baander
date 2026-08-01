# Scheduler

The Scheduler context implements a cron-like scheduled job system. Jobs are registered with a cron expression and either a Messenger command (marked `SchedulableCommandInterface`) or a Symfony console command (marked `SchedulableConsoleCommandInterface`). A Swoole-backed scheduler process polls due jobs and dispatches them through the CPU process pool so blocking command execution never lands on a Swoole worker.

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

### Marker Interfaces

| Interface | Purpose |
|-----------|---------|
| `SchedulableCommandInterface` | Marker for Messenger commands that may be scheduled |
| `SchedulableConsoleCommandInterface` | Marker for Symfony console commands that may be scheduled |

## Commands & Handlers

| Command | Handler | Purpose |
|---------|---------|---------|
| `ExecuteScheduledJobCommand` | `ExecuteScheduledJobHandler` | Execute a due scheduled job |

## Ports

| Port | Purpose |
|------|---------|
| `ScheduledJobPortInterface` | Scheduled job CRUD, enable/disable, pause/resume, and trigger |

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
| Depends on | Shared | `Uuid`, `Async`, `CpuProcessPoolInterface`, `RedisClientFactory` |
| Depended on by | All contexts with `SchedulableCommandInterface`/`SchedulableConsoleCommandInterface` markers | Any context may expose schedulable commands |

## Infrastructure

| Component | Type | Purpose |
|-----------|------|---------|
| `SchedulerProcess` | Swoole process | Long-running process that polls due jobs and dispatches executions |
| `SchedulerConsolePoolWorker` | Process pool worker | Worker that runs scheduled console commands inside the CPU process pool |
| `SchedulerProcessShutdownHandler` | Shutdown handler | Graceful shutdown for the scheduler process |
| `SchedulerRegistry` | Domain service | Registry of schedulable commands (Messenger + console) |
| `ScheduledJobService` | Application service | Job lifecycle operations backing the port |
| `ScheduledJobEntity` | Doctrine entity | Persistence for `ScheduledJob` |
| `ScheduledJobRepository` | Doctrine repository | Repository implementation |
| `CronExpression` validator | Validator | Validates cron expressions on create/update requests |

### Console Commands

| Command | Purpose |
|---------|---------|
| `app:scheduler:list` (`SchedulerListCommand`) | List scheduled jobs |
| `app:scheduler:run` (`SchedulerRunCommand`) | Run due jobs |

See the [Architecture](../architecture.md#async-runtime) page for details on the Swoole scheduler process and the CPU process pool.
