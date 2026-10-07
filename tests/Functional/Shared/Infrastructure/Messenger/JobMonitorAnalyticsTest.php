<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Infrastructure\Messenger;

use App\Shared\Infrastructure\Messenger\JobMonitorService;
use App\Tests\Functional\TestCase;

/**
 * Analytics count the jobs created in the half-open range [from, to): a job created exactly at
 * `to` belongs to the next range, and one created a microsecond before it to this one.
 */
final class JobMonitorAnalyticsTest extends TestCase
{
    private JobMonitorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = static::getContainer()->get(JobMonitorService::class);
        $connection = $this->entityManager->getConnection();
        // failedAt carries the session's offset; the assertions expect UTC.
        $connection->executeStatement("SET LOCAL TIME ZONE 'UTC'");
        // [job_id, status, created_at, started_at, finished_at, retried, exception_class, exception]
        $rows = [
            ['before-range', 'finished', '2026-03-15 09:59:59.999999', '2026-03-15 10:00:00', '2026-03-15 10:00:02', false, null, null],
            ['at-from', 'finished', '2026-03-15 10:00:00', '2026-03-15 10:00:01', '2026-03-15 10:00:01.25', false, null, null],
            ['inside', 'failed', '2026-03-15 10:30:00', '2026-03-15 10:30:00.5', '2026-03-15 10:30:05', true, 'RuntimeException', '{"message": "inside failed"}'],
            ['last-microsecond', 'failed', '2026-03-15 10:59:59.999999', '2026-03-15 11:00:00', '2026-03-15 11:00:00.5', false, 'LogicException', '{"message": "last-microsecond failed"}'],
            ['at-to', 'finished', '2026-03-15 11:00:00', '2026-03-15 11:00:00', '2026-03-15 11:00:03', false, null, null],
        ];
        foreach ($rows as [$jobId, $status, $createdAt, $startedAt, $finishedAt, $retried, $exceptionClass, $exception]) {
            $connection->executeStatement(
                "INSERT INTO job_monitors (id, job_id, name, queue, status, queued_at, started_at, finished_at, attempt, retried,
                                           exception_class, exception, created_at, updated_at)
                 VALUES (gen_random_uuid(), :job_id, 'AnalyticsProbe', 'async', :status, :created_at, :started_at, :finished_at, 1, :retried, :exception_class, :exception, :created_at, :created_at)",
                [
                    'job_id' => $jobId,
                    'status' => $status,
                    'created_at' => $createdAt . '+00',
                    'started_at' => $startedAt . '+00',
                    'finished_at' => $finishedAt . '+00',
                    'retried' => $retried ? 'true' : 'false',
                    'exception_class' => $exceptionClass,
                    'exception' => $exception,
                ],
            );
        }
    }

    public function testTheRangeIncludesItsStartAndExcludesItsEnd(): void
    {
        [$from, $to] = $this->range('2026-03-15T10:00:00Z', '2026-03-15T11:00:00Z');

        self::assertSame(['failed' => 2, 'finished' => 1], $this->sorted($this->service->countByStatusAndDateRange($from, $to)));

        $summary = $this->service->getAnalyticsSummary($from, $to);
        self::assertSame(['cancelled' => 0, 'failed' => 2, 'finished' => 1, 'queued' => 0, 'running' => 0], $this->sorted($summary['statusCounts']));
        self::assertSame([['name' => 'AnalyticsProbe', 'count' => 3]], $summary['jobTypeBreakdown']);
        self::assertSame(0.3333, $summary['successRate']);
        self::assertSame(3.0, $summary['throughputPerHour']);
    }

    public function testConsecutiveRangesCountEveryJobOnce(): void
    {
        $counts = [];
        foreach ([['09:00:00', '10:00:00'], ['10:00:00', '10:59:59.999999'], ['10:59:59.999999', '11:00:00'], ['11:00:00', '12:00:00']] as [$start, $end]) {
            [$from, $to] = $this->range('2026-03-15T' . $start . 'Z', '2026-03-15T' . $end . 'Z');
            $counts[] = array_sum($this->service->countByStatusAndDateRange($from, $to));
        }

        self::assertSame([1, 2, 1, 1], $counts);
    }

    public function testAnOffsetNamesTheSameInstantAsUtc(): void
    {
        [$from, $to] = $this->range('2026-03-15T12:00:00+02:00', '2026-03-15T13:00:00+02:00');

        self::assertSame(['failed' => 2, 'finished' => 1], $this->sorted($this->service->countByStatusAndDateRange($from, $to)));
        self::assertSame(2, $this->service->getAnalyticsFailures($from, $to)['retryFrequency']['total']);
    }

    public function testTimingKeepsSubSecondDurations(): void
    {
        [$from, $to] = $this->range('2026-03-15T10:00:00Z', '2026-03-15T11:00:00Z');

        self::assertSame([
            'executionTimes' => [['name' => 'AnalyticsProbe', 'avg' => 0.25, 'median' => 0.25, 'p95' => 0.25]],
            'queueLatency' => [['name' => 'AnalyticsProbe', 'avg' => 1.0]],
        ], $this->service->getAnalyticsTiming($from, $to));
    }

    public function testFailuresCoverTheRangeAndReportRfc3339FailureTimes(): void
    {
        [$from, $to] = $this->range('2026-03-15T10:00:00Z', '2026-03-15T11:00:00Z');

        self::assertSame([
            'topFailingTypes' => [['name' => 'AnalyticsProbe', 'count' => 2]],
            'topExceptionClasses' => [['class' => 'LogicException', 'count' => 1], ['class' => 'RuntimeException', 'count' => 1]],
            'retryFrequency' => ['retried' => 1, 'total' => 2, 'rate' => 0.5],
            'recentFailures' => [
                ['jobId' => 'last-microsecond', 'name' => 'AnalyticsProbe', 'exceptionClass' => 'LogicException', 'exceptionMessage' => 'last-microsecond failed', 'failedAt' => '2026-03-15T11:00:00+00:00'],
                ['jobId' => 'inside', 'name' => 'AnalyticsProbe', 'exceptionClass' => 'RuntimeException', 'exceptionMessage' => 'inside failed', 'failedAt' => '2026-03-15T10:30:05+00:00'],
            ],
        ], $this->sortedExceptionClasses($this->service->getAnalyticsFailures($from, $to)));
    }

    /** @return array{\DateTimeImmutable, \DateTimeImmutable} */
    private function range(string $from, string $to): array
    {
        return [new \DateTimeImmutable($from), new \DateTimeImmutable($to)];
    }

    /**
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private function sorted(array $counts): array
    {
        ksort($counts);

        return $counts;
    }

    /**
     * Equal counts have no defined order.
     *
     * @param array<string, mixed> $failures
     * @return array<string, mixed>
     */
    private function sortedExceptionClasses(array $failures): array
    {
        usort($failures['topExceptionClasses'], static fn (array $left, array $right): int => $left['class'] <=> $right['class']);

        return $failures;
    }
}
