<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Event\Outbox;

use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Event\Outbox\OutboxRepository;
use App\Shared\Domain\Event\Outbox\OutboxSubscriber;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

final class OutboxSubscriberFailureTest extends TestCase
{
    public function testSerializationFailureIsLoggedAndOriginalErrorPropagates(): void
    {
        $error = new RuntimeException('Cannot serialize event');
        $event = new readonly class($error) extends AbstractDomainEvent {
            public function __construct(private RuntimeException $error)
            {
                parent::__construct();
            }

            public function eventName(): string
            {
                return 'outbox.serialization_failure';
            }

            /** @return array<string, mixed> */
            public function toPayload(): array
            {
                throw $this->error;
            }
        };

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('insert');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            'Outbox: failed to serialize event {event}',
            ['event' => $event->eventName(), 'error' => $error->getMessage()],
        );

        $subscriber = new OutboxSubscriber(new OutboxRepository($connection), $logger);

        $this->expectExceptionObject($error);
        $subscriber($event);
    }

    public function testRepositoryFailurePropagates(): void
    {
        $error = new RuntimeException('Database unavailable');
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('insert')->willThrowException($error);
        $subscriber = new OutboxSubscriber(new OutboxRepository($connection), new NullLogger());

        $this->expectExceptionObject($error);
        $subscriber($this->serializableEvent());
    }

    public function testSuccessfulPayloadIsPersisted(): void
    {
        $event = $this->serializableEvent();
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('insert')->with(
            'domain_event_outbox',
            $this->callback(static fn (array $row): bool =>
                $row['event_class'] === $event::class
                && $row['event_name'] === $event->eventName()
                && json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR) === $event->toPayload()),
        );

        (new OutboxSubscriber(new OutboxRepository($connection), new NullLogger()))($event);
    }

    public function testEventWithoutPayloadRemainsTransient(): void
    {
        $event = new readonly class extends AbstractDomainEvent {
            public function eventName(): string
            {
                return 'outbox.transient';
            }
        };
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('insert');

        (new OutboxSubscriber(new OutboxRepository($connection), new NullLogger()))($event);
    }

    private function serializableEvent(): OutboxPersistableTestEvent
    {
        return new OutboxPersistableTestEvent();
    }
}

final readonly class OutboxPersistableTestEvent extends AbstractDomainEvent
{
    public function eventName(): string
    {
        return 'outbox.persistable';
    }

    /** @return array{value: string} */
    public function toPayload(): array
    {
        return ['value' => 'persist me'];
    }
}
