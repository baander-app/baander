# CQRS and Messaging

Baander uses Symfony Messenger to implement the Command Query Responsibility Segregation (CQRS) pattern. Commands represent writes (create, update, delete), queries represent reads, and they are handled asynchronously through a message bus.

## How It Works

1. A controller creates a command DTO and dispatches it via `MessageBusInterface`
2. Messenger routes the command to the matching handler (identified by `#[AsMessageHandler]`)
3. The handler executes business logic using domain models and repositories
4. The handler returns a result or dispatches domain events

In production, commands are processed asynchronously by a worker process consuming from Redis. In development and tests, commands are processed synchronously by default.

## Writing a Command

Commands are `final readonly class` with getter-only properties. They carry input data and nothing else — no business logic.

```php
// src/Playlist/Application/Command/CreatePlaylistCommand.php
final readonly class CreatePlaylistCommand
{
    public function __construct(
        private string $name,
        private Uuid $userId,
        private ?string $description = null,
        private bool $isPublic = false,
    ) {
    }

    public function getName(): string { return $this->name; }
    public function getUserId(): Uuid { return $this->userId; }
    public function getDescription(): ?string { return $this->description; }
    public function isPublic(): bool { return $this->isPublic; }
}
```

## Writing a Handler

Handlers are `final class` with `#[AsMessageHandler]` on `__invoke`. They depend on domain interfaces (repositories, ports), never on infrastructure directly.

```php
// src/Playlist/Application/CommandHandler/CreatePlaylistHandler.php
final class CreatePlaylistHandler
{
    public function __construct(
        private readonly PlaylistRepositoryInterface $playlistRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(CreatePlaylistCommand $command): Playlist
    {
        $playlist = Playlist::create(
            $command->getName(),
            $command->getUserId(),
            $command->getDescription(),
            $command->isPublic(),
        );

        $this->playlistRepository->save($playlist);

        $this->eventDispatcher->dispatch(new PlaylistCreated(
            playlistId: $playlist->getId(),
            name: $playlist->getName(),
            userId: $command->getUserId(),
        ));

        return $playlist;
    }
}
```

## Dispatching from a Controller

Controllers inject `MessageBusInterface` and dispatch commands:

```php
$this->commandBus->dispatch(new CreatePlaylistCommand(
    name: $payload->name,
    userId: $user->getId(),
    description: $payload->description,
    isPublic: $payload->isPublic(),
));
```

Symfony's service container auto-wires `MessageBusInterface` to the command bus. No explicit configuration is needed.

## Domain Events

Domain events carry side-effect signals between contexts. They extend `AbstractDomainEvent` and implement `eventName()`, `toPayload()`, and `fromPayload()`:

```php
// src/Transcode/Domain/Event/TranscodeJobCompleted.php
final readonly class TranscodeJobCompleted extends AbstractDomainEvent
{
    public function __construct(
        private readonly Uuid $jobId,
        private readonly Uuid $videoId,
        private readonly string $qualityTier,
        private readonly int $totalSegments,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($occurredAt);
    }

    public function eventName(): string
    {
        return 'transcode.job_completed';
    }

    public function toPayload(): array { /* ... */ }
    public static function fromPayload(array $payload): static { /* ... */ }
}
```

Events are dispatched via Symfony's `EventDispatcherInterface` inside handlers. Event listeners in other contexts react to these events (e.g., a `TranscodeJobCompleted` listener might notify users that their video is ready).

## Job Monitoring

Every dispatched message gets a `JobIdStamp` from `JobMonitoringMiddleware` before a transport stores it. The job ID travels with the message, so each delivery of it (a Messenger retry, a retry from the failure transport, or a redelivery after a worker died) is an attempt of the same job. `WorkerJobMonitorSubscriber` and `SwooleTaskJobMonitorDecorator` record each attempt with `JobMonitorService::startAttempt()`, which upserts the job's single `job_monitors` row, and complete only the attempt they started. See [Monitoring](../part-1-operator-guide/monitoring.md) for details.

A long-running handler lets an operator cancel its job. It calls `JobCancellationCheckpointInterface::check()` (a Shared Application port) between items of its work, outside any `catch` that would swallow the exception. `check()` throws `JobCancelledException` when the job monitor's Cancel action, or `app:monitor:job:cancel`, has set the job's cancellation flag; outside a job run it does nothing. Each check costs a Redis `EXISTS`, so place checkpoints per item of real work (an album, a directory, a page), not inside a tight loop.

`JobCancellationMiddleware` runs each received job as the current job of its coroutine, so the checkpoint finds the job ID without the handler passing it, on Messenger workers, Swoole task workers and inline runs alike. When the handler stops at a checkpoint, the middleware marks the row `cancelled` and returns the envelope with a `JobCancelledStamp`, so the transport acknowledges the delivery: it is not retried and does not reach the failure transport. An inline run (`JobMonitorAdministrationInterface::runInline()`) rethrows `JobCancelledException` to its console command.

## Async Processing

In production, the Messenger transport is Redis (`MESSENGER_TRANSPORT_DSN`). Workers consume commands from the Redis queue:

```
php bin/console messenger:consume async
```

Workers are managed by supervisord inside the Docker container. If a command fails, it is retried according to the Messenger retry configuration.

A message that exhausts its retries goes to the `failed` transport. That transport is a PostgreSQL table, `failed_messages`, created by a migration rather than by Messenger (`auto_setup: false`) and excluded from Doctrine schema comparison. Its receiver is listable, so `messenger:failed:show`, `messenger:failed:retry` and `messenger:failed:remove` work by message ID, and the admin endpoints in [Monitoring](../part-1-operator-guide/monitoring.md#failed-messages) use the same receiver. Tests use this table too; the other transports are in-memory.

In tests, commands are processed synchronously by default — no worker process is needed. This makes unit and functional tests deterministic.

## See Also

- [Coding Conventions](coding-conventions.md) — CQRS rules and common mistakes
- [Real-Time Patterns](real-time-patterns.md) — how events feed into WebSocket and SSE
- [Testing](testing.md) — how to test handlers
