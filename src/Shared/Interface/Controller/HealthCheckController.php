<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Shared\Application\Port\ServerDrainStateInterface;
use App\Shared\Infrastructure\Health\HealthCheckService;
use App\Shared\Infrastructure\Health\HealthCheckResult;
use App\Shared\Infrastructure\Health\HealthStatus;
use OpenApi\Spec as OA;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'System', description: 'System utilities and background job monitoring')]
final class HealthCheckController
{
    /** What a public probe shows in place of a failed check's error text. */
    private const string UNAVAILABLE = 'unavailable';

    public function __construct(
        private readonly HealthCheckService $healthCheckService,
        private readonly ServerDrainStateInterface $drain,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    #[OA\Operation\Get(
        path: '/health',
        summary: 'Health check endpoint',
        description: 'Returns the health status of all system components (PostgreSQL, Redis, the background worker, the worker deployment profile, Swoole, memory). A missing worker heartbeat reports the worker as not available until a worker has reported in. The worker profile reads unhealthy when the active worker boot was started with another deployment profile than web runs. The background worker, the worker profile and memory are listed but do not decide the status code: each can read unhealthy while the endpoint answers 200.',
        responses: [
            new OA\Response(
                response: '200',
                description: 'All systems healthy',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'status' => new OA\Schema(type: 'string', example: 'healthy'),
                            'checks' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(type: 'object'),
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '503',
                description: 'One or more components unhealthy',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'status' => new OA\Schema(type: 'string', example: 'unhealthy'),
                            'checks' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(type: 'object'),
                            ),
                        ],
                    ),
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

    #[OA\Operation\Get(
        path: '/ready',
        summary: 'Readiness probe',
        description: 'Kubernetes readiness probe. Checks dependency availability (PostgreSQL, Redis). Returns 503 if any dependency is unreachable, and while the web server drains before it stops; the drain answer lists a single `server` check and checks no dependency. The background worker and memory are not dependencies: a worker outage or a web server worker near its memory limit does not make the web container unready.',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Dependencies ready',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'status' => new OA\Schema(type: 'string', example: 'ready'),
                            'checks' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(type: 'object'),
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '503',
                description: 'Dependencies not ready, or the web server is draining',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'status' => new OA\Schema(type: 'string', example: 'not_ready'),
                            'checks' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(type: 'object'),
                            ),
                        ],
                    ),
                ),
            ),
        ],
    )]
    #[Route('/ready', name: 'health_ready', methods: ['GET'])]
    public function ready(): JsonResponse
    {
        if ($this->drain->isDraining()) {
            return $this->buildResponse(
                [new HealthCheckResult('server', HealthStatus::Unhealthy, 0.0, ['draining' => true])],
                'ready',
                'not_ready',
            );
        }

        $results = $this->healthCheckService->checkReadiness();

        return $this->buildResponse($results, 'ready', 'not_ready');
    }

    #[OA\Operation\Get(
        path: '/live',
        summary: 'Liveness probe',
        description: 'Kubernetes liveness probe. Checks if the Swoole worker process is alive. Always returns 200 if the process can respond, also while the web server drains before it stops; `draining` is then true. A client that asks whether the server is back treats a draining answer as down.',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Process is alive',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'status' => new OA\Schema(type: 'string', example: 'alive'),
                            'draining' => new OA\Schema(
                                description: 'Whether the web server is draining before it stops',
                                type: 'boolean',
                                example: false,
                            ),
                            'checks' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(type: 'object'),
                            ),
                        ],
                        required: ['status', 'draining', 'checks'],
                    ),
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
                // Liveness for orchestrators stays 200; clients read the drain from the body.
                'draining' => $this->drain->isDraining(),
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
        // The worker, its profile and memory are listed, but none makes this container unhealthy.
        $healthy = true;
        foreach ($results as $result) {
            if ($result->status === HealthStatus::Unhealthy && HealthCheckService::affectsContainerHealth($result)) {
                $healthy = false;
                break;
            }
        }

        return new JsonResponse(
            data: [
                'status' => $healthy ? $okStatus : $failStatus,
                'checks' => array_map($this->publicCheck(...), $results),
            ],
            status: $healthy ? 200 : 503,
        );
    }

    /**
     * A check as an unauthenticated probe shows it: an error's text, which can carry hosts,
     * credentials or driver detail, is logged and replaced by a fixed string. The operator
     * console's configuration check keeps the detail.
     *
     * @return array<string, mixed>
     */
    private function publicCheck(HealthCheckResult $result): array
    {
        $check = $result->toArray();
        if (isset($check['details']['error'])) {
            $this->logger->warning('Health probe check {component} failed: {error}', [
                'component' => $result->component,
                'error' => $check['details']['error'],
            ]);
            $check['details']['error'] = self::UNAVAILABLE;
        }

        return $check;
    }
}
