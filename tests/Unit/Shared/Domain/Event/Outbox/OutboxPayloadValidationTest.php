<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Event\Outbox;

use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Event\Outbox\OutboxRelayException;
use App\Shared\Domain\Event\Outbox\OutboxRepository;
use App\Shared\Domain\Event\Outbox\RelayOutboxCommand;
use App\Shared\Domain\Event\Outbox\RelayOutboxHandler;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use App\Shared\Domain\Event\Outbox\OutboxEventDispatcherInterface;

final class OutboxPayloadValidationTest extends TestCase
{
    #[DataProvider('invalidRows')]
    public function testInvalidRowRetriesAndLaterHealthyRowIsRelayed(string $eventClass, string $payload, string $eventName): void
    {
        $this->assertInvalidRowHandled($eventClass, $payload, $eventName, 0);
    }

    public function testUnsupportedRowExhaustingRetriesIsDeadLettered(): void
    {
        $this->assertInvalidRowHandled(PayloadEventWithoutFactory::class, '{}', 'payload.valid', 4);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidRows(): iterable
    {
        yield 'unknown class' => ['App\\MissingOutboxEvent', '{}', 'payload.valid'];
        yield 'missing factory' => [PayloadEventWithoutFactory::class, '{}', 'payload.valid'];
        yield 'non-event class' => [PayloadNonEventFactory::class, '{}', 'payload.valid'];
        yield 'abstract class' => [PayloadAbstractFactory::class, '{}', 'payload.valid'];
        yield 'instance factory' => [PayloadInstanceFactory::class, '{}', 'payload.valid'];
        yield 'private factory' => [PayloadPrivateFactory::create()::class, '{}', 'payload.valid'];
        yield 'wrong event class' => [PayloadWrongEventFactory::class, '{}', 'payload.valid'];
        yield 'non-event result' => [PayloadNonEventResult::class, '{}', 'payload.valid'];
        yield 'event name mismatch' => [PayloadValidEvent::class, '{}', 'payload.other'];
        yield 'malformed JSON' => [PayloadValidEvent::class, '{', 'payload.valid'];
        yield 'null payload' => [PayloadValidEvent::class, 'null', 'payload.valid'];
        yield 'string payload' => [PayloadValidEvent::class, '"value"', 'payload.valid'];
        yield 'number payload' => [PayloadValidEvent::class, '42', 'payload.valid'];
        yield 'boolean payload' => [PayloadValidEvent::class, 'true', 'payload.valid'];
        yield 'empty array payload' => [PayloadValidEvent::class, '[]', 'payload.valid'];
        yield 'array payload' => [PayloadValidEvent::class, '[{"value":1}]', 'payload.valid'];
    }

    private function assertInvalidRowHandled(string $eventClass, string $payload, string $eventName, int $attempts): void
    {
        $rows = [
            ['id' => 1, 'event_class' => $eventClass, 'event_name' => $eventName,
                'payload' => $payload, 'attempts' => $attempts, 'lease_token' => 'invalid-lease'],
            ['id' => 2, 'event_class' => PayloadValidEvent::class, 'event_name' => 'payload.valid',
                'payload' => '{}', 'attempts' => 0, 'lease_token' => 'healthy-lease'],
        ];
        $result = $this->createMock(Result::class);
        $result->expects($this->once())->method('fetchAllAssociative')->willReturn($rows);
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeQuery')->willReturn($result);
        $failures = [];
        $acknowledgements = [];
        $connection->expects($this->exactly(4))->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $parameters) use (&$failures, &$acknowledgements): int {
                if (str_contains($sql, 'SET attempts =')) {
                    $failures[] = $parameters;
                } elseif (str_contains($sql, 'SET relayed_at =')) {
                    $acknowledgements[] = $parameters;
                }

                return 1;
            });
        $dispatcher = $this->createMock(OutboxEventDispatcherInterface::class);
        $dispatcher->expects($this->once())->method('dispatch')
            ->with($this->isInstanceOf(PayloadValidEvent::class), 2);
        $handler = new RelayOutboxHandler(new OutboxRepository($connection), $dispatcher, new NullLogger());

        try {
            $handler(new RelayOutboxCommand());
            $this->fail('Invalid outbox rows must report a relay failure.');
        } catch (OutboxRelayException $exception) {
            self::assertCount(1, $exception->getFailures());
            self::assertSame(1, $exception->getFailures()[0]['id']);
        }

        self::assertSame([['id' => 2, 'leaseToken' => 'healthy-lease']], $acknowledgements);
        self::assertCount(1, $failures);
        self::assertSame(1, $failures[0]['id']);
        self::assertSame('invalid-lease', $failures[0]['leaseToken']);
        self::assertSame($attempts + 1, $failures[0]['attempts']);
        if ($attempts === 4) {
            self::assertNull($failures[0]['nextAttemptAt']);
            self::assertNotNull($failures[0]['deadLetteredAt']);
        } else {
            self::assertNotNull($failures[0]['nextAttemptAt']);
            self::assertNull($failures[0]['deadLetteredAt']);
        }
    }
}

readonly class PayloadEventWithoutFactory extends AbstractDomainEvent
{
    public function eventName(): string
    {
        return 'payload.valid';
    }
}

final readonly class PayloadValidEvent extends PayloadEventWithoutFactory
{
    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        return new self();
    }
}

final class PayloadNonEventFactory
{
    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): PayloadValidEvent
    {
        return new PayloadValidEvent();
    }
}

abstract readonly class PayloadAbstractFactory extends PayloadEventWithoutFactory
{
    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): PayloadValidEvent
    {
        return new PayloadValidEvent();
    }
}

final readonly class PayloadInstanceFactory extends PayloadEventWithoutFactory
{
    /** @param array<string, mixed> $payload */
    public function fromPayload(array $payload): self
    {
        return $this;
    }
}

final readonly class PayloadPrivateFactory extends PayloadEventWithoutFactory
{
    public static function create(): self
    {
        return self::fromPayload([]);
    }

    /** @param array<string, mixed> $payload */
    private static function fromPayload(array $payload): self
    {
        return new self();
    }
}

final readonly class PayloadWrongEventFactory extends PayloadEventWithoutFactory
{
    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): PayloadValidEvent
    {
        return new PayloadValidEvent();
    }
}

final readonly class PayloadNonEventResult extends PayloadEventWithoutFactory
{
    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): \stdClass
    {
        return new \stdClass();
    }
}
