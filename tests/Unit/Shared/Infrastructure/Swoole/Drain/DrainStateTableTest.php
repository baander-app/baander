<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole\Drain;

use App\Shared\Infrastructure\Swoole\Drain\DrainStateTable;
use PHPUnit\Framework\TestCase;
use SwooleBundle\SwooleBundle\Server\HttpServerConfiguration;

final class DrainStateTableTest extends TestCase
{
    public function testTheDrainRowIsSetOnceAndReadBack(): void
    {
        $table = $this->bootedTable();
        self::assertFalse($table->isDraining());
        self::assertNull($table->startedAtNs());

        self::assertTrue($table->begin(123_456_789));
        self::assertFalse($table->begin(999_999_999));

        self::assertTrue($table->isDraining());
        self::assertSame(123_456_789, $table->startedAtNs());
    }

    public function testEveryEventWorkerMustReportDrained(): void
    {
        $table = $this->bootedTable(workers: 3);
        $table->markWorkerDrained(0);
        $table->markWorkerDrained(2);
        self::assertFalse($table->allWorkersDrained(3));

        $table->markWorkerDrained(1);
        self::assertTrue($table->allWorkersDrained(3));
        // A task worker's id is above the event workers' and is not waited for.
        self::assertFalse($table->allWorkersDrained(4));
    }

    public function testAWorkerCanWithdrawItsReportAndReportAgain(): void
    {
        $table = $this->bootedTable(workers: 2);
        $table->markWorkerDrained(0);
        $table->markWorkerDrained(1);

        $table->withdrawWorkerDrained(1);
        self::assertFalse($table->allWorkersDrained(2));

        $table->markWorkerDrained(1);
        self::assertTrue($table->allWorkersDrained(2));
    }

    public function testAWriteThatFindsNoFreeRowThrows(): void
    {
        $table = $this->bootedTable(workers: 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/^Cannot store the drain state row "worker:\d+"\.$/');
        for ($workerId = 0; $workerId < 10_000; ++$workerId) {
            $table->markWorkerDrained($workerId);
        }
    }

    public function testOutsideTheServerNothingIsDrainingAndWritesThrow(): void
    {
        $table = new DrainStateTable();
        self::assertFalse($table->isDraining());
        self::assertNull($table->startedAtNs());
        self::assertFalse($table->allWorkersDrained(1));

        $this->expectException(\LogicException::class);
        $table->begin(1);
    }

    private function bootedTable(int $workers = 4): DrainStateTable
    {
        $configuration = $this->createStub(HttpServerConfiguration::class);
        $configuration->method('getWorkerCount')->willReturn($workers);
        $table = new DrainStateTable($configuration);
        $table->boot();

        return $table;
    }
}
