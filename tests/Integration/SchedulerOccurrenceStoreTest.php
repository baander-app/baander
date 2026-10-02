<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Scheduler\Application\DTO\SchedulerOccurrence;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerOccurrenceStore;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Actual migration, native independent connections and committed immutable intents; no dispatch/execution. */
final class SchedulerOccurrenceStoreTest extends TestCase
{
    private Connection $first;
    private Connection $second;
    private string $schema;
    /** @var array<string, mixed> */
    private array $params;
    /** @var list<Connection> */
    private array $extras = [];

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to disposable PostgreSQL.');
        }
        $this->params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $this->first = DriverManager::getConnection($this->params);
        $this->second = DriverManager::getConnection($this->params);
        $this->schema = 'scheduler_occurrence_test_' . bin2hex(random_bytes(8));
        $this->first->executeStatement('CREATE SCHEMA ' . $this->schema);
        foreach ([$this->first, $this->second] as $connection) {
            $connection->executeStatement('SET search_path TO ' . $this->schema);
        }
        require_once dirname(__DIR__, 2) . '/migrations/Version20261002230000.php';
        $migration = new \DoctrineMigrations\Version20261002230000($this->first, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->first->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    public function testCommittedRoundTripAfterReconnectPreservesTypedOrderedSnapshot(): void
    {
        $parameters = ['address' => 'worker@baander.app', 'largeFloat' => 1.0e18, 'negativeZero' => -0.0, 'fraction' => 1.0, 'integer' => 1, 'nested' => [false, null, ['01' => 'unicode 日本語']], 3 => 'third', 1 => 'first'];
        $occurrence = $this->occurrence(parameters: $parameters);
        $store = new DoctrineSchedulerOccurrenceStore($this->first);
        self::assertTrue($store->record($occurrence));
        self::assertSame(0, $this->first->getTransactionNestingLevel());
        $this->first->close();
        $this->first->executeStatement('SET search_path TO ' . $this->schema);
        $found = $store->find($occurrence->jobId, $occurrence->scheduledFor);
        self::assertNotNull($found);
        self::assertTrue($found->id->equals($occurrence->id));
        self::assertSame($occurrence->parametersJson(), $found->parametersJson());
        self::assertSame($parameters, $found->parameters);
        self::assertSame('UTC', $found->scheduledFor->getTimezone()->getName());
        self::assertSame('json', $this->second->fetchOne("SELECT data_type FROM information_schema.columns WHERE table_schema = :schema AND table_name = 'scheduler_occurrences' AND column_name = 'parameters'", ['schema' => $this->schema]));
        self::assertSame(0, (int) $this->second->fetchOne("SELECT count(*) FROM information_schema.table_constraints WHERE table_schema = :schema AND table_name = 'scheduler_occurrences' AND constraint_type = 'FOREIGN KEY'" , ['schema' => $this->schema]));
    }

    public function testEquivalentTimezoneAndUppercaseUuidRetryPreservesFirstId(): void
    {
        $original = $this->occurrence();
        $store = new DoctrineSchedulerOccurrenceStore($this->first);
        self::assertTrue($store->record($original));
        $retry = $this->occurrence(id: Uuid::v7(), job: Uuid::fromString(strtoupper($original->jobId->toString())), at: new DateTimeImmutable('2026-10-02 14:00:00+02:00'));
        self::assertFalse((new DoctrineSchedulerOccurrenceStore($this->second))->record($retry));
        $found = $store->find($retry->jobId, $retry->scheduledFor);
        self::assertNotNull($found);
        self::assertSame($original->id->toString(), $found->id->toString());
        self::assertSame(1, (int) $this->second->fetchOne('SELECT count(*) FROM scheduler_occurrences'));
    }

    /** @param array{first: int|float, second: int} $parameters */
    #[DataProvider('conflictingSnapshots')]
    public function testConflictingSnapshotCannotReplaceOriginal(JobType $type, string $command, array $parameters): void
    {
        $original = $this->occurrence();
        $store = new DoctrineSchedulerOccurrenceStore($this->first);
        self::assertTrue($store->record($original));
        try {
            $store->record($this->occurrence(id: Uuid::v7(), type: $type, command: $command, parameters: $parameters));
            self::fail('Changed snapshot must not be an ambiguous duplicate.');
        } catch (\LogicException) {
            self::assertFalse($this->first->isConnected());
        }
        $found = (new DoctrineSchedulerOccurrenceStore($this->second))->find($original->jobId, $original->scheduledFor);
        self::assertNotNull($found);
        self::assertSame($original->id->toString(), $found->id->toString());
        self::assertSame($original->parametersJson(), $found->parametersJson());
    }

    /** @return iterable<string, array{JobType, string, array{first: int|float, second: int}}> */
    public static function conflictingSnapshots(): iterable
    {
        yield 'job type' => [JobType::Messenger, 'app:baander-check', ['first' => 1, 'second' => 2]];
        yield 'command' => [JobType::Console, 'app:baander-other', ['first' => 1, 'second' => 2]];
        yield 'parameter value' => [JobType::Console, 'app:baander-check', ['first' => 3, 'second' => 2]];
        yield 'parameter type' => [JobType::Console, 'app:baander-check', ['first' => 1.0, 'second' => 2]];
        yield 'parameter order' => [JobType::Console, 'app:baander-check', ['second' => 2, 'first' => 1]];
    }

    public function testAnOccurrenceIdCannotBeReusedForAnotherSlot(): void
    {
        $original = $this->occurrence();
        $store = new DoctrineSchedulerOccurrenceStore($this->first);
        self::assertTrue($store->record($original));
        try {
            $store->record($this->occurrence(id: $original->id, at: new DateTimeImmutable('2026-10-02 12:01:00Z')));
            self::fail('A reused immutable identity must fail.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('identifier', $error->getMessage());
        }
        self::assertSame(1, (int) $this->second->fetchOne('SELECT count(*) FROM scheduler_occurrences'));
    }

    public function testMissingLookupsReturnNullAndNonMinuteIsRejected(): void
    {
        $store = new DoctrineSchedulerOccurrenceStore($this->first);
        $occurrence = $this->occurrence();
        self::assertNull($store->find($occurrence->jobId, $occurrence->scheduledFor));
        self::assertTrue($store->record($occurrence));
        foreach (['2026-10-02 12:00:01Z', '2026-10-02 12:00:00.000001Z'] as $invalid) {
            try {
                $store->find($occurrence->jobId, new DateTimeImmutable($invalid));
                self::fail('Invalid lookup must be rejected before a query.');
            } catch (\InvalidArgumentException) {
                self::assertSame(0, $this->first->getTransactionNestingLevel());
            }
        }
        self::assertNull($store->find(Uuid::v7(), $occurrence->scheduledFor));
    }

    #[DataProvider('commitFailures')]
    public function testCommitFailureHasNoAcknowledgmentAndAuthoritativeReconciliation(bool $afterCommit): void
    {
        $params = $this->params;
        $params['wrapperClass'] = OccurrenceCommitFailureConnection::class;
        $connection = DriverManager::getConnection($params);
        self::assertInstanceOf(OccurrenceCommitFailureConnection::class, $connection);
        $this->extras[] = $connection;
        $connection->executeStatement('SET search_path TO ' . $this->schema);
        $connection->failure = $afterCommit ? 'after' : 'before';
        $occurrence = $this->occurrence();
        try {
            (new DoctrineSchedulerOccurrenceStore($connection))->record($occurrence);
            self::fail('No committed-insertion acknowledgment may escape a failed commit.');
        } catch (\RuntimeException $error) {
            self::assertSame('Fixture commit failed.', $error->getMessage());
        }
        self::assertFalse($connection->isConnected());
        $observer = new DoctrineSchedulerOccurrenceStore($this->second);
        $found = $observer->find($occurrence->jobId, $occurrence->scheduledFor);
        if ($afterCommit) {
            self::assertNotNull($found);
            self::assertSame($occurrence->id->toString(), $found->id->toString());
            self::assertFalse($observer->record($this->occurrence(id: Uuid::v7())));
        } else {
            self::assertNull($found);
            self::assertTrue($observer->record($occurrence));
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function commitFailures(): iterable
    {
        yield 'rollback before commit' => [false];
        yield 'lost acknowledgment after real commit' => [true];
    }

    public function testActualUniqueContentionTimesOutWithoutAdmissionThenReconcilesCommittedWinner(): void
    {
        $occurrence = $this->occurrence();
        $this->second->beginTransaction();
        $this->second->executeStatement('INSERT INTO scheduler_occurrences (id, job_id, scheduled_for, job_type, command, parameters) VALUES (:id, :job, :at, :type, :command, CAST(:parameters AS JSON))', ['id' => $occurrence->id->toString(), 'job' => $occurrence->jobId->toString(), 'at' => $occurrence->scheduledFor->format('c'), 'type' => $occurrence->jobType->value, 'command' => $occurrence->command, 'parameters' => $occurrence->parametersJson()]);
        $started = hrtime(true);
        try {
            (new DoctrineSchedulerOccurrenceStore($this->first, 500, 50))->record($this->occurrence(id: Uuid::v7()));
            self::fail('Uncommitted unique winner must block admission.');
        } catch (DriverException $error) {
            self::assertSame('55P03', $error->getSQLState());
        }
        self::assertLessThan(2.0, (hrtime(true) - $started) / 1e9);
        self::assertFalse($this->first->isConnected());
        $this->second->commit();
        $this->first->executeStatement('SET search_path TO ' . $this->schema);
        self::assertFalse((new DoctrineSchedulerOccurrenceStore($this->first))->record($this->occurrence(id: Uuid::v7())));
        self::assertSame(1, (int) $this->second->fetchOne('SELECT count(*) FROM scheduler_occurrences'));
    }

    public function testCallerTransactionIsRejectedWithoutCommittingIt(): void
    {
        $this->first->beginTransaction();
        try {
            (new DoctrineSchedulerOccurrenceStore($this->first))->record($this->occurrence());
            self::fail('Store cannot admit intents under a caller transaction.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('idle autocommit', $error->getMessage());
        }
        self::assertSame(1, $this->first->getTransactionNestingLevel());
        self::assertTrue($this->first->isConnected());
        self::assertSame(0, (int) $this->second->fetchOne('SELECT count(*) FROM scheduler_occurrences'));
        $this->first->rollBack();
    }

    /** @param array{at?: string, command?: string, parameters?: string} $invalid */
    #[DataProvider('invalidPhysicalRows')]
    public function testActualSqlConstraintsRejectInvalidRows(array $invalid): void
    {
        $occurrence = $this->occurrence();
        try {
            $this->first->executeStatement('INSERT INTO scheduler_occurrences (id, job_id, scheduled_for, job_type, command, parameters) VALUES (:id, :job, :at, :type, :command, CAST(:parameters AS JSON))', array_replace(['id' => $occurrence->id->toString(), 'job' => $occurrence->jobId->toString(), 'at' => $occurrence->scheduledFor->format('c'), 'type' => $occurrence->jobType->value, 'command' => $occurrence->command, 'parameters' => $occurrence->parametersJson()], $invalid));
            self::fail('Migration must independently enforce snapshot constraints.');
        } catch (DriverException $error) {
            self::assertSame('23514', $error->getSQLState());
        }
        self::assertSame(0, (int) $this->second->fetchOne('SELECT count(*) FROM scheduler_occurrences'));
    }

    /** @return iterable<string, array{array{at?: string, command?: string, parameters?: string}}> */
    public static function invalidPhysicalRows(): iterable
    {
        yield 'non-minute' => [['at' => '2026-10-02T12:00:01+00:00']];
        yield 'oversized command' => [['command' => str_repeat('x', 513)]];
        yield 'scalar JSON' => [['parameters' => '123']];
        yield 'oversized JSON' => [['parameters' => json_encode(['value' => str_repeat('x', 16385)], JSON_THROW_ON_ERROR)]];
    }

    public function testDistinctValidMinutesRetainDistinctIntents(): void
    {
        $store = new DoctrineSchedulerOccurrenceStore($this->first);
        $first = $this->occurrence();
        $next = $this->occurrence(at: new DateTimeImmutable('2026-10-02 12:01:00Z'));
        self::assertTrue($store->record($first));
        self::assertTrue($store->record($next));
        $observer = new DoctrineSchedulerOccurrenceStore($this->second);
        self::assertSame($first->id->toString(), $observer->find($first->jobId, $first->scheduledFor)?->id->toString());
        self::assertSame($next->id->toString(), $observer->find($next->jobId, $next->scheduledFor)?->id->toString());
        self::assertSame(2, (int) $this->second->fetchOne('SELECT count(*) FROM scheduler_occurrences'));
    }

    /** @param array<array-key, scalar|null|array<array-key, scalar|null|array<string, string>>> $parameters */
    private function occurrence(?Uuid $id = null, ?Uuid $job = null, ?DateTimeImmutable $at = null, JobType $type = JobType::Console, string $command = 'app:baander-check', array $parameters = ['first' => 1, 'second' => 2]): SchedulerOccurrence
    {
        return new SchedulerOccurrence($id ?? Uuid::v7(), $job ?? Uuid::fromString('0199a03e-0000-7000-8000-aaaaaaaaaaaa'), $at ?? new DateTimeImmutable('2026-10-02 12:00:00Z'), $type, $command, $parameters);
    }

    protected function tearDown(): void
    {
        foreach ([$this->first ?? null, $this->second ?? null, ...$this->extras] as $connection) {
            if ($connection !== null) {
                while ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
            }
        }
        if (isset($this->schema)) {
            $this->second->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
        foreach ([$this->first ?? null, $this->second ?? null, ...$this->extras] as $connection) {
            $connection?->close();
        }
    }
}

/** Real database transactions; injected exception only surrounds commit acknowledgment. */
final class OccurrenceCommitFailureConnection extends Connection
{
    public ?string $failure = null;

    public function commit(): void
    {
        if ($this->failure === 'before') {
            $this->failure = null;
            throw new \RuntimeException('Fixture commit failed.');
        }
        parent::commit();
        if ($this->failure === 'after') {
            $this->failure = null;
            throw new \RuntimeException('Fixture commit failed.');
        }
    }
}
