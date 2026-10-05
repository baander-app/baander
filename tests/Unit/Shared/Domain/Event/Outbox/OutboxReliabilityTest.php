<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Event\Outbox;

use App\Auth\Domain\Event\UserRegistered;
use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Event\Outbox\OutboxRepository;
use App\Shared\Domain\Event\Outbox\OutboxSubscriber;
use App\Shared\Domain\Event\Outbox\OutboxSubscriberPass;
use App\Shared\Domain\Event\Outbox\RelayOutboxCommand;
use App\Shared\Domain\Event\Outbox\RelayOutboxHandler;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use App\Shared\Domain\Event\Outbox\OutboxEventDispatcherInterface;

/**
 * Concrete event class used to prove that OutboxSubscriberPass only sees
 * classes registered as DI definitions, not every AbstractDomainEvent subclass.
 */
final readonly class OutboxSubscriberPassTestEvent extends AbstractDomainEvent
{
    public function eventName(): string
    {
        return 'outbox.test_event';
    }
}

/**
 * Confirms the transactional-outbox implementation lacks the reliability
 * mechanisms required for safe multi-consumer relay: row-level locking and
 * retry/dead-letter handling on failure, and that event discovery is tied to
 * DI definitions rather than event classes.
 */
#[AllowMockObjectsWithoutExpectations]
final class OutboxReliabilityTest extends TestCase
{
    /**
     * fetchPending() must use a pessimistic lock (e.g. FOR UPDATE) so that
     * multiple concurrent relay workers cannot select and double-deliver the
     * same pending event.
     */
    public function testFetchPendingUsesPessimisticLocking(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeQuery')
            ->with(
                $this->callback(static function (string $sql): bool {
                    return str_contains($sql, 'FOR UPDATE');
                }),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn($this->createMock(\Doctrine\DBAL\Result::class));

        $repository = new OutboxRepository($connection);
        $repository->fetchPending();
    }

    /**
     * When an event fails to dispatch, the handler must record the retry state
     * (increment attempts and set a backoff window) and report the failure in a
     * single aggregated exception rather than silently swallowing it or failing
     * on the first row and abandoning the rest of the batch.
     */
    public function testFailedRelayRecordsRetryStateAndThrowsAggregatedException(): void
    {
        $userId = Uuid::v4();
        $publicId = PublicId::fromString('aaaaaaaaaaaaaaaaaaaaa');

        $row = [
            'id' => 1,
            'event_class' => UserRegistered::class,
            'event_name' => 'user.registered',
            'payload' => json_encode([
                'user_id' => $userId->toString(),
                'public_id' => $publicId->toString(),
                'email' => 'test@baander.app',
                'name' => 'Test User',
                'occurred_at' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
            ], JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'lease_token' => 'lease-a',
        ];

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeQuery')
            ->willReturn($this->createResultMock([$row]));
        $connection->expects($this->exactly(2))->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $parameters): int {
                self::assertSame('lease-a', $parameters['leaseToken']);
                if (str_contains($sql, 'next_attempt_at =')) {
                    self::assertSame(1, $parameters['attempts']);
                    self::assertNotNull($parameters['nextAttemptAt']);
                    self::assertNull($parameters['deadLetteredAt']);
                }
                return 1;
            });

        $repository = new OutboxRepository($connection);

        $dispatcher = $this->createMock(OutboxEventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException(new RuntimeException('Dispatch transport unavailable'));

        $handler = new RelayOutboxHandler($repository, $dispatcher, new NullLogger());

        $this->expectException(\App\Shared\Domain\Event\Outbox\OutboxRelayException::class);

        try {
            $handler(new RelayOutboxCommand());
        } catch (\App\Shared\Domain\Event\Outbox\OutboxRelayException $e) {
            $this->assertCount(1, $e->getFailures());
            $this->assertSame('user.registered', $e->getFailures()[0]['event']);

            throw $e;
        }
    }

    /**
     * Events that have exhausted the maximum retry attempts are moved to the
     * dead-letter queue instead of being scheduled for another attempt.
     */
    public function testExhaustedAttemptsMoveRowToDeadLetter(): void
    {
        $userId = Uuid::v4();
        $publicId = PublicId::fromString('aaaaaaaaaaaaaaaaaaaaa');

        $row = [
            'id' => 2,
            'event_class' => UserRegistered::class,
            'event_name' => 'user.registered',
            'payload' => json_encode([
                'user_id' => $userId->toString(),
                'public_id' => $publicId->toString(),
                'email' => 'test@baander.app',
                'name' => 'Test User',
                'occurred_at' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
            ], JSON_THROW_ON_ERROR),
            'attempts' => 4,
            'lease_token' => 'lease-b',
        ];

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeQuery')
            ->willReturn($this->createResultMock([$row]));
        $connection->expects($this->exactly(2))->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $parameters): int {
                self::assertSame('lease-b', $parameters['leaseToken']);
                if (str_contains($sql, 'next_attempt_at =')) {
                    self::assertSame(5, $parameters['attempts']);
                    self::assertNull($parameters['nextAttemptAt']);
                    self::assertNotNull($parameters['deadLetteredAt']);
                }
                return 1;
            });

        $repository = new OutboxRepository($connection);

        $dispatcher = $this->createMock(OutboxEventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException(new RuntimeException('Dispatch transport unavailable'));

        $handler = new RelayOutboxHandler($repository, $dispatcher, new NullLogger());

        $this->expectException(\App\Shared\Domain\Event\Outbox\OutboxRelayException::class);

        $handler(new RelayOutboxCommand());
    }

    /**
     * The compiler pass should discover every AbstractDomainEvent subclass that
     * the application can emit, not only those that happen to be registered as
     * DI service definitions. The current implementation iterates definitions,
     * so a concrete event class that is not a service is never registered with
     * the subscriber.
     */
    public function testDiscoversEventClassesNotOnlyDiDefinitions(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(OutboxSubscriber::class, new Definition(OutboxSubscriber::class));

        // The test event class exists and extends AbstractDomainEvent, but is
        // intentionally NOT registered as a service definition.
        $this->assertFalse($container->hasDefinition(OutboxSubscriberPassTestEvent::class));

        (new OutboxSubscriberPass())->process($container);

        $tags = $container->getDefinition(OutboxSubscriber::class)->getTags();
        $this->assertArrayHasKey('kernel.event_listener', $tags);

        $events = array_column($tags['kernel.event_listener'], 'event');
        $this->assertContains(OutboxSubscriberPassTestEvent::class, $events);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function createResultMock(array $rows): \Doctrine\DBAL\Result
    {
        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAllAssociative')->willReturn($rows);

        return $result;
    }
}
