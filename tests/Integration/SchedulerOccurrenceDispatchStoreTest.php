<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Scheduler\Application\DTO\SchedulerOccurrence;
use App\Scheduler\Application\DTO\SchedulerOccurrenceDispatchClaim;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerOccurrenceDispatchStore;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerOccurrenceStore;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Independent PostgreSQL observers prove committed reservations, not job execution or broker acceptance. */
final class SchedulerOccurrenceDispatchStoreTest extends TestCase
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
        $this->schema = 'scheduler_dispatch_test_' . bin2hex(random_bytes(8));
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

    public function testCommittedReservationRetryReplacementAndStableReceipt(): void
    {
        $id = $this->seed();
        $store = new DoctrineSchedulerOccurrenceDispatchStore($this->first);
        $claims = $store->claimPending(1, 3600);
        self::assertCount(1, $claims);
        self::assertSame($id->toString(), $claims[0]->occurrenceId->toString());
        self::assertSame($claims[0]->token->toString(), $this->second->fetchOne('SELECT dispatch_token FROM scheduler_occurrences'));
        self::assertSame([], (new DoctrineSchedulerOccurrenceDispatchStore($this->second))->claimPending());
        $this->expireReservations();
        $replacement = $store->claimPending()[0];
        self::assertNotSame($claims[0]->token->toString(), $replacement->token->toString());
        self::assertFalse($store->markPublished($claims[0]));
        $this->expireReservations();
        // Expiry without a replacement does not invalidate transport acceptance.
        self::assertTrue($store->markPublished($replacement));
        $timestamp = $this->second->fetchOne('SELECT dispatched_at FROM scheduler_occurrences');
        self::assertNotNull($timestamp);
        self::assertTrue($store->markPublished($replacement));
        self::assertSame($timestamp, $this->second->fetchOne('SELECT dispatched_at FROM scheduler_occurrences'));
        self::assertSame([], $store->claimPending());
        self::assertFalse($store->markPublished(new SchedulerOccurrenceDispatchClaim(Uuid::v7(), Uuid::v7())));
    }

    public function testFutureRetryFutureOccurrenceAndCommittedExecutionsAreExcluded(): void
    {
        $retry = $this->seed();
        $this->seed(new \DateTimeImmutable('tomorrow UTC'));
        $executed = $this->seed();
        $this->second->executeStatement('UPDATE scheduler_occurrences SET dispatch_after = clock_timestamp() + INTERVAL \'1 day\' WHERE id = :id', ['id' => $retry->toString()]);
        $this->second->executeStatement("INSERT INTO scheduler_occurrence_executions (occurrence_id, attempt_id, deployment_namespace, deployment_boot_id, deployment_epoch) VALUES (:id, :attempt, 'baander.app:dispatch-test', :boot, 1)", ['id' => $executed->toString(), 'attempt' => Uuid::v7()->toString(), 'boot' => str_repeat('a', 32)]);
        self::assertSame([], (new DoctrineSchedulerOccurrenceDispatchStore($this->first))->claimPending());
        self::assertSame(1, (int) $this->second->fetchOne('SELECT count(*) FROM scheduler_occurrence_executions'));
    }

    public function testLockedCandidateIsSkippedAndFairOrderIsDeterministic(): void
    {
        $ids = [$this->seed(), $this->seed(), $this->seed()];
        $this->second->executeStatement("UPDATE scheduler_occurrences SET dispatch_after = '2026-01-01 UTC'");
        usort($ids, static fn (Uuid $a, Uuid $b): int => strcmp($a->toString(), $b->toString()));
        $this->second->beginTransaction();
        $this->second->fetchOne('SELECT id FROM scheduler_occurrences WHERE id = :id FOR UPDATE', ['id' => $ids[0]->toString()]);
        $claims = (new DoctrineSchedulerOccurrenceDispatchStore($this->first))->claimPending(100, 3600);
        self::assertSame([$ids[1]->toString(), $ids[2]->toString()], array_map(static fn (SchedulerOccurrenceDispatchClaim $claim): string => $claim->occurrenceId->toString(), $claims));
        $this->second->rollBack();
        self::assertSame($ids[0]->toString(), (new DoctrineSchedulerOccurrenceDispatchStore($this->first))->claimPending()[0]->occurrenceId->toString());
    }

    public function testBeforeCommitFailureRollsBackReservationAndDiscardsConnection(): void
    {
        $id = $this->seed();
        $connection = $this->failingCommit(false);
        try {
            (new DoctrineSchedulerOccurrenceDispatchStore($connection))->claimPending();
            self::fail('Expected commit failure.');
        } catch (\RuntimeException $error) {
            self::assertSame('injected commit failure', $error->getMessage());
        }
        self::assertFalse($connection->isConnected());
        self::assertNull($this->second->fetchOne('SELECT dispatch_token FROM scheduler_occurrences'));
        self::assertSame($id->toString(), (new DoctrineSchedulerOccurrenceDispatchStore($this->second))->claimPending()[0]->occurrenceId->toString());
    }

    public function testLostCommitAcknowledgmentRetainsCooldownAndCanRetryAfterExpiry(): void
    {
        $id = $this->seed();
        $connection = $this->failingCommit(true);
        try {
            (new DoctrineSchedulerOccurrenceDispatchStore($connection))->claimPending();
            self::fail('Expected unknown commit result.');
        } catch (\RuntimeException $error) {
            self::assertSame('injected commit failure', $error->getMessage());
        }
        self::assertFalse($connection->isConnected());
        self::assertNotNull($this->second->fetchOne('SELECT dispatch_token FROM scheduler_occurrences'));
        self::assertSame([], (new DoctrineSchedulerOccurrenceDispatchStore($this->second))->claimPending());
        $this->expireReservations();
        self::assertSame($id->toString(), (new DoctrineSchedulerOccurrenceDispatchStore($this->second))->claimPending()[0]->occurrenceId->toString());
    }

    #[DataProvider('receiptCommitOutcomes')]
    public function testReceiptCommitFailurePreservesActualCommittedOutcome(bool $committed): void
    {
        $this->seed();
        $claim = (new DoctrineSchedulerOccurrenceDispatchStore($this->first))->claimPending(1, 1)[0];
        $connection = $this->failingCommit($committed);
        try {
            (new DoctrineSchedulerOccurrenceDispatchStore($connection))->markPublished($claim);
            self::fail('Expected receipt commit failure.');
        } catch (\RuntimeException $error) {
            self::assertSame('injected commit failure', $error->getMessage());
        }
        self::assertFalse($connection->isConnected());
        $receipt = $this->second->fetchOne('SELECT dispatched_at FROM scheduler_occurrences');
        $observer = new DoctrineSchedulerOccurrenceDispatchStore($this->second);
        $this->expireReservations();
        if ($committed) {
            self::assertNotNull($receipt);
            self::assertSame([], $observer->claimPending());
            self::assertTrue($observer->markPublished($claim));
            self::assertSame($receipt, $this->second->fetchOne('SELECT dispatched_at FROM scheduler_occurrences'));
        } else {
            self::assertNull($receipt);
            $replacement = $observer->claimPending()[0];
            self::assertNotSame($claim->token->toString(), $replacement->token->toString());
            self::assertFalse($observer->markPublished($claim));
            self::assertTrue($observer->markPublished($replacement));
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function receiptCommitOutcomes(): iterable
    {
        yield 'rollback before commit' => [false];
        yield 'acknowledgment lost after commit' => [true];
    }

    public function testCallerTransactionCannotClaimOrPublish(): void
    {
        $id = $this->seed();
        $this->first->beginTransaction();
        $store = new DoctrineSchedulerOccurrenceDispatchStore($this->first);
        foreach ([fn () => $store->claimPending(), fn () => $store->markPublished(new SchedulerOccurrenceDispatchClaim($id, Uuid::v7()))] as $operation) {
            try {
                $operation();
                self::fail('Caller transaction must not be committed.');
            } catch (\LogicException) {
                self::assertSame(1, $this->first->getTransactionNestingLevel());
            }
        }
        $this->first->rollBack();
        self::assertNull($this->second->fetchOne('SELECT dispatch_token FROM scheduler_occurrences'));
    }

    #[DataProvider('invalidBounds')]
    public function testBoundsRejectBeforeMutation(int $limit, int $retry): void
    {
        $this->seed();
        try {
            (new DoctrineSchedulerOccurrenceDispatchStore($this->first))->claimPending($limit, $retry);
            self::fail('Expected invalid bounds.');
        } catch (\InvalidArgumentException) {
            self::assertNull($this->second->fetchOne('SELECT dispatch_token FROM scheduler_occurrences'));
        }
    }

    /** @return iterable<string, array{int, int}> */
    public static function invalidBounds(): iterable
    {
        yield 'zero limit' => [0, 60];
        yield 'oversized limit' => [101, 60];
        yield 'zero retry' => [100, 0];
        yield 'oversized retry' => [100, 3601];
    }

    public function testMaximumBatchLeavesOneEligibleIntentForNextClaim(): void
    {
        for ($i = 0; $i < 101; ++$i) {
            $this->seed();
        }
        $store = new DoctrineSchedulerOccurrenceDispatchStore($this->first);
        self::assertCount(100, $store->claimPending(100, 3600));
        self::assertCount(1, $store->claimPending(100, 3600));
    }

    private function seed(?\DateTimeImmutable $minute = null): Uuid
    {
        $occurrence = new SchedulerOccurrence(Uuid::v7(), Uuid::v7(), $minute ?? new \DateTimeImmutable('2026-01-01 UTC'), JobType::Console, 'app:test', []);
        self::assertTrue((new DoctrineSchedulerOccurrenceStore($this->first))->record($occurrence));
        return $occurrence->id;
    }

    private function expireReservations(): void
    {
        $this->second->executeStatement("UPDATE scheduler_occurrences SET dispatch_after = clock_timestamp() - INTERVAL '1 second'");
    }

    private function failingCommit(bool $committed): Connection
    {
        $connection = new class($this->params, $this->first->getDriver()) extends Connection {
            public bool $committed = false;
            public function commit(): void
            {
                if ($this->committed) {
                    parent::commit();
                }
                throw new \RuntimeException('injected commit failure');
            }
        };
        $connection->committed = $committed;
        $connection->executeStatement('SET search_path TO ' . $this->schema);
        $this->extras[] = $connection;
        return $connection;
    }

    protected function tearDown(): void
    {
        if (!isset($this->first)) {
            return;
        }
        foreach ([$this->first, $this->second, ...$this->extras] as $connection) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $connection->close();
        }
        $cleanup = DriverManager::getConnection($this->params);
        $cleanup->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        $cleanup->close();
    }
}
