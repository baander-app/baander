<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Shared\Infrastructure\Health\HealthCheckResult;
use App\Shared\Infrastructure\Health\HealthCheckService;
use App\Shared\Interface\Attribute\CliCounterpart;
use OpenApi\Spec as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** The health report the public probes leave out: each check's latency and details, errors included. */
#[OA\Tag(name: 'System', description: 'System utilities and background job monitoring')]
final readonly class HealthReportController
{
    public function __construct(
        private HealthCheckService $healthCheckService,
    ) {
    }

    #[OA\Operation\Get(
        path: '/api/debug/health',
        summary: 'Full health report',
        description: 'Runs the checks of `/health` and returns each with its latency and details: connection state, Redis key count, Swoole figures, the largest web worker\'s memory against its limit, the background worker\'s heartbeat, the deployment profile fingerprints, and a failed dependency\'s error. The status code follows `/health`: the background worker, the worker profile and memory can read unhealthy while it answers 200.',
        responses: [
            new OA\Response(
                response: '200',
                description: 'This container is healthy',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'status' => new OA\Schema(type: 'string', enum: ['healthy']),
                            'checks' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(
                                    type: 'object',
                                    properties: [
                                        'component' => new OA\Schema(type: 'string', example: 'postgresql'),
                                        'status' => new OA\Schema(type: 'string', enum: ['healthy', 'unhealthy', 'not_available']),
                                        'responseTimeMs' => new OA\Schema(type: 'number'),
                                        'details' => new OA\Schema(type: 'object'),
                                    ],
                                    required: ['component', 'status', 'responseTimeMs', 'details'],
                                ),
                            ),
                        ],
                        required: ['status', 'checks'],
                    ),
                ),
            ),
            new OA\Response(
                response: '503',
                description: 'A component that decides this container\'s health is unhealthy; the body is the same report, with status `unhealthy`',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(type: 'object'),
                ),
            ),
        ],
    )]
    #[Route('/api/debug/health', name: 'debug_health', methods: ['GET'])]
    #[CliCounterpart('app:health:check')]
    public function __invoke(): JsonResponse
    {
        $results = $this->healthCheckService->check();
        $healthy = HealthCheckService::containerHealthy($results);

        return new JsonResponse(
            data: [
                'status' => $healthy ? 'healthy' : 'unhealthy',
                'checks' => array_map(static fn (HealthCheckResult $result): array => $result->toArray(), $results),
            ],
            status: $healthy ? 200 : 503,
        );
    }
}
