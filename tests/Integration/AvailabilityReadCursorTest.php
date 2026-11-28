<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Infrastructure\Doctrine\Query\AvailabilityReadCursor;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AvailabilityReadCursorTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Requires disposable PostgreSQL.');
        }
        $this->connection = ArtistImportIdentityRace::connect('public');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            while ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $this->connection->close();
        }
    }

    public function testOneSortedScanVisitsTenThousandCandidatesOnceInBoundedBatches(): void
    {
        $this->connection->executeStatement('CREATE TEMP SEQUENCE availability_evaluations');
        $seen = 0;
        $batches = 0;
        $previous = [-1, -1];
        foreach (AvailabilityReadCursor::batches(
            $this->connection,
            "SELECT n AS id, n % 7 AS tie FROM generate_series(1, :count) n WHERE nextval('availability_evaluations') > 0 ORDER BY tie, id",
            ['count' => 10000],
            ['count' => 'integer'],
            100,
        ) as $rows) {
            ++$batches;
            self::assertCount(100, $rows);
            self::assertSame(1, $this->connection->getTransactionNestingLevel());
            $cursor = $this->connection->fetchAssociative("SELECT statement FROM pg_cursors WHERE name LIKE 'availability_scan_%'");
            self::assertIsArray($cursor);
            self::assertStringNotContainsString('OFFSET', $cursor['statement']);
            foreach ($rows as $row) {
                $current = [(int) $row['tie'], (int) $row['id']];
                self::assertTrue($current > $previous);
                $previous = $current;
                ++$seen;
            }
        }
        self::assertSame(10000, $seen);
        self::assertSame(100, $batches);
        self::assertSame(10000, (int) $this->connection->fetchOne('SELECT last_value FROM availability_evaluations'));
        self::assertFalse($this->connection->isTransactionActive());
        $this->assertNoCursor();
    }

    public function testEarlyReturnAndConsumerFailureCloseTheirCursorAndOwnedTransaction(): void
    {
        foreach ([false, true] as $fail) {
            try {
                foreach (AvailabilityReadCursor::batches($this->connection, 'SELECT n FROM generate_series(1, 251) n ORDER BY n', [], [], 100) as $rows) {
                    self::assertCount(100, $rows);
                    if ($fail) {
                        throw new RuntimeException('Consumer failed.');
                    }
                    break;
                }
            } catch (RuntimeException $error) {
                self::assertSame('Consumer failed.', $error->getMessage());
            }
            self::assertFalse($this->connection->isTransactionActive());
            $this->assertNoCursor();
        }
    }

    public function testCallerTransactionRemainsOpenAndKeepsItsUncommittedWrites(): void
    {
        $this->connection->executeStatement('CREATE TEMP TABLE availability_caller (id INTEGER)');
        $this->connection->beginTransaction();
        $this->connection->executeStatement('INSERT INTO availability_caller VALUES (1)');
        foreach ([false, true] as $fail) {
            $seen = 0;
            try {
                foreach (AvailabilityReadCursor::batches($this->connection, 'SELECT n FROM generate_series(1, 251) n ORDER BY n', [], [], 100) as $rows) {
                    self::assertLessThanOrEqual(100, count($rows));
                    $seen += count($rows);
                    if ($fail) {
                        throw new RuntimeException('Consumer failed.');
                    }
                }
            } catch (RuntimeException $error) {
                self::assertSame('Consumer failed.', $error->getMessage());
            }
            if (!$fail) {
                self::assertSame(251, $seen);
            }
            self::assertSame(1, $this->connection->getTransactionNestingLevel());
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM availability_caller'));
            $this->assertNoCursor();
        }
        $this->connection->rollBack();
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM availability_caller'));
    }

    public function testDatabaseFailureKeepsItsSqlstateAndLeavesCallerRollbackToTheCaller(): void
    {
        foreach ([false, true] as $callerTransaction) {
            if ($callerTransaction) {
                $this->connection->beginTransaction();
            }
            try {
                foreach (AvailabilityReadCursor::batches($this->connection, 'SELECT n FROM generate_series(1, 251) n ORDER BY n', [], [], 100) as $rows) {
                    $this->connection->executeStatement('SELECT 1 / 0');
                }
                self::fail('The database failure must escape the scan.');
            } catch (DriverException $error) {
                self::assertSame('22012', $error->getSQLState());
            }
            self::assertSame($callerTransaction, $this->connection->isTransactionActive());
            if ($callerTransaction) {
                $this->connection->rollBack();
            }
            $this->assertNoCursor();
        }
    }

    private function assertNoCursor(): void
    {
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM pg_cursors WHERE name LIKE 'availability_scan_%'"));
    }
}
