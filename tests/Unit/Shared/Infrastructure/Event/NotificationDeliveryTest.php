<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Event;

use App\Tests\Fixtures\Messaging\MessageCodecFactory;
use App\Notification\Application\DTO\SendEmailCommand;
use App\Notification\Application\DTO\SendPushCommand;
use App\Notification\Application\DTO\SendWebhookCommand;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Notification\Infrastructure\Messaging\NotificationDeliveryIntentResolver;
use App\Shared\Domain\Event\Outbox\OutboxRelayException;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Event\NotificationDeliveryBus;
use App\Shared\Infrastructure\Event\NotificationDeliveryRepository;
use App\Shared\Infrastructure\Event\RelayNotificationDeliveriesHandler;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class NotificationDeliveryTest extends TestCase
{
    /** @param 'email'|'push'|'webhook' $channel */
    #[DataProvider('channels')]
    public function testBusPersistsVersionedIntent(string $channel): void
    {
        $message = self::message($channel);
        $codec = MessageCodecFactory::create();
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeStatement')
            ->with($this->callback(static fn (string $sql): bool => str_contains($sql, 'ON CONFLICT (channel, notification_id) DO NOTHING')),
                ['channel' => $channel, 'notificationId' => 'notification-1', 'payload' => $codec->encode($message)])
            ->willReturn(1);
        $bus = new NotificationDeliveryBus(new NotificationDeliveryRepository($connection), $codec, new NotificationDeliveryIntentResolver());

        self::assertSame($message, $bus->dispatch($message)->getMessage());
    }

    /** @return iterable<int, array{'email'|'push'|'webhook'}> */
    public static function channels(): iterable
    {
        yield ['email'];
        yield ['push'];
        yield ['webhook'];
    }

    #[DataProvider('unsupportedMessages')]
    public function testBusRejectsUnsupportedMessageOrStamps(string $case): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('executeStatement');
        $bus = new NotificationDeliveryBus(new NotificationDeliveryRepository($connection), MessageCodecFactory::create(), new NotificationDeliveryIntentResolver());
        $message = match ($case) {
            'unsupported' => new \stdClass(),
            'envelope stamps' => new Envelope(self::message('push'), [new DelayStamp(100)]),
            'blank ID' => new SendPushCommand(Uuid::v4(), NotificationCategory::Security, 'title', 'body', '   '),
            'overlong ID' => new SendWebhookCommand(Uuid::v4(), NotificationCategory::Security, 'title', 'body', str_repeat('x', 65)),
            'missing ID' => new SendEmailCommand(Uuid::v4(), 'user@baander.app', NotificationCategory::Security, 'title', [], 'body', [], new \DateTimeImmutable()),
            default => self::message('push'),
        };
        $this->expectException(\InvalidArgumentException::class);
        $bus->dispatch($message, $case === 'argument stamps' ? [new DelayStamp(100)] : []);
    }

    /** @return iterable<int, array{string}> */
    public static function unsupportedMessages(): iterable
    {
        yield ['unsupported'];
        yield ['envelope stamps'];
        yield ['argument stamps'];
        yield ['missing ID'];
        yield ['blank ID'];
        yield ['overlong ID'];
    }

    public function testClaimsUseSkipLockedAndBoundedLease(): void
    {
        $result = $this->createMock(Result::class);
        $result->expects($this->once())->method('fetchAllAssociative')->willReturn([]);
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeQuery')
            ->with($this->callback(static fn (string $sql): bool => str_contains($sql, 'FOR UPDATE SKIP LOCKED')
                && str_contains($sql, "INTERVAL '60 seconds'") && str_contains($sql, 'dead_lettered_at IS NULL')),
                $this->callback(static fn (array $parameters): bool => $parameters['limit'] === 25 && strlen($parameters['leaseToken']) === 64),
                $this->anything())
            ->willReturn($result);
        self::assertSame([], (new NotificationDeliveryRepository($connection))->fetchPending(25));
    }

    #[DataProvider('invalidBatchSizes')]
    public function testRejectsInvalidBatchSize(int $size): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('executeQuery');
        $this->expectException(\InvalidArgumentException::class);
        (new NotificationDeliveryRepository($connection))->fetchPending($size);
    }

    /** @return iterable<int, array{int}> */
    public static function invalidBatchSizes(): iterable
    {
        yield [0];
        yield [1001];
    }

    #[DataProvider('retryCases')]
    public function testFailedDeliveryRetainsRetryOrDeadLetterAndContinuesBatch(string $failure, int $attempts): void
    {
        $codec = MessageCodecFactory::create();
        $message = self::message('push');
        $payload = match ($failure) {
            'malformed' => '{',
            'wrong channel' => $codec->encode(self::message('email')),
            'wrong ID' => $codec->encode(new SendPushCommand(Uuid::v4(), NotificationCategory::Security, 'title', 'body', 'notification-2')),
            'missing ID' => $codec->encode(new SendEmailCommand(Uuid::v4(), 'user@baander.app', NotificationCategory::Security, 'title', [], 'body', [], new \DateTimeImmutable())),
            'unsupported' => $codec->encode(new \App\Shared\Domain\Event\Outbox\RelayOutboxCommand()),
            default => $codec->encode($message),
        };
        $rows = [
            ['id' => 1, 'channel' => 'push', 'notification_id' => 'notification-1', 'payload' => $payload, 'attempts' => $attempts, 'lease_token' => 'bad'],
            ['id' => 2, 'channel' => 'push', 'notification_id' => 'notification-1', 'payload' => $codec->encode($message), 'attempts' => 0, 'lease_token' => 'good'],
        ];
        $result = $this->createMock(Result::class);
        $result->expects($this->once())->method('fetchAllAssociative')->willReturn($rows);
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeQuery')->willReturn($result);
        $connection->expects($this->exactly(4))->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $parameters) use ($attempts): int {
                if (str_contains($sql, 'SET attempts =')) {
                    self::assertSame(1, $parameters['id']);
                    self::assertSame('bad', $parameters['leaseToken']);
                    self::assertSame($attempts + 1, $parameters['attempts']);
                    self::assertSame($attempts === 4, $parameters['nextAttemptAt'] === null);
                    self::assertSame($attempts !== 4, $parameters['deadLetteredAt'] === null);
                } elseif (str_contains($sql, 'SET relayed_at =')) {
                    self::assertSame(2, $parameters['id']);
                    self::assertSame('good', $parameters['leaseToken']);
                }
                self::assertStringContainsString('lease_until > NOW()', $sql);
                return 1;
            });
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->exactly($failure === 'transport' ? 2 : 1))->method('dispatch')
            ->willReturnCallback(static function (object $sent) use ($failure): Envelope {
                static $calls = 0;
                if ($failure === 'transport' && ++$calls === 1) {
                    throw new \RuntimeException('Transport unavailable');
                }
                return new Envelope($sent);
            });
        $handler = new RelayNotificationDeliveriesHandler(new NotificationDeliveryRepository($connection), $bus, $codec, new NullLogger(), new NotificationDeliveryIntentResolver());
        try {
            $handler();
            $this->fail('Failed delivery must be reported.');
        } catch (OutboxRelayException $exception) {
            self::assertCount(1, $exception->getFailures());
            self::assertSame(1, $exception->getFailures()[0]['id']);
        }
    }

    /** @return iterable<int, array{string, int}> */
    public static function retryCases(): iterable
    {
        yield ['transport', 0];
        yield ['transport', 4];
        yield ['malformed', 0];
        yield ['wrong channel', 0];
        yield ['wrong ID', 0];
        yield ['missing ID', 0];
        yield ['unsupported', 0];
    }

    /** @param 'email'|'push'|'webhook' $channel */
    #[DataProvider('acknowledgements')]
    public function testDurableHandoffPrecedesFencedAcknowledgement(bool $acknowledged, string $channel): void
    {
        $message = self::message($channel);
        $codec = MessageCodecFactory::create();
        $result = $this->createMock(Result::class);
        $result->expects($this->once())->method('fetchAllAssociative')->willReturn([
            ['id' => 7, 'channel' => $channel, 'notification_id' => 'notification-1',
                'payload' => $codec->encode($message), 'attempts' => 0, 'lease_token' => 'lease'],
        ]);
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeQuery')->willReturn($result);
        $handoff = new class {
            public bool $sent = false;
        };
        $connection->expects($this->exactly($acknowledged ? 2 : 3))->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $parameters) use ($handoff, $acknowledged): int {
                self::assertSame(7, $parameters['id']);
                self::assertSame('lease', $parameters['leaseToken']);
                self::assertStringContainsString('lease_until > NOW()', $sql);
                if (str_contains($sql, 'SET relayed_at =')) {
                    self::assertTrue($handoff->sent, 'Acknowledge only after a durable transport handoff.');
                    return $acknowledged ? 1 : 0;
                }
                return 1;
            });
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')
            ->willReturnCallback(static function (object $message, array $stamps) use ($handoff): Envelope {
                self::assertCount(1, $stamps);
                self::assertInstanceOf(TransportNamesStamp::class, $stamps[0]);
                self::assertSame(['async'], $stamps[0]->getTransportNames());
                $handoff->sent = true;
                return new Envelope($message);
            });
        $handler = new RelayNotificationDeliveriesHandler(new NotificationDeliveryRepository($connection), $bus, $codec, new NullLogger(), new NotificationDeliveryIntentResolver());
        if (!$acknowledged) {
            $this->expectException(OutboxRelayException::class);
        }
        self::assertSame(1, $handler());
    }

    /** @return iterable<string, array{bool, 'email'|'push'|'webhook'}> */
    public static function acknowledgements(): iterable
    {
        foreach (['email', 'push', 'webhook'] as $channel) {
            yield $channel.' successful acknowledgement' => [true, $channel];
            yield $channel.' expired acknowledgement' => [false, $channel];
        }
    }

    public function testLostLeaseIsNotDispatched(): void
    {
        $result = $this->createMock(Result::class);
        $result->expects($this->once())->method('fetchAllAssociative')->willReturn([
            ['id' => 1, 'lease_token' => 'expired'],
        ]);
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeQuery')->willReturn($result);
        $connection->expects($this->once())->method('executeStatement')->willReturn(0);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');
        $handler = new RelayNotificationDeliveriesHandler(new NotificationDeliveryRepository($connection), $bus, MessageCodecFactory::create(), new NullLogger(), new NotificationDeliveryIntentResolver());
        self::assertSame(0, $handler());
    }

    /** @param 'email'|'push'|'webhook' $channel */
    private static function message(string $channel): object
    {
        $user = Uuid::fromString('0198ebcf-8b2a-7110-8f07-174f3359b428');
        return match ($channel) {
            'email' => new SendEmailCommand($user, 'user@baander.app', NotificationCategory::Security, 'title', [], 'body', [], new \DateTimeImmutable(), 'notification-1'),
            'push' => new SendPushCommand($user, NotificationCategory::Security, 'title', 'body', 'notification-1'),
            'webhook' => new SendWebhookCommand($user, NotificationCategory::Security, 'title', 'body', 'notification-1'),
        };
    }
}
