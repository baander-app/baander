<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Infrastructure\Messenger;

use App\Shared\Infrastructure\Doctrine\Entity\JobMonitorEntity;
use App\Shared\Infrastructure\Messenger\JobMonitorFilter;
use App\Shared\Infrastructure\Messenger\JobMonitorService;
use App\Tests\Functional\TestCase;

/**
 * Ascending job pages walk forward with Next cursors and back with Prev
 * cursors; jobs created in the same second are ordered by id. Duration pages
 * follow finished_at - started_at, with unmeasured jobs lowest.
 */
final class JobMonitorCursorPaginationTest extends TestCase
{
    private const CREATED = [
        'job-a' => '2026-02-01 08:00:00',
        'job-b' => '2026-02-01 09:00:00',
        'job-c' => '2026-02-01 08:00:00',
        'job-d' => '2026-02-01 10:00:00',
        'job-e' => '2026-02-01 09:00:00',
    ];

    /** @var list<string> job ids in (createdAt, id) order */
    private array $expected = [];

    protected function setUp(): void
    {
        parent::setUp();
        $rows = [];
        foreach (array_keys(self::CREATED) as $jobId) {
            $job = new JobMonitorEntity($jobId, name: 'cursor-test', queue: 'default');
            $this->entityManager->persist($job);
            $rows[$jobId] = [self::CREATED[$jobId], $job->getId()->toString()];
        }
        $this->entityManager->flush();

        $connection = $this->entityManager->getConnection();
        foreach ($rows as [$created, $id]) {
            $connection->executeStatement('UPDATE job_monitors SET created_at = :created WHERE id = :id', ['created' => $created, 'id' => $id]);
        }
        $this->entityManager->clear();

        uasort($rows, static fn (array $left, array $right): int => $left <=> $right);
        $this->expected = array_map('strval', array_keys($rows));
    }

    public function testAscendingPagesWalkForwardAndBack(): void
    {
        $service = static::getContainer()->get(JobMonitorService::class);
        $filter = new JobMonitorFilter(status: null, name: 'cursor-test', queue: null);

        $pages = [];
        $cursor = null;
        do {
            $page = $service->findWithCursor($filter, $cursor, 2, 'createdAt', 'asc');
            self::assertSame($pages !== [], $page->hasPreviousPage);
            $pages[] = array_map(static fn (JobMonitorEntity $job): string => $job->getJobId(), $page->items);
            $cursor = $page->nextCursor;
        } while ($page->hasNextPage && count($pages) <= count(self::CREATED));

        self::assertSame($this->expected, array_merge(...$pages));
        self::assertCount(3, $pages);

        $seen = end($pages);
        $cursor = $page->prevCursor;
        while ($cursor !== null) {
            $page = $service->findWithCursor($filter, $cursor, 2, 'createdAt', 'asc');
            self::assertTrue($page->hasNextPage);
            $seen = array_merge(array_map(static fn (JobMonitorEntity $job): string => $job->getJobId(), $page->items), $seen);
            $cursor = $page->hasPreviousPage ? $page->prevCursor : null;
        }

        self::assertSame($this->expected, $seen);
    }

    public function testDescendingPagesStartWithTheNewestJobAndWalkForwardAndBack(): void
    {
        $expected = array_reverse($this->expected);

        self::assertSame($expected, $this->walkForward('createdAt', 'desc'));
        self::assertSame($expected, $this->walkBackFromLastPage('createdAt', 'desc'));
    }

    public function testJobsCreatedWithinOneSecondAreOrderedByTheirMicroseconds(): void
    {
        // Creation order runs against id order, so a cursor cut to whole seconds would bring
        // back rows that an earlier page already returned.
        $byId = array_keys(self::CREATED);
        usort($byId, fn (string $left, string $right): int => $this->idOf($left) <=> $this->idOf($right));
        $created = array_reverse($byId);
        foreach ($created as $position => $jobId) {
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE job_monitors SET created_at = :created WHERE job_id = :job',
                ['created' => sprintf('2026-02-01 08:00:00.%06d+00', 100_000 + $position), 'job' => $jobId],
            );
        }

        self::assertSame($created, $this->walkForward('createdAt', 'asc'));
        self::assertSame($created, $this->walkBackFromLastPage('createdAt', 'asc'));
        self::assertSame($byId, $this->walkForward('createdAt', 'desc'));
    }

    public function testJobsThatNeverStartedAppearOnceWhenSortingByStartTime(): void
    {
        // Only job-b and job-d have started; the others have no start time and sort lowest.
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement("UPDATE job_monitors SET started_at = '2026-02-01 12:00:00' WHERE job_id = 'job-d'");
        $connection->executeStatement("UPDATE job_monitors SET started_at = '2026-02-01 11:00:00' WHERE job_id = 'job-b'");
        $unstarted = array_values(array_diff($this->expected, ['job-b', 'job-d']));
        usort($unstarted, fn (string $left, string $right): int => $this->idOf($left) <=> $this->idOf($right));

        self::assertSame([...$unstarted, 'job-b', 'job-d'], $this->walkForward('startedAt', 'asc'));
        self::assertSame(['job-d', 'job-b', ...array_reverse($unstarted)], $this->walkForward('startedAt', 'desc'));
    }

    public function testDurationSortOrdersByRunTimeNotFinishTime(): void
    {
        // Finish order is a, b, c; run-time order is c (1 min), a (30 min), b (3 h). job-d is
        // still running and job-e was cancelled before it started: neither has a duration.
        $times = [
            'job-a' => ['2026-02-01 10:00:00', '2026-02-01 10:30:00'],
            'job-b' => ['2026-02-01 08:00:00', '2026-02-01 11:00:00'],
            'job-c' => ['2026-02-01 11:30:00', '2026-02-01 11:31:00'],
            'job-d' => ['2026-02-01 09:00:00', null],
            'job-e' => [null, '2026-02-01 12:00:00'],
        ];
        $connection = $this->entityManager->getConnection();
        foreach ($times as $jobId => [$started, $finished]) {
            $connection->executeStatement(
                'UPDATE job_monitors SET started_at = :started, finished_at = :finished WHERE job_id = :job',
                ['started' => $started, 'finished' => $finished, 'job' => $jobId],
            );
        }
        $unmeasured = ['job-d', 'job-e'];
        usort($unmeasured, fn (string $left, string $right): int => $this->idOf($left) <=> $this->idOf($right));
        $ascending = [...$unmeasured, 'job-c', 'job-a', 'job-b'];

        self::assertSame($ascending, $this->walkForward('duration', 'asc'));
        self::assertSame($ascending, $this->walkBackFromLastPage('duration', 'asc'));
        self::assertSame(array_reverse($ascending), $this->walkForward('duration', 'desc'));
        self::assertSame(array_reverse($ascending), $this->walkBackFromLastPage('duration', 'desc'));

        $this->entityManager->clear();
        $durations = [];
        foreach (array_keys($times) as $jobId) {
            $durations[$jobId] = static::getContainer()->get(JobMonitorService::class)->findByJobIdOrFail($jobId)->getDurationMicroseconds();
        }
        self::assertSame(['job-a' => 1_800_000_000, 'job-b' => 10_800_000_000, 'job-c' => 60_000_000, 'job-d' => null, 'job-e' => null], $durations);
    }

    public function testDurationTiesAreOrderedById(): void
    {
        // job-a and job-c both ran for ten minutes; whichever has the lower id finishes last.
        $tied = ['job-a', 'job-c'];
        usort($tied, fn (string $left, string $right): int => $this->idOf($left) <=> $this->idOf($right));
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement("UPDATE job_monitors SET started_at = '2026-02-01 11:00:00', finished_at = '2026-02-01 11:10:00' WHERE job_id = ?", [$tied[0]]);
        $connection->executeStatement("UPDATE job_monitors SET started_at = '2026-02-01 10:00:00', finished_at = '2026-02-01 10:10:00' WHERE job_id = ?", [$tied[1]]);
        $connection->executeStatement("UPDATE job_monitors SET started_at = '2026-02-01 09:00:00', finished_at = '2026-02-01 09:05:00' WHERE job_id = 'job-e'");
        $unmeasured = ['job-b', 'job-d'];
        usort($unmeasured, fn (string $left, string $right): int => $this->idOf($left) <=> $this->idOf($right));
        $ascending = [...$unmeasured, 'job-e', ...$tied];

        self::assertSame($ascending, $this->walkForward('duration', 'asc'));
        self::assertSame(array_reverse($ascending), $this->walkBackFromLastPage('duration', 'desc'));
    }

    /** @return list<string> */
    private function walkForward(string $sort, string $direction): array
    {
        $service = static::getContainer()->get(JobMonitorService::class);
        $filter = new JobMonitorFilter(status: null, name: 'cursor-test', queue: null);
        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = $service->findWithCursor($filter, $cursor, 2, $sort, $direction);
            self::assertSame($pages > 0, $page->hasPreviousPage, 'Only pages after the first have a previous page.');
            $seen = [...$seen, ...array_map(static fn (JobMonitorEntity $job): string => $job->getJobId(), $page->items)];
            $cursor = $page->nextCursor;
            ++$pages;
        } while ($page->hasNextPage && $pages <= count(self::CREATED));

        return $seen;
    }

    /** @return list<string> */
    private function walkBackFromLastPage(string $sort, string $direction): array
    {
        $service = static::getContainer()->get(JobMonitorService::class);
        $filter = new JobMonitorFilter(status: null, name: 'cursor-test', queue: null);
        $cursor = null;
        do {
            $page = $service->findWithCursor($filter, $cursor, 2, $sort, $direction);
            $cursor = $page->nextCursor;
        } while ($page->hasNextPage);

        $seen = array_map(static fn (JobMonitorEntity $job): string => $job->getJobId(), $page->items);
        $cursor = $page->prevCursor;
        while ($cursor !== null) {
            $page = $service->findWithCursor($filter, $cursor, 2, $sort, $direction);
            $seen = [...array_map(static fn (JobMonitorEntity $job): string => $job->getJobId(), $page->items), ...$seen];
            $cursor = $page->hasPreviousPage ? $page->prevCursor : null;
        }

        return $seen;
    }

    private function idOf(string $jobId): string
    {
        return (string) $this->entityManager->getConnection()->fetchOne('SELECT id FROM job_monitors WHERE job_id = ?', [$jobId]);
    }
}
