<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Shared\Infrastructure\Messenger\JobMonitorService;
use OpenApi\Attributes as OA;
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
        private readonly JobMonitorService $jobMonitorService,
    )
    {
    }

    /**
     * Get analytics summary for a time range.
     *
     * Returns status counts, job type breakdown, success rate, and throughput per hour.
     */
    #[OA\Get(
        path: '/api/monitor/analytics/summary',
        description: 'Returns status counts, job type breakdown, success rate, and throughput per hour.',
        summary: 'Get analytics summary for a time range',
        parameters: [
            new OA\Parameter(name: 'from', description: 'Start of time range (ISO 8601). Defaults to 24 hours ago.', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'to', description: 'End of time range (ISO 8601). Defaults to now.', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Analytics summary',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'statusCounts', description: 'Job counts grouped by status', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'integer')),
                        new OA\Property(property: 'jobTypeBreakdown', description: 'Job counts grouped by job type name', type: 'array', items: new OA\Items(properties: [
                            new OA\Property(property: 'name', type: 'string'),
                            new OA\Property(property: 'count', type: 'integer'),
                        ], type: 'object')),
                        new OA\Property(property: 'successRate', description: 'Ratio of finished jobs to total completed jobs (0-1)', type: 'number', format: 'float'),
                        new OA\Property(property: 'throughputPerHour', description: 'Completed jobs per hour in the time range', type: 'number', format: 'float'),
                    ], type: 'object')],
                    type: 'object',
                ),
            ),
        ],
    )]
    #[Route('/summary', name: 'summary', methods: ['GET'])]
    public function summary(Request $request): JsonResponse
    {
        [$from, $to] = $this->parseTimeRange($request);

        return $this->successResponse(
            $this->jobMonitorService->getAnalyticsSummary($from, $to),
        );
    }

    /**
     * Get timing analytics for a time range.
     *
     * Returns average, median, and P95 execution times per job type,
     * as well as average queue latency per job type.
     */
    #[OA\Get(
        path: '/api/monitor/analytics/timing',
        description: 'Returns average, median, and P95 execution times and queue latency per job type.',
        summary: 'Get timing analytics for a time range',
        parameters: [
            new OA\Parameter(name: 'from', description: 'Start of time range (ISO 8601). Defaults to 24 hours ago.', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'to', description: 'End of time range (ISO 8601). Defaults to now.', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Timing analytics',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'executionTimes', description: 'Execution time statistics per job type (seconds)', type: 'array', items: new OA\Items(properties: [
                            new OA\Property(property: 'name', type: 'string'),
                            new OA\Property(property: 'avg', type: 'number', format: 'float'),
                            new OA\Property(property: 'median', type: 'number', format: 'float'),
                            new OA\Property(property: 'p95', type: 'number', format: 'float'),
                        ], type: 'object')),
                        new OA\Property(property: 'queueLatency', description: 'Average queue latency per job type (seconds)', type: 'array', items: new OA\Items(properties: [
                            new OA\Property(property: 'name', type: 'string'),
                            new OA\Property(property: 'avg', type: 'number', format: 'float'),
                        ], type: 'object')),
                    ], type: 'object')],
                    type: 'object',
                ),
            ),
        ],
    )]
    #[Route('/timing', name: 'timing', methods: ['GET'])]
    public function timing(Request $request): JsonResponse
    {
        [$from, $to] = $this->parseTimeRange($request);

        return $this->successResponse(
            $this->jobMonitorService->getAnalyticsTiming($from, $to),
        );
    }

    /**
     * Get failure analytics for a time range.
     *
     * Returns top failing job types, top exception classes, retry frequency, and recent failures.
     */
    #[OA\Get(
        path: '/api/monitor/analytics/failures',
        description: 'Returns top failing job types, top exception classes, retry frequency, and recent failures.',
        summary: 'Get failure analytics for a time range',
        parameters: [
            new OA\Parameter(name: 'from', description: 'Start of time range (ISO 8601). Defaults to 24 hours ago.', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'to', description: 'End of time range (ISO 8601). Defaults to now.', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'limit', description: 'Maximum number of recent failures to return (1-200)', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 50, maximum: 200, minimum: 1)),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Failure analytics',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'topFailingTypes', description: 'Top 10 failing job types by count', type: 'array', items: new OA\Items(properties: [
                            new OA\Property(property: 'name', type: 'string'),
                            new OA\Property(property: 'count', type: 'integer'),
                        ], type: 'object')),
                        new OA\Property(property: 'topExceptionClasses', description: 'Top 10 exception classes by count', type: 'array', items: new OA\Items(properties: [
                            new OA\Property(property: 'class', type: 'string'),
                            new OA\Property(property: 'count', type: 'integer'),
                        ], type: 'object')),
                        new OA\Property(property: 'retryFrequency', description: 'Retry statistics for failed jobs', properties: [
                            new OA\Property(property: 'retried', type: 'integer'),
                            new OA\Property(property: 'total', type: 'integer'),
                            new OA\Property(property: 'rate', type: 'number', format: 'float'),
                        ], type: 'object'),
                        new OA\Property(property: 'recentFailures', description: 'Most recent failures in the time range', type: 'array', items: new OA\Items(properties: [
                            new OA\Property(property: 'jobId', type: 'string'),
                            new OA\Property(property: 'name', type: 'string', nullable: true),
                            new OA\Property(property: 'exceptionClass', type: 'string', nullable: true),
                            new OA\Property(property: 'exceptionMessage', type: 'string', nullable: true),
                            new OA\Property(property: 'failedAt', type: 'string', format: 'date-time', nullable: true),
                        ], type: 'object')),
                    ], type: 'object')],
                    type: 'object',
                ),
            ),
        ],
    )]
    #[Route('/failures', name: 'failures', methods: ['GET'])]
    public function failures(Request $request): JsonResponse
    {
        [$from, $to] = $this->parseTimeRange($request);
        $limit = $this->parseLimit($request);

        return $this->successResponse(
            $this->jobMonitorService->getAnalyticsFailures($from, $to, $limit),
        );
    }

    /**
     * Parse and validate the time range from query parameters.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    private function parseTimeRange(Request $request): array
    {
        $from = $request->query->get('from');
        $to = $request->query->get('to');

        $fromDate = $from !== null ? new \DateTimeImmutable($from) : new \DateTimeImmutable('-24 hours');
        $toDate = $to !== null ? new \DateTimeImmutable($to) : new \DateTimeImmutable();

        // Clamp: maximum 90 days
        $maxRange = new \DateInterval('P90D');
        $maxTo = (clone $fromDate)->add($maxRange);
        if ($toDate > $maxTo) {
            $toDate = $maxTo;
        }

        return [$fromDate, $toDate];
    }

    private function parseLimit(Request $request, int $default = 50, int $max = 200): int
    {
        return max(1, min((int)($request->query->get('limit') ?? $default), $max));
    }
}
