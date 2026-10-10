<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use App\Shared\Infrastructure\Swoole\WorkerMemoryReporter;
use App\Shared\Infrastructure\Swoole\WorkerMemoryTable;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Swoole\Server;
use Swoole\Timer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStoppedEvent;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WorkerMemoryReporterTest extends TestCase
{
    protected function tearDown(): void
    {
        Timer::clearAll();
    }

    public function testAnHttpWorkerReportsAtOnceAndKeepsATimerUntilItStops(): void
    {
        $table = new WorkerMemoryTable();
        $table->boot();
        $reporter = new WorkerMemoryReporter($table);
        $server = new Server('127.0.0.1', 0);

        $reporter->onWorkerStarted(new WorkerStartedEvent($server, 3));

        $row = $table->largest(time());
        self::assertNotNull($row);
        self::assertSame(3, $row['workerId']);
        self::assertGreaterThan(0, $row['bytes']);
        self::assertSame(1, Timer::stats()['num']);

        $reporter->onWorkerStopped(new WorkerStoppedEvent($server, 3));

        self::assertSame(0, Timer::stats()['num']);
    }

    public function testATaskWorkerReportsNothing(): void
    {
        $table = new WorkerMemoryTable();
        $table->boot();
        $reporter = new WorkerMemoryReporter($table);
        $server = new Server('127.0.0.1', 0);
        $server->taskworker = true;

        $reporter->onWorkerStarted(new WorkerStartedEvent($server, 5));
        $reporter->onWorkerStopped(new WorkerStoppedEvent($server, 5));

        self::assertNull($table->largest(time()));
        self::assertSame(0, Timer::stats()['num']);
    }
}
