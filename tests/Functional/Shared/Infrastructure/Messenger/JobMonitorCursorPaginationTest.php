<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Infrastructure\Messenger;

use App\Shared\Infrastructure\Doctrine\Entity\JobMonitorEntity;
use App\Shared\Infrastructure\Messenger\JobMonitorFilter;
use App\Shared\Infrastructure\Messenger\JobMonitorService;
use App\Tests\Functional\TestCase;

/**
 * Ascending job pages walk forward with Next cursors and back with Prev
 * cursors; jobs created in the same second are ordered by id.
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
}
