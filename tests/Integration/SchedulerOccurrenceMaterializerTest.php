<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use App\Scheduler\Application\DTO\SchedulerOccurrence;
use App\Scheduler\Domain\Model\ScheduledJob;
use App\Scheduler\Domain\Repository\ScheduledJobRepositoryInterface;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerOccurrenceMaterializer;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerOccurrenceStore;
use App\Scheduler\Infrastructure\Doctrine\Entity\ScheduledJobEntity;
use App\Shared\Domain\Model\Uuid;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Database-clock, per-job materialization against real migrations; only uniquely owned rows are removed. */
#[SkipDatabaseRollback]
final class SchedulerOccurrenceMaterializerTest extends TestCase
{
    private Kernel $kernel;
    private Connection $writer;
    private Connection $observer;
    private ScheduledJobRepositoryInterface $jobs;
    private EntityManagerInterface $manager;
    /** @var list<Uuid> */
    private array $ownedIds = [];

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL and DATABASE_URL to the same migrated disposable PostgreSQL database.');
        }
        $parameters = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $parameters['serverVersion'] = '18';
        $this->writer = DriverManager::getConnection($parameters);
        $this->observer = DriverManager::getConnection($parameters);
        $this->kernel = new Kernel('test', false);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        $manager = $container->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
        self::assertSame(0, $manager->getConnection()->getTransactionNestingLevel());
        self::assertSame($this->observer->fetchOne('SELECT current_database()'), $manager->getConnection()->fetchOne('SELECT current_database()'));
        $this->jobs = $container->get(ScheduledJobRepositoryInterface::class);
    }

    public function testFirstObservationInitializesCurrentDatabaseMinuteWithoutHistoricalIntents(): void
    {
        $job = $this->job();
        $this->observer->executeStatement("UPDATE scheduled_jobs SET created_at = clock_timestamp() - INTERVAL '10 days' WHERE id = :id", ['id' => $job->getId()->toString()]);
        $before = $this->databaseMinute();
        self::assertSame(0, $this->materializer()->materialize($job->getId()));
        $cursor = $this->cursor($job);
        self::assertInstanceOf(DateTimeImmutable::class, $cursor);
        self::assertGreaterThanOrEqual($before->getTimestamp(), $cursor->getTimestamp());
        self::assertLessThanOrEqual($this->databaseMinute()->getTimestamp(), $cursor->getTimestamp());
        self::assertSame('00.000000', $cursor->format('s.u'));
        self::assertSame([], $this->occurrences($job));
        self::assertSame($this->domainRevision($job), $this->revision($job));
    }

    public function testBoundedCatchUpAndFreshAdapterResumeKeepEveryEvaluatedMinuteAndSnapshot(): void
    {
        $job = $this->job();
        $base = $this->databaseMinute()->modify('-20 minutes');
        $this->seedCursor($job, $base);
        self::assertSame(2, $this->materializer()->materialize($job->getId(), 2));
        self::assertEquals($base->modify('+2 minutes'), $this->cursor($job));
        $firstIds = array_column($this->occurrences($job), 'id');
        self::assertSame(2, $this->materializer()->materialize($job->getId(), 2));
        self::assertSame(1, $this->materializer()->materialize($job->getId(), 1));
        $rows = $this->occurrences($job);
        self::assertCount(5, $rows);
        self::assertSame($firstIds, array_slice(array_column($rows, 'id'), 0, 2));
        foreach ($rows as $offset => $row) {
            self::assertSame($base->modify('+' . ($offset + 1) . ' minutes')->format(DATE_ATOM), $row['minute']);
            self::assertSame('app:materializer-fixture', $row['command']);
            self::assertSame(json_encode($job->getParameters(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), $row['parameters']);
        }
        self::assertEquals($base->modify('+5 minutes'), $this->cursor($job));
        self::assertSame($this->domainRevision($job), $this->revision($job), 'Materialization cannot invalidate an unrelated loaded execution snapshot.');
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM scheduler_occurrence_executions WHERE occurrence_id IN (SELECT id FROM scheduler_occurrences WHERE job_id = :id)', ['id' => $job->getId()->toString()]));
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM scheduler_occurrences WHERE job_id = :id AND (dispatch_token IS NOT NULL OR dispatched_at IS NOT NULL)', ['id' => $job->getId()->toString()]));
    }

    public function testSparseCronAdvancesAcrossNonDueMinutes(): void
    {
        $job = $this->job('*/5 * * * *');
        $pastHour = $this->databaseMinute()->modify('-2 hours');
        $base = $pastHour->setTime((int) $pastHour->format('H'), 2);
        $this->seedCursor($job, $base);
        self::assertSame(1, $this->materializer()->materialize($job->getId(), 4));
        self::assertEquals($base->modify('+4 minutes'), $this->cursor($job));
        self::assertSame([$base->modify('+3 minutes')->format(DATE_ATOM)], array_column($this->occurrences($job), 'minute'));
    }

    public function testAnotherConnectionHoldingJobLockIsSkippedWhileDifferentJobCanProgress(): void
    {
        $locked = $this->job();
        $unlocked = $this->job();
        $base = $this->databaseMinute()->modify('-20 minutes');
        $this->seedCursor($locked, $base);
        $this->seedCursor($unlocked, $base);
        $this->observer->beginTransaction();
        try {
            $this->observer->fetchOne('SELECT id FROM scheduled_jobs WHERE id = :id FOR UPDATE', ['id' => $locked->getId()->toString()]);
            self::assertSame(0, $this->materializer()->materialize($locked->getId(), 2));
            self::assertSame([], $this->occurrences($locked));
            self::assertEquals($base, $this->cursor($locked));
            self::assertSame(1, $this->materializer()->materialize($unlocked->getId(), 1));
        } finally {
            $this->observer->rollBack();
        }
        self::assertSame(2, $this->materializer()->materialize($locked->getId(), 2));
        self::assertCount(2, $this->occurrences($locked));
        self::assertCount(1, $this->occurrences($unlocked));
    }

    public function testConflictingSecondSnapshotRollsBackEarlierIntentAndCursorThenRetryRecovers(): void
    {
        $job = $this->job();
        $base = $this->databaseMinute()->modify('-20 minutes');
        $this->seedCursor($job, $base);
        $poison = new SchedulerOccurrence(Uuid::generate(), $job->getId(), $base->modify('+2 minutes'), JobType::Console, 'app:conflicting-snapshot', []);
        self::assertTrue((new DoctrineSchedulerOccurrenceStore($this->observer))->record($poison));
        try {
            $this->materializer()->materialize($job->getId(), 2);
            self::fail('Expected immutable occurrence snapshot conflict.');
        } catch (\LogicException $error) {
            self::assertNotSame('', $error->getMessage());
        }
        self::assertEquals($base, $this->cursor($job));
        self::assertSame([$poison->id->toString()], array_column($this->occurrences($job), 'id'));
        self::assertSame($this->domainRevision($job), $this->revision($job));
        $this->observer->executeStatement('DELETE FROM scheduler_occurrences WHERE id = :id', ['id' => $poison->id->toString()]);
        self::assertSame(2, $this->materializer()->materialize($job->getId(), 2));
        self::assertCount(2, $this->occurrences($job));
        self::assertEquals($base->modify('+2 minutes'), $this->cursor($job));
    }

    public function testIdenticalAlreadyRecordedMinutePreservesOriginalIdAndCountsOnlyNewIntent(): void
    {
        $job = $this->job();
        $base = $this->databaseMinute()->modify('-20 minutes');
        $this->seedCursor($job, $base);
        $existing = new SchedulerOccurrence(Uuid::generate(), $job->getId(), $base->modify('+1 minute'), $job->getJobType(), $job->getCommand(), $job->getParameters());
        self::assertTrue((new DoctrineSchedulerOccurrenceStore($this->observer))->record($existing));
        self::assertSame(1, $this->materializer()->materialize($job->getId(), 2));
        $rows = $this->occurrences($job);
        self::assertCount(2, $rows);
        self::assertSame($existing->id->toString(), $rows[0]['id']);
        self::assertEquals($base->modify('+2 minutes'), $this->cursor($job));
    }

    public function testNoOpMetadataAndExecutionResultSavesPreserveMaterializedCursor(): void
    {
        $job = $this->job();
        $base = $this->databaseMinute()->modify('-20 minutes');
        $this->seedCursor($job, $base);
        self::assertSame(2, $this->materializer()->materialize($job->getId(), 2));
        $cursor = $base->modify('+2 minutes');
        $this->jobs->save($job);
        self::assertEquals($cursor, $this->cursor($job));
        $job->update('Metadata only', $job->getExpression(), $job->getJobType(), $job->getCommand(), 'New description', $job->getParameters());
        $this->jobs->save($job);
        self::assertEquals($cursor, $this->cursor($job));
        $job->markSuccess('Returned');
        $this->jobs->save($job);
        self::assertEquals($cursor, $this->cursor($job));
        $job->markFailed('Known failure');
        $this->jobs->save($job);
        self::assertEquals($cursor, $this->cursor($job));
        self::assertCount(2, $this->occurrences($job));
    }

    /** @return iterable<string, array{string}> */
    public static function resets(): iterable
    {
        foreach (['expression', 'type', 'command', 'parameters', 'parameter_order', 'zero_sign', 'pause', 'disable', 'resume', 'enable'] as $change) {
            yield $change => [$change];
        }
    }

    #[DataProvider('resets')]
    public function testConfigurationAndStatusTransitionsResetObservationWithoutMutatingRetainedIntents(string $change): void
    {
        $job = $this->job();
        $base = $this->databaseMinute()->modify('-20 minutes');
        $retained = new SchedulerOccurrence(Uuid::generate(), $job->getId(), $base, $job->getJobType(), $job->getCommand(), $job->getParameters());
        self::assertTrue((new DoctrineSchedulerOccurrenceStore($this->observer))->record($retained));
        if ($change === 'resume' || $change === 'enable') {
            $change === 'resume' ? $job->pause() : $job->disable();
            $this->jobs->save($job);
        }
        $this->seedCursor($job, $base);
        $parameters = $job->getParameters();
        match ($change) {
            'pause' => $job->pause(),
            'disable' => $job->disable(),
            'resume' => $job->resume(),
            'enable' => $job->enable(),
            default => $job->update($job->getName(), $change === 'expression' ? '*/5 * * * *' : $job->getExpression(),
                $change === 'type' ? JobType::Messenger : $job->getJobType(), $change === 'command' ? 'app:new-fixture' : $job->getCommand(), $job->getDescription(),
                match ($change) {
                    'parameters' => ['z' => 2.0, 'a' => -0.0],
                    'parameter_order' => ['a' => $parameters['a'], 'z' => $parameters['z']],
                    'zero_sign' => ['z' => 1.0, 'a' => 0.0],
                    default => $parameters,
                }),
        };
        $this->jobs->save($job);
        self::assertNull($this->cursor($job));
        self::assertSame(0, $this->materializer()->materialize($job->getId(), 1000));
        self::assertSame([$retained->id->toString()], array_column($this->occurrences($job), 'id'));
        self::assertSame($retained->parametersJson(), $this->occurrences($job)[0]['parameters']);
        if (in_array($change, ['pause', 'disable'], true)) {
            self::assertNull($this->cursor($job));
        } else {
            self::assertInstanceOf(DateTimeImmutable::class, $this->cursor($job));
        }
    }

    /** @return iterable<string, array{int}> */
    public static function invalidLimits(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'above ceiling' => [1001];
    }

    #[DataProvider('invalidLimits')]
    public function testInvalidMinuteLimitHasNoWrites(int $limit): void
    {
        $job = $this->job();
        $base = $this->databaseMinute()->modify('-20 minutes');
        $this->seedCursor($job, $base);
        try {
            $this->materializer()->materialize($job->getId(), $limit);
            self::fail('Expected bounded minute limit rejection.');
        } catch (\InvalidArgumentException $error) {
            self::assertNotSame('', $error->getMessage());
        }
        self::assertEquals($base, $this->cursor($job));
        self::assertSame([], $this->occurrences($job));
    }

    public function testMissingDeletedJobsAndUpperBoundHaveNoMaterialization(): void
    {
        self::assertSame(0, $this->materializer()->materialize(Uuid::generate(), 1000));
        $job = $this->job();
        $this->jobs->delete($job);
        self::assertSame(0, $this->materializer()->materialize($job->getId()));
        self::assertSame([], $this->occurrences($job));
    }

    public function testCallerTransactionIsRejectedWithoutCommittingIt(): void
    {
        $job = $this->job();
        $this->writer->beginTransaction();
        try {
            $this->materializer()->materialize($job->getId());
            self::fail('Expected dedicated idle connection rejection.');
        } catch (\LogicException $error) {
            self::assertNotSame('', $error->getMessage());
            self::assertSame(1, $this->writer->getTransactionNestingLevel());
        } finally {
            $this->writer->rollBack();
        }
        self::assertNull($this->cursor($job));
        self::assertSame([], $this->occurrences($job));
    }

    public function testCronAndCursorUseUtcDespitePhpAndPostgresqlTimezones(): void
    {
        $previousTimezone = date_default_timezone_get();
        $this->writer->executeStatement("SET TIME ZONE 'Pacific/Auckland'");
        $this->observer->executeStatement("SET TIME ZONE 'America/Los_Angeles'");
        date_default_timezone_set('Europe/Copenhagen');
        try {
            $pastHour = $this->databaseMinute()->modify('-3 hours');
            $base = $pastHour->setTime((int) $pastHour->format('H'), 29);
            $job = $this->job('30 ' . $base->format('G') . ' * * *');
            $this->seedCursor($job, $base->setTimezone(new DateTimeZone('Asia/Kathmandu')));
            self::assertSame(1, $this->materializer()->materialize($job->getId(), 2));
            self::assertEquals($base->modify('+2 minutes'), $this->cursor($job));
            self::assertSame([$base->modify('+1 minute')->format(DATE_ATOM)], array_column($this->occurrences($job), 'minute'));
        } finally {
            date_default_timezone_set($previousTimezone);
        }
    }

    public function testEightArrayLevelsRemainValidAndPreserveSnapshot(): void
    {
        $job = $this->job();
        $nested = 'scheduler@baander.app';
        for ($depth = 0; $depth < 7; ++$depth) {
            $nested = ['nested' => $nested];
        }
        $job->getState()->parameters = ['root' => $nested];
        $this->jobs->save($job);
        $this->seedCursor($job, $this->databaseMinute()->modify('-20 minutes'));
        self::assertSame(1, $this->materializer()->materialize($job->getId(), 1));
        self::assertSame(json_encode($job->getParameters(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), $this->occurrences($job)[0]['parameters']);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function invalidConfigurations(): iterable
    {
        foreach (['cron', 'command', 'parameters', 'depth'] as $fault) {
            yield $fault . ' before first observation' => [$fault, false];
            yield $fault . ' with existing cursor' => [$fault, true];
        }
    }

    #[DataProvider('invalidConfigurations')]
    public function testUnsupportedPersistedConfigurationCannotAdvanceObservation(string $fault, bool $initialized): void
    {
        $job = $this->job();
        $state = $job->getState();
        if ($fault === 'cron') {
            $state->expression = 'invalid cron';
        } elseif ($fault === 'command') {
            $state->command = str_repeat('x', 513);
        } elseif ($fault === 'parameters') {
            $state->parameters = ['large' => str_repeat('x', 16385)];
        } else {
            $nested = 'too deep';
            for ($depth = 0; $depth < 8; ++$depth) {
                $nested = ['nested' => $nested];
            }
            $state->parameters = ['root' => $nested];
        }
        // Reproduce unsupported persisted legacy/internal input, without pretending the API permits it.
        $this->jobs->save($job);
        $base = $this->databaseMinute()->modify('-20 minutes');
        if ($initialized) {
            $this->seedCursor($job, $base);
        }
        try {
            $this->materializer()->materialize($job->getId(), 1);
            self::fail('Expected recovery snapshot validation to fail before cursor advancement.');
        } catch (\InvalidArgumentException|\UnexpectedValueException $error) {
            self::assertNotSame('', $error->getMessage());
        }
        self::assertEquals($initialized ? $base : null, $this->cursor($job));
        self::assertSame([], $this->occurrences($job));
    }

    public function testFutureCursorIsNotRewoundByBackwardDatabaseTimeRelativeToCursor(): void
    {
        $job = $this->job();
        $future = $this->databaseMinute()->modify('+20 minutes');
        $this->seedCursor($job, $future);
        self::assertSame(0, $this->materializer()->materialize($job->getId(), 1000));
        self::assertEquals($future, $this->cursor($job));
        self::assertSame([], $this->occurrences($job));
    }

    public function testUncommittedConfigurationIsSkippedAndOnlyCommittedChangeStartsFreshObservation(): void
    {
        $job = $this->job();
        $base = $this->databaseMinute()->modify('-20 minutes');
        $this->seedCursor($job, $base);
        $connection = $this->manager->getConnection();
        $connection->beginTransaction();
        try {
            $job->update($job->getName(), $job->getExpression(), $job->getJobType(), 'app:uncommitted-fixture', null, $job->getParameters());
            $this->jobs->save($job);
            self::assertSame(0, $this->materializer()->materialize($job->getId(), 1));
            self::assertEquals($base, $this->cursor($job));
            self::assertSame([], $this->occurrences($job));
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
        self::assertEquals($base, $this->cursor($job));
        $fresh = $this->jobs->findByUuid($job->getId());
        self::assertInstanceOf(ScheduledJob::class, $fresh);
        self::assertSame('app:materializer-fixture', $fresh->getCommand());
        $connection->beginTransaction();
        try {
            $fresh->update($fresh->getName(), $fresh->getExpression(), $fresh->getJobType(), 'app:committed-fixture', null, $fresh->getParameters());
            $this->jobs->save($fresh);
            self::assertSame(0, $this->materializer()->materialize($fresh->getId(), 1));
            self::assertEquals($base, $this->cursor($fresh));
            $connection->commit();
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
        self::assertNull($this->cursor($fresh));
        self::assertSame(0, $this->materializer()->materialize($fresh->getId(), 1000));
        self::assertInstanceOf(DateTimeImmutable::class, $this->cursor($fresh));
        self::assertSame([], $this->occurrences($fresh));
    }

    public function testCursorHasNativeNullableTimestampWithTimeZoneAndMatchingGeneratedDdl(): void
    {
        $schemaManager = $this->manager->getConnection()->createSchemaManager();
        $actual = $schemaManager->introspectTable('scheduled_jobs');
        $expected = (new SchemaTool($this->manager))->getSchemaFromMetadata([$this->manager->getClassMetadata(ScheduledJobEntity::class)])->getTable('scheduled_jobs');
        self::assertFalse($actual->getColumn('evaluated_through')->getNotnull());
        self::assertSame('timestamp with time zone', $this->observer->fetchOne("SELECT format_type(a.atttypid, a.atttypmod) FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND c.relname = 'scheduled_jobs' AND a.attname = 'evaluated_through' AND NOT a.attisdropped"));
        $columnDdl = $this->manager->getConnection()->getDatabasePlatform()->getColumnDeclarationSQL('evaluated_through', $expected->getColumn('evaluated_through')->toArray());
        self::assertStringContainsString('TIMESTAMPTZ', strtoupper($columnDdl));
        self::assertArrayNotHasKey('evaluated_through', $schemaManager->createComparator()->compareTables($actual, $expected)->getChangedColumns());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCursorValues(): iterable
    {
        yield 'positive infinity' => ['infinity'];
        yield 'negative infinity' => ['-infinity'];
        yield 'non-minute instant' => ['2000-01-01T10:00:01Z'];
    }

    #[DataProvider('invalidCursorValues')]
    public function testPostgresqlRejectsNonfiniteAndNonMinuteCursors(string $value): void
    {
        $job = $this->job();
        try {
            $this->observer->executeStatement('UPDATE scheduled_jobs SET evaluated_through = CAST(:value AS TIMESTAMPTZ) WHERE id = :id', ['value' => $value, 'id' => $job->getId()->toString()]);
            self::fail('Expected physical cursor constraint rejection.');
        } catch (\Doctrine\DBAL\Exception\DriverException $error) {
            self::assertSame('23514', $error->getSQLState(), 'The actual CHECK constraint must reject the value.');
        }
        self::assertNull($this->cursor($job));
    }

    /** @return iterable<string, array{bool}> */
    public static function commitFailures(): iterable
    {
        yield 'failure before commit' => [false];
        yield 'lost acknowledgment after commit' => [true];
    }

    #[DataProvider('commitFailures')]
    public function testCommitFailureReconcilesCursorAndWholeWindowBeforeRetry(bool $afterCommit): void
    {
        $job = $this->job();
        $base = $this->databaseMinute()->modify('-20 minutes');
        $this->seedCursor($job, $base);
        $connection = new class($this->writer->getParams(), $this->writer->getDriver()) extends Connection {
            public bool $afterCommit = false;

            public function commit(): void
            {
                if ($this->afterCommit) {
                    parent::commit();
                }
                throw new \RuntimeException('Fixture recovery commit acknowledgment failed.');
            }
        };
        $connection->afterCommit = $afterCommit;
        try {
            try {
                (new DoctrineSchedulerOccurrenceMaterializer($connection))->materialize($job->getId(), 2);
                self::fail('A commit failure must not acknowledge a recovery window.');
            } catch (\RuntimeException $error) {
                self::assertSame('Fixture recovery commit acknowledgment failed.', $error->getMessage());
            }
            self::assertFalse($connection->isConnected(), 'An uncertain connection must be discarded.');
            $committed = $this->occurrences($job);
            self::assertCount($afterCommit ? 2 : 0, $committed);
            self::assertEquals($afterCommit ? $base->modify('+2 minutes') : $base, $this->cursor($job));

            // A fresh adapter resumes from authoritative state, regardless of the lost return value.
            self::assertSame(2, $this->materializer()->materialize($job->getId(), 2));
            $rows = $this->occurrences($job);
            self::assertCount($afterCommit ? 4 : 2, $rows);
            self::assertSame($committed, array_slice($rows, 0, count($committed)));
            foreach ($rows as $offset => $row) {
                self::assertSame($base->modify('+' . ($offset + 1) . ' minutes')->format(DATE_ATOM), $row['minute']);
            }
            self::assertEquals($base->modify('+' . count($rows) . ' minutes'), $this->cursor($job));
            self::assertSame($this->domainRevision($job), $this->revision($job));
        } finally {
            $connection->close();
        }
    }

    private function materializer(): DoctrineSchedulerOccurrenceMaterializer
    {
        return new DoctrineSchedulerOccurrenceMaterializer($this->writer);
    }

    private function job(string $expression = '* * * * *'): ScheduledJob
    {
        $job = ScheduledJob::create('Materializer ' . bin2hex(random_bytes(6)), $expression, JobType::Console, 'app:materializer-fixture', parameters: ['z' => 1.0, 'a' => -0.0]);
        $this->ownedIds[] = $job->getId();
        $this->jobs->save($job);
        self::assertInstanceOf(Uuid::class, $job->getState()->revision);
        return $job;
    }

    private function seedCursor(ScheduledJob $job, DateTimeImmutable $minute): void
    {
        self::assertSame(1, $this->observer->executeStatement('UPDATE scheduled_jobs SET evaluated_through = :minute WHERE id = :id', ['minute' => $minute->format(DATE_ATOM), 'id' => $job->getId()->toString()]));
    }

    private function databaseMinute(): DateTimeImmutable
    {
        return (new DateTimeImmutable($this->observer->fetchOne("SELECT date_trunc('minute', clock_timestamp(), 'UTC')::text")))->setTimezone(new DateTimeZone('UTC'));
    }

    private function cursor(ScheduledJob $job): ?DateTimeImmutable
    {
        $value = $this->observer->fetchOne('SELECT evaluated_through::text FROM scheduled_jobs WHERE id = :id', ['id' => $job->getId()->toString()]);
        return $value === false || $value === null ? null : (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
    }

    private function revision(ScheduledJob $job): string
    {
        $value = $this->observer->fetchOne('SELECT revision::text FROM scheduled_jobs WHERE id = :id', ['id' => $job->getId()->toString()]);
        self::assertIsString($value);
        return $value;
    }

    private function domainRevision(ScheduledJob $job): string
    {
        $revision = $job->getState()->revision;
        self::assertInstanceOf(Uuid::class, $revision);
        return $revision->toString();
    }

    /** @return list<array{id: string, minute: string, command: string, parameters: string}> */
    private function occurrences(ScheduledJob $job): array
    {
        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'], 'minute' => (new DateTimeImmutable((string) $row['scheduled_for']))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            'command' => (string) $row['command'], 'parameters' => (string) $row['parameters'],
        ], $this->observer->fetchAllAssociative('SELECT id::text, scheduled_for::text, command, parameters::text FROM scheduler_occurrences WHERE job_id = :id ORDER BY scheduled_for', ['id' => $job->getId()->toString()]));
    }

    protected function tearDown(): void
    {
        try {
            foreach ([$this->writer ?? null, $this->observer ?? null] as $connection) {
                if ($connection !== null) {
                    while ($connection->isTransactionActive()) {
                        $connection->rollBack();
                    }
                }
            }
            if (isset($this->observer)) {
                foreach ($this->ownedIds as $id) {
                    $this->observer->executeStatement('DELETE FROM scheduler_occurrences WHERE job_id = :id', ['id' => $id->toString()]);
                    $this->observer->executeStatement('DELETE FROM scheduled_jobs WHERE id = :id', ['id' => $id->toString()]);
                }
            }
        } finally {
            if (isset($this->kernel)) {
                $this->kernel->shutdown();
            }
            if (isset($this->writer)) {
                $this->writer->close();
            }
            if (isset($this->observer)) {
                $this->observer->close();
            }
        }
    }
}
