<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Shared\Infrastructure\Health\HealthCheckService;
use App\Shared\Infrastructure\Health\HealthStatus;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'System', description: 'System utilities and background job monitoring')]
final class HealthCheckController
{
    public function __construct(
        private readonly HealthCheckService $healthCheckService,
    )
    {
    }

    #[OA\Get(
        path: '/health',
        description: 'Returns the health status of all system components (PostgreSQL, Redis, Swoole, memory). Used for Docker HEALTHCHECK and orchestration.',
        summary: 'Health check endpoint',
        responses: [
            new OA\Response(
                response: '200',
                description: 'All systems healthy',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'healthy'),
                        new OA\Property(property: 'checks', type: 'array', items: new OA\Items(type: 'object')),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: '503',
                description: 'One or more components unhealthy',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'unhealthy'),
                        new OA\Property(property: 'checks', type: 'array', items: new OA\Items(type: 'object')),
                    ],
                    type: 'object',
                ),
            ),
        ],
    )]
    #[Route('/health', name: 'health_check', methods: ['GET'])]
    public function health(): JsonResponse
    {
        $results = $this->healthCheckService->check();

        return $this->buildResponse($results);
    }

    #[OA\Get(
        path: '/ready',
        description: 'Kubernetes readiness probe. Checks dependency availability (PostgreSQL, Redis, memory). Returns 503 if any dependency is unreachable.',
        summary: 'Readiness probe',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Dependencies ready',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'ready'),
                        new OA\Property(property: 'checks', type: 'array', items: new OA\Items(type: 'object')),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: '503',
                description: 'Dependencies not ready',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'not_ready'),
                        new OA\Property(property: 'checks', type: 'array', items: new OA\Items(type: 'object')),
                    ],
                    type: 'object',
                ),
            ),
        ],
    )]
    #[Route('/ready', name: 'health_ready', methods: ['GET'])]
    public function ready(): JsonResponse
    {
        $results = $this->healthCheckService->checkReadiness();

        return $this->buildResponse($results, 'ready', 'not_ready');
    }

    #[OA\Get(
        path: '/live',
        description: 'Kubernetes liveness probe. Checks if the Swoole worker process is alive. Always returns 200 if the process can respond.',
        summary: 'Liveness probe',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Process is alive',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'alive'),
                        new OA\Property(property: 'checks', type: 'array', items: new OA\Items(type: 'object')),
                    ],
                    type: 'object',
                ),
            ),
        ],
    )]
    #[Route('/live', name: 'health_live', methods: ['GET'])]
    public function live(): JsonResponse
    {
        $result = $this->healthCheckService->checkLiveness();

        return new JsonResponse(
            data: [
                'status' => 'alive',
                'checks' => [$result->toArray()],
            ],
            status: 200,
        );
    }

    /**
     * @param HealthCheckResult[] $results
     */
    private function buildResponse(array $results, string $okStatus = 'healthy', string $failStatus = 'unhealthy'): JsonResponse
    {
        $healthy = true;
        foreach ($results as $result) {
            if ($result->status === HealthStatus::Unhealthy) {
                $healthy = false;
                break;
            }
        }

        return new JsonResponse(
            data: [
                'status' => $healthy ? $okStatus : $failStatus,
                'checks' => array_map(
                    static fn($r) => $r->toArray(),
                    $results,
                ),
            ],
            status: $healthy ? 200 : 503,
        );
    }
}
