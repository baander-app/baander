<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Shared\Application\DTO\JobMonitorQuery;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\DTO\ApiError;
use App\Shared\Interface\Resource\JobMonitorResource;
use OpenApi\Spec as OA;
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
    #[OA\Operation\Get(
        path: '/api/monitor/status',
        summary: 'Get background job monitoring status summary',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Job monitor state',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'counts' => new OA\Schema(description: 'Job counts by status', type: 'object'),
                                    'running' => new OA\Schema(
                                        type: 'array',
                                        items: new OA\Schema(
                                            type: 'object',
                                            properties: [
                                                'jobId' => new OA\Schema(description: 'Public job identifier', type: 'string'),
                                                'name' => new OA\Schema(description: 'Job name', type: 'string'),
                                                'queue' => new OA\Schema(description: 'Queue the job is running on', type: 'string'),
                                                'startedAt' => new OA\Schema(
                                                    type: 'string',
                                                    format: 'date-time',
                                                    nullable: true,
                                                ),
                                                'progress' => new OA\Schema(
                                                    description: 'Job progress between 0 and 100',
                                                    type: 'integer',
                                                    nullable: true,
                                                ),
                                            ],
                                        ),
                                    ),
                                ],
                            ),
                        ],
                    ),
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
    #[OA\Operation\Get(
        path: '/api/monitor/jobs',
        summary: 'Get background jobs with filtering, sorting, and pagination',
        parameters: [
            new OA\Parameter(
                name: 'status',
                in: 'query',
                description: 'Filter by job status',
                required: false,
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['queued', 'running', 'finished', 'failed', 'cancelled'],
                ),
            ),
            new OA\Parameter(
                name: 'name',
                in: 'query',
                description: 'Filter by job name (partial match)',
                required: false,
                schema: new OA\Schema(type: 'string'),
            ),
            new OA\Parameter(
                name: 'queue',
                in: 'query',
                description: 'Filter by queue name (exact match)',
                required: false,
                schema: new OA\Schema(type: 'string'),
            ),
            new OA\Parameter(
                name: 'sort',
                in: 'query',
                description: 'Sort field',
                required: false,
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['createdAt', 'startedAt', 'finishedAt', 'duration'],
                    default: 'createdAt',
                ),
            ),
            new OA\Parameter(
                name: 'direction',
                in: 'query',
                description: 'Sort direction',
                required: false,
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['asc', 'desc'],
                    default: 'desc',
                ),
            ),
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                description: 'Page size (1-200)',
                required: false,
                schema: new OA\Schema(
                    type: 'integer',
                    minimum: 1,
                    maximum: 200,
                    default: 50,
                ),
            ),
            new OA\Parameter(
                name: 'cursor',
                in: 'query',
                description: 'Cursor string for pagination',
                required: false,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Paginated job list',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'items' => new OA\Schema(
                                        type: 'array',
                                        items: new OA\Schema(
                                            type: 'object',
                                            properties: [
                                                'jobId' => new OA\Schema(description: 'Public job identifier', type: 'string'),
                                                'name' => new OA\Schema(
                                                    description: 'Job name',
                                                    type: 'string',
                                                    nullable: true,
                                                ),
                                                'queue' => new OA\Schema(
                                                    description: 'Queue the job was dispatched to',
                                                    type: 'string',
                                                    nullable: true,
                                                ),
                                                'status' => new OA\Schema(description: 'Job status', type: 'string'),
                                                'progress' => new OA\Schema(
                                                    description: 'Job progress between 0 and 100',
                                                    type: 'integer',
                                                    nullable: true,
                                                ),
                                                'attempt' => new OA\Schema(description: 'Current attempt number', type: 'integer'),
                                                'retried' => new OA\Schema(description: 'Whether the job has been retried', type: 'boolean'),
                                                'startedAt' => new OA\Schema(
                                                    type: 'string',
                                                    format: 'date-time',
                                                    nullable: true,
                                                ),
                                                'finishedAt' => new OA\Schema(
                                                    type: 'string',
                                                    format: 'date-time',
                                                    nullable: true,
                                                ),
                                                'createdAt' => new OA\Schema(
                                                    description: 'Job creation timestamp',
                                                    type: 'string',
                                                    format: 'date-time',
                                                ),
                                                'updatedAt' => new OA\Schema(type: 'string', format: 'date-time'),
                                                'exceptionClass' => new OA\Schema(
                                                    description: 'Exception class name (failed jobs only)',
                                                    type: 'string',
                                                    nullable: true,
                                                ),
                                            ],
                                        ),
                                    ),
                                    'next_cursor' => new OA\Schema(
                                        description: 'Cursor for the next page',
                                        type: 'string',
                                        nullable: true,
                                    ),
                                    'has_next_page' => new OA\Schema(description: 'Whether there is a next page', type: 'boolean'),
                                    'per_page' => new OA\Schema(description: 'Number of items per page', type: 'integer'),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Unknown status',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
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
    #[OA\Operation\Get(
        path: '/api/monitor/jobs/{jobId}',
        summary: 'Get background job detail',
        parameters: [
            new OA\Parameter(
                name: 'jobId',
                in: 'path',
                description: 'Public job identifier',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Full job record',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'jobId' => new OA\Schema(description: 'Public job identifier', type: 'string'),
                                    'name' => new OA\Schema(
                                        description: 'Job name',
                                        type: 'string',
                                        nullable: true,
                                    ),
                                    'queue' => new OA\Schema(
                                        description: 'Queue the job was dispatched to',
                                        type: 'string',
                                        nullable: true,
                                    ),
                                    'status' => new OA\Schema(description: 'Job status', type: 'string'),
                                    'progress' => new OA\Schema(
                                        description: 'Job progress between 0 and 100',
                                        type: 'integer',
                                        nullable: true,
                                    ),
                                    'attempt' => new OA\Schema(description: 'Current attempt number', type: 'integer'),
                                    'retried' => new OA\Schema(description: 'Whether the job has been retried', type: 'boolean'),
                                    'startedAt' => new OA\Schema(
                                        type: 'string',
                                        format: 'date-time',
                                        nullable: true,
                                    ),
                                    'finishedAt' => new OA\Schema(
                                        type: 'string',
                                        format: 'date-time',
                                        nullable: true,
                                    ),
                                    'createdAt' => new OA\Schema(
                                        description: 'Job creation timestamp',
                                        type: 'string',
                                        format: 'date-time',
                                    ),
                                    'updatedAt' => new OA\Schema(type: 'string', format: 'date-time'),
                                    'exceptionClass' => new OA\Schema(
                                        description: 'Exception class name (failed jobs only)',
                                        type: 'string',
                                        nullable: true,
                                    ),
                                    'duration' => new OA\Schema(
                                        description: 'Execution time in seconds (null if not finished)',
                                        type: 'number',
                                        format: 'float',
                                        nullable: true,
                                    ),
                                    'cancellable' => new OA\Schema(
                                        description: 'Whether cancelling the job can still change anything: it is queued or running, or it has finished and work it queued, such as lyrics fetches, may still be waiting',
                                        type: 'boolean',
                                    ),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Job not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
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
    #[OA\Operation\Post(
        path: '/api/monitor/prune',
        summary: 'Prune old job monitors',
        description: 'Deletes finished, failed, and cancelled job monitors older than the specified number of days. Defaults to 7 days.',
        requestBody: new OA\RequestBody(
            content: new OA\MediaType\Json(
                schema: new OA\Schema(
                    properties: [
                        'days' => new OA\Schema(
                            description: 'Prune jobs older than this many days',
                            type: 'integer',
                            minimum: 1,
                            maximum: JobMonitorAdministrationInterface::MAX_PRUNE_DAYS,
                            default: JobMonitorAdministrationInterface::DEFAULT_PRUNE_DAYS,
                        ),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Prune result',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'pruned' => new OA\Schema(description: 'Number of job monitors deleted', type: 'integer'),
                                    'olderThan' => new OA\Schema(
                                        description: 'Cutoff timestamp',
                                        type: 'string',
                                        format: 'date-time',
                                    ),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Days is not a whole number, or lies outside 1 to ' . JobMonitorAdministrationInterface::MAX_PRUNE_DAYS,
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[CliCounterpart('app:monitor:prune')]
    #[Route('/prune', name: 'prune', methods: ['POST'])]
    public function prune(Request $request): JsonResponse
    {
        // The body is optional: without one, as without `days`, the prune keeps the default.
        $body = $request->getContent() === '' ? [] : $request->toArray();
        $result = $this->jobMonitor->prune(self::days($body['days'] ?? JobMonitorAdministrationInterface::DEFAULT_PRUNE_DAYS));

        return $this->successResponse([
            'pruned' => $result->count,
            'olderThan' => $result->olderThan->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * Reads the days a prune keeps as `app:monitor:prune --days` does: a whole number, as a JSON
     * integer or its decimal string. The use case refuses one less than 1.
     *
     * @throws InvalidInputException when the value is not a whole number
     */
    private static function days(mixed $value): int
    {
        $days = match (true) {
            is_int($value) => $value,
            is_string($value) => filter_var($value, FILTER_VALIDATE_INT),
            default => false,
        };

        return $days === false
            ? throw new InvalidInputException('Days must be an integer.', ['days' => 'Days must be an integer.'])
            : $days;
    }

    /**
     * Retry a failed job by re-dispatching its original message payload.
     */
    #[OA\Operation\Post(
        path: '/api/monitor/jobs/{jobId}/retry',
        summary: 'Retry a failed background job',
        description: 'Re-dispatches the original message payload of a failed job. The job must be in Failed status, not previously retried, and have a stored message payload.',
        parameters: [
            new OA\Parameter(
                name: 'jobId',
                in: 'path',
                description: 'Public job identifier',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Job retried successfully',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'newJobId' => new OA\Schema(description: 'Job ID of the newly dispatched job', type: 'string'),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Job not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '409',
                description: 'Job cannot be retried: it has not failed, was already retried, or has no readable stored message',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
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
     * Cancel a running job at its next checkpoint, or the work a finished job queued.
     */
    #[OA\Operation\Post(
        path: '/api/monitor/jobs/{jobId}/cancel',
        summary: 'Cancel a running background job, or the work a finished job queued',
        description: 'Sets a cooperative cancellation flag in Redis; a running job stops at its next checkpoint and becomes cancelled. A finished job that queued work to run later, such as the lyrics fetches of a bulk fetch, has that work cancelled instead: what has not run yet is skipped when it comes due, and the job becomes cancelled.',
        parameters: [
            new OA\Parameter(
                name: 'jobId',
                in: 'path',
                description: 'Public job identifier',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Job cancellation requested',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'cancelled' => new OA\Schema(description: 'Whether the cancellation flag was set', type: 'boolean'),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Job not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '409',
                description: 'Job cannot be cancelled because it has failed, or has finished with no queued work waiting',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
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
