<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler\Application\Service;

use App\Scheduler\Application\Port\SchedulerOccurrenceMaterializerInterface;
use App\Scheduler\Application\Port\SchedulerRecoveryScheduleStoreInterface;
use App\Scheduler\Application\Service\SchedulerRecoveryPoller;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SchedulerRecoveryPollerTest extends TestCase
{
    public function testPoisonAndUncertainJobsDoNotStarveRemainingReservedJobs(): void
    {
        $jobs = [Uuid::v7(), Uuid::v7(), Uuid::v7()];
        $store = $this->createMock(SchedulerRecoveryScheduleStoreInterface::class);
        $store->expects(self::once())->method('claimPendingJobs')->with(3, 90)->willReturn($jobs);
        $materializer = $this->createMock(SchedulerOccurrenceMaterializerInterface::class);
        $cause = new \UnexpectedValueException('Fixture invalid schedule.');
        $attempted = [];
        $materializer->expects(self::exactly(3))->method('materialize')->willReturnCallback(
            static function (Uuid $jobId, int $limit) use ($jobs, $cause, &$attempted): int {
                $attempted[] = $jobId;
                self::assertSame(2, $limit);
                if ($jobId === $jobs[0]) {
                    throw $cause;
                }
                if ($jobId === $jobs[1]) {
                    throw new \RuntimeException('Fixture uncertain recovery commit.');
                }
                return 2;
            },
        );
        try {
            (new SchedulerRecoveryPoller($store, $materializer))->recoverPending(3, 2, 90);
            self::fail('Per-job errors must remain visible after the rest of the pass.');
        } catch (\RuntimeException $error) {
            self::assertSame('Scheduler recovery failed for 2 of 3 schedules; 2 new intents acknowledged.', $error->getMessage());
            self::assertSame($cause, $error->getPrevious());
        }
        self::assertSame($jobs, $attempted);
    }

    public function testClaimFailureDoesNotMaterializeAnySchedule(): void
    {
        $store = $this->createMock(SchedulerRecoveryScheduleStoreInterface::class);
        $cause = new \RuntimeException('Fixture claim commit acknowledgment lost.');
        $store->expects(self::once())->method('claimPendingJobs')->willThrowException($cause);
        $materializer = $this->createMock(SchedulerOccurrenceMaterializerInterface::class);
        $materializer->expects(self::never())->method('materialize');
        $this->expectExceptionObject($cause);
        (new SchedulerRecoveryPoller($store, $materializer))->recoverPending();
    }

    public function testSuccessfulPassReturnsNewIntentsRatherThanJobCount(): void
    {
        $jobs = [Uuid::v7(), Uuid::v7()];
        $store = $this->createMock(SchedulerRecoveryScheduleStoreInterface::class);
        $store->expects(self::once())->method('claimPendingJobs')->with(2, 60)->willReturn($jobs);
        $materializer = $this->createMock(SchedulerOccurrenceMaterializerInterface::class);
        $materializer->expects(self::exactly(2))->method('materialize')->willReturnOnConsecutiveCalls(0, 3);
        self::assertSame(3, (new SchedulerRecoveryPoller($store, $materializer))->recoverPending(2, 3));
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function invalidBudgets(): iterable
    {
        yield 'zero jobs' => [0, 60, 60];
        yield 'too many jobs' => [101, 1, 60];
        yield 'zero minutes' => [1, 0, 60];
        yield 'too many minutes' => [1, 1001, 60];
        yield 'pass product exceeded' => [2, 501, 60];
        yield 'zero retry delay' => [1, 1, 0];
        yield 'too much retry delay' => [1, 1, 3601];
        yield 'integer ceiling' => [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX];
    }

    #[DataProvider('invalidBudgets')]
    public function testInvalidBudgetsAreRejectedBeforeReservingJobs(int $jobs, int $minutes, int $retry): void
    {
        $store = $this->createMock(SchedulerRecoveryScheduleStoreInterface::class);
        $store->expects(self::never())->method('claimPendingJobs');
        $materializer = $this->createMock(SchedulerOccurrenceMaterializerInterface::class);
        $materializer->expects(self::never())->method('materialize');
        $this->expectException(\InvalidArgumentException::class);
        (new SchedulerRecoveryPoller($store, $materializer))->recoverPending($jobs, $minutes, $retry);
    }
}
