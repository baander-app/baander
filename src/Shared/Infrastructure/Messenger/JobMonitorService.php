<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Domain\Model\Cursor;
use App\Shared\Domain\Model\CursorDirection;
use App\Shared\Domain\Model\JobStatus;
use App\Shared\Infrastructure\Doctrine\Entity\JobMonitorEntity;
use App\Shared\Infrastructure\Pagination\CursorPaginator;
use App\Shared\Infrastructure\Pagination\CursorResult;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class JobMonitorService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CursorPaginator $cursorPaginator,
        private readonly JsonEncoder $jsonEncoder,
    ) {
    }

    public function create(string $jobId, ?string $name = null, ?string $queue = null, ?string $data = null, bool $dataTruncated = false): JobMonitorEntity
    {
        $monitor = new JobMonitorEntity($jobId, name: $name, queue: $queue);
        $monitor->setData($data);
        $monitor->setDataTruncated($dataTruncated);

        $this->entityManager->persist($monitor);
        $this->entityManager->flush();

        return $monitor;
    }

    public function markStarted(string $jobId): void
    {
        $monitor = $this->findByJobId($jobId);
        if ($monitor !== null) {
            $monitor->markStarted();
            $this->entityManager->flush();
        }
    }

    public function markFinished(string $jobId): void
    {
        $monitor = $this->findByJobId($jobId);
        if ($monitor !== null) {
            $monitor->markFinished();
            $this->entityManager->flush();
        }
    }

    public function markFailed(string $jobId, \Throwable $exception): void
    {
        $monitor = $this->findByJobId($jobId);
        if ($monitor !== null) {
            $monitor->markFailed();
            $monitor->setExceptionClass($exception::class);
            $monitor->setException([
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);
            $this->entityManager->flush();
        }
    }

    public function setProgress(string $jobId, int $progress): void
    {
        $monitor = $this->findByJobId($jobId);
        if ($monitor !== null) {
            $monitor->setProgress($progress);
            $this->entityManager->flush();
        }
    }

    public function setJobData(string $jobId, ?string $data, bool $dataTruncated = false): void
    {
        $monitor = $this->findByJobId($jobId);
        if ($monitor !== null) {
            $monitor->setData($data);
            $monitor->setDataTruncated($dataTruncated);
            $this->entityManager->flush();
        }
    }

    public function getRecent(int $limit = 50): array
    {
        return $this->entityManager
            ->getRepository(JobMonitorEntity::class)
            ->findBy([], ['createdAt' => 'DESC'], $limit);
    }

    public function getRunning(): array
    {
        return $this->entityManager
            ->getRepository(JobMonitorEntity::class)
            ->findBy(['status' => JobStatus::Running], ['startedAt' => 'ASC']);
    }

    public function setData(string $jobId, ?string $data, bool $dataTruncated): void
    {
        $monitor = $this->findByJobId($jobId);
        if ($monitor !== null) {
            $monitor->setData($data);
            $monitor->setDataTruncated($dataTruncated);
            $this->entityManager->flush();
        }
    }

    public function countByStatus(): array
    {
        $qb = $this->entityManager
            ->getRepository(JobMonitorEntity::class)
            ->createQueryBuilder('j')
            ->select('j.status, COUNT(j.id) as count')
            ->groupBy('j.status');

        $results = $qb->getQuery()->getResult();

        $counts = [];
        foreach ($results as $row) {
            $counts[$row['status']->value] = (int) $row['count'];
        }

        return $counts;
    }

    public function findByJobIdOrFail(string $jobId): JobMonitorEntity
    {
        $monitor = $this->findByJobId($jobId);

        if ($monitor === null) {
            throw new RuntimeException(sprintf('Job monitor with jobId "%s" not found.', $jobId));
        }

        return $monitor;
    }

    public function findWithCursor(
        JobMonitorFilter $filter,
        ?Cursor $cursor,
        int $limit,
        string $sort,
        string $direction,
    ): CursorResult {
        $qb = $this->entityManager
            ->getRepository(JobMonitorEntity::class)
            ->createQueryBuilder('j');

        // Apply filters
        if ($filter->status !== null) {
            $qb->andWhere('j.status = :status')
                ->setParameter('status', JobStatus::from($filter->status));
        }

        if ($filter->name !== null) {
            $qb->andWhere('j.name LIKE :name')
                ->setParameter('name', '%' . $filter->name . '%');
        }

        if ($filter->queue !== null) {
            $qb->andWhere('j.queue = :queue')
                ->setParameter('queue', $filter->queue);
        }

        // Map sort parameter to DQL field.
        // Note: 'duration' is a computed field (finishedAt - startedAt). Doctrine DQL does not
        // support arithmetic on datetime columns directly. Mapping to j.finishedAt as a pragmatic
        // approximation -- jobs that finished later tend to have longer durations in a batch setting.
        $sortColumn = match ($sort) {
            'createdAt' => 'j.createdAt',
            'startedAt' => 'j.startedAt',
            'finishedAt' => 'j.finishedAt',
            'duration' => 'j.finishedAt',
            default => 'j.createdAt',
        };

        // The cursor paginator always sorts ASC internally and uses keyset conditions.
        // For DESC sort, we reverse the cursor direction and extractors.
        $isDesc = strtolower($direction) === 'desc';

        if ($isDesc) {
            // Reverse the cursor direction: if client sends next cursor, treat as prev (seek backward in ASC).
            $effectiveCursor = null;
            if ($cursor !== null) {
                $values = $cursor->getValues();
                $reversedDirection = ($cursor->getDirection() === CursorDirection::Next)
                    ? CursorDirection::Prev
                    : CursorDirection::Next;
                $effectiveCursor = Cursor::create($reversedDirection, $values);
            }

            $result = $this->cursorPaginator->paginate(
                $qb,
                $sortColumn,
                'j.id',
                $effectiveCursor,
                $limit,
                function (JobMonitorEntity $entity) use ($sort): array {
                    $sortValue = $this->extractSortValue($entity, $sort);

                    return ['sort' => $sortValue, 'id' => $entity->getId()->toString()];
                },
                withCount: false,
            );

            // Swap next/prev cursors to present DESC pagination to the client.
            return new CursorResult(
                items: $result->items,
                nextCursor: $result->prevCursor,
                prevCursor: $result->nextCursor,
                hasNextPage: $result->hasPreviousPage,
                hasPreviousPage: $result->hasNextPage,
                total: 0,
                staleCursor: $result->staleCursor,
                perPage: $result->perPage,
            );
        }

        return $this->cursorPaginator->paginate(
            $qb,
            $sortColumn,
            'j.id',
            $cursor,
            $limit,
            function (JobMonitorEntity $entity) use ($sort): array {
                $sortValue = $this->extractSortValue($entity, $sort);

                return ['sort' => $sortValue, 'id' => $entity->getId()->toString()];
            },
            withCount: false,
        );
    }

    public function countByStatusAndDateRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $qb = $this->entityManager
            ->getRepository(JobMonitorEntity::class)
            ->createQueryBuilder('j')
            ->select('j.status, COUNT(j.id) as count')
            ->where('j.createdAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('j.status');

        $results = $qb->getQuery()->getResult();

        $counts = [];
        foreach ($results as $row) {
            $counts[$row['status']->value] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * @return array{
     *     statusCounts: array<string, int>,
     *     jobTypeBreakdown: array<int, array{name: string, count: int}>,
     *     successRate: float,
     *     throughputPerHour: float,
     * }
     */
    public function getAnalyticsSummary(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        // Status counts
        $statusCounts = $this->countByStatusAndDateRange($from, $to);

        // Initialize all statuses to 0
        foreach (JobStatus::cases() as $status) {
            if (!isset($statusCounts[$status->value])) {
                $statusCounts[$status->value] = 0;
            }
        }

        // Job type breakdown
        $qb = $this->entityManager
            ->getRepository(JobMonitorEntity::class)
            ->createQueryBuilder('j')
            ->select('j.name, COUNT(j.id) as count')
            ->where('j.createdAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('j.name')
            ->orderBy('count', 'DESC');

        $typeResults = $qb->getQuery()->getResult();

        $jobTypeBreakdown = [];
        foreach ($typeResults as $row) {
            $jobTypeBreakdown[] = [
                'name' => $row['name'] ?? '(unknown)',
                'count' => (int) $row['count'],
            ];
        }

        // Success rate: finished / (finished + failed + cancelled)
        $finished = $statusCounts[JobStatus::Finished->value] ?? 0;
        $failed = $statusCounts[JobStatus::Failed->value] ?? 0;
        $cancelled = $statusCounts[JobStatus::Cancelled->value] ?? 0;
        $completed = $finished + $failed + $cancelled;
        $successRate = $completed > 0 ? $finished / $completed : 0.0;

        // Throughput: completed jobs per hour
        $hours = max(($to->getTimestamp() - $from->getTimestamp()) / 3600, 0.001);
        $throughputPerHour = $completed / $hours;

        return [
            'statusCounts' => $statusCounts,
            'jobTypeBreakdown' => $jobTypeBreakdown,
            'successRate' => round($successRate, 4),
            'throughputPerHour' => round($throughputPerHour, 1),
        ];
    }

    /**
     * @return array{
     *     executionTimes: array<int, array{name: string, avg: float, median: float, p95: float}>,
     *     queueLatency: array<int, array{name: string, avg: float}>,
     * }
     */
    public function getAnalyticsTiming(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $conn = $this->entityManager->getConnection();

        // Execution times for successfully finished jobs (startedAt and finishedAt both non-null)
        $rows = $conn->fetchAllAssociative(
            <<<'SQL'
                SELECT j.name,
                       AVG(EXTRACT(EPOCH FROM (j.finished_at - j.started_at))) as avg_time,
                       PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (j.finished_at - j.started_at))) as median_time,
                       PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (j.finished_at - j.started_at))) as p95_time
                FROM job_monitors j
                WHERE j.created_at BETWEEN :from AND :to
                  AND j.status = :finished_status
                  AND j.started_at IS NOT NULL
                  AND j.finished_at IS NOT NULL
                GROUP BY j.name
                ORDER BY avg_time DESC
            SQL,
            [
                'from' => $from->format('Y-m-d H:i:s.u'),
                'to' => $to->format('Y-m-d H:i:s.u'),
                'finished_status' => JobStatus::Finished->value,
            ],
        );

        $executionTimes = [];
        foreach ($rows as $row) {
            $executionTimes[] = [
                'name' => $row['name'] ?? '(unknown)',
                'avg' => round((float) $row['avg_time'], 2),
                'median' => round((float) $row['median_time'], 2),
                'p95' => round((float) $row['p95_time'], 2),
            ];
        }

        // Queue latency: startedAt - createdAt for finished jobs
        $latencyRows = $conn->fetchAllAssociative(
            <<<'SQL'
                SELECT j.name,
                       AVG(EXTRACT(EPOCH FROM (j.started_at - j.created_at))) as avg_latency
                FROM job_monitors j
                WHERE j.created_at BETWEEN :from AND :to
                  AND j.status = :finished_status
                  AND j.started_at IS NOT NULL
                GROUP BY j.name
                ORDER BY avg_latency DESC
            SQL,
            [
                'from' => $from->format('Y-m-d H:i:s.u'),
                'to' => $to->format('Y-m-d H:i:s.u'),
                'finished_status' => JobStatus::Finished->value,
            ],
        );

        $queueLatency = [];
        foreach ($latencyRows as $row) {
            $queueLatency[] = [
                'name' => $row['name'] ?? '(unknown)',
                'avg' => round((float) $row['avg_latency'], 2),
            ];
        }

        return [
            'executionTimes' => $executionTimes,
            'queueLatency' => $queueLatency,
        ];
    }

    /**
     * @return array{
     *     topFailingTypes: array<int, array{name: string, count: int}>,
     *     topExceptionClasses: array<int, array{class: string, count: int}>,
     *     retryFrequency: array{retried: int, total: int, rate: float},
     *     recentFailures: array<int, array{jobId: string, name: string|null, exceptionClass: string|null, exceptionMessage: string|null, failedAt: string|null}>,
     * }
     */
    public function getAnalyticsFailures(\DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 50): array
    {
        $conn = $this->entityManager->getConnection();

        // Top failing job types
        $failingTypes = $conn->fetchAllAssociative(
            <<<'SQL'
                SELECT j.name, COUNT(j.id) as count
                FROM job_monitors j
                WHERE j.created_at BETWEEN :from AND :to
                  AND j.status = :failed_status
                GROUP BY j.name
                ORDER BY count DESC
                LIMIT 10
            SQL,
            [
                'from' => $from->format('Y-m-d H:i:s.u'),
                'to' => $to->format('Y-m-d H:i:s.u'),
                'failed_status' => JobStatus::Failed->value,
            ],
        );

        $topFailingTypes = [];
        foreach ($failingTypes as $row) {
            $topFailingTypes[] = [
                'name' => $row['name'] ?? '(unknown)',
                'count' => (int) $row['count'],
            ];
        }

        // Top exception classes
        $exceptionClasses = $conn->fetchAllAssociative(
            <<<'SQL'
                SELECT j.exception_class, COUNT(j.id) as count
                FROM job_monitors j
                WHERE j.created_at BETWEEN :from AND :to
                  AND j.status = :failed_status
                  AND j.exception_class IS NOT NULL
                GROUP BY j.exception_class
                ORDER BY count DESC
                LIMIT 10
            SQL,
            [
                'from' => $from->format('Y-m-d H:i:s.u'),
                'to' => $to->format('Y-m-d H:i:s.u'),
                'failed_status' => JobStatus::Failed->value,
            ],
        );

        $topExceptionClasses = [];
        foreach ($exceptionClasses as $row) {
            $topExceptionClasses[] = [
                'class' => $row['exception_class'],
                'count' => (int) $row['count'],
            ];
        }

        // Retry frequency (within date range)
        $retryStats = $conn->fetchAssociative(
            <<<'SQL'
                SELECT COUNT(*) FILTER (WHERE j.retried = true) as retried,
                       COUNT(*) as total
                FROM job_monitors j
                WHERE j.created_at BETWEEN :from AND :to
                  AND j.status = :failed_status
            SQL,
            [
                'from' => $from->format('Y-m-d H:i:s.u'),
                'to' => $to->format('Y-m-d H:i:s.u'),
                'failed_status' => JobStatus::Failed->value,
            ],
        );

        $retried = (int) ($retryStats['retried'] ?? 0);
        $total = (int) ($retryStats['total'] ?? 0);
        $retryRate = $total > 0 ? $retried / $total : 0.0;

        // Recent failures
        $recentFailures = $conn->fetchAllAssociative(
            <<<'SQL'
                SELECT j.job_id, j.name, j.exception_class,
                       j.exception->>'message' as exception_message,
                       j.finished_at
                FROM job_monitors j
                WHERE j.created_at BETWEEN :from AND :to
                  AND j.status = :failed_status
                ORDER BY j.finished_at DESC
                LIMIT :limit
            SQL,
            [
                'from' => $from->format('Y-m-d H:i:s.u'),
                'to' => $to->format('Y-m-d H:i:s.u'),
                'failed_status' => JobStatus::Failed->value,
                'limit' => $limit,
            ],
        );

        $recentFailureList = [];
        foreach ($recentFailures as $row) {
            $recentFailureList[] = [
                'jobId' => $row['job_id'],
                'name' => $row['name'],
                'exceptionClass' => $row['exception_class'],
                'exceptionMessage' => $row['exception_message'],
                'failedAt' => $row['finished_at'] ?? null,
            ];
        }

        return [
            'topFailingTypes' => $topFailingTypes,
            'topExceptionClasses' => $topExceptionClasses,
            'retryFrequency' => [
                'retried' => $retried,
                'total' => $total,
                'rate' => round($retryRate, 4),
            ],
            'recentFailures' => $recentFailureList,
        ];
    }

    public function markCancelled(string $jobId): void
    {
        $monitor = $this->findByJobIdOrFail($jobId);
        $monitor->markCancelled();
        $this->entityManager->flush();
    }

    public function markRetriedWithAudit(string $jobId, string $newJobId, string $userId): void
    {
        $monitor = $this->findByJobIdOrFail($jobId);
        $monitor->markRetried();

        // appendAuditLog calls flush() internally, so no additional flush needed
        $this->appendAuditLog($jobId, [
            'action' => 'retry',
            'newJobId' => $newJobId,
            'userId' => $userId,
            'at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }

    public function appendAuditLog(string $jobId, array $entry): void
    {
        $monitor = $this->findByJobIdOrFail($jobId);

        $currentLog = $monitor->getAuditLog();
        $entries = $currentLog !== null ? $this->jsonEncoder->decode($currentLog, 'json') : [];
        $entries[] = $entry;

        $monitor->setAuditLog($this->jsonEncoder->encode($entries, 'json'));
        $this->entityManager->flush();
    }

    /**
     * Prune completed (finished, failed, cancelled) job monitors older than the given date.
     *
     * @return int Number of pruned rows
     */
    public function prune(\DateTimeImmutable $olderThan): int
    {
        $qb = $this->entityManager->createQueryBuilder();

        return (int) $qb->delete(JobMonitorEntity::class, 'j')
            ->where('j.status IN (:statuses)')
            ->andWhere('j.createdAt < :olderThan')
            ->setParameter('statuses', [JobStatus::Finished, JobStatus::Failed, JobStatus::Cancelled])
            ->setParameter('olderThan', $olderThan)
            ->getQuery()
            ->execute();
    }

    public function findByJobId(string $jobId): ?JobMonitorEntity
    {
        return $this->entityManager
            ->getRepository(JobMonitorEntity::class)
            ->findOneBy(['jobId' => $jobId]);
    }

    /**
     * Extract the sort value from an entity for cursor-based pagination.
     */
    private function extractSortValue(JobMonitorEntity $entity, string $sort): string
    {
        return match ($sort) {
            'createdAt' => $entity->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'startedAt' => ($entity->getStartedAt() ?? new \DateTimeImmutable('1970-01-01'))->format(\DateTimeInterface::ATOM),
            'finishedAt' => ($entity->getFinishedAt() ?? new \DateTimeImmutable('1970-01-01'))->format(\DateTimeInterface::ATOM),
            'duration' => ($entity->getFinishedAt() ?? new \DateTimeImmutable('1970-01-01'))->format(\DateTimeInterface::ATOM),
            default => $entity->getCreatedAt()->format(\DateTimeInterface::ATOM),
        };
    }
}
