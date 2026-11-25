<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole\Control;

use App\Shared\Application\Port\ServerControlPortInterface;
use App\Shared\Application\Port\ServerControlResult;
use App\Tests\Fixtures\WebRuntime\WorkerRequestCounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** The evidence the request-identity drill accepts that a group of requests reached every worker. */
final class WorkerRequestCountsTest extends TestCase
{
    public function testAReadingHoldsEachWorkersProcessAndRequestCount(): void
    {
        $counts = WorkerRequestCounts::read($this->server(new ServerControlResult([
            0 => ['process' => ['pid' => 41], 'requests' => ['received' => 7]],
            1 => ['process' => ['pid' => 42], 'requests' => ['received' => 0]],
        ])));

        self::assertSame([0 => ['pid' => 41, 'received' => 7], 1 => ['pid' => 42, 'received' => 0]], $counts->workers);
    }

    /** @return iterable<string, array{ServerControlResult}> */
    public static function incompleteReadings(): iterable
    {
        $worker = ['process' => ['pid' => 41], 'requests' => ['received' => 7]];
        yield 'a worker that did not answer' => [new ServerControlResult([0 => $worker], [], [1])];
        yield 'a worker whose figures could not be read' => [new ServerControlResult([0 => $worker], [1 => 'stats unavailable'])];
        yield 'a worker without a request count' => [new ServerControlResult([0 => $worker, 1 => ['process' => ['pid' => 42], 'requests' => ['received' => null]]])];
    }

    #[DataProvider('incompleteReadings')]
    public function testAReadingThatLacksAWorkerIsRefused(ServerControlResult $result): void
    {
        $this->expectException(RuntimeException::class);

        WorkerRequestCounts::read($this->server($result));
    }

    public function testNothingIsReportedWhenEveryWorkerReceivedMoreThanTheEarlierReadingItself(): void
    {
        $before = self::counts([0 => [41, 10], 1 => [42, 3], 2 => [43, 0]]);
        $after = self::counts([0 => [41, 12], 1 => [42, 9], 2 => [43, 6]]);

        self::assertSame([], $before->workersNotShownToHaveServed($after));
    }

    public function testAWorkerWhoseCountRoseByOneIsReportedBecauseTheEarlierReadingIsOneRequest(): void
    {
        $before = self::counts([0 => [41, 10], 1 => [42, 3]]);
        $after = self::counts([0 => [41, 16], 1 => [42, 4]]);

        self::assertSame(
            ['the request count of worker 1 went from 3 to 4, which the reading before can account for'],
            $before->workersNotShownToHaveServed($after),
        );
    }

    public function testAnIdleOrReplacedOrAbsentWorkerIsReported(): void
    {
        $before = self::counts([0 => [41, 10], 1 => [42, 3], 2 => [43, 5]]);
        $after = self::counts([0 => [41, 10], 1 => [52, 9], 3 => [44, 8]]);

        self::assertSame(
            [
                'the request count of worker 0 went from 10 to 10, which the reading before can account for',
                'worker 1 was replaced (PID 42, then 52)',
                'worker 2 reported in one reading only',
                'worker 3 reported in one reading only',
            ],
            $before->workersNotShownToHaveServed($after),
        );
    }

    /** A comparison stands only when both of its sides reached every worker. */
    public function testEitherSideOfAComparisonCanMissAWorker(): void
    {
        $before = self::counts([0 => [41, 0], 1 => [42, 0]]);
        $between = self::counts([0 => [41, 6], 1 => [42, 6]]);
        $after = self::counts([0 => [41, 13], 1 => [42, 7]]);

        self::assertSame([], $before->workersNotShownToHaveServed($between));
        self::assertCount(1, $between->workersNotShownToHaveServed($after));
        self::assertCount(1, self::counts([0 => [41, 0], 1 => [42, 5]])->workersNotShownToHaveServed($between));
    }

    /** @param array<int, array{int, int}> $workers worker ID => PID and requests received */
    private static function counts(array $workers): WorkerRequestCounts
    {
        return new WorkerRequestCounts(array_map(
            static fn (array $worker): array => ['pid' => $worker[0], 'received' => $worker[1]],
            $workers,
        ));
    }

    private function server(ServerControlResult $result): ServerControlPortInterface
    {
        $server = $this->createStub(ServerControlPortInterface::class);
        $server->method('execute')->willReturn($result);

        return $server;
    }
}
