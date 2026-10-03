<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use App\Scheduler\Application\CommandHandler\ExecuteScheduledJobHandler;
use App\Scheduler\Application\DTO\SchedulerOccurrence;
use App\Scheduler\Application\Port\ScheduledConsoleExecutorInterface;
use App\Scheduler\Application\Port\ScheduledJobPortInterface;
use App\Scheduler\Domain\Model\SchedulableCommandInterface;
use App\Scheduler\Domain\Model\ScheduledJob;
use App\Scheduler\Domain\Repository\ScheduledJobRepositoryInterface;
use App\Scheduler\Domain\Service\SchedulerRegistry;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerOccurrenceStore;
use App\Scheduler\Infrastructure\Doctrine\Entity\ScheduledJobEntity;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPoolInterface;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/** Configured revision-checking DBAL repository, ORM metadata and committed JSON observation; only owned rows are cleaned up. */
#[SkipDatabaseRollback]
final class ScheduledJobParameterPersistenceTest extends TestCase
{
    private Kernel $kernel;
    private Connection $observer;
    private EntityManagerInterface $manager;
    private ScheduledJobRepositoryInterface $jobs;
    private ?Uuid $jobId = null;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL and DATABASE_URL to the same fully migrated disposable PostgreSQL database.');
        }
        $parameters = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $parameters['serverVersion'] = '18';
        $this->observer = DriverManager::getConnection($parameters);
        $this->kernel = new Kernel('test', false);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        $manager = $container->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
        self::assertSame(0, $manager->getConnection()->getTransactionNestingLevel(), 'DAMA must not hide the repository commit from the independent observer.');
        self::assertSame($this->observer->fetchOne('SELECT current_database()'), $manager->getConnection()->fetchOne('SELECT current_database()'));
        $this->jobs = $container->get(ScheduledJobRepositoryInterface::class);
    }

    public function testOrmParametersBecomeAnIdenticalDurableOccurrenceAndPassCurrentSnapshotGate(): void
    {
        $parameters = [
            'zeta' => 1,
            'alpha' => 1.0,
            'zero' => -0.0,
            'exponent' => 1.0e18,
            'nested' => ['z' => [1, 1.0, -0.0], 'a' => [2 => 'first', 0 => 'second']],
            'address' => 'scheduler@baander.app',
        ];
        $encoded = json_encode($parameters, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $job = ScheduledJob::create('JSON preservation ' . bin2hex(random_bytes(6)), '* * * * *', JobType::Messenger,
            PersistedSchedulerParametersMessage::class, parameters: $parameters);
        $this->jobId = $job->getId();
        $this->jobs->save($job);
        $row = $this->observer->fetchAssociative('SELECT pg_typeof(parameters)::text AS physical_type, parameters::text AS raw_parameters FROM scheduled_jobs WHERE id = :id', ['id' => $job->getId()->toString()]);
        self::assertIsArray($row);
        self::assertSame('json', $row['physical_type']);
        self::assertSame($encoded, $row['raw_parameters'], 'Native JSON must retain numeric lexemes and invocation order.');

        $this->manager->clear();
        $rehydrated = $this->jobs->findByUuid($job->getId());
        self::assertInstanceOf(ScheduledJob::class, $rehydrated);
        self::assertSame($parameters, $rehydrated->getParameters());
        self::assertSame($encoded, json_encode($rehydrated->getParameters(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $minute = new \DateTimeImmutable('2026-10-02T10:00:00Z');
        $occurrence = new SchedulerOccurrence(Uuid::generate(), $rehydrated->getId(), $minute, $rehydrated->getJobType(), $rehydrated->getCommand(), $rehydrated->getParameters());
        $store = new DoctrineSchedulerOccurrenceStore($this->observer);
        self::assertTrue($store->record($occurrence));
        $snapshot = $store->find($rehydrated->getId(), $minute);
        self::assertInstanceOf(SchedulerOccurrence::class, $snapshot);
        self::assertSame($parameters, $snapshot->parameters);
        self::assertSame($encoded, $snapshot->parametersJson());
        self::assertFalse($store->record(new SchedulerOccurrence(Uuid::generate(), $rehydrated->getId(), $minute, $rehydrated->getJobType(), $rehydrated->getCommand(), $rehydrated->getParameters())));
        $retained = $store->find($rehydrated->getId(), $minute);
        self::assertInstanceOf(SchedulerOccurrence::class, $retained);
        self::assertTrue($occurrence->id->equals($retained->id));

        $jobPort = $this->createMock(ScheduledJobPortInterface::class);
        $jobPort->expects(self::once())->method('getById')->willReturn($rehydrated);
        $jobPort->expects(self::exactly(2))->method('save')->willReturnCallback(function (ScheduledJob $saved): void {
            $this->jobs->save($saved);
        });
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(function (PersistedSchedulerParametersMessage $message) use ($encoded): Envelope {
            self::assertSame($encoded, json_encode(get_object_vars($message), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
            return new Envelope($message);
        });
        $pool = $this->createMock(CpuProcessPoolInterface::class);
        $pool->expects(self::never())->method('dispatch');
        $redis = $this->createMock(RedisClientFactory::class);
        $redis->expects(self::never())->method('borrow');
        $console = $this->createMock(ScheduledConsoleExecutorInterface::class);
        $console->expects(self::never())->method('execute');
        $executor = new ExecuteScheduledJobHandler($jobPort, new SchedulerRegistry([new PersistedSchedulerParametersMessage(1, 1.0, -0.0, 1.0e18, [], 'scheduler@baander.app')], []),
            $bus, $pool, $redis, new NullLogger(), $console);
        $executor->executeOccurrence($snapshot);
        self::assertSame('dispatched', $rehydrated->getLastResult(), 'The unchanged persisted configuration must not falsely cancel its exact snapshot.');

        // Exercise type/order updates separately from the signed-zero-only regression below.
        $updated = ['address' => $parameters['address'], 'nested' => $parameters['nested'], 'exponent' => 1.0e18, 'zero' => -0.0, 'alpha' => 1, 'zeta' => 1.0];
        $rehydrated->getState()->parameters = $updated;
        $this->jobs->save($rehydrated);
        $updatedJson = json_encode($updated, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        self::assertSame($updatedJson, $this->observer->fetchOne('SELECT parameters::text FROM scheduled_jobs WHERE id = :id', ['id' => $job->getId()->toString()]));
        $this->manager->clear();
        $updatedJob = $this->jobs->findByUuid($job->getId());
        self::assertInstanceOf(ScheduledJob::class, $updatedJob);
        self::assertSame($updated, $updatedJob->getParameters());
        $retained = $store->find($job->getId(), $minute);
        self::assertInstanceOf(SchedulerOccurrence::class, $retained);
        self::assertSame($encoded, $retained->parametersJson(), 'Updating a schedule cannot mutate its already recorded immutable occurrence.');
    }

    public function testParameterColumnHasNoSpuriousDoctrineSchemaChange(): void
    {
        $metadata = $this->manager->getClassMetadata(ScheduledJobEntity::class);
        $expected = (new SchemaTool($this->manager))->getSchemaFromMetadata([$metadata])->getTable('scheduled_jobs');
        $schemaManager = $this->manager->getConnection()->createSchemaManager();
        $actual = $schemaManager->introspectTable('scheduled_jobs');
        $difference = $schemaManager->createComparator()->compareTables($actual, $expected);
        self::assertArrayNotHasKey('parameters', $difference->getChangedColumns(), 'Only parameter mapping drift is in scope; unrelated existing column differences are not suppressed globally.');
        self::assertArrayNotHasKey('revision', $difference->getChangedColumns(), 'The revision mapping must match its physical UUID column.');
        self::assertTrue($actual->getColumn('revision')->getNotnull());
        foreach ([...$difference->getAddedColumns(), ...$difference->getDroppedColumns()] as $column) {
            self::assertNotSame('parameters', $column->getName());
            self::assertNotSame('revision', $column->getName());
        }
        self::assertSame('json', $this->observer->fetchOne("SELECT format_type(a.atttypid, a.atttypmod) FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND c.relname = 'scheduled_jobs' AND a.attname = 'parameters' AND NOT a.attisdropped"));
        self::assertSame('uuid', $this->observer->fetchOne("SELECT format_type(a.atttypid, a.atttypmod) FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND c.relname = 'scheduled_jobs' AND a.attname = 'revision' AND NOT a.attisdropped"));
    }

    /** @return iterable<string, array{float, float}> */
    public static function signedZeroChanges(): iterable
    {
        yield 'positive to negative' => [0.0, -0.0];
        yield 'negative to positive' => [-0.0, 0.0];
    }

    #[DataProvider('signedZeroChanges')]
    public function testSignedZeroOnlyParameterSaveIsCommittedAndReloaded(float $before, float $after): void
    {
        $job = ScheduledJob::create('Signed zero ' . bin2hex(random_bytes(6)), '* * * * *', JobType::Console, 'app:zero-fixture', parameters: ['zero' => $before]);
        $this->jobId = $job->getId();
        $this->jobs->save($job);
        $this->manager->clear();
        $loaded = $this->jobs->findByUuid($job->getId());
        self::assertInstanceOf(ScheduledJob::class, $loaded);
        // Deliberately change no other field: PHP/Doctrine strict array equality treats both signed zeros as equal.
        $loaded->getState()->parameters = ['zero' => $after];
        $this->jobs->save($loaded);
        $expected = json_encode(['zero' => $after], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        self::assertSame($expected, $this->observer->fetchOne('SELECT parameters::text FROM scheduled_jobs WHERE id = :id', ['id' => $job->getId()->toString()]));
        $this->manager->clear();
        $fresh = $this->jobs->findByUuid($job->getId());
        self::assertInstanceOf(ScheduledJob::class, $fresh);
        self::assertSame($expected, json_encode($fresh->getParameters(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->manager)) {
                $this->manager->clear();
            }
            if (isset($this->observer) && $this->jobId !== null) {
                $this->observer->executeStatement('DELETE FROM scheduler_occurrences WHERE job_id = :id', ['id' => $this->jobId->toString()]);
                $this->observer->executeStatement('DELETE FROM scheduled_jobs WHERE id = :id', ['id' => $this->jobId->toString()]);
            }
        } finally {
            if (isset($this->kernel)) {
                $this->kernel->shutdown();
            }
            if (isset($this->observer)) {
                $this->observer->close();
            }
        }
    }
}

final readonly class PersistedSchedulerParametersMessage implements SchedulableCommandInterface
{
    /** @param array<array-key, mixed> $nested */
    public function __construct(public int $zeta, public float $alpha, public float $zero, public float $exponent, public array $nested, public string $address) {}
    public static function schedulerDescription(): string { return 'Real parameter persistence fixture.'; }
    /** @return array<string, array<string, mixed>> */
    public static function schedulerParameters(): array { return []; }
}
