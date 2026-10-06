<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use App\Scheduler\Application\Port\SchedulerOccurrenceMaterializerInterface;
use App\Scheduler\Application\Service\SchedulerRecoveryPoller;
use App\Scheduler\Domain\Model\ScheduledJob;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerOccurrenceMaterializer;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerRecoveryScheduleStore;
use App\Scheduler\Infrastructure\Doctrine\Entity\ScheduledJobEntity;
use App\Scheduler\Infrastructure\Doctrine\Repository\ScheduledJobRepository;
use App\Shared\Domain\Model\Uuid;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Real durable fairness and PostgreSQL commit outcomes, in a schema containing only owned schedules. */
#[SkipDatabaseRollback]
final class SchedulerRecoveryPollerTest extends TestCase
{
    private Kernel $kernel;
    private Connection $writer;
    private Connection $observer;
    private EntityManager $manager;
    private EntityManagerInterface $configuredManager;
    private ScheduledJobRepository $jobs;
    private string $schema;
    /** @var array<string, mixed> */
    private array $parameters;
    /** @var list<Connection> */
    private array $extraConnections = [];

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to disposable PostgreSQL.');
        }
        $this->parameters = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $this->parameters['serverVersion'] = '18';
        $this->writer = DriverManager::getConnection($this->parameters);
        $this->observer = DriverManager::getConnection($this->parameters);
        $this->schema = 'scheduler_recovery_test_' . bin2hex(random_bytes(8));
        $this->writer->executeStatement('CREATE SCHEMA ' . $this->schema);
        foreach ([$this->writer, $this->observer] as $connection) {
            $connection->executeStatement('SET search_path TO ' . $this->schema);
        }
        require_once dirname(__DIR__, 2) . '/migrations/Version_60000000_20260619CreateMissingEntityTables.php';
        $migration = new \DoctrineMigrations\Version620260619CreateMissingEntityTables($this->writer, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            // Use the actual standalone schedule table/index DDL, without fabricating a fixture schema or unrelated tables.
            if (str_contains($query->getStatement(), 'scheduled_jobs')) {
                $this->writer->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        }
        foreach (['Version20261002230000', 'Version20261003010000', 'Version20261006230000'] as $version) {
            require_once dirname(__DIR__, 2) . '/migrations/' . $version . '.php';
            $class = 'DoctrineMigrations\\' . $version;
            $migration = new $class($this->writer, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                // The naming migration renames objects across the schema; this fixture has only scheduled_jobs.
                if ($version === 'Version20261006230000' && !str_contains($query->getStatement(), 'scheduled_jobs')) {
                    continue;
                }
                $this->writer->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        }
        $this->kernel = new Kernel('test', false);
        $this->kernel->boot();
        $configured = $this->kernel->getContainer()->get('test.service_container')->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $configured);
        self::assertSame(0, $configured->getConnection()->getTransactionNestingLevel());
        $this->configuredManager = $configured;
        $this->manager = new EntityManager($this->writer, $configured->getConfiguration(), $configured->getEventManager());
        $this->jobs = new ScheduledJobRepository($this->manager);
    }

    public function testPoisonScheduleIsDeferredAndHealthyScheduleProgressesAfterPollerRestart(): void
    {
        $poison = $this->job(1, true);
        $healthy = $this->job(2);
        $this->assertRecoveryFailure($this->poller(), 1);
        self::assertSame(0, $this->intentCount($poison));
        self::assertTrue($this->isDeferred($poison));
        self::assertSame(1, $this->poller()->recoverPending(1, 1, 3600));
        self::assertSame(1, $this->intentCount($healthy));
        self::assertSame(0, $this->poller()->recoverPending(1, 1, 3600));
        $this->expire($poison, 1);
        $this->assertRecoveryFailure($this->poller(), 1);
        self::assertSame(1, $this->intentCount($healthy));
        $this->assertNoDispatchOrExecution();
    }

    public function testPoisonFirstClaimDoesNotPreventLaterClaimInSamePass(): void
    {
        $poison = $this->job(1, true);
        $healthy = $this->job(2);
        $this->assertRecoveryFailure($this->poller(), 2);
        self::assertSame(0, $this->intentCount($poison));
        self::assertSame(1, $this->intentCount($healthy));
        self::assertTrue($this->isDeferred($poison));
        self::assertTrue($this->isDeferred($healthy));
        $this->assertNoDispatchOrExecution();
    }

    public function testHugeBacklogCannotStarveOtherSchedulesAcrossPassesRestartsAndExpiry(): void
    {
        $jobs = [$this->job(1), $this->job(2), $this->job(3)];
        foreach ($jobs as $job) {
            self::assertSame(1, $this->poller()->recoverPending(1, 1, 3600));
            self::assertSame(1, $this->intentCount($job));
        }
        self::assertSame(0, $this->poller()->recoverPending(1, 1, 3600));
        foreach ($jobs as $order => $job) {
            $this->expire($job, $order + 1);
        }
        foreach ($jobs as $job) {
            self::assertSame(1, $this->poller()->recoverPending(1, 1, 3600));
            self::assertSame(2, $this->intentCount($job));
        }
        self::assertSame(6, (int) $this->observer->fetchOne('SELECT count(*) FROM scheduler_occurrences'));
        self::assertSame(6, (int) $this->observer->fetchOne('SELECT count(DISTINCT (job_id, scheduled_for)) FROM scheduler_occurrences'));
        $this->assertNoDispatchOrExecution();
    }

    public function testIndependentWorkersHaveDistinctCommittedReservationsBeforeMaterialization(): void
    {
        $first = $this->job(1);
        $second = $this->job(2);
        $firstClaims = (new DoctrineSchedulerRecoveryScheduleStore($this->connection()))->claimPendingJobs(1, 3600);
        $secondClaims = (new DoctrineSchedulerRecoveryScheduleStore($this->connection()))->claimPendingJobs(1, 3600);
        self::assertSame([$first->getId()->toString()], array_map(static fn (Uuid $id): string => $id->toString(), $firstClaims));
        self::assertSame([$second->getId()->toString()], array_map(static fn (Uuid $id): string => $id->toString(), $secondClaims));
        self::assertTrue($this->isDeferred($first));
        self::assertTrue($this->isDeferred($second));
        self::assertSame([], (new DoctrineSchedulerRecoveryScheduleStore($this->connection()))->claimPendingJobs());
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM scheduler_occurrences'));
        $this->assertNoDispatchOrExecution();
    }

    public function testSelectionPreservesLoadedCasSnapshotAndSavesCannotJumpClaimedQueueDeadline(): void
    {
        $job = $this->job(1);
        $before = $this->observer->fetchAssociative('SELECT revision::text, evaluated_through::text FROM scheduled_jobs WHERE id = :id', ['id' => $job->getId()->toString()]);
        $claims = (new DoctrineSchedulerRecoveryScheduleStore($this->connection()))->claimPendingJobs(1, 3600);
        self::assertCount(1, $claims);
        self::assertTrue($claims[0]->equals($job->getId()));
        self::assertSame($before, $this->observer->fetchAssociative('SELECT revision::text, evaluated_through::text FROM scheduled_jobs WHERE id = :id', ['id' => $job->getId()->toString()]));
        $deadline = $this->observer->fetchOne('SELECT recovery_after::text FROM scheduled_jobs WHERE id = :id', ['id' => $job->getId()->toString()]);
        $job->markSuccess('Known return');
        $this->jobs->save($job);
        self::assertSame($deadline, $this->observer->fetchOne('SELECT recovery_after::text FROM scheduled_jobs WHERE id = :id', ['id' => $job->getId()->toString()]));
        $job->update('Edited after selection', $job->getExpression(), $job->getJobType(), 'app:edited-fixture', null, $job->getParameters());
        $this->jobs->save($job);
        self::assertSame($deadline, $this->observer->fetchOne('SELECT recovery_after::text FROM scheduled_jobs WHERE id = :id', ['id' => $job->getId()->toString()]));
        self::assertNull($this->observer->fetchOne('SELECT evaluated_through FROM scheduled_jobs WHERE id = :id', ['id' => $job->getId()->toString()]));
        self::assertSame([], (new DoctrineSchedulerRecoveryScheduleStore($this->connection()))->claimPendingJobs());
    }

    public function testLockedScheduleIsSkippedAndOtherScheduleCanProgress(): void
    {
        $locked = $this->job(1);
        $other = $this->job(2);
        $this->observer->beginTransaction();
        try {
            $this->observer->fetchOne('SELECT id FROM scheduled_jobs WHERE id = :id FOR UPDATE', ['id' => $locked->getId()->toString()]);
            self::assertSame(1, $this->poller()->recoverPending(1, 1, 3600));
            self::assertSame(0, $this->intentCount($locked));
            self::assertSame(1, $this->intentCount($other));
            self::assertFalse($this->isDeferred($locked));
        } finally {
            $this->observer->rollBack();
        }
        self::assertSame(1, $this->poller()->recoverPending(1, 1, 3600));
        self::assertSame(1, $this->intentCount($locked));
    }

    /** @return iterable<string, array{bool}> */
    public static function commitOutcomes(): iterable
    {
        yield 'failure before commit' => [false];
        yield 'lost reply after commit' => [true];
    }

    #[DataProvider('commitOutcomes')]
    public function testUnacknowledgedClaimCannotInvokeMaterializerAndRecoveryFollowsActualCommit(bool $committed): void
    {
        $job = $this->job(1);
        $connection = new class($this->parameters, $this->writer->getDriver()) extends Connection {
            public bool $committed = false;
            public function commit(): void
            {
                if ($this->committed) {
                    parent::commit();
                }
                throw new \RuntimeException('Injected recovery claim commit failure.');
            }
        };
        $connection->committed = $committed;
        $connection->executeStatement('SET search_path TO ' . $this->schema);
        $this->extraConnections[] = $connection;
        $materializer = $this->createMock(SchedulerOccurrenceMaterializerInterface::class);
        $materializer->expects(self::never())->method('materialize');
        try {
            (new SchedulerRecoveryPoller(new DoctrineSchedulerRecoveryScheduleStore($connection), $materializer))->recoverPending(1, 1, 3600);
            self::fail('Expected original unacknowledged claim failure.');
        } catch (\RuntimeException $error) {
            self::assertSame('Injected recovery claim commit failure.', $error->getMessage());
        }
        self::assertFalse($connection->isConnected());
        self::assertSame(0, $this->intentCount($job));
        self::assertSame($committed, $this->isDeferred($job));
        if ($committed) {
            self::assertSame(0, $this->poller()->recoverPending(1, 1, 3600));
            $this->expire($job, 1);
        }
        self::assertSame(1, $this->poller()->recoverPending(1, 1, 3600));
        self::assertSame(1, $this->intentCount($job));
        $this->assertNoDispatchOrExecution();
    }

    public function testInactiveAndAlreadyEvaluatedSchedulesAreNotClaimed(): void
    {
        $paused = $this->job(1);
        $paused->pause();
        $this->jobs->save($paused);
        $disabled = $this->job(2);
        $disabled->disable();
        $this->jobs->save($disabled);
        $evaluated = $this->job(3);
        $this->observer->executeStatement("UPDATE scheduled_jobs SET evaluated_through = date_trunc('minute', clock_timestamp(), 'UTC') + INTERVAL '20 minutes' WHERE id = :id", ['id' => $evaluated->getId()->toString()]);
        self::assertSame(0, $this->poller()->recoverPending(100, 1));
        foreach ([$paused, $disabled, $evaluated] as $job) {
            self::assertFalse($this->isDeferred($job));
            self::assertSame(0, $this->intentCount($job));
        }
    }

    public function testUninitializedActiveScheduleCanBeClaimedButFirstObservationHasNoHistory(): void
    {
        $job = $this->job(1);
        $this->observer->executeStatement('UPDATE scheduled_jobs SET evaluated_through = NULL WHERE id = :id', ['id' => $job->getId()->toString()]);
        self::assertSame(0, $this->poller()->recoverPending(1, 1, 3600));
        self::assertTrue($this->isDeferred($job));
        self::assertNotNull($this->observer->fetchOne('SELECT evaluated_through FROM scheduled_jobs WHERE id = :id', ['id' => $job->getId()->toString()]));
        self::assertSame(0, $this->intentCount($job));
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function invalidBudgets(): iterable
    {
        yield 'no jobs' => [0, 1, 60];
        yield 'too many jobs' => [101, 1, 60];
        yield 'no minutes' => [1, 0, 60];
        yield 'too many minutes' => [1, 1001, 60];
        yield 'no retry' => [1, 1, 0];
        yield 'retry too long' => [1, 1, 3601];
        yield 'combined work exceeds cap' => [2, 501, 60];
    }

    #[DataProvider('invalidBudgets')]
    public function testInvalidBudgetFailsBeforeAnyCommittedClaim(int $jobs, int $minutes, int $retry): void
    {
        $job = $this->job(1);
        $before = $this->observer->fetchAssociative('SELECT recovery_after::text, evaluated_through::text, revision::text FROM scheduled_jobs WHERE id = :id', ['id' => $job->getId()->toString()]);
        try {
            $this->poller()->recoverPending($jobs, $minutes, $retry);
            self::fail('Expected work budget rejection before selection.');
        } catch (\InvalidArgumentException $error) {
            self::assertNotSame('', $error->getMessage());
        }
        self::assertSame($before, $this->observer->fetchAssociative('SELECT recovery_after::text, evaluated_through::text, revision::text FROM scheduled_jobs WHERE id = :id', ['id' => $job->getId()->toString()]));
        self::assertSame(0, $this->intentCount($job));
    }

    public function testConfiguredContainerResolvesRecoveryPollerWithoutClaimingGlobalSchedules(): void
    {
        self::assertInstanceOf(SchedulerRecoveryPoller::class, $this->kernel->getContainer()->get('test.service_container')->get('test.scheduler_recovery_poller'));
    }

    public function testActualRecoveryIndexPredicateMatchesConfiguredMetadataWithoutSchemaChurn(): void
    {
        $schemaManager = $this->configuredManager->getConnection()->createSchemaManager();
        $actual = $schemaManager->introspectTable($this->schema . '.scheduled_jobs');
        $expected = (new SchemaTool($this->configuredManager))->getSchemaFromMetadata([$this->configuredManager->getClassMetadata(ScheduledJobEntity::class)])->getTable('scheduled_jobs');
        $name = 'idx_scheduled_jobs_recovery_after_id';
        self::assertSame(['recovery_after', 'id'], $actual->getIndex($name)->getColumns());
        self::assertSame("(status = 'active'::text)", $this->observer->fetchOne('SELECT pg_get_expr(i.indpred, i.indrelid) FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = :schema AND c.relname = :name', ['schema' => $this->schema, 'name' => $name]));
        $difference = $schemaManager->createComparator()->compareTables($actual, $expected);
        foreach ([...$difference->getAddedIndexes(), ...$difference->getModifiedIndexes(), ...$difference->getDroppedIndexes()] as $index) {
            self::assertNotSame($name, $index->getName(), 'The configured partial recovery index must not be recreated by schema updates.');
        }
        self::assertArrayNotHasKey('recovery_after', $difference->getChangedColumns());
        self::assertTrue($actual->getColumn('recovery_after')->getNotnull());
        $column = $this->observer->fetchAssociative("SELECT format_type(a.atttypid, a.atttypmod) AS physical_type, pg_get_expr(d.adbin, d.adrelid) AS database_default FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid JOIN pg_namespace n ON n.oid = c.relnamespace LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum WHERE n.nspname = :schema AND c.relname = 'scheduled_jobs' AND a.attname = 'recovery_after' AND NOT a.attisdropped", ['schema' => $this->schema]);
        self::assertIsArray($column);
        self::assertSame('timestamp with time zone', $column['physical_type']);
        self::assertSame('clock_timestamp()', $column['database_default']);
        $job = $this->job(1);
        $before = $this->observer->fetchOne('SELECT recovery_after::text FROM scheduled_jobs WHERE id = :id', ['id' => $job->getId()->toString()]);
        foreach (['infinity', '-infinity'] as $value) {
            try {
                $this->observer->executeStatement('UPDATE scheduled_jobs SET recovery_after = CAST(:value AS TIMESTAMPTZ) WHERE id = :id', ['value' => $value, 'id' => $job->getId()->toString()]);
                self::fail('Expected physical finite-deadline constraint rejection.');
            } catch (\Doctrine\DBAL\Exception\DriverException $error) {
                self::assertSame('23514', $error->getSQLState());
            }
            self::assertSame($before, $this->observer->fetchOne('SELECT recovery_after::text FROM scheduled_jobs WHERE id = :id', ['id' => $job->getId()->toString()]));
        }
    }

    private function poller(): SchedulerRecoveryPoller
    {
        $connection = $this->connection();
        $materializer = new DoctrineSchedulerOccurrenceMaterializer($connection);
        $scoped = new class($materializer, $connection, $this->schema) implements SchedulerOccurrenceMaterializerInterface {
            public function __construct(private DoctrineSchedulerOccurrenceMaterializer $materializer, private Connection $connection, private string $schema) {}
            public function materialize(Uuid $jobId, int $minuteLimit = 60): int
            {
                // A failed real adapter discards its connection. Restore only this fixture's session isolation on reconnect.
                $this->connection->executeStatement('SET search_path TO ' . $this->schema);
                return $this->materializer->materialize($jobId, $minuteLimit);
            }
        };
        return new SchedulerRecoveryPoller(new DoctrineSchedulerRecoveryScheduleStore($this->connection()), $scoped);
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection($this->parameters);
        $connection->executeStatement('SET search_path TO ' . $this->schema);
        $this->extraConnections[] = $connection;
        return $connection;
    }

    private function job(int $order, bool $poison = false): ScheduledJob
    {
        $job = ScheduledJob::create('Recovery ' . $order, '* * * * *', JobType::Console, 'app:recovery-fixture', parameters: ['address' => 'scheduler@baander.app']);
        if ($poison) {
            $job->getState()->expression = 'invalid cron';
        }
        $this->jobs->save($job);
        $this->observer->executeStatement("UPDATE scheduled_jobs SET evaluated_through = date_trunc('minute', clock_timestamp(), 'UTC') - INTERVAL '1000 minutes' WHERE id = :id", ['id' => $job->getId()->toString()]);
        $this->expire($job, $order);
        return $job;
    }

    private function expire(ScheduledJob $job, int $order): void
    {
        $this->observer->executeStatement("UPDATE scheduled_jobs SET recovery_after = TIMESTAMPTZ '2000-01-01 00:00:00+00' + (:ordering * INTERVAL '1 second') WHERE id = :id", ['ordering' => $order, 'id' => $job->getId()->toString()]);
    }

    private function intentCount(ScheduledJob $job): int
    {
        return (int) $this->observer->fetchOne('SELECT count(*) FROM scheduler_occurrences WHERE job_id = :id', ['id' => $job->getId()->toString()]);
    }

    private function isDeferred(ScheduledJob $job): bool
    {
        return (int) $this->observer->fetchOne('SELECT CASE WHEN recovery_after > clock_timestamp() THEN 1 ELSE 0 END FROM scheduled_jobs WHERE id = :id', ['id' => $job->getId()->toString()]) === 1;
    }

    private function assertRecoveryFailure(SchedulerRecoveryPoller $poller, int $limit): void
    {
        try {
            $poller->recoverPending($limit, 1, 3600);
            self::fail('Expected aggregated materialization failure.');
        } catch (\RuntimeException $error) {
            self::assertInstanceOf(\InvalidArgumentException::class, $error->getPrevious());
            self::assertStringContainsString('Scheduler recovery failed', $error->getMessage());
        }
    }

    private function assertNoDispatchOrExecution(): void
    {
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM scheduler_occurrences WHERE dispatch_token IS NOT NULL OR dispatched_at IS NOT NULL'));
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM scheduler_occurrence_executions'));
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM scheduled_jobs WHERE run_count <> 0 OR last_run_at IS NOT NULL'));
    }

    protected function tearDown(): void
    {
        $kernelStopped = false;
        try {
            foreach ([$this->writer ?? null, $this->observer ?? null, ...$this->extraConnections] as $connection) {
                if ($connection !== null) {
                    while ($connection->isTransactionActive()) {
                        $connection->rollBack();
                    }
                    $connection->close();
                }
            }
            if (isset($this->manager)) {
                $this->manager->close();
            }
            if (isset($this->kernel)) {
                $this->kernel->shutdown();
                $kernelStopped = true;
            }
            if (isset($this->schema)) {
                $cleanup = DriverManager::getConnection($this->parameters);
                try {
                    $cleanup->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
                } finally {
                    $cleanup->close();
                }
            }
        } finally {
            if (!$kernelStopped && isset($this->kernel)) {
                $this->kernel->shutdown();
            }
        }
    }
}
