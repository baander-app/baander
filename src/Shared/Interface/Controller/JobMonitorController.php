<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Shared\Domain\Model\JobStatus;
use App\Shared\Infrastructure\Doctrine\Entity\JobMonitorEntity;
use App\Shared\Infrastructure\Messenger\JobIdStamp;
use App\Shared\Infrastructure\Messenger\JobMessageSerializer;
use App\Shared\Infrastructure\Messenger\JobMonitorFilter;
use App\Shared\Infrastructure\Messenger\JobMonitorService;
use App\Shared\Infrastructure\Pagination\CursorCodec;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use DateTimeInterface;
use OpenApi\Attributes as OA;
use Nelmio\ApiDocBundle\Attribute\Model;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNameStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[OA\Tag(name: 'System', description: 'System utilities and background job monitoring')]
#[Route('/api/monitor', name: 'monitor_')]
final class JobMonitorController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly JobMonitorService $jobMonitorService,
        private readonly CursorCodec $cursorCodec,
        private readonly MessageBusInterface $messageBus,
        private readonly JobMessageSerializer $messageSerializer,
        private readonly RedisClientFactory $redisClientFactory,
    ) {
    }

    /**
     * Get job monitoring status summary.
     */
    #[OA\Get(
        path: '/api/monitor/status',
        summary: 'Get background job monitoring status summary',
        responses: [
            new OA\Response(response: '200', description: 'Job monitor state',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'counts', description: 'Job counts by status', type: 'object'),
                        new OA\Property(property: 'running', type: 'array', items: new OA\Items(properties: [
                            new OA\Property(property: 'jobId', description: 'Internal job identifier', type: 'string'),
                            new OA\Property(property: 'name', description: 'Job name', type: 'string'),
                            new OA\Property(property: 'queue', description: 'Queue the job is running on', type: 'string'),
                            new OA\Property(property: 'startedAt', type: 'string', format: 'date-time', nullable: true),
                            new OA\Property(property: 'progress', description: 'Job progress between 0 and 100', type: 'integer', nullable: true),
                        ], type: 'object')),
                    ], type: 'object')],
                    type: 'object',
                ),
            ),
        ],
    )]
    #[Route('/status', name: 'status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $counts = $this->jobMonitorService->countByStatus();
        $running = $this->jobMonitorService->getRunning();

        $runningData = array_map(
            static fn(JobMonitorEntity $job): array => [
                'jobId'     => $job->getJobId(),
                'name'      => $job->getName(),
                'queue'     => $job->getQueue(),
                'startedAt' => $job->getStartedAt()?->format(DateTimeInterface::ATOM),
                'progress'  => $job->getProgress(),
            ],
            $running,
        );

        return $this->successResponse([
            'counts'  => $counts,
            'running' => $runningData,
        ]);
    }

    /**
     * Get job list with filtering, sorting, and cursor-based pagination.
     */
    #[OA\Get(
        path: '/api/monitor/jobs',
        summary: 'Get background jobs with filtering, sorting, and pagination',
        parameters: [
            new OA\Parameter(name: 'status', description: 'Filter by job status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['queued',
                                                                                                                                                             'running',
                                                                                                                                                             'finished',
                                                                                                                                                             'failed',
                                                                                                                                                             'cancelled'])),
            new OA\Parameter(name: 'name', description: 'Filter by job name (partial match)', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'queue', description: 'Filter by queue name (exact match)', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', description: 'Sort field', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'createdAt', enum: ['createdAt',
                                                                                                                                                                       'startedAt',
                                                                                                                                                                       'finishedAt',
                                                                                                                                                                       'duration'])),
            new OA\Parameter(name: 'direction', description: 'Sort direction', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'desc', enum: ['asc',
                                                                                                                                                                           'desc'])),
            new OA\Parameter(name: 'limit', description: 'Page size (1-200)', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 50, maximum: 200, minimum: 1)),
            new OA\Parameter(name: 'cursor', description: 'Cursor string for pagination', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Paginated job list',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'items', type: 'array', items: new OA\Items(properties: [
                            new OA\Property(property: 'jobId', description: 'Internal job identifier', type: 'string'),
                            new OA\Property(property: 'name', description: 'Job name', type: 'string', nullable: true),
                            new OA\Property(property: 'queue', description: 'Queue the job was dispatched to', type: 'string', nullable: true),
                            new OA\Property(property: 'status', description: 'Job status', type: 'string'),
                            new OA\Property(property: 'progress', description: 'Job progress between 0 and 100', type: 'integer', nullable: true),
                            new OA\Property(property: 'attempt', description: 'Current attempt number', type: 'integer'),
                            new OA\Property(property: 'retried', description: 'Whether the job has been retried', type: 'boolean'),
                            new OA\Property(property: 'startedAt', type: 'string', format: 'date-time', nullable: true),
                            new OA\Property(property: 'finishedAt', type: 'string', format: 'date-time', nullable: true),
                            new OA\Property(property: 'createdAt', description: 'Job creation timestamp', type: 'string', format: 'date-time'),
                            new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
                            new OA\Property(property: 'exceptionClass', description: 'Exception class name (failed jobs only)', type: 'string', nullable: true),
                        ], type: 'object')),
                        new OA\Property(property: 'next_cursor', description: 'Cursor for the next page', type: 'string', nullable: true),
                        new OA\Property(property: 'has_next_page', description: 'Whether there is a next page', type: 'boolean'),
                        new OA\Property(property: 'per_page', description: 'Number of items per page', type: 'integer'),
                    ], type: 'object')],
                    type: 'object',
                ),
            ),
        ],
    )]
    #[Route('/jobs', name: 'jobs', methods: ['GET'])]
    public function jobs(Request $request): JsonResponse
    {
        $limit = max(1, min((int)($request->query->get('limit') ?? 50), 200));
        $sort = $request->query->get('sort', 'createdAt');
        $direction = $request->query->get('direction', 'desc');
        $cursorString = $request->query->get('cursor');

        $filter = new JobMonitorFilter(
            status: $request->query->get('status'),
            name: $request->query->get('name'),
            queue: $request->query->get('queue'),
        );

        $cursor = ($cursorString !== null) ? $this->cursorCodec->decode($cursorString) : null;

        $result = $this->jobMonitorService->findWithCursor($filter, $cursor, $limit, $sort, $direction);

        $items = array_map(
            static fn(JobMonitorEntity $job): array => [
                'jobId'          => $job->getJobId(),
                'name'           => $job->getName(),
                'queue'          => $job->getQueue(),
                'status'         => $job->getStatus()->value,
                'progress'       => $job->getProgress(),
                'attempt'        => $job->getAttempt(),
                'retried'        => $job->isRetried(),
                'startedAt'      => $job->getStartedAt()?->format(DateTimeInterface::ATOM),
                'finishedAt'     => $job->getFinishedAt()?->format(DateTimeInterface::ATOM),
                'createdAt'      => $job->getCreatedAt()->format(DateTimeInterface::ATOM),
                'updatedAt'      => $job->getUpdatedAt()->format(DateTimeInterface::ATOM),
                'exceptionClass' => $job->getStatus()->value === 'failed'
                    ? $job->getExceptionClass()
                    : null,
            ],
            $result->items,
        );

        return $this->successResponse([
            'items'         => $items,
            'next_cursor'   => $result->nextCursor !== null
                ? $this->cursorCodec->encode($result->nextCursor)
                : null,
            'has_next_page' => $result->hasNextPage,
            'per_page'      => $result->perPage,
        ]);
    }

    /**
     * Get job detail by job ID.
     */
    #[OA\Get(
        path: '/api/monitor/jobs/{jobId}',
        summary: 'Get background job detail',
        parameters: [
            new OA\Parameter(name: 'jobId', description: 'Internal job identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Full job record',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'jobId', description: 'Internal job identifier', type: 'string'),
                        new OA\Property(property: 'name', description: 'Job name', type: 'string', nullable: true),
                        new OA\Property(property: 'queue', description: 'Queue the job was dispatched to', type: 'string', nullable: true),
                        new OA\Property(property: 'status', description: 'Job status', type: 'string'),
                        new OA\Property(property: 'progress', description: 'Job progress between 0 and 100', type: 'integer', nullable: true),
                        new OA\Property(property: 'attempt', description: 'Current attempt number', type: 'integer'),
                        new OA\Property(property: 'retried', description: 'Whether the job has been retried', type: 'boolean'),
                        new OA\Property(property: 'startedAt', type: 'string', format: 'date-time', nullable: true),
                        new OA\Property(property: 'finishedAt', type: 'string', format: 'date-time', nullable: true),
                        new OA\Property(property: 'createdAt', description: 'Job creation timestamp', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'exceptionClass', description: 'Exception class name (failed jobs only)', type: 'string', nullable: true),
                        new OA\Property(property: 'exception', description: 'Full exception details (failed jobs only)', properties: [
                            new OA\Property(property: 'message', type: 'string'),
                            new OA\Property(property: 'file', type: 'string'),
                            new OA\Property(property: 'line', type: 'integer'),
                        ], type: 'object', nullable: true),
                        new OA\Property(property: 'data', description: 'Serialized message payload', type: 'string', nullable: true),
                        new OA\Property(property: 'dataTruncated', description: 'Whether the message payload was truncated', type: 'boolean'),
                        new OA\Property(property: 'duration', description: 'Execution time in seconds (null if not finished)', type: 'number', format: 'float', nullable: true),
                    ], type: 'object')],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '404', description: 'Job not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/jobs/{jobId}', name: 'jobs_detail', methods: ['GET'])]
    public function detail(string $jobId): JsonResponse
    {
        try {
            $job = $this->jobMonitorService->findByJobIdOrFail($jobId);
        } catch (RuntimeException) {
            return $this->notFound('Job not found.');
        }

        $duration = null;
        if ($job->getStartedAt() !== null && $job->getFinishedAt() !== null) {
            $duration = (float)$job->getStartedAt()->diff($job->getFinishedAt())->format('%s.%f');
        }

        return $this->successResponse([
            'jobId'          => $job->getJobId(),
            'name'           => $job->getName(),
            'queue'          => $job->getQueue(),
            'status'         => $job->getStatus()->value,
            'progress'       => $job->getProgress(),
            'attempt'        => $job->getAttempt(),
            'retried'        => $job->isRetried(),
            'startedAt'      => $job->getStartedAt()?->format(DateTimeInterface::ATOM),
            'finishedAt'     => $job->getFinishedAt()?->format(DateTimeInterface::ATOM),
            'createdAt'      => $job->getCreatedAt()->format(DateTimeInterface::ATOM),
            'updatedAt'      => $job->getUpdatedAt()->format(DateTimeInterface::ATOM),
            'exceptionClass' => $job->getStatus()->value === 'failed'
                ? $job->getExceptionClass()
                : null,
            'exception'      => $job->getStatus()->value === 'failed'
                ? $job->getException()
                : null,
            'data'           => $job->getData(),
            'dataTruncated'  => $job->getDataTruncated(),
            'duration'       => $duration,
        ]);
    }

    /**
     * Prune completed job monitors older than a given age.
     */
    #[OA\Post(
        path: '/api/monitor/prune',
        description: 'Deletes finished, failed, and cancelled job monitors older than the specified number of days. Defaults to 7 days.',
        summary: 'Prune old job monitors',
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'days', description: 'Prune jobs older than this many days', type: 'integer', default: 7, minimum: 1),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: '200', description: 'Prune result',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'pruned', description: 'Number of job monitors deleted', type: 'integer'),
                        new OA\Property(property: 'olderThan', description: 'Cutoff timestamp', type: 'string', format: 'date-time'),
                    ], type: 'object')],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '422', description: 'Invalid input', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/prune', name: 'prune', methods: ['POST'])]
    public function prune(Request $request): JsonResponse
    {
        $days = (int) ($request->toArray()['days'] ?? 7);

        if ($days < 1) {
            return $this->errorResponse('Days must be at least 1.', 422);
        }

        $olderThan = new \DateTimeImmutable(sprintf('-%d days', $days));
        $count = $this->jobMonitorService->prune($olderThan);

        return $this->successResponse([
            'pruned' => $count,
            'olderThan' => $olderThan->format(DateTimeInterface::ATOM),
        ]);
    }

    /**
     * Retry a failed job by re-dispatching its original message payload.
     */
    #[OA\Post(
        path: '/api/monitor/jobs/{jobId}/retry',
        description: 'Re-dispatches the original message payload of a failed job. The job must be in Failed status, not previously retried, and have a stored message payload.',
        summary: 'Retry a failed background job',
        parameters: [
            new OA\Parameter(name: 'jobId', description: 'Internal job identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Job retried successfully',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'newJobId', description: 'Job ID of the newly dispatched job', type: 'string'),
                    ], type: 'object')],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '404', description: 'Job not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Job cannot be retried', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/jobs/{jobId}/retry', name: 'jobs_retry', methods: ['POST'])]
    public function retry(Request $request, string $jobId): JsonResponse
    {
        try {
            $job = $this->jobMonitorService->findByJobIdOrFail($jobId);
        } catch (RuntimeException) {
            return $this->notFound('Job not found.');
        }

        if ($job->getStatus() !== JobStatus::Failed) {
            return $this->errorResponse('Only failed jobs can be retried.', 422);
        }

        if ($job->isRetried()) {
            return $this->errorResponse('This job has already been retried.', 422);
        }

        if ($job->getData() === null) {
            return $this->errorResponse('No message payload stored for this job.', 422);
        }

        $message = $this->messageSerializer->deserialize($job->getData());
        if ($message === null) {
            return $this->errorResponse('Failed to deserialize the stored message payload.', 422);
        }

        $envelope = new Envelope($message);
        if ($job->getQueue() !== null) {
            $envelope = $envelope->with(new TransportNameStamp($job->getQueue()));
        }

        $dispatched = $this->messageBus->dispatch($envelope);

        /** @var JobIdStamp|null $stamp */
        $stamp = $dispatched->last(JobIdStamp::class);
        $newJobId = $stamp?->jobId->toString() ?? '';

        $userId = $request->getUser()?->getUserIdentifier() ?? 'anonymous';
        $this->jobMonitorService->markRetriedWithAudit($jobId, $newJobId, $userId);

        return $this->successResponse([
            'newJobId' => $newJobId,
        ]);
    }

    /**
     * Cancel a running or queued job via cooperative Redis flag.
     *
     * Sets a Redis key that handlers can check at their checkpoints.
     * Cancellation is best-effort: already-executing handlers detect the flag
     * on their next checkCancellation() call, and queued messages are flagged
     * before the worker picks them up.
     */
    #[OA\Post(
        path: '/api/monitor/jobs/{jobId}/cancel',
        description: 'Sets a cooperative cancellation flag in Redis. Handlers that implement CancellableJobInterface will detect the flag at their next checkpoint. For queued jobs, the flag is set before the worker picks up the message.',
        summary: 'Cancel a running or queued background job',
        parameters: [
            new OA\Parameter(name: 'jobId', description: 'Internal job identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Job cancellation requested',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'cancelled', description: 'Whether the cancellation flag was set', type: 'boolean'),
                    ], type: 'object')],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '404', description: 'Job not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Job cannot be cancelled', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/jobs/{jobId}/cancel', name: 'jobs_cancel', methods: ['POST'])]
    public function cancel(string $jobId): JsonResponse
    {
        try {
            $job = $this->jobMonitorService->findByJobIdOrFail($jobId);
        } catch (RuntimeException) {
            return $this->notFound('Job not found.');
        }

        if ($job->getStatus() === JobStatus::Finished) {
            return $this->errorResponse('Finished jobs cannot be cancelled.', 422);
        }

        if ($job->getStatus() === JobStatus::Failed) {
            return $this->errorResponse('Failed jobs cannot be cancelled.', 422);
        }

        // Set cooperative cancellation flag in Redis with 1-hour TTL
        $this->redisClientFactory->borrow(fn(\Redis $redis) => $redis->setex("job_cancel:{$jobId}", 3600, '1'));

        return $this->successResponse([
            'cancelled' => true,
        ]);
    }
}
