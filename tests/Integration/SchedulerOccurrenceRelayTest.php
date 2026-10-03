<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use App\Scheduler\Application\Command\ExecuteScheduledOccurrenceCommand;
use App\Scheduler\Application\DTO\SchedulerOccurrence;
use App\Scheduler\Application\Port\SchedulerOccurrencePublisherInterface;
use App\Scheduler\Application\Service\SchedulerOccurrenceRelay;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerOccurrenceDispatchStore;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerOccurrenceExecutionStore;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerOccurrenceStore;
use App\Scheduler\Infrastructure\Messenger\MessengerSchedulerOccurrencePublisher;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Messaging\JsonMessageCodec;
use App\Shared\Infrastructure\Messenger\JsonTransportSerializer;
use App\Shared\Infrastructure\Worker\DeploymentLease;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentLease;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection as RedisConnection;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/** Real committed scheduler handoff and lost-send reply; isolated PostgreSQL schema and Redis stream. */
final class SchedulerOccurrenceRelayTest extends TestCase
{
    private Connection $writer;
    private Connection $observer;
    private string $schema;
    /** @var list<RedisConnection> */
    private array $redisConnections = [];

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to disposable PostgreSQL.');
        }
        $parameters = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $this->writer = DriverManager::getConnection($parameters);
        $this->observer = DriverManager::getConnection($parameters);
        $this->schema = 'scheduler_relay_test_' . bin2hex(random_bytes(8));
        $this->writer->executeStatement('CREATE SCHEMA ' . $this->schema);
        foreach ([$this->writer, $this->observer] as $connection) {
            $connection->executeStatement('SET search_path TO ' . $this->schema);
        }
        foreach (['Version20261002210000', 'Version20261002230000', 'Version20261003010000'] as $version) {
            require_once dirname(__DIR__, 2) . '/migrations/' . $version . '.php';
            $class = 'DoctrineMigrations\\' . $version;
            $migration = new $class($this->writer, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $this->writer->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        }
    }

    public function testCommittedReservationPrecedesRealSendAndReceiptSurvivesRelayRestart(): void
    {
        $occurrence = $this->record();
        $transport = $this->transport();
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects(self::once())->method('send')->willReturnCallback(function (Envelope $envelope) use ($occurrence, $transport): Envelope {
            $this->assertReservedBeforeSend($occurrence, $envelope);
            return $transport->send($envelope);
        });
        self::assertSame(1, $this->relay($sender)->dispatchPending());
        $receipt = $this->row($occurrence);
        self::assertNotNull($receipt['dispatched_at']);
        self::assertSame(0, $this->relay($sender)->dispatchPending());
        self::assertSame($receipt, $this->row($occurrence));
        self::assertSame(1, $transport->getMessageCount());
        $this->assertQueuedIds($transport, [$occurrence->id->toString()]);
    }

    public function testAcceptedSendWithLostReplyRetriesStableIdAndExecutionClaimsOnlyOnce(): void
    {
        $occurrence = $this->record();
        $transport = $this->transport();
        $failure = new \RuntimeException('Disposable accepted-send reply lost.');
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects(self::once())->method('send')->willReturnCallback(function (Envelope $envelope) use ($occurrence, $transport, $failure): never {
            $this->assertReservedBeforeSend($occurrence, $envelope);
            $transport->send($envelope);
            throw $failure;
        });
        $this->assertRelayFailure($this->relay($sender), $failure);
        self::assertNull($this->row($occurrence)['dispatched_at']);
        self::assertSame(1, $transport->getMessageCount());
        self::assertSame(0, $this->relay($transport)->dispatchPending(), 'Unknown send stays reserved until database-clock expiry.');
        $this->expire($occurrence);
        self::assertSame(1, $this->relay($transport)->dispatchPending());
        self::assertNotNull($this->row($occurrence)['dispatched_at']);
        self::assertSame(2, $transport->getMessageCount());

        $authority = (new DoctrineDeploymentLease($this->writer))->acquire('baander.app:scheduler-relay', str_repeat('a', 32), 300);
        self::assertInstanceOf(DeploymentLease::class, $authority);
        $executions = new DoctrineSchedulerOccurrenceExecutionStore($this->writer, $authority);
        $grants = 0;
        $delivered = [];
        for ($delivery = 0; $delivery < 2; ++$delivery) {
            foreach ($transport->get() as $envelope) {
                $message = $envelope->getMessage();
                self::assertInstanceOf(ExecuteScheduledOccurrenceCommand::class, $message);
                $delivered[] = $message->occurrenceId->toString();
                if ($executions->begin($message->occurrenceId, Uuid::generate()) !== null) {
                    ++$grants;
                }
                $transport->ack($envelope);
            }
        }
        self::assertSame([$occurrence->id->toString(), $occurrence->id->toString()], $delivered);
        self::assertSame(1, $grants);
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM scheduler_occurrence_executions'));
        self::assertSame(0, $transport->getMessageCount());
    }

    public function testRejectedSendRetainsIntentAndRetriesAfterReservationExpires(): void
    {
        $occurrence = $this->record();
        $transport = $this->transport();
        $failure = new \RuntimeException('Disposable transport rejected before acceptance.');
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects(self::once())->method('send')->willReturnCallback(function (Envelope $envelope) use ($occurrence, $failure): never {
            $this->assertReservedBeforeSend($occurrence, $envelope);
            throw $failure;
        });
        $this->assertRelayFailure($this->relay($sender), $failure);
        self::assertNull($this->row($occurrence)['dispatched_at']);
        self::assertSame(0, $transport->getMessageCount());
        $this->expire($occurrence);
        self::assertSame(1, $this->relay($transport)->dispatchPending());
        $this->assertQueuedIds($transport, [$occurrence->id->toString()]);
    }

    public function testFailedFirstSendDoesNotStarveAnotherClaimedOccurrence(): void
    {
        $first = $this->record();
        $second = $this->record();
        $transport = $this->transport();
        $failure = new \RuntimeException('Disposable poison transport entry.');
        $failedId = null;
        $publishedId = null;
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects(self::exactly(2))->method('send')->willReturnCallback(function (Envelope $envelope) use ($transport, $failure, &$failedId, &$publishedId): Envelope {
            $message = $envelope->getMessage();
            self::assertInstanceOf(ExecuteScheduledOccurrenceCommand::class, $message);
            if ($failedId === null) {
                $failedId = $message->occurrenceId;
                throw $failure;
            }
            $publishedId = $message->occurrenceId;
            return $transport->send($envelope);
        });
        $this->assertRelayFailure($this->relay($sender), $failure);
        self::assertInstanceOf(Uuid::class, $failedId);
        self::assertInstanceOf(Uuid::class, $publishedId);
        self::assertFalse($failedId->equals($publishedId));
        foreach ([$first, $second] as $occurrence) {
            if ($occurrence->id->equals($failedId)) {
                self::assertNull($this->row($occurrence)['dispatched_at']);
            } else {
                self::assertNotNull($this->row($occurrence)['dispatched_at']);
            }
        }
        $this->assertQueuedIds($transport, [$publishedId->toString()]);
    }

    public function testConfiguredPublisherSendsUuidOnlyToAsyncWithoutInvokingGuard(): void
    {
        $kernel = new Kernel('test', false);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(SchedulerOccurrenceRelay::class, $container->get(SchedulerOccurrenceRelay::class));
            $publisher = $container->get(SchedulerOccurrencePublisherInterface::class);
            self::assertInstanceOf(MessengerSchedulerOccurrencePublisher::class, $publisher);
            $transport = $container->get('messenger.transport.async');
            self::assertInstanceOf(InMemoryTransport::class, $transport);
            $transport->reset();
            $id = Uuid::generate();
            // No occurrence or execution authority exists for this ID. A synchronous guard invocation would fail.
            $publisher->publish($id);
            $sent = $transport->getSent();
            self::assertCount(1, $sent);
            $message = $sent[0]->getMessage();
            self::assertInstanceOf(ExecuteScheduledOccurrenceCommand::class, $message);
            self::assertTrue($id->equals($message->occurrenceId));
            self::assertSame(['occurrenceId'], array_keys(get_object_vars($message)));
        } finally {
            $kernel->shutdown();
        }
    }

    private function relay(SenderInterface $sender): SchedulerOccurrenceRelay
    {
        return new SchedulerOccurrenceRelay(new DoctrineSchedulerOccurrenceDispatchStore($this->writer), new MessengerSchedulerOccurrencePublisher($sender));
    }

    private function record(): SchedulerOccurrence
    {
        $occurrence = new SchedulerOccurrence(Uuid::generate(), Uuid::generate(), new \DateTimeImmutable('2026-10-02T10:00:00Z'), JobType::Console,
            'app:relay-fixture', ['address' => 'scheduler@baander.app', 'fraction' => 1.0]);
        self::assertTrue((new DoctrineSchedulerOccurrenceStore($this->writer))->record($occurrence));
        return $occurrence;
    }

    /** @return array<string, mixed> */
    private function row(SchedulerOccurrence $occurrence): array
    {
        $row = $this->observer->fetchAssociative('SELECT dispatch_token, dispatch_after, dispatched_at FROM scheduler_occurrences WHERE id = :id', ['id' => $occurrence->id->toString()]);
        self::assertIsArray($row);
        return $row;
    }

    private function assertReservedBeforeSend(SchedulerOccurrence $occurrence, Envelope $envelope): void
    {
        $message = $envelope->getMessage();
        self::assertInstanceOf(ExecuteScheduledOccurrenceCommand::class, $message);
        self::assertTrue($message->occurrenceId->equals($occurrence->id));
        self::assertSame(['occurrenceId'], array_keys(get_object_vars($message)));
        self::assertSame(0, $this->writer->getTransactionNestingLevel());
        $row = $this->row($occurrence);
        self::assertNotNull($row['dispatch_token'], 'Independent observer must see committed reservation before transport send.');
        self::assertNull($row['dispatched_at']);
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT CASE WHEN dispatch_after > clock_timestamp() THEN 1 ELSE 0 END FROM scheduler_occurrences WHERE id = :id', ['id' => $occurrence->id->toString()]));
    }

    private function expire(SchedulerOccurrence $occurrence): void
    {
        self::assertSame(1, $this->observer->executeStatement("UPDATE scheduler_occurrences SET dispatch_after = clock_timestamp() - INTERVAL '1 second' WHERE id = :id", ['id' => $occurrence->id->toString()]));
    }

    private function assertRelayFailure(SchedulerOccurrenceRelay $relay, \RuntimeException $failure): void
    {
        try {
            $relay->dispatchPending();
            self::fail('Expected aggregated relay failure.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught->getPrevious());
        }
    }

    private function transport(): RedisTransport
    {
        $dsn = getenv('MESSENGER_TEST_REDIS_DSN');
        self::assertIsString($dsn, 'Provide disposable Redis through the functional runner.');
        self::assertNotSame('', $dsn);
        $connection = RedisConnection::fromDsn($dsn, ['stream' => 'scheduler_relay_' . bin2hex(random_bytes(12)), 'group' => 'test', 'consumer' => 'test']);
        $this->redisConnections[] = $connection;
        $transport = new RedisTransport($connection, new JsonTransportSerializer(new JsonMessageCodec()));
        $transport->setup();
        return $transport;
    }

    /** @param list<string> $expected */
    private function assertQueuedIds(RedisTransport $transport, array $expected): void
    {
        $actual = [];
        foreach ($expected as $_) {
            foreach ($transport->get() as $envelope) {
                $message = $envelope->getMessage();
                self::assertInstanceOf(ExecuteScheduledOccurrenceCommand::class, $message);
                $actual[] = $message->occurrenceId->toString();
                $transport->ack($envelope);
            }
        }
        self::assertSame($expected, $actual);
        self::assertSame(0, $transport->getMessageCount());
    }

    protected function tearDown(): void
    {
        foreach ($this->redisConnections as $connection) {
            $connection->cleanup();
            $connection->close();
        }
        foreach ([$this->writer ?? null, $this->observer ?? null] as $connection) {
            if ($connection !== null && $connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
        if (isset($this->schema)) {
            $this->observer->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
        if (isset($this->writer)) {
            $this->writer->close();
        }
        if (isset($this->observer)) {
            $this->observer->close();
        }
    }
}
