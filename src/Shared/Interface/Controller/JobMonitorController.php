<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Shared\Application\DTO\JobMonitorQuery;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\DTO\ApiError;
use App\Shared\Interface\Resource\JobMonitorResource;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[OA\Tag(name: 'System', description: 'System utilities and background job monitoring')]
#[Route('/api/monitor', name: 'monitor_')]
final class JobMonitorController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly JobMonitorAdministrationInterface $jobMonitor,
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
    #[CliCounterpart('app:monitor:status')]
    #[Route('/status', name: 'status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return $this->successResponse(JobMonitorResource::overview($this->jobMonitor->overview()));
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
            new OA\Response(response: '422', description: 'Unknown status', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
        ],
    )]
    #[CliCounterpart('app:monitor:jobs')]
    #[Route('/jobs', name: 'jobs', methods: ['GET'])]
    public function jobs(Request $request): JsonResponse
    {
        $query = $request->query;
        $optional = static fn (string $name): ?string => $query->has($name) ? $query->getString($name) : null;

        return $this->successResponse(JobMonitorResource::page($this->jobMonitor->jobs(new JobMonitorQuery(
            status: $optional('status'),
            name: $optional('name'),
            queue: $optional('queue'),
            sort: $query->getString('sort', 'createdAt'),
            direction: $query->getString('direction', 'desc'),
            limit: (int) ($query->get('limit') ?? JobMonitorQuery::DEFAULT_LIMIT),
            cursor: $optional('cursor'),
        ))));
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
            new OA\Response(response: '404', description: 'Job not found', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
        ],
    )]
    #[CliCounterpart('app:monitor:job:show')]
    #[Route('/jobs/{jobId}', name: 'jobs_detail', methods: ['GET'])]
    public function detail(string $jobId): JsonResponse
    {
        return $this->successResponse(JobMonitorResource::detail($this->jobMonitor->job($jobId)));
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
            new OA\Response(response: '422', description: 'Days is less than 1', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
        ],
    )]
    #[CliCounterpart('app:monitor:prune')]
    #[Route('/prune', name: 'prune', methods: ['POST'])]
    public function prune(Request $request): JsonResponse
    {
        $result = $this->jobMonitor->prune((int) ($request->toArray()['days'] ?? 7));

        return $this->successResponse([
            'pruned' => $result->count,
            'olderThan' => $result->olderThan->format(\DateTimeInterface::ATOM),
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
            new OA\Response(response: '404', description: 'Job not found', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '409', description: 'Job cannot be retried: it has not failed, was already retried, or has no readable stored message', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
        ],
    )]
    #[CliCounterpart('app:monitor:job:retry')]
    #[Route('/jobs/{jobId}/retry', name: 'jobs_retry', methods: ['POST'])]
    public function retry(string $jobId, #[CurrentUser] UserInterface $user): JsonResponse
    {
        return $this->successResponse([
            'newJobId' => $this->jobMonitor->retry($jobId, $user->getUserIdentifier()),
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
            new OA\Response(response: '404', description: 'Job not found', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '409', description: 'Job cannot be cancelled because it has finished or failed', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
        ],
    )]
    #[CliCounterpart('app:monitor:job:cancel')]
    #[Route('/jobs/{jobId}/cancel', name: 'jobs_cancel', methods: ['POST'])]
    public function cancel(string $jobId): JsonResponse
    {
        $this->jobMonitor->cancel($jobId);

        return $this->successResponse([
            'cancelled' => true,
        ]);
    }
}
