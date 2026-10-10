<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use App\Shared\Infrastructure\Swoole\WorkerMemoryTable;
use PHPUnit\Framework\TestCase;
use SwooleBundle\SwooleBundle\Server\HttpServerConfiguration;

final class WorkerMemoryTableTest extends TestCase
{
    public function testEveryHttpWorkerOfTheConfiguredCountGetsARow(): void
    {
        $table = $this->bootedTable(workers: 32);

        for ($workerId = 0; $workerId < 32; ++$workerId) {
            self::assertTrue($table->record($workerId, 1_000 + $workerId, 100), sprintf('worker %d', $workerId));
        }

        self::assertSame(['workerId' => 31, 'bytes' => 1_031], $table->largest(100));
    }

    public function testTheLargestFreshRowWins(): void
    {
        $table = $this->bootedTable();
        $table->record(0, 300, 100);
        $table->record(1, 950, 100);
        $table->record(2, 500, 100);

        self::assertSame(['workerId' => 1, 'bytes' => 950], $table->largest(100));
    }

    public function testARowOlderThanThreeUpdateIntervalsIsIgnored(): void
    {
        $table = $this->bootedTable();
        $staleAfter = 3 * WorkerMemoryTable::UPDATE_INTERVAL_SECONDS;
        $table->record(0, 300, 1_000);
        $table->record(1, 950, 1_000 - $staleAfter - 1);
        $table->record(2, 400, 1_000 - $staleAfter);

        self::assertSame(['workerId' => 2, 'bytes' => 400], $table->largest(1_000));
        self::assertNull($table->largest(1_000 + 10 * $staleAfter));
    }

    public function testARecordReplacesTheWorkersPreviousRow(): void
    {
        $table = $this->bootedTable();
        $table->record(0, 950, 100);
        $table->record(0, 200, 101);

        self::assertSame(['workerId' => 0, 'bytes' => 200], $table->largest(101));
    }

    public function testOutsideTheServerNothingIsStored(): void
    {
        $table = new WorkerMemoryTable();

        self::assertFalse($table->record(0, 300, 100));
        self::assertNull($table->largest(100));
    }

    private function bootedTable(int $workers = 4): WorkerMemoryTable
    {
        $configuration = $this->createStub(HttpServerConfiguration::class);
        $configuration->method('getWorkerCount')->willReturn($workers);
        $table = new WorkerMemoryTable($configuration);
        $table->boot();

        return $table;
    }
}
