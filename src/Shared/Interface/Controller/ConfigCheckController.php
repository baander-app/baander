<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Shared\Infrastructure\Health\HealthCheckService;
use App\Shared\Infrastructure\Health\HealthStatus;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[OA\Tag(name: 'System', description: 'System utilities and background job monitoring')]
#[Route('/api/debug', name: 'debug_')]
final class ConfigCheckController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly HealthCheckService $healthCheckService,
        private readonly RateLimiterFactoryInterface $configCheckLimiter,
    )
    {
    }

    /**
     * Validate application configuration.
     *
     * Runs all configuration checks (env vars, connectivity, framework config)
     * and returns results with a summary. Used by the admin Configuration page.
     */
    #[OA\Get(
        path: '/api/debug/config-check',
        summary: 'Validate application configuration',
        responses: [
            new OA\Response(response: '200', description: 'Configuration check results',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'results', type: 'array', items: new OA\Items(type: 'object')),
                            new OA\Property(property: 'summary', properties: [
                                new OA\Property(property: 'errors', type: 'integer'),
                                new OA\Property(property: 'warnings', type: 'integer'),
                                new OA\Property(property: 'passed', type: 'integer'),
                            ], type: 'object'),
                        ], type: 'object'),
                    ],
                    type: 'object',
                ),
            ),
        ],
    )]
    #[Route('/config-check', name: 'config_check', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $limiter = $this->configCheckLimiter->create('config-check');
        $result = $limiter->consume(1);

        if (!$result->isAccepted()) {
            $retryAfter = (int)ceil($result->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException($retryAfter, 'Too many requests. Please try again later.');
        }

        $results = $this->healthCheckService->checkConfiguration();

        $errors = 0;
        $warnings = 0;
        $passed = 0;

        foreach ($results as $result) {
            $severity = $result->details['severity'] ?? null;
            if ($severity === 'error' || $result->status === HealthStatus::Unhealthy) {
                $errors++;
            } else if ($severity === 'warning') {
                $warnings++;
            } else {
                $passed++;
            }
        }

        return $this->successResponse([
            'results' => array_map(fn($r) => $r->toArray(), $results),
            'summary' => [
                'errors'   => $errors,
                'warnings' => $warnings,
                'passed'   => $passed,
            ],
        ]);
    }
}
