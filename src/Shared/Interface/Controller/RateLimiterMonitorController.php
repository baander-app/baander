<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use OpenApi\Attributes as OA;
use Nelmio\ApiDocBundle\Attribute\Model;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[OA\Tag(name: 'System', description: 'System utilities and background job monitoring')]
#[Route('/api/monitor/rate-limiters', name: 'monitor_ratelimiters_')]
final class RateLimiterMonitorController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly CacheItemPoolInterface $rateLimiterCache,
    )
    {
    }

    /**
     * List all rate limiters with their configuration.
     */
    #[OA\Get(
        path: '/api/monitor/rate-limiters',
        summary: 'List all rate limiters with configuration',
        responses: [
            new OA\Response(response: '200', description: 'Rate limiter configurations',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'limiters', description: 'Map of rate limiter name to configuration',
                            type: 'object',
                            additionalProperties: true,
                        ),
                        new OA\Property(property: 'count', description: 'Total number of configured rate limiters', type: 'integer'),
                        new OA\Property(property: 'cachePool', type: 'string', example: 'cache.rate_limiter'),
                    ], type: 'object')],
                    type: 'object',
                ),
            ),
        ],
    )]
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $definitions = self::getLimiterDefinitions();

        return $this->successResponse([
            'limiters'  => $definitions,
            'count'     => count($definitions),
            'cachePool' => 'cache.rate_limiter',
        ]);
    }

    /**
     * Limiter configuration catalog.
     *
     * MUST be kept in sync with config/packages/framework.yaml rate_limiter section
     * and config/packages/auth.yaml rate_limit parameters.
     *
     * @return array<string, array{policy: string, limit: int, interval: string, description: string}>
     */
    private static function getLimiterDefinitions(): array
    {
        return [
            'anonymous_api'          => [
                'policy'      => 'sliding_window',
                'limit'       => 360,
                'interval'    => '60 seconds',
                'description' => 'Anonymous API requests',
            ],
            'authenticated_api'      => [
                'policy'      => 'sliding_window',
                'limit'       => 1800,
                'interval'    => '60 seconds',
                'description' => 'Authenticated API requests',
            ],
            'oauth_token'            => [
                'policy'      => 'fixed_window',
                'limit'       => 60,
                'interval'    => '60 seconds',
                'description' => 'OAuth2 token issuance',
            ],
            'password_reset'         => [
                'policy'      => 'fixed_window',
                'limit'       => 10,
                'interval'    => '15 minutes',
                'description' => 'Password reset token requests',
            ],
            'auth_login_ip'          => [
                'policy'      => 'fixed_window',
                'limit'       => 20,
                'interval'    => '300 seconds',
                'description' => 'Login attempts per IP (brute-force protection)',
            ],
            'auth_login_ip_email'    => [
                'policy'      => 'fixed_window',
                'limit'       => 10,
                'interval'    => '300 seconds',
                'description' => 'Login attempts per IP+email (distributed brute-force protection)',
            ],
            'auth_register_ip'       => [
                'policy'      => 'fixed_window',
                'limit'       => 10,
                'interval'    => '900 seconds',
                'description' => 'Registration attempts per IP',
            ],
            'auth_password_reset_ip' => [
                'policy'      => 'fixed_window',
                'limit'       => 10,
                'interval'    => '900 seconds',
                'description' => 'Password reset requests per IP',
            ],
            'auth_refresh_client'    => [
                'policy'      => 'fixed_window',
                'limit'       => 60,
                'interval'    => '60 seconds',
                'description' => 'Token refresh per client',
            ],
            'batch_cover_extract'    => [
                'policy'      => 'sliding_window',
                'limit'       => 10,
                'interval'    => '60 seconds',
                'description' => 'Album cover batch extraction',
            ],
        ];
    }

    /**
     * Clear all rate limiter state from Redis.
     *
     * Since all rate limiters share the same cache pool (cache.rate_limiter),
     * this operation resets state for every limiter. Use to unblock rate-limited
     * users after configuration changes or during incidents.
     */
    #[OA\Delete(
        path: '/api/monitor/rate-limiters/{name}/clear',
        description: 'Clears ALL rate limiter state from Redis. Since all limiters share the same cache pool, this resets every limiter. Requires ?confirm=true.',
        summary: 'Clear all rate limiter state',
        parameters: [
            new OA\Parameter(name: 'name', description: 'Rate limiter name (for audit purposes)', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'auth_login_ip'),
            new OA\Parameter(name: 'confirm', description: 'Must be "true" to confirm', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['true'])),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Rate limiter state cleared',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'cleared', type: 'boolean'),
                        new OA\Property(property: 'limiter', type: 'string'),
                        new OA\Property(property: 'pool', type: 'string'),
                    ], type: 'object')],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '404', description: 'Unknown limiter name', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Missing confirm parameter', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
            new OA\Response(response: '503', description: 'Cache pool clear failed', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{name}/clear', name: 'clear', methods: ['DELETE'])]
    public function clear(Request $request, string $name): JsonResponse
    {
        $definitions = self::getLimiterDefinitions();

        if (!isset($definitions[$name])) {
            return $definitions
                    |> array_keys(...)
                    |> (fn($x) => implode(', ', $x))
                    |> (fn($x) => sprintf('Unknown rate limiter "%s". Valid names: %s', $name, $x))
                    |> (fn($x) => $this->errorResponse($x, Response::HTTP_NOT_FOUND));
        }

        if ($request->query->get('confirm') !== 'true') {
            return $this->errorResponse(
                'Missing confirm parameter. Pass ?confirm=true to confirm the operation.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $cleared = $this->rateLimiterCache->clear();

        if (!$cleared) {
            return $this->errorResponse(
                'Failed to clear rate limiter cache pool.',
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return $this->successResponse([
            'cleared' => true,
            'limiter' => $name,
            'pool'    => 'cache.rate_limiter',
        ]);
    }
}
