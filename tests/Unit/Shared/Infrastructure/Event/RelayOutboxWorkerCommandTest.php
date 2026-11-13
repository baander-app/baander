<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Event;

use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Application\Port\DeliveryIntentResolverInterface;
use App\Shared\Domain\Event\Outbox\OutboxEventDispatcherInterface;
use App\Shared\Domain\Event\Outbox\OutboxRepository;
use App\Shared\Domain\Event\Outbox\RelayOutboxHandler;
use App\Shared\Infrastructure\Event\NotificationDeliveryRepository;
use App\Shared\Infrastructure\Event\RelayNotificationDeliveriesHandler;
use App\Shared\Infrastructure\Event\RelayOutboxWorkerCommand;
use App\Shared\Infrastructure\Messaging\JsonMessageCodec;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;

final class RelayOutboxWorkerCommandTest extends TestCase
{
    #[DataProvider('profiles')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBatchRecyclingUsesTheDeploymentHeap(string $capability, int $expectedBatches): void
    {
        ini_set('memory_limit', '512M');
        $allocation = str_repeat('x', 196 * 1024 * 1024);
        self::assertGreaterThanOrEqual(192 * 1024 * 1024, memory_get_usage(true));
        self::assertLessThan(256 * 1024 * 1024, memory_get_usage(true));

        $result = $this->createStub(Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly($expectedBatches * 2))->method('executeQuery')->willReturn($result);
        $events = new RelayOutboxHandler(new OutboxRepository($connection), $this->createStub(OutboxEventDispatcherInterface::class), new NullLogger());
        $deliveries = new RelayNotificationDeliveriesHandler(new NotificationDeliveryRepository($connection),
            $this->createStub(MessageBusInterface::class), new JsonMessageCodec([]), new NullLogger(), $this->createStub(DeliveryIntentResolverInterface::class));
        $manager = $this->createMock(ObjectManager::class);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);
        $command = new RelayOutboxWorkerCommand($events, $deliveries, $registry, new NullLogger(),
            $capability === 'remux' ? new DeploymentProfile('remux', 2, 4, 1, sharedCopySlots: 2) : new DeploymentProfile());
        $batches = 0;
        $manager->expects($this->exactly($expectedBatches))->method('clear')->willReturnCallback(static function () use (&$batches, $command): void {
            if (++$batches === 2) {
                $command->handleSignal(SIGTERM);
            }
        });

        self::assertSame(Command::SUCCESS, new CommandTester($command)->execute(['--time-limit' => '5']));
        self::assertSame($expectedBatches, $batches);
        self::assertSame(196 * 1024 * 1024, strlen($allocation));
    }

    /** @return iterable<string, array{string, int}> */
    public static function profiles(): iterable
    {
        yield 'remux continues below 256 MiB' => ['remux', 2];
        yield 'transcode continues below 256 MiB' => ['transcode', 2];
    }
}
