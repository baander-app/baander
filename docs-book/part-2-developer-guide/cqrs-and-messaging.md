# CQRS and Messaging

Baander uses Symfony Messenger to implement the Command Query Responsibility Segregation (CQRS) pattern. Commands represent writes (create, update, delete), and queries represent reads. Messenger routes selected background commands to a transport; commands without a transport route run in the calling process.

## How It Works

1. A controller creates a command DTO and dispatches it via `MessageBusInterface`
2. Messenger routes the command to the matching handler (identified by `#[AsMessageHandler]`)
3. The handler executes business logic using domain models and repositories
4. The handler returns a result or dispatches domain events

The routed background commands use Redis outside tests. Tests replace the ordinary transports with in-memory transports; an unrouted command still runs inline.

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

The admitted worker deployment owns the Redis `async` (`MESSENGER_TRANSPORT_DSN`)
and `scheduler` consumers, outbox relay, scheduler and media execution. The web container runs `app:serve`
without task workers or a CPU pool. Follow [Worker deployment](../part-1-operator-guide/commands/README.md#worker-deployment)
for manifests, shared named volumes and the immutable boot lifecycle. Consumer
names include the deployment and boot identity. A child failure, lease loss or
termination signal drains the deployment; replacement requires explicit host
recovery. Automatic worker restart and a second child supervisor are unsupported.

### Message contract and retries

Queued commands and stored job retry data use the `baander.message` JSON format,
version 1. The codec registers supported message types and fields explicitly;
Redis payloads do not depend on Symfony object serialization. Retry metadata keeps
completed handler names, so a retry skips handlers that already succeeded.
Consumers still need idempotency for external effects.

A message that exhausts its retries goes to the `failed` transport. That transport is a PostgreSQL table, `failed_messages`, created by a migration rather than by Messenger (`auto_setup: false`) and excluded from Doctrine schema comparison. Its receiver is listable, so `messenger:failed:show`, `messenger:failed:retry` and `messenger:failed:remove` work by message ID, and the admin endpoints in [Monitoring](../part-1-operator-guide/monitoring.md#failed-messages) use the same receiver. Tests use this table too; the other transports are in-memory.

Tests use in-memory ordinary transports. See [Testing](testing.md) for the disposable-service delivery, retry and runtime drills.

## Durable Event Replay

Live domain events enter the outbox. For `LibraryDirectoryIndexed`, a Library
dispatcher converts the committed event into the existing `FilesDiscovered`
message and sends it to `async`. Other events continue through the private
notification dispatcher, where notification and admin-alert listeners run. Session
and transcode listeners stay on the live dispatcher. A notification event's receipt,
notification records and channel delivery intents commit in one transaction;
replaying the same outbox ID skips an already committed receipt.

Committed email, push and webhook intents use the versioned JSON codec and are
handed to Redis `async`. A lost acknowledgement can repeat that handoff, so the
receipt does not guarantee exactly-once external delivery. Both event replay and
intent handoff use leased claims, retry backoff and dead-letter state after five
failed attempts. See [app:outbox:consume](../part-1-operator-guide/commands/app-outbox-consume.md)
for relay operation and its monitoring limit.

Operator user creation, user registration and email verification commit their
domain writes and event capture in one transaction. The producer runtime drill
verifies those three auth producers; other event producers still need their
transaction boundaries reviewed. See [Testing](testing.md) for the outbox and
producer drills and the limits of their coverage.

Library scans commit each directory's file-index updates and
`LibraryDirectoryIndexed` capture in one transaction. Traversal and hashing stay
outside it. The relay cannot read the intent until the index is committed, so an
import's missing-file observation cannot be overwritten by the scanner's later
index write. A capture failure rolls back that directory; earlier committed
directories survive a later failure or cancellation.

Directory relay uses the existing leased retries and five-attempt dead-letter
policy. It does not use the notification receipt: Redis may accept a message even
when the relay loses its acknowledgement, so duplicate imports remain possible.
The scan's `directoriesQueued` count reports durable acceptance, not completed
transport delivery or catalog ingestion. After a dead-lettered handoff, repair the
cause and use the existing [rescan option](../part-1-operator-guide/commands/app-library-scan.md#queued-directories-and-delivery-failures)
to queue unchanged indexed files again. This producer boundary does not establish
a complete audit of all event producers.

## See Also

- [Coding Conventions](coding-conventions.md) — CQRS rules and common mistakes
- [Real-Time Patterns](real-time-patterns.md) — how events feed into WebSocket pushes
- [Testing](testing.md) — how to test handlers
