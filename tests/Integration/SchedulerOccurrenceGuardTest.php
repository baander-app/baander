<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use App\Scheduler\Application\Command\ExecuteScheduledOccurrenceCommand;
use App\Scheduler\Application\CommandHandler\ExecuteScheduledJobHandler;
use App\Scheduler\Application\CommandHandler\ExecuteScheduledOccurrenceHandler;
use App\Scheduler\Application\DTO\SchedulerOccurrence;
use App\Scheduler\Application\DTO\SchedulerOccurrenceOrigin;
use App\Scheduler\Application\Exception\ScheduledOccurrenceJobBusy;
use App\Scheduler\Application\Port\ScheduledJobPortInterface;
use App\Scheduler\Application\Port\ScheduledConsoleExecutorInterface;
use App\Scheduler\Application\Port\SchedulerOccurrenceExecutionStoreInterface;
use App\Scheduler\Domain\Model\SchedulableCommandInterface;
use App\Scheduler\Domain\Model\ScheduledJob;
use App\Scheduler\Domain\Service\SchedulerRegistry;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerOccurrenceExecutionStore;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerOccurrenceStore;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Infrastructure\Messaging\JsonMessageCodec;
use App\Shared\Infrastructure\Messenger\JsonTransportSerializer;
use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPoolInterface;
use App\Shared\Infrastructure\Worker\DeploymentLease;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentLease;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection as RedisConnection;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Event\WorkerMessageRetriedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\AddErrorDetailsStampListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Worker;

/** Real committed admission around the actual legacy executor; no poller or production transport. */
final class SchedulerOccurrenceGuardTest extends TestCase
{
    private Connection $writer;
    private Connection $observer;
    private string $schema;
    private DeploymentLease $authority;
    /** @var list<RedisConnection> */
    private array $redisConnections = [];

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to disposable PostgreSQL.');
        }
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $this->writer = DriverManager::getConnection($params);
        $this->observer = DriverManager::getConnection($params);
        $this->schema = 'scheduler_guard_test_' . bin2hex(random_bytes(8));
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
        $authority = (new DoctrineDeploymentLease($this->writer))->acquire('baander.app:scheduler-guard', str_repeat('a', 32), 300);
        self::assertInstanceOf(DeploymentLease::class, $authority);
        $this->authority = $authority;
    }

    public function testCommittedClaimPrecedesEffectAndRepeatedCommandDoesNotExecuteAgain(): void
    {
        [$occurrence, $job] = $this->recordOccurrence();
        $effects = 0;
        $guard = $this->guard($occurrence, $job, $effects);
        $command = new ExecuteScheduledOccurrenceCommand($occurrence->id);

        $guard($command);
        $receipt = $this->receipt($occurrence);
        self::assertNotNull($receipt['returned_at']);
        self::assertSame(1, $job->getRunCount());
        self::assertSame('dispatched', $job->getLastResult());
        $guard($command);

        self::assertSame(1, $effects);
        self::assertSame($receipt, $this->receipt($occurrence), 'Redelivery cannot replace the owner or return marker.');
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM scheduler_occurrence_executions'));
    }

    public function testRealKernelResolvesConfiguredGuardAndDedicatedExecutionStore(): void
    {
        $kernel = new Kernel('test', false);
        $variables = ['BAANDER_WORKER_NAMESPACE', 'BAANDER_WORKER_BOOT_ID', 'BAANDER_WORKER_LEASE_EPOCH'];
        $server = $_SERVER;
        $environment = $_ENV;
        $actual = [];
        foreach ($variables as $name) {
            $actual[$name] = getenv($name);
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
        }
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(SchedulerOccurrenceExecutionStoreInterface::class, $container->get(SchedulerOccurrenceExecutionStoreInterface::class));
            $handler = $container->get(ExecuteScheduledOccurrenceHandler::class);
            self::assertInstanceOf(ExecuteScheduledOccurrenceHandler::class, $handler);
            // Maintenance/web boot remains available without worker authority,
            // but even a missing occurrence cannot bypass the admission gate.
            try {
                $handler(new ExecuteScheduledOccurrenceCommand(Uuid::v7()));
                self::fail('Configured execution without deployment authority must be denied.');
            } catch (\RuntimeException $error) {
                self::assertSame('Scheduler execution admission requires active deployment authority.', $error->getMessage());
            }
        } finally {
            try {
                $kernel->shutdown();
            } finally {
                foreach ($variables as $name) {
                    if (array_key_exists($name, $server)) {
                        $_SERVER[$name] = $server[$name];
                    } else {
                        unset($_SERVER[$name]);
                    }
                    if (array_key_exists($name, $environment)) {
                        $_ENV[$name] = $environment[$name];
                    } else {
                        unset($_ENV[$name]);
                    }
                    putenv($actual[$name] === false ? $name : $name . '=' . $actual[$name]);
                }
            }
        }
    }

    public function testExpiredAuthorityDeniesBeforeReadingJobOrInvokingEffectAndRetainsIntent(): void
    {
        [$occurrence] = $this->recordOccurrence();
        $jobs = $this->createMock(ScheduledJobPortInterface::class);
        $jobs->expects(self::never())->method('getById');
        $jobs->expects(self::never())->method('save');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $pool = $this->createMock(CpuProcessPoolInterface::class);
        $pool->expects(self::never())->method('dispatch');
        $redis = $this->createMock(RedisClientFactory::class);
        $redis->expects(self::never())->method('borrow');
        $executor = new ExecuteScheduledJobHandler($jobs, new SchedulerRegistry([], []), $bus, $pool, $redis, new NullLogger(), $this->createStub(ScheduledConsoleExecutorInterface::class));
        $guard = new ExecuteScheduledOccurrenceHandler(new DoctrineSchedulerOccurrenceExecutionStore($this->writer, $this->authority), $executor);
        $this->observer->executeStatement("UPDATE worker_deployment_leases SET expires_at = clock_timestamp() - INTERVAL '1 second' WHERE namespace = :namespace", ['namespace' => $this->authority->namespace]);

        try {
            $guard(new ExecuteScheduledOccurrenceCommand($occurrence->id));
            self::fail('Expired authority must never reach the executor.');
        } catch (\RuntimeException $error) {
            self::assertSame('Scheduler execution admission requires active deployment authority.', $error->getMessage());
        }
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM scheduler_occurrence_executions'));
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM scheduler_occurrences'));
        $retained = (new DoctrineSchedulerOccurrenceStore($this->observer))->find($occurrence->jobId, $occurrence->scheduledFor);
        self::assertNotNull($retained);
        self::assertSame($occurrence->parametersJson(), $retained->parametersJson());
    }

    public function testFailurePersistingAfterEffectLeavesAdmissionConsumedAndRedeliveryDoesNotRepeatEffect(): void
    {
        [$occurrence, $job] = $this->recordOccurrence();
        $effects = 0;
        $failure = new \RuntimeException('Fixture final schedule persistence failed.');
        $guard = $this->guard($occurrence, $job, $effects, $failure);
        $command = new ExecuteScheduledOccurrenceCommand($occurrence->id);

        try {
            $guard($command);
            self::fail('The executor persistence error must propagate.');
        } catch (\RuntimeException $error) {
            self::assertSame($failure, $error);
        }
        $receipt = $this->receipt($occurrence);
        self::assertNull($receipt['returned_at'], 'An exception cannot produce a normal-return receipt.');
        self::assertSame(1, $effects, 'The error occurs after the nested external effect.');
        $guard($command);

        self::assertSame(1, $effects);
        self::assertSame($receipt, $this->receipt($occurrence), 'Started/unknown admission never becomes retry permission.');
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM scheduler_occurrence_executions'));
    }

    public function testActualRedisRetryAcknowledgesConsumedOccurrenceWithoutRepeatingEffect(): void
    {
        [$occurrence, $job] = $this->recordOccurrence();
        $effects = 0;
        $guard = $this->guard($occurrence, $job, $effects, new \RuntimeException('Fixture final schedule persistence failed.'));
        $async = $this->redisTransport();
        $failed = $this->redisTransport();
        $bus = new MessageBus([
            new SendMessageMiddleware(new SendersLocator([ExecuteScheduledOccurrenceCommand::class => ['async']], new ServiceLocator(['async' => static fn () => $async]))),
            new HandleMessageMiddleware(new HandlersLocator([ExecuteScheduledOccurrenceCommand::class => [new HandlerDescriptor($guard, ['from_transport' => 'async'])]])),
        ]);
        $bus->dispatch(new ExecuteScheduledOccurrenceCommand($occurrence->id));
        self::assertSame(0, $effects, 'Sending the wrapper must not execute inline.');
        self::assertSame(1, $async->getMessageCount());
        $events = new EventDispatcher();
        $deliveries = [];
        $retries = [];
        $events->addListener(WorkerMessageReceivedEvent::class, static function (WorkerMessageReceivedEvent $event) use (&$deliveries, $occurrence): void {
            $message = $event->getEnvelope()->getMessage();
            self::assertInstanceOf(ExecuteScheduledOccurrenceCommand::class, $message);
            self::assertSame($occurrence->id->toString(), $message->occurrenceId->toString());
            $deliveries[] = $event->getReceiverName();
        });
        $events->addListener(WorkerMessageRetriedEvent::class, static function (WorkerMessageRetriedEvent $event) use (&$retries): void {
            $retries[] = $event->getEnvelope()->last(RedeliveryStamp::class)?->getRetryCount();
        });
        $events->addSubscriber(new AddErrorDetailsStampListener());
        $events->addSubscriber(new SendFailedMessageForRetryListener(
            new ServiceLocator(['async' => static fn () => $async]),
            new ServiceLocator(['async' => static fn () => new MultiplierRetryStrategy(1, 0)]),
            eventDispatcher: $events,
        ));
        $events->addSubscriber(new SendFailedMessageToFailureTransportListener(new ServiceLocator(['async' => static fn () => $failed])));
        $deadline = hrtime(true) / 1e9 + 3.0;
        $events->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event) use ($deadline): void {
            if ($event->isWorkerIdle() || hrtime(true) / 1e9 >= $deadline) {
                $event->getWorker()->stop();
            }
        });
        (new Worker(['async' => $async], $bus, $events))->run(['sleep' => 1000]);

        self::assertSame(['async', 'async'], $deliveries);
        self::assertSame([1], $retries);
        self::assertSame(1, $effects);
        self::assertNull($this->receipt($occurrence)['returned_at'], 'A successful duplicate delivery cannot manufacture an executor return receipt.');
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM scheduler_occurrence_executions'));
        self::assertSame(0, $async->getMessageCount());
        self::assertSame(0, $failed->getMessageCount());
    }

    public function testBusyJobExhaustsBoundedRetriesWithoutConsumptionAndCanBeExplicitlyRetriedAfterReturn(): void
    {
        [$blocker, $job] = $this->recordOccurrence();
        $pending = new SchedulerOccurrence(Uuid::v7(), $job->getId(), $blocker->scheduledFor->modify('+1 minute'),
            $blocker->jobType, $blocker->command, $blocker->parameters);
        self::assertTrue((new DoctrineSchedulerOccurrenceStore($this->writer))->record($pending));
        $attempt = Uuid::v7();
        $owner = new DoctrineSchedulerOccurrenceExecutionStore($this->observer, $this->authority);
        self::assertNotNull($owner->begin($blocker->id, $attempt));
        $effects = 0;
        $guard = $this->guard($pending, $job, $effects);
        $async = $this->redisTransport();
        $failed = $this->redisTransport();
        // Admission closes its dedicated connection on failures. Restore only this
        // fixture's isolated schema on reconnect; production uses its normal schema.
        $invoke = function (ExecuteScheduledOccurrenceCommand $command) use ($guard): void {
            $this->writer->executeStatement('SET search_path TO ' . $this->schema);
            $guard($command);
        };
        $bus = new MessageBus([
            new SendMessageMiddleware(new SendersLocator([ExecuteScheduledOccurrenceCommand::class => ['async']], new ServiceLocator(['async' => static fn () => $async]))),
            new HandleMessageMiddleware(new HandlersLocator([ExecuteScheduledOccurrenceCommand::class => [new HandlerDescriptor($invoke, ['from_transport' => 'async'])]])),
        ]);
        $events = new EventDispatcher();
        $deliveries = 0;
        $events->addListener(WorkerMessageReceivedEvent::class, static function () use (&$deliveries): void { ++$deliveries; });
        $events->addListener(WorkerMessageFailedEvent::class, static function (WorkerMessageFailedEvent $event): void {
            $error = $event->getThrowable();
            self::assertInstanceOf(HandlerFailedException::class, $error);
            self::assertInstanceOf(ScheduledOccurrenceJobBusy::class, $error->getPrevious());
        });
        $events->addSubscriber(new AddErrorDetailsStampListener());
        $events->addSubscriber(new SendFailedMessageForRetryListener(
            new ServiceLocator(['async' => static fn () => $async]),
            new ServiceLocator(['async' => static fn () => new MultiplierRetryStrategy(3, 0)]),
            eventDispatcher: $events,
        ));
        $events->addSubscriber(new SendFailedMessageToFailureTransportListener(new ServiceLocator(['async' => static fn () => $failed])));
        $deadline = hrtime(true) / 1e9 + 3.0;
        $events->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event) use (&$deadline): void {
            if ($event->isWorkerIdle() || hrtime(true) / 1e9 >= $deadline) {
                $event->getWorker()->stop();
            }
        });
        $bus->dispatch(new ExecuteScheduledOccurrenceCommand($pending->id));
        (new Worker(['async' => $async], $bus, $events))->run(['sleep' => 1000]);
        self::assertSame(4, $deliveries, 'Initial attempt and exactly three retries, never an infinite recoverable retry.');
        self::assertSame(0, $effects);
        self::assertSame(0, $async->getMessageCount());
        self::assertSame(1, $failed->getMessageCount());
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM scheduler_occurrence_executions WHERE occurrence_id = :id', ['id' => $pending->id->toString()]));
        self::assertNull($this->receipt($blocker)['returned_at']);

        self::assertTrue($owner->markReturned($blocker->id, $attempt));
        $retained = iterator_to_array($failed->get());
        self::assertCount(1, $retained);
        $envelope = reset($retained);
        self::assertInstanceOf(Envelope::class, $envelope);
        $command = $envelope->getMessage();
        self::assertInstanceOf(ExecuteScheduledOccurrenceCommand::class, $command);
        self::assertSame($pending->id->toString(), $command->occurrenceId->toString());
        $deadline = hrtime(true) / 1e9 + 3.0;
        $bus->dispatch($command); // Explicit operator retry preserves occurrence identity.
        (new Worker(['async' => $async], $bus, $events))->run(['sleep' => 1000]);
        self::assertSame(5, $deliveries);
        self::assertSame(1, $effects);
        self::assertNotNull($this->receipt($pending)['returned_at']);
        $failed->ack($envelope);
        self::assertSame(0, $failed->getMessageCount());
        self::assertSame(0, $async->getMessageCount());
        self::assertNull($owner->begin($blocker->id, Uuid::v7()), 'A released job slot never reopens the consumed occurrence.');
    }

    public function testPersistedManualOriginRunsPausedJobAndCannotRepeatItsAttempt(): void
    {
        [$scheduled, $job] = $this->recordOccurrence();
        $manual = new SchedulerOccurrence(Uuid::v7(), $job->getId(), $scheduled->scheduledFor,
            $scheduled->jobType, $scheduled->command, $scheduled->parameters, SchedulerOccurrenceOrigin::Manual);
        self::assertTrue((new DoctrineSchedulerOccurrenceStore($this->writer))->record($manual));
        $job->pause();
        $effects = 0;
        $guard = $this->guard($manual, $job, $effects);
        $command = new ExecuteScheduledOccurrenceCommand($manual->id);
        $guard($command);
        $guard($command);
        self::assertSame(1, $effects);
        self::assertSame(1, $job->getRunCount());
        self::assertSame(\App\Scheduler\Domain\ValueObject\ScheduleStatus::Paused, $job->getStatus());
        self::assertNotNull($this->receipt($manual)['returned_at']);
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM scheduler_occurrence_executions'));
        self::assertSame($scheduled->id->toString(), (new DoctrineSchedulerOccurrenceStore($this->writer))->find($job->getId(), $scheduled->scheduledFor)?->id->toString());
    }

    private function redisTransport(): RedisTransport
    {
        $dsn = getenv('MESSENGER_TEST_REDIS_DSN');
        self::assertIsString($dsn, 'Provide disposable Redis through the functional runner.');
        self::assertNotSame('', $dsn);
        $connection = RedisConnection::fromDsn($dsn, ['stream' => 'scheduler_guard_' . bin2hex(random_bytes(12)), 'group' => 'test', 'consumer' => 'test']);
        $this->redisConnections[] = $connection;
        $transport = new RedisTransport($connection, new JsonTransportSerializer(new JsonMessageCodec()));
        $transport->setup();
        return $transport;
    }

    /** @return array{SchedulerOccurrence, ScheduledJob} */
    private function recordOccurrence(): array
    {
        $job = ScheduledJob::create('Guarded schedule', '* * * * *', JobType::Messenger, GuardedSchedulerMessage::class,
            parameters: ['address' => 'worker@baander.app', 'fraction' => 1.0]);
        $occurrence = new SchedulerOccurrence(Uuid::v7(), $job->getId(), new DateTimeImmutable('2026-10-02T10:00:00Z'),
            JobType::Messenger, $job->getCommand(), $job->getParameters());
        self::assertTrue((new DoctrineSchedulerOccurrenceStore($this->writer))->record($occurrence));
        return [$occurrence, $job];
    }

    private function guard(SchedulerOccurrence $occurrence, ScheduledJob $job, int &$effects, ?\RuntimeException $finalSaveFailure = null): ExecuteScheduledOccurrenceHandler
    {
        $jobs = $this->createMock(ScheduledJobPortInterface::class);
        $jobs->expects(self::once())->method('getById')->willReturn($job);
        $saves = 0;
        $jobs->expects(self::exactly(2))->method('save')->willReturnCallback(static function (ScheduledJob $saved) use ($job, &$saves, $finalSaveFailure): void {
            self::assertSame($job, $saved);
            if (++$saves === 2 && $finalSaveFailure !== null) {
                throw $finalSaveFailure;
            }
        });
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(function (object $message, array $stamps = []) use ($occurrence, &$effects): Envelope {
            self::assertInstanceOf(GuardedSchedulerMessage::class, $message, 'Invoke the snapshotted work directly, without redispatching the legacy wrapper.');
            self::assertSame('worker@baander.app', $message->address);
            self::assertSame(1.0, $message->fraction);
            self::assertSame(0, $this->writer->getTransactionNestingLevel());
            $receipt = $this->receipt($occurrence);
            self::assertNotNull($receipt['started_at'], 'Independent connection must observe committed admission before the effect.');
            self::assertNull($receipt['returned_at']);
            self::assertSame($this->authority->namespace, $receipt['deployment_namespace']);
            self::assertSame($this->authority->bootId, $receipt['deployment_boot_id']);
            self::assertSame($this->authority->epoch, (int) $receipt['deployment_epoch']);
            ++$effects;
            return new Envelope($message, $stamps);
        });
        $redis = $this->createMock(RedisClientFactory::class);
        $redis->expects(self::never())->method('borrow');
        $pool = $this->createMock(CpuProcessPoolInterface::class);
        $pool->expects(self::never())->method('dispatch');
        $executor = new ExecuteScheduledJobHandler($jobs, new SchedulerRegistry([new GuardedSchedulerMessage('worker@baander.app', 1.0)], []),
            $bus, $pool, $redis, new NullLogger(), $this->createStub(ScheduledConsoleExecutorInterface::class));
        return new ExecuteScheduledOccurrenceHandler(new DoctrineSchedulerOccurrenceExecutionStore($this->writer, $this->authority), $executor);
    }

    /** @return array<string, mixed> */
    private function receipt(SchedulerOccurrence $occurrence): array
    {
        $row = $this->observer->fetchAssociative('SELECT attempt_id, started_at, returned_at, deployment_namespace, deployment_boot_id, deployment_epoch FROM scheduler_occurrence_executions WHERE occurrence_id = :id', ['id' => $occurrence->id->toString()]);
        self::assertIsArray($row);
        self::assertNotSame('', $row['attempt_id']);
        return $row;
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

final readonly class GuardedSchedulerMessage implements SchedulableCommandInterface
{
    public function __construct(public string $address, public float $fraction) {}

    public static function schedulerDescription(): string
    {
        return 'Disposable occurrence guard fixture.';
    }

    public static function schedulerParameters(): array
    {
        return ['address' => ['type' => 'string', 'required' => true], 'fraction' => ['type' => 'float', 'required' => true]];
    }
}
