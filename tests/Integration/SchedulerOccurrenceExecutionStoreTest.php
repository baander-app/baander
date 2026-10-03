<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Scheduler\Application\DTO\SchedulerOccurrence;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerOccurrenceExecutionStore;
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

/** Native PostgreSQL commits and independent observers; never dispatches or executes job effects. */
final class SchedulerOccurrenceExecutionStoreTest extends TestCase
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
        $this->schema = 'scheduler_execution_test_' . bin2hex(random_bytes(8));
        $this->first->executeStatement('CREATE SCHEMA ' . $this->schema);
        foreach ([$this->first, $this->second] as $connection) {
            $connection->executeStatement('SET search_path TO ' . $this->schema);
        }
        require_once dirname(__DIR__, 2) . '/migrations/Version20261002230000.php';
        require_once dirname(__DIR__, 2) . '/migrations/Version20261003010000.php';
        foreach ([new \DoctrineMigrations\Version20261002230000($this->first, new NullLogger()), new \DoctrineMigrations\Version20261003010000($this->first, new NullLogger())] as $migration) {
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $this->first->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        }
    }

    public function testOneCommittedWinnerReturnsAuthoritativeTypedSnapshotAndEveryDuplicateIsDenied(): void
    {
        $occurrence = $this->seed();
        $attempt = Uuid::v7();
        $store = new DoctrineSchedulerOccurrenceExecutionStore($this->first);
        $claimed = $store->begin($occurrence->id, $attempt);
        self::assertNotNull($claimed);
        self::assertSame($occurrence->id->toString(), $claimed->id->toString());
        self::assertSame($occurrence->parameters, $claimed->parameters);
        self::assertSame($occurrence->parametersJson(), $claimed->parametersJson());
        self::assertSame($occurrence->jobType, $claimed->jobType);
        self::assertSame($occurrence->command, $claimed->command);
        self::assertSame(0, $this->first->getTransactionNestingLevel());
        $row = $this->second->fetchAssociative('SELECT attempt_id, started_at, returned_at FROM scheduler_occurrence_executions');
        self::assertIsArray($row);
        self::assertSame($attempt->toString(), $row['attempt_id']);
        self::assertNotNull($row['started_at']);
        self::assertNull($row['returned_at']);
        $observer = new DoctrineSchedulerOccurrenceExecutionStore($this->second);
        self::assertNull($observer->begin($occurrence->id, $attempt));
        self::assertNull($observer->begin(Uuid::fromString(strtoupper($occurrence->id->toString())), Uuid::v7()));
        self::assertSame(1, (int) $this->second->fetchOne('SELECT count(*) FROM scheduler_occurrence_executions'));
    }

    public function testExactOwnerReturnIsIdempotentAndDoesNotReopenAdmission(): void
    {
        $occurrence = $this->seed();
        $attempt = Uuid::v7();
        $store = new DoctrineSchedulerOccurrenceExecutionStore($this->first);
        self::assertNotNull($store->begin($occurrence->id, $attempt));
        self::assertFalse($store->markReturned($occurrence->id, Uuid::v7()));
        self::assertFalse($store->markReturned(Uuid::v7(), $attempt));
        self::assertNull($this->second->fetchOne('SELECT returned_at FROM scheduler_occurrence_executions'));
        self::assertTrue($store->markReturned($occurrence->id, $attempt));
        $returned = $this->second->fetchOne('SELECT returned_at FROM scheduler_occurrence_executions');
        self::assertNotNull($returned);
        self::assertTrue((new DoctrineSchedulerOccurrenceExecutionStore($this->second))->markReturned($occurrence->id, $attempt));
        self::assertSame($returned, $this->second->fetchOne('SELECT returned_at FROM scheduler_occurrence_executions'));
        self::assertNull($store->begin($occurrence->id, Uuid::v7()));
    }

    public function testMissingOccurrenceAndAttemptRebindingAreDeniedWithoutRows(): void
    {
        $store = new DoctrineSchedulerOccurrenceExecutionStore($this->first);
        $attempt = Uuid::v7();
        self::assertNull($store->begin(Uuid::v7(), $attempt));
        self::assertSame(0, (int) $this->second->fetchOne('SELECT count(*) FROM scheduler_occurrence_executions'));
        $first = $this->seed();
        $second = $this->seed();
        self::assertNotNull($store->begin($first->id, $attempt));
        self::assertNull($store->begin($second->id, $attempt));
        self::assertNotNull($store->begin($second->id, Uuid::v7()));
        self::assertSame(2, (int) $this->second->fetchOne('SELECT count(*) FROM scheduler_occurrence_executions'));
    }

    public function testFutureOccurrenceCannotConsumeAdmission(): void
    {
        $future = new DateTimeImmutable($this->second->fetchOne("SELECT date_trunc('minute', clock_timestamp(), 'UTC') + INTERVAL '1 day'"));
        $occurrence = new SchedulerOccurrence(Uuid::v7(), Uuid::v7(), $future, JobType::Console, 'app:baander-check', []);
        self::assertTrue((new DoctrineSchedulerOccurrenceStore($this->first))->record($occurrence));
        $store = new DoctrineSchedulerOccurrenceExecutionStore($this->first);
        self::assertNull($store->begin($occurrence->id, Uuid::v7()));
        self::assertNull((new DoctrineSchedulerOccurrenceExecutionStore($this->second))->begin($occurrence->id, Uuid::v7()));
        self::assertSame(0, (int) $this->second->fetchOne('SELECT count(*) FROM scheduler_occurrence_executions'));
        self::assertSame(1, (int) $this->second->fetchOne('SELECT count(*) FROM scheduler_occurrences'));
    }

    public function testPhysicalForeignKeyRejectsUnknownOccurrenceAndDeletionOfConsumedIntent(): void
    {
        try {
            $this->first->executeStatement('INSERT INTO scheduler_occurrence_executions (occurrence_id, attempt_id) VALUES (:occurrence, :attempt)', ['occurrence' => Uuid::v7()->toString(), 'attempt' => Uuid::v7()->toString()]);
            self::fail('Physical admission row must reference a retained occurrence.');
        } catch (DriverException $error) {
            self::assertSame('23503', $error->getSQLState());
        }
        $occurrence = $this->seed();
        self::assertNotNull((new DoctrineSchedulerOccurrenceExecutionStore($this->first))->begin($occurrence->id, Uuid::v7()));
        try {
            $this->second->executeStatement('DELETE FROM scheduler_occurrences WHERE id = :id', ['id' => $occurrence->id->toString()]);
            self::fail('Consumed intent must not be deleted by a cascading relationship.');
        } catch (DriverException $error) {
            self::assertSame('23001', $error->getSQLState());
        }
        self::assertSame(1, (int) $this->second->fetchOne('SELECT count(*) FROM scheduler_occurrences'));
        self::assertSame(1, (int) $this->second->fetchOne('SELECT count(*) FROM scheduler_occurrence_executions'));
    }

    #[DataProvider('commitFailures')]
    public function testBeginCommitFailureNeverAcknowledgesAndAfterCommitNeverAllowsReentry(bool $after): void
    {
        $occurrence = $this->seed();
        $attempt = Uuid::v7();
        $connection = $this->uncertainConnection($after);
        try {
            (new DoctrineSchedulerOccurrenceExecutionStore($connection))->begin($occurrence->id, $attempt);
            self::fail('A failed commit cannot acknowledge admission.');
        } catch (\RuntimeException $error) {
            self::assertSame('Fixture execution commit failed.', $error->getMessage());
        }
        self::assertFalse($connection->isConnected());
        $observer = new DoctrineSchedulerOccurrenceExecutionStore($this->second);
        if ($after) {
            self::assertSame($attempt->toString(), $this->second->fetchOne('SELECT attempt_id FROM scheduler_occurrence_executions'));
            self::assertNull($observer->begin($occurrence->id, $attempt));
            self::assertNull($observer->begin($occurrence->id, Uuid::v7()));
        } else {
            self::assertSame(0, (int) $this->second->fetchOne('SELECT count(*) FROM scheduler_occurrence_executions'));
            self::assertNotNull($observer->begin($occurrence->id, $attempt));
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function commitFailures(): iterable
    {
        yield 'rollback before commit' => [false];
        yield 'lost admission acknowledgment' => [true];
    }

    public function testLostReturnAcknowledgmentCanOnlyReconcileSameOwnerWithoutChangingTimestamp(): void
    {
        $occurrence = $this->seed();
        $attempt = Uuid::v7();
        self::assertNotNull((new DoctrineSchedulerOccurrenceExecutionStore($this->first))->begin($occurrence->id, $attempt));
        $connection = $this->uncertainConnection(true);
        try {
            (new DoctrineSchedulerOccurrenceExecutionStore($connection))->markReturned($occurrence->id, $attempt);
            self::fail('Lost return commit cannot return acknowledged true.');
        } catch (\RuntimeException $error) {
            self::assertSame('Fixture execution commit failed.', $error->getMessage());
        }
        self::assertFalse($connection->isConnected());
        $returned = $this->second->fetchOne('SELECT returned_at FROM scheduler_occurrence_executions');
        self::assertNotNull($returned);
        $observer = new DoctrineSchedulerOccurrenceExecutionStore($this->second);
        self::assertTrue($observer->markReturned($occurrence->id, $attempt));
        self::assertSame($returned, $this->second->fetchOne('SELECT returned_at FROM scheduler_occurrence_executions'));
        self::assertFalse($observer->markReturned($occurrence->id, Uuid::v7()));
        self::assertNull($observer->begin($occurrence->id, Uuid::v7()));
    }

    public function testActualUncommittedContenderBlocksThenBoundedTimeoutDiscardsConnection(): void
    {
        $occurrence = $this->seed();
        $winner = Uuid::v7();
        $this->second->beginTransaction();
        $this->second->executeStatement('INSERT INTO scheduler_occurrence_executions (occurrence_id, attempt_id) VALUES (:occurrence, :attempt)', ['occurrence' => $occurrence->id->toString(), 'attempt' => $winner->toString()]);
        $start = hrtime(true);
        try {
            (new DoctrineSchedulerOccurrenceExecutionStore($this->first, 500, 50))->begin($occurrence->id, Uuid::v7());
            self::fail('Uncommitted unique contender must block admission.');
        } catch (DriverException $error) {
            self::assertSame('55P03', $error->getSQLState());
        }
        self::assertLessThan(2.0, (hrtime(true) - $start) / 1e9);
        self::assertFalse($this->first->isConnected());
        $this->second->commit();
        $this->first->executeStatement('SET search_path TO ' . $this->schema);
        self::assertNull((new DoctrineSchedulerOccurrenceExecutionStore($this->first))->begin($occurrence->id, Uuid::v7()));
        self::assertSame($winner->toString(), $this->second->fetchOne('SELECT attempt_id FROM scheduler_occurrence_executions'));
    }

    public function testBothOperationsRejectCallerTransactionWithoutCommittingIt(): void
    {
        $occurrence = $this->seed();
        $this->first->beginTransaction();
        $store = new DoctrineSchedulerOccurrenceExecutionStore($this->first);
        foreach ([fn () => $store->begin($occurrence->id, Uuid::v7()), fn () => $store->markReturned($occurrence->id, Uuid::v7())] as $operation) {
            try {
                $operation();
                self::fail('Caller-owned transaction must be rejected.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('idle autocommit', $error->getMessage());
            }
            self::assertSame(1, $this->first->getTransactionNestingLevel());
        }
        self::assertTrue($this->first->isConnected());
        self::assertSame(0, (int) $this->second->fetchOne('SELECT count(*) FROM scheduler_occurrence_executions'));
        $this->first->rollBack();
    }

    private function seed(): SchedulerOccurrence
    {
        $occurrence = new SchedulerOccurrence(Uuid::v7(), Uuid::v7(), new DateTimeImmutable('2026-10-03 00:00:00Z'), JobType::Console, 'app:baander-check', ['integral' => 1.0, 'negativeZero' => -0.0, 'large' => 1.0e18, 'nested' => [false, null, 'worker@baander.app']]);
        self::assertTrue((new DoctrineSchedulerOccurrenceStore($this->first))->record($occurrence));
        return $occurrence;
    }

    private function uncertainConnection(bool $after): ExecutionCommitFailureConnection
    {
        $params = $this->params;
        $params['wrapperClass'] = ExecutionCommitFailureConnection::class;
        $connection = DriverManager::getConnection($params);
        self::assertInstanceOf(ExecutionCommitFailureConnection::class, $connection);
        $this->extras[] = $connection;
        $connection->executeStatement('SET search_path TO ' . $this->schema);
        $connection->failure = $after ? 'after' : 'before';
        return $connection;
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

/** Real transactions; inject acknowledgment uncertainty before/after the actual DBAL commit. */
final class ExecutionCommitFailureConnection extends Connection
{
    public ?string $failure = null;

    public function commit(): void
    {
        if ($this->failure === 'before') {
            $this->failure = null;
            throw new \RuntimeException('Fixture execution commit failed.');
        }
        parent::commit();
        if ($this->failure === 'after') {
            $this->failure = null;
            throw new \RuntimeException('Fixture execution commit failed.');
        }
    }
}
