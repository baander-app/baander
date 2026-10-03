<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Scheduler\Application\DTO\SchedulerOccurrence;
use App\Scheduler\Application\Exception\ScheduledOccurrenceJobBusy;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerOccurrenceExecutionStore;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerOccurrenceStore;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Worker\DeploymentLease;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentLease;
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
    private DeploymentLease $authority;
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
        require_once dirname(__DIR__, 2) . '/migrations/Version20261002210000.php';
        require_once dirname(__DIR__, 2) . '/migrations/Version20261002230000.php';
        require_once dirname(__DIR__, 2) . '/migrations/Version20261003010000.php';
        foreach ([new \DoctrineMigrations\Version20261002210000($this->first, new NullLogger()), new \DoctrineMigrations\Version20261002230000($this->first, new NullLogger()), new \DoctrineMigrations\Version20261003010000($this->first, new NullLogger())] as $migration) {
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $this->first->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        }
        $authority = (new DoctrineDeploymentLease($this->first))->acquire('baander.app:scheduler-test', str_repeat('a', 32), 3600);
        self::assertNotNull($authority);
        $this->authority = $authority;
    }

    public function testOneCommittedWinnerReturnsAuthoritativeTypedSnapshotAndEveryDuplicateIsDenied(): void
    {
        $occurrence = $this->seed();
        $attempt = Uuid::v7();
        $store = new DoctrineSchedulerOccurrenceExecutionStore($this->first, $this->authority);
        $claimed = $store->begin($occurrence->id, $attempt);
        self::assertNotNull($claimed);
        self::assertSame($occurrence->id->toString(), $claimed->id->toString());
        self::assertSame($occurrence->parameters, $claimed->parameters);
        self::assertSame($occurrence->parametersJson(), $claimed->parametersJson());
        self::assertSame($occurrence->jobType, $claimed->jobType);
        self::assertSame($occurrence->command, $claimed->command);
        self::assertSame(0, $this->first->getTransactionNestingLevel());
        $row = $this->second->fetchAssociative('SELECT attempt_id, started_at, returned_at, deployment_namespace, deployment_boot_id, deployment_epoch FROM scheduler_occurrence_executions');
        self::assertIsArray($row);
        self::assertSame($attempt->toString(), $row['attempt_id']);
        self::assertSame($this->authority->namespace, $row['deployment_namespace']);
        self::assertSame($this->authority->bootId, $row['deployment_boot_id']);
        self::assertSame($this->authority->epoch, (int) $row['deployment_epoch']);
        self::assertNotNull($row['started_at']);
        self::assertNull($row['returned_at']);
        $observer = new DoctrineSchedulerOccurrenceExecutionStore($this->second, $this->authority);
        self::assertNull($observer->begin($occurrence->id, $attempt));
        self::assertNull($observer->begin(Uuid::fromString(strtoupper($occurrence->id->toString())), Uuid::v7()));
        self::assertSame(1, (int) $this->second->fetchOne('SELECT count(*) FROM scheduler_occurrence_executions'));
    }

    public function testExactOwnerReturnIsIdempotentAndDoesNotReopenAdmission(): void
    {
        $occurrence = $this->seed();
        $attempt = Uuid::v7();
        $store = new DoctrineSchedulerOccurrenceExecutionStore($this->first, $this->authority);
        self::assertNotNull($store->begin($occurrence->id, $attempt));
        self::assertFalse($store->markReturned($occurrence->id, Uuid::v7()));
        self::assertFalse($store->markReturned(Uuid::v7(), $attempt));
        self::assertNull($this->second->fetchOne('SELECT returned_at FROM scheduler_occurrence_executions'));
        self::assertTrue($store->markReturned($occurrence->id, $attempt));
        $returned = $this->second->fetchOne('SELECT returned_at FROM scheduler_occurrence_executions');
        self::assertNotNull($returned);
        self::assertTrue((new DoctrineSchedulerOccurrenceExecutionStore($this->second, $this->authority))->markReturned($occurrence->id, $attempt));
        self::assertSame($returned, $this->second->fetchOne('SELECT returned_at FROM scheduler_occurrence_executions'));
        self::assertNull($store->begin($occurrence->id, Uuid::v7()));
    }

    public function testMissingOccurrenceAndAttemptRebindingAreDeniedWithoutRows(): void
    {
        $store = new DoctrineSchedulerOccurrenceExecutionStore($this->first, $this->authority);
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
        $store = new DoctrineSchedulerOccurrenceExecutionStore($this->first, $this->authority);
        self::assertNull($store->begin($occurrence->id, Uuid::v7()));
        self::assertNull((new DoctrineSchedulerOccurrenceExecutionStore($this->second, $this->authority))->begin($occurrence->id, Uuid::v7()));
        self::assertSame(0, (int) $this->second->fetchOne('SELECT count(*) FROM scheduler_occurrence_executions'));
        self::assertSame(1, (int) $this->second->fetchOne('SELECT count(*) FROM scheduler_occurrences'));
    }

    public function testPhysicalForeignKeyRejectsUnknownOccurrenceAndDeletionOfConsumedIntent(): void
    {
        try {
            $this->first->executeStatement('INSERT INTO scheduler_occurrence_executions (occurrence_id, job_id, attempt_id, deployment_namespace, deployment_boot_id, deployment_epoch) VALUES (:occurrence, :job, :attempt, :namespace, :boot, :epoch)', ['occurrence' => Uuid::v7()->toString(), 'job' => Uuid::v7()->toString(), 'attempt' => Uuid::v7()->toString(), 'namespace' => $this->authority->namespace, 'boot' => $this->authority->bootId, 'epoch' => $this->authority->epoch]);
            self::fail('Physical admission row must reference a retained occurrence.');
        } catch (DriverException $error) {
            self::assertSame('23503', $error->getSQLState());
        }
        $occurrence = $this->seed();
        self::assertNotNull((new DoctrineSchedulerOccurrenceExecutionStore($this->first, $this->authority))->begin($occurrence->id, Uuid::v7()));
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
            (new DoctrineSchedulerOccurrenceExecutionStore($connection, $this->authority))->begin($occurrence->id, $attempt);
            self::fail('A failed commit cannot acknowledge admission.');
        } catch (\RuntimeException $error) {
            self::assertSame('Fixture execution commit failed.', $error->getMessage());
        }
        self::assertFalse($connection->isConnected());
        $observer = new DoctrineSchedulerOccurrenceExecutionStore($this->second, $this->authority);
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
        self::assertNotNull((new DoctrineSchedulerOccurrenceExecutionStore($this->first, $this->authority))->begin($occurrence->id, $attempt));
        $connection = $this->uncertainConnection(true);
        try {
            (new DoctrineSchedulerOccurrenceExecutionStore($connection, $this->authority))->markReturned($occurrence->id, $attempt);
            self::fail('Lost return commit cannot return acknowledged true.');
        } catch (\RuntimeException $error) {
            self::assertSame('Fixture execution commit failed.', $error->getMessage());
        }
        self::assertFalse($connection->isConnected());
        $returned = $this->second->fetchOne('SELECT returned_at FROM scheduler_occurrence_executions');
        self::assertNotNull($returned);
        $observer = new DoctrineSchedulerOccurrenceExecutionStore($this->second, $this->authority);
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
        $this->second->executeStatement('INSERT INTO scheduler_occurrence_executions (occurrence_id, job_id, attempt_id, deployment_namespace, deployment_boot_id, deployment_epoch) VALUES (:occurrence, :job, :attempt, :namespace, :boot, :epoch)', ['occurrence' => $occurrence->id->toString(), 'job' => $occurrence->jobId->toString(), 'attempt' => $winner->toString(), 'namespace' => $this->authority->namespace, 'boot' => $this->authority->bootId, 'epoch' => $this->authority->epoch]);
        $start = hrtime(true);
        try {
            (new DoctrineSchedulerOccurrenceExecutionStore($this->first, $this->authority, 500, 50))->begin($occurrence->id, Uuid::v7());
            self::fail('Uncommitted unique contender must block admission.');
        } catch (DriverException $error) {
            self::assertSame('55P03', $error->getSQLState());
        }
        self::assertLessThan(2.0, (hrtime(true) - $start) / 1e9);
        self::assertFalse($this->first->isConnected());
        $this->second->commit();
        $this->first->executeStatement('SET search_path TO ' . $this->schema);
        self::assertNull((new DoctrineSchedulerOccurrenceExecutionStore($this->first, $this->authority))->begin($occurrence->id, Uuid::v7()));
        self::assertSame($winner->toString(), $this->second->fetchOne('SELECT attempt_id FROM scheduler_occurrence_executions'));
    }

    public function testBothOperationsRejectCallerTransactionWithoutCommittingIt(): void
    {
        $occurrence = $this->seed();
        $this->first->beginTransaction();
        $store = new DoctrineSchedulerOccurrenceExecutionStore($this->first, $this->authority);
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

    public function testAbsentContextDeniesBeforeOpeningDatabaseAndCannotMarkReturn(): void
    {
        $occurrence = $this->seed();
        $store = new DoctrineSchedulerOccurrenceExecutionStore($this->first);
        self::assertFalse($store->markReturned($occurrence->id, Uuid::v7()));
        try {
            $store->begin($occurrence->id, Uuid::v7());
            self::fail('Absent authority cannot admit any work.');
        } catch (\RuntimeException $error) {
            self::assertSame('Scheduler execution admission requires active deployment authority.', $error->getMessage());
        }
        self::assertSame(0, (int) $this->second->fetchOne('SELECT count(*) FROM scheduler_occurrence_executions'));
    }

    #[DataProvider('invalidLeaseStates')]
    public function testInvalidLiveLeaseDeniesWithoutConsumingAttempt(string $state): void
    {
        $occurrence = $this->seed();
        match ($state) {
            'missing' => $this->second->executeStatement('DELETE FROM worker_deployment_leases'),
            'expired' => $this->second->executeStatement("UPDATE worker_deployment_leases SET expires_at = clock_timestamp() - INTERVAL '1 second'"),
            'boot' => $this->second->executeStatement('UPDATE worker_deployment_leases SET owner_boot_id = :boot', ['boot' => str_repeat('b', 32)]),
            'epoch' => $this->second->executeStatement('UPDATE worker_deployment_leases SET epoch = epoch + 1'),
            'retired' => $this->second->executeStatement("UPDATE worker_deployment_leases SET state = 'available'"),
            default => throw new \LogicException('Unexpected test lease state.'),
        };
        try {
            (new DoctrineSchedulerOccurrenceExecutionStore($this->first, $this->authority))->begin($occurrence->id, Uuid::v7());
            self::fail('Only matching unexpired active lease can admit work.');
        } catch (\RuntimeException $error) {
            self::assertSame('Scheduler execution admission requires active deployment authority.', $error->getMessage());
        }
        self::assertFalse($this->first->isConnected());
        self::assertSame(0, (int) $this->second->fetchOne('SELECT count(*) FROM scheduler_occurrence_executions'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidLeaseStates(): iterable
    {
        foreach (['missing', 'expired', 'boot', 'epoch', 'retired'] as $state) {
            yield $state => [$state];
        }
    }

    public function testHistoricalReceiptAllowsExpiredOwnerButRejectsDifferentContext(): void
    {
        $occurrence = $this->seed();
        $attempt = Uuid::v7();
        $store = new DoctrineSchedulerOccurrenceExecutionStore($this->first, $this->authority);
        self::assertNotNull($store->begin($occurrence->id, $attempt));
        $this->second->executeStatement("UPDATE worker_deployment_leases SET expires_at = clock_timestamp() - INTERVAL '1 second', owner_boot_id = :boot, epoch = epoch + 1, state = 'available'", ['boot' => str_repeat('b', 32)]);
        foreach ([new DeploymentLease('baander.app:other-context', $this->authority->bootId, $this->authority->epoch), new DeploymentLease($this->authority->namespace, str_repeat('b', 32), $this->authority->epoch), new DeploymentLease($this->authority->namespace, $this->authority->bootId, $this->authority->epoch + 1)] as $wrong) {
            self::assertFalse((new DoctrineSchedulerOccurrenceExecutionStore($this->second, $wrong))->markReturned($occurrence->id, $attempt));
        }
        self::assertNull($this->second->fetchOne('SELECT returned_at FROM scheduler_occurrence_executions'));
        self::assertTrue($store->markReturned($occurrence->id, $attempt));
        $returned = $this->second->fetchOne('SELECT returned_at FROM scheduler_occurrence_executions');
        self::assertNotNull($returned);
        self::assertTrue($store->markReturned($occurrence->id, $attempt));
        self::assertSame($returned, $this->second->fetchOne('SELECT returned_at FROM scheduler_occurrence_executions'));
    }

    public function testAuthorityLostDuringInsertRollsBackAttemptBeforeGrant(): void
    {
        $occurrence = $this->seed();
        $expiry = $this->second->fetchOne('SELECT expires_at FROM worker_deployment_leases');
        $this->second->executeStatement(<<<'SQL'
            CREATE FUNCTION expire_admission_lease() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                UPDATE worker_deployment_leases SET expires_at = clock_timestamp() - INTERVAL '1 second';
                RETURN NEW;
            END $$
            SQL);
        $this->second->executeStatement('CREATE TRIGGER expire_admission BEFORE INSERT ON scheduler_occurrence_executions FOR EACH ROW EXECUTE FUNCTION expire_admission_lease()');
        try {
            (new DoctrineSchedulerOccurrenceExecutionStore($this->first, $this->authority))->begin($occurrence->id, Uuid::v7());
            self::fail('Final database-clock check must reject authority lost during insertion.');
        } catch (\RuntimeException $error) {
            self::assertSame('Scheduler execution admission requires active deployment authority.', $error->getMessage());
        }
        self::assertFalse($this->first->isConnected());
        self::assertSame(0, (int) $this->second->fetchOne('SELECT count(*) FROM scheduler_occurrence_executions'));
        self::assertSame($expiry, $this->second->fetchOne('SELECT expires_at FROM worker_deployment_leases'), 'The rejected attempt and test trigger mutation both rolled back.');
    }

    public function testLeaseRowContentionIsBoundedAndCannotConsumeAdmission(): void
    {
        $occurrence = $this->seed();
        $this->second->beginTransaction();
        $this->second->fetchOne('SELECT 1 FROM worker_deployment_leases WHERE namespace = :namespace FOR UPDATE', ['namespace' => $this->authority->namespace]);
        $started = hrtime(true);
        try {
            (new DoctrineSchedulerOccurrenceExecutionStore($this->first, $this->authority, 500, 50))->begin($occurrence->id, Uuid::v7());
            self::fail('Lease-row contention must not bypass authority serialization.');
        } catch (DriverException $error) {
            self::assertSame('55P03', $error->getSQLState());
        }
        self::assertLessThan(2.0, (hrtime(true) - $started) / 1e9);
        self::assertFalse($this->first->isConnected());
        self::assertSame(0, (int) $this->second->fetchOne('SELECT count(*) FROM scheduler_occurrence_executions'));
        $this->second->rollBack();
    }

    #[DataProvider('environmentCases')]
    public function testEnvironmentFactoryFailsClosedAndRestoresProcessValues(?string $namespace, ?string $bootId, ?string $epoch, bool $malformed): void
    {
        $values = ['BAANDER_WORKER_NAMESPACE' => $namespace, 'BAANDER_WORKER_BOOT_ID' => $bootId, 'BAANDER_WORKER_LEASE_EPOCH' => $epoch];
        $previous = [];
        foreach ($values as $key => $value) {
            $previous[$key] = getenv($key);
            putenv($value === null ? $key : $key . '=' . $value);
        }
        try {
            if ($malformed) {
                try {
                    DoctrineSchedulerOccurrenceExecutionStore::fromDsn((string) getenv('OUTBOX_TEST_DATABASE_URL'));
                    self::fail('Malformed complete context cannot construct an execution store.');
                } catch (\RuntimeException $error) {
                    self::assertSame('Scheduler execution authority context is invalid.', $error->getMessage());
                }
            } else {
                $store = DoctrineSchedulerOccurrenceExecutionStore::fromDsn((string) getenv('OUTBOX_TEST_DATABASE_URL'));
                self::assertFalse($store->markReturned(Uuid::v7(), Uuid::v7()));
                try {
                    $store->begin(Uuid::v7(), Uuid::v7());
                    self::fail('Partial process context cannot authorize work.');
                } catch (\RuntimeException $error) {
                    self::assertSame('Scheduler execution admission requires active deployment authority.', $error->getMessage());
                }
            }
        } finally {
            foreach ($previous as $key => $value) {
                putenv($value === false ? $key : $key . '=' . $value);
            }
        }
    }

    /** @return iterable<string, array{?string, ?string, ?string, bool}> */
    public static function environmentCases(): iterable
    {
        yield 'no namespace' => [null, str_repeat('a', 32), '1', false];
        yield 'no boot' => ['baander.app:scheduler-test', null, '1', false];
        yield 'no epoch' => ['baander.app:scheduler-test', str_repeat('a', 32), null, false];
        yield 'partial stale epoch' => [null, null, '9999', false];
        yield 'bad namespace' => ['bad namespace', str_repeat('a', 32), '1', true];
        yield 'bad boot' => ['baander.app:scheduler-test', 'invalid', '1', true];
        yield 'zero epoch' => ['baander.app:scheduler-test', str_repeat('a', 32), '0', true];
        yield 'overflow epoch' => ['baander.app:scheduler-test', str_repeat('a', 32), '99999999999999999999999', true];
        yield 'fraction epoch' => ['baander.app:scheduler-test', str_repeat('a', 32), '1.0', true];
    }

    public function testDatabaseRejectsConcurrentUnresolvedJobAndMismatchedOccurrenceJob(): void
    {
        $running = $this->seed();
        $pending = $this->seed($running->jobId, $running->scheduledFor->modify('+1 minute'));
        $differentJob = $this->seed();
        self::assertNotNull((new DoctrineSchedulerOccurrenceExecutionStore($this->first, $this->authority))->begin($running->id, Uuid::v7()));
        foreach ([[$pending, $running->jobId, '23505'], [$differentJob, Uuid::v7(), '23503']] as [$occurrence, $jobId, $sqlState]) {
            try {
                $this->second->executeStatement(<<<'SQL'
                    INSERT INTO scheduler_occurrence_executions (occurrence_id, job_id, attempt_id, deployment_namespace, deployment_boot_id, deployment_epoch)
                    VALUES (:occurrence, :job, :attempt, :namespace, :boot, :epoch)
                    SQL, ['occurrence' => $occurrence->id->toString(), 'job' => $jobId->toString(), 'attempt' => Uuid::v7()->toString(), 'namespace' => $this->authority->namespace, 'boot' => $this->authority->bootId, 'epoch' => $this->authority->epoch]);
                self::fail('Database constraints must reject an invalid invocation independently of store SQL.');
            } catch (DriverException $error) {
                self::assertSame($sqlState, $error->getSQLState());
            }
        }
        self::assertSame(1, (int) $this->second->fetchOne('SELECT count(*) FROM scheduler_occurrence_executions'));
    }

    public function testUnresolvedJobBlocksDistinctOccurrenceAcrossDeploymentNamespacesButAllowsOtherJobs(): void
    {
        $running = $this->seed();
        $pending = $this->seed($running->jobId, $running->scheduledFor->modify('+1 minute'));
        $otherJob = $this->seed();
        self::assertNotNull((new DoctrineSchedulerOccurrenceExecutionStore($this->first, $this->authority))->begin($running->id, Uuid::v7()));
        $otherAuthority = (new DoctrineDeploymentLease($this->second))->acquire('baander.app:other-scheduler', str_repeat('b', 32), 3600);
        self::assertNotNull($otherAuthority);
        $this->assertJobBusy($pending, $this->authority);
        $this->assertJobBusy($pending, $otherAuthority);
        self::assertNotNull((new DoctrineSchedulerOccurrenceExecutionStore($this->second, $otherAuthority))->begin($otherJob->id, Uuid::v7()));
        self::assertSame(2, (int) $this->first->fetchOne('SELECT count(*) FROM scheduler_occurrence_executions'));
        self::assertFalse($this->first->fetchOne('SELECT 1 FROM scheduler_occurrence_executions WHERE occurrence_id = :id', ['id' => $pending->id->toString()]));
    }

    public function testOnlyExactReturnReleasesJobAndOldReturnCannotReleaseNewInvocation(): void
    {
        $running = $this->seed();
        $pending = $this->seed($running->jobId, $running->scheduledFor->modify('+1 minute'));
        $later = $this->seed($running->jobId, $running->scheduledFor->modify('+2 minutes'));
        $attempt = Uuid::v7();
        $store = new DoctrineSchedulerOccurrenceExecutionStore($this->first, $this->authority);
        self::assertNotNull($store->begin($running->id, $attempt));
        self::assertFalse($store->markReturned($running->id, Uuid::v7()));
        self::assertFalse($store->markReturned($pending->id, $attempt));
        foreach ([new DeploymentLease('baander.app:other-context', $this->authority->bootId, $this->authority->epoch), new DeploymentLease($this->authority->namespace, str_repeat('b', 32), $this->authority->epoch), new DeploymentLease($this->authority->namespace, $this->authority->bootId, $this->authority->epoch + 1)] as $wrong) {
            self::assertFalse((new DoctrineSchedulerOccurrenceExecutionStore($this->second, $wrong))->markReturned($running->id, $attempt));
            $this->assertJobBusy($pending, $this->authority);
        }
        self::assertTrue($store->markReturned($running->id, $attempt));
        $nextAttempt = Uuid::v7();
        self::assertNotNull($store->begin($pending->id, $nextAttempt));
        self::assertNull($store->begin($running->id, Uuid::v7()));
        self::assertTrue($store->markReturned($running->id, $attempt));
        $this->assertJobBusy($later, $this->authority);
        self::assertTrue($store->markReturned($pending->id, $nextAttempt));
        self::assertNotNull($store->begin($later->id, Uuid::v7()));
    }

    public function testLeaseExpiryAndReplacementCannotReclaimUnresolvedJob(): void
    {
        $running = $this->seed();
        $pending = $this->seed($running->jobId, $running->scheduledFor->modify('+1 minute'));
        $attempt = Uuid::v7();
        $store = new DoctrineSchedulerOccurrenceExecutionStore($this->first, $this->authority);
        self::assertNotNull($store->begin($running->id, $attempt));
        $this->second->executeStatement("UPDATE worker_deployment_leases SET expires_at = clock_timestamp() - INTERVAL '1 second'");
        $leaseStore = new DoctrineDeploymentLease($this->second);
        self::assertNull($leaseStore->acquire($this->authority->namespace, str_repeat('b', 32), 3600));
        self::assertTrue($leaseStore->acknowledgeContainment($this->authority));
        $replacement = $leaseStore->acquire($this->authority->namespace, str_repeat('b', 32), 3600);
        self::assertNotNull($replacement);
        self::assertGreaterThan($this->authority->epoch, $replacement->epoch);
        $this->assertJobBusy($pending, $replacement);
        self::assertFalse((new DoctrineSchedulerOccurrenceExecutionStore($this->second, $replacement))->markReturned($running->id, $attempt));
        $this->assertJobBusy($pending, $replacement);
        self::assertTrue($store->markReturned($running->id, $attempt));
        self::assertNotNull((new DoctrineSchedulerOccurrenceExecutionStore($this->second, $replacement))->begin($pending->id, Uuid::v7()));
    }

    #[DataProvider('commitFailures')]
    public function testUncertainAdmissionCommitPreservesJobBlockOnlyWhenCommitted(bool $after): void
    {
        $running = $this->seed();
        $pending = $this->seed($running->jobId, $running->scheduledFor->modify('+1 minute'));
        $attempt = Uuid::v7();
        $connection = $this->uncertainConnection($after);
        try {
            (new DoctrineSchedulerOccurrenceExecutionStore($connection, $this->authority))->begin($running->id, $attempt);
            self::fail('Uncertain commit cannot acknowledge admission.');
        } catch (\RuntimeException $error) {
            self::assertSame('Fixture execution commit failed.', $error->getMessage());
        }
        self::assertFalse($connection->isConnected());
        if ($after) {
            $this->assertJobBusy($pending, $this->authority);
            self::assertTrue((new DoctrineSchedulerOccurrenceExecutionStore($this->second, $this->authority))->markReturned($running->id, $attempt));
        } else {
            self::assertSame(0, (int) $this->second->fetchOne('SELECT count(*) FROM scheduler_occurrence_executions'));
        }
        self::assertNotNull((new DoctrineSchedulerOccurrenceExecutionStore($this->second, $this->authority))->begin($pending->id, Uuid::v7()));
    }

    public function testIndependentNamespaceContenderWaitsForUncommittedJobClaimAndCannotBypassIt(): void
    {
        $running = $this->seed();
        $pending = $this->seed($running->jobId, $running->scheduledFor->modify('+1 minute'));
        $otherAuthority = (new DoctrineDeploymentLease($this->first))->acquire('baander.app:other-scheduler', str_repeat('b', 32), 3600);
        self::assertNotNull($otherAuthority);
        $this->second->beginTransaction();
        $this->second->executeStatement(<<<'SQL'
            INSERT INTO scheduler_occurrence_executions (occurrence_id, job_id, attempt_id, deployment_namespace, deployment_boot_id, deployment_epoch)
            VALUES (:occurrence, :job, :attempt, :namespace, :boot, :epoch)
            SQL, ['occurrence' => $running->id->toString(), 'job' => $running->jobId->toString(), 'attempt' => Uuid::v7()->toString(), 'namespace' => $this->authority->namespace, 'boot' => $this->authority->bootId, 'epoch' => $this->authority->epoch]);
        $started = hrtime(true);
        try {
            (new DoctrineSchedulerOccurrenceExecutionStore($this->first, $otherAuthority, 500, 50))->begin($pending->id, Uuid::v7());
            self::fail('Uncommitted claim for another occurrence must block this job in every namespace.');
        } catch (DriverException $error) {
            self::assertSame('55P03', $error->getSQLState());
        }
        self::assertLessThan(2.0, (hrtime(true) - $started) / 1e9);
        self::assertFalse($this->first->isConnected());
        $this->second->rollBack();
        $this->first->executeStatement('SET search_path TO ' . $this->schema);
        self::assertNotNull((new DoctrineSchedulerOccurrenceExecutionStore($this->first, $otherAuthority))->begin($pending->id, Uuid::v7()));
        self::assertSame(1, (int) $this->first->fetchOne('SELECT count(*) FROM scheduler_occurrence_executions'));
    }

    #[DataProvider('commitFailures')]
    public function testUncertainReturnCommitReleasesJobOnlyWhenCommitted(bool $after): void
    {
        $running = $this->seed();
        $pending = $this->seed($running->jobId, $running->scheduledFor->modify('+1 minute'));
        $attempt = Uuid::v7();
        self::assertNotNull((new DoctrineSchedulerOccurrenceExecutionStore($this->first, $this->authority))->begin($running->id, $attempt));
        $connection = $this->uncertainConnection($after);
        try {
            (new DoctrineSchedulerOccurrenceExecutionStore($connection, $this->authority))->markReturned($running->id, $attempt);
            self::fail('Uncertain return commit cannot acknowledge release.');
        } catch (\RuntimeException $error) {
            self::assertSame('Fixture execution commit failed.', $error->getMessage());
        }
        self::assertFalse($connection->isConnected());
        if (!$after) {
            $this->assertJobBusy($pending, $this->authority);
            self::assertTrue((new DoctrineSchedulerOccurrenceExecutionStore($this->second, $this->authority))->markReturned($running->id, $attempt));
        }
        $store = new DoctrineSchedulerOccurrenceExecutionStore($this->second, $this->authority);
        self::assertNotNull($store->begin($pending->id, Uuid::v7()));
        self::assertTrue($store->markReturned($running->id, $attempt));
        self::assertNull($store->begin($running->id, Uuid::v7()));
    }

    private function assertJobBusy(SchedulerOccurrence $occurrence, DeploymentLease $authority): void
    {
        try {
            (new DoctrineSchedulerOccurrenceExecutionStore($this->second, $authority))->begin($occurrence->id, Uuid::v7());
            self::fail('A distinct occurrence must retry while its job has an unresolved invocation.');
        } catch (ScheduledOccurrenceJobBusy $error) {
            self::assertSame($occurrence->jobId->toString(), $error->jobId->toString());
            self::assertSame(0, $this->second->getTransactionNestingLevel());
        }
        // The dedicated store discards failed-operation connections. Reapply only this fixture's schema on reconnect.
        if (!$this->second->isConnected()) {
            $this->second->executeStatement('SET search_path TO ' . $this->schema);
        }
    }

    private function seed(?Uuid $jobId = null, ?DateTimeImmutable $scheduledFor = null): SchedulerOccurrence
    {
        $occurrence = new SchedulerOccurrence(Uuid::v7(), $jobId ?? Uuid::v7(), $scheduledFor ?? new DateTimeImmutable('2026-10-03 00:00:00Z'), JobType::Console, 'app:baander-check', ['integral' => 1.0, 'negativeZero' => -0.0, 'large' => 1.0e18, 'nested' => [false, null, 'worker@baander.app']]);
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
