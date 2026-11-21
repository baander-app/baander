<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Shared\Application\DTO\JobAnalyticsRange;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\DTO\ApiError;
use App\Shared\Interface\Exception\InvalidQueryParameter;
use App\Shared\Interface\Request\QueryParameters;
use OpenApi\Spec as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[OA\Tag(name: 'System', description: 'System utilities and background job monitoring')]
#[Route('/api/monitor/analytics', name: 'monitor_analytics_')]
final class JobAnalyticsController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly JobMonitorAdministrationInterface $jobMonitor,
    ) {
    }

    /**
     * Get analytics summary for a time range.
     *
     * Returns status counts, job type breakdown, success rate, and throughput per hour.
     */
    #[OA\Operation\Get(
        path: '/api/monitor/analytics/summary',
        summary: 'Get analytics summary for a time range',
        description: 'Returns status counts, job type breakdown, success rate, and throughput per hour.',
        parameters: [
            new OA\Parameter(
                name: 'from',
                in: 'query',
                description: 'Inclusive start of the job creation range (RFC 3339 with a timezone, up to six fractional digits). Defaults to 24 hours ago.',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date-time'),
            ),
            new OA\Parameter(
                name: 'to',
                in: 'query',
                description: 'Exclusive end of the job creation range (RFC 3339 with a timezone, up to six fractional digits); must be after from. Defaults to now. A range longer than 90 days ends 90 days after from.',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date-time'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '400',
                description: 'Invalid time range',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '200',
                description: 'Analytics summary',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'statusCounts' => new OA\Schema(
                                        description: 'Job counts grouped by status',
                                        type: 'object',
                                        additionalProperties: new OA\Schema(type: 'integer'),
                                    ),
                                    'jobTypeBreakdown' => new OA\Schema(
                                        description: 'Job counts grouped by job type name',
                                        type: 'array',
                                        items: new OA\Schema(
                                            type: 'object',
                                            properties: [
                                                'name' => new OA\Schema(type: 'string'),
                                                'count' => new OA\Schema(type: 'integer'),
                                            ],
                                        ),
                                    ),
                                    'successRate' => new OA\Schema(
                                        description: 'Ratio of finished jobs to total completed jobs (0-1)',
                                        type: 'number',
                                        format: 'float',
                                    ),
                                    'throughputPerHour' => new OA\Schema(
                                        description: 'Completed jobs per hour in the time range',
                                        type: 'number',
                                        format: 'float',
                                    ),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
        ],
    )]
    #[CliCounterpart('app:monitor:analytics')]
    #[Route('/summary', name: 'summary', methods: ['GET'])]
    public function summary(Request $request): JsonResponse
    {
        return $this->successResponse($this->jobMonitor->analyticsSummary($this->range($request)));
    }

    /**
     * Get timing analytics for a time range.
     *
     * Returns average, median, and P95 execution times per job type,
     * as well as average queue latency per job type.
     */
    #[OA\Operation\Get(
        path: '/api/monitor/analytics/timing',
        summary: 'Get timing analytics for a time range',
        description: 'Returns average, median, and P95 execution times and queue latency per job type.',
        parameters: [
            new OA\Parameter(
                name: 'from',
                in: 'query',
                description: 'Inclusive start of the job creation range (RFC 3339 with a timezone, up to six fractional digits). Defaults to 24 hours ago.',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date-time'),
            ),
            new OA\Parameter(
                name: 'to',
                in: 'query',
                description: 'Exclusive end of the job creation range (RFC 3339 with a timezone, up to six fractional digits); must be after from. Defaults to now. A range longer than 90 days ends 90 days after from.',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date-time'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '400',
                description: 'Invalid time range',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '200',
                description: 'Timing analytics',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'executionTimes' => new OA\Schema(
                                        description: 'Execution time statistics per job type (seconds)',
                                        type: 'array',
                                        items: new OA\Schema(
                                            type: 'object',
                                            properties: [
                                                'name' => new OA\Schema(type: 'string'),
                                                'avg' => new OA\Schema(type: 'number', format: 'float'),
                                                'median' => new OA\Schema(type: 'number', format: 'float'),
                                                'p95' => new OA\Schema(type: 'number', format: 'float'),
                                            ],
                                        ),
                                    ),
                                    'queueLatency' => new OA\Schema(
                                        description: 'Average queue latency per job type (seconds)',
                                        type: 'array',
                                        items: new OA\Schema(
                                            type: 'object',
                                            properties: [
                                                'name' => new OA\Schema(type: 'string'),
                                                'avg' => new OA\Schema(type: 'number', format: 'float'),
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
    #[CliCounterpart('app:monitor:analytics')]
    #[Route('/timing', name: 'timing', methods: ['GET'])]
    public function timing(Request $request): JsonResponse
    {
        return $this->successResponse($this->jobMonitor->analyticsTiming($this->range($request)));
    }

    /**
     * Get failure analytics for a time range.
     *
     * Returns top failing job types, top exception classes, retry frequency, and recent failures.
     */
    #[OA\Operation\Get(
        path: '/api/monitor/analytics/failures',
        summary: 'Get failure analytics for a time range',
        description: 'Returns top failing job types, top exception classes, retry frequency, and recent failures.',
        parameters: [
            new OA\Parameter(
                name: 'from',
                in: 'query',
                description: 'Inclusive start of the job creation range (RFC 3339 with a timezone, up to six fractional digits). Defaults to 24 hours ago.',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date-time'),
            ),
            new OA\Parameter(
                name: 'to',
                in: 'query',
                description: 'Exclusive end of the job creation range (RFC 3339 with a timezone, up to six fractional digits); must be after from. Defaults to now. A range longer than 90 days ends 90 days after from.',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date-time'),
            ),
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                description: 'Maximum number of recent failures to return (1-200)',
                required: false,
                schema: new OA\Schema(
                    type: 'integer',
                    minimum: 1,
                    maximum: 200,
                    default: 50,
                ),
            ),
        ],
        responses: [
            new OA\Response(
                response: '400',
                description: 'Invalid time range',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '200',
                description: 'Failure analytics',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'topFailingTypes' => new OA\Schema(
                                        description: 'Top 10 failing job types by count',
                                        type: 'array',
                                        items: new OA\Schema(
                                            type: 'object',
                                            properties: [
                                                'name' => new OA\Schema(type: 'string'),
                                                'count' => new OA\Schema(type: 'integer'),
                                            ],
                                        ),
                                    ),
                                    'topExceptionClasses' => new OA\Schema(
                                        description: 'Top 10 exception classes by count',
                                        type: 'array',
                                        items: new OA\Schema(
                                            type: 'object',
                                            properties: [
                                                'class' => new OA\Schema(type: 'string'),
                                                'count' => new OA\Schema(type: 'integer'),
                                            ],
                                        ),
                                    ),
                                    'retryFrequency' => new OA\Schema(
                                        description: 'Retry statistics for failed jobs',
                                        type: 'object',
                                        properties: [
                                            'retried' => new OA\Schema(type: 'integer'),
                                            'total' => new OA\Schema(type: 'integer'),
                                            'rate' => new OA\Schema(type: 'number', format: 'float'),
                                        ],
                                    ),
                                    'recentFailures' => new OA\Schema(
                                        description: 'Most recent failures in the time range',
                                        type: 'array',
                                        items: new OA\Schema(
                                            type: 'object',
                                            properties: [
                                                'jobId' => new OA\Schema(type: 'string'),
                                                'name' => new OA\Schema(type: 'string', nullable: true),
                                                'exceptionClass' => new OA\Schema(type: 'string', nullable: true),
                                                'exceptionMessage' => new OA\Schema(type: 'string', nullable: true),
                                                'failedAt' => new OA\Schema(
                                                    type: 'string',
                                                    format: 'date-time',
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
    #[CliCounterpart('app:monitor:analytics')]
    #[Route('/failures', name: 'failures', methods: ['GET'])]
    public function failures(Request $request): JsonResponse
    {
        return $this->successResponse($this->jobMonitor->analyticsFailures(
            $this->range($request),
            (int) ($request->query->get('limit') ?? 50),
        ));
    }

    /**
     * The half-open job creation range [from, to). A malformed instant and an end that is
     * not after the start are both bad requests that name the parameter.
     */
    private function range(Request $request): JobAnalyticsRange
    {
        try {
            return JobAnalyticsRange::resolve(
                QueryParameters::optionalDateTime($request->query, 'from'),
                QueryParameters::optionalDateTime($request->query, 'to'),
            );
        } catch (InvalidInputException $exception) {
            throw new InvalidQueryParameter('to', $exception->getMessage());
        }
    }
}
