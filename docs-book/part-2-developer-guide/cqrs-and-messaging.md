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

Every dispatched command gets a `JobIdStamp` automatically applied by `JobMonitoringMiddleware`. This assigns a unique job ID that can be tracked through the monitoring endpoint. See [Monitoring](../part-1-operator-guide/monitoring.md) for details.

## Async Processing

In production, the Messenger transport is Redis (`MESSENGER_TRANSPORT_DSN`). Workers consume commands from the Redis queue:

```
php bin/console messenger:consume async
```

Workers are managed by supervisord inside the Docker container. If a command fails, it is retried according to the Messenger retry configuration.

In tests, commands are processed synchronously by default — no worker process is needed. This makes unit and functional tests deterministic.

## See Also

- [Coding Conventions](coding-conventions.md) — CQRS rules and common mistakes
- [Real-Time Patterns](real-time-patterns.md) — how events feed into WebSocket and SSE
- [Testing](testing.md) — how to test handlers
