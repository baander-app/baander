<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use Baander\RedisRateLimiter\Exception\RateLimiterClearFailedException;
use Baander\RedisRateLimiter\Exception\UnknownRateLimiterException;
use Baander\RedisRateLimiter\Administration\RateLimiterAdministrationInterface;
use App\Shared\Interface\Attribute\CliCounterpart;
use OpenApi\Spec as OA;
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
        private readonly RateLimiterAdministrationInterface $rateLimiters,
    ) {
    }

    /**
     * List every configured rate limiter with its effective configuration.
     */
    #[OA\Operation\Get(
        path: '/api/monitor/rate-limiters',
        summary: 'List all rate limiters with configuration',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Rate limiter configurations',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'limiters' => new OA\Schema(
                                        description: 'Map of rate limiter name to configuration',
                                        type: 'object',
                                        additionalProperties: new OA\Schema(
                                            type: 'object',
                                            properties: [
                                                'policy' => new OA\Schema(type: 'string', example: 'fixed_window'),
                                                'limit' => new OA\Schema(type: 'integer'),
                                                'interval' => new OA\Schema(
                                                    type: 'string',
                                                    nullable: true,
                                                    example: '60 seconds',
                                                ),
                                                'description' => new OA\Schema(type: 'string', nullable: true),
                                                'storageNamespace' => new OA\Schema(
                                                    description: 'Redis namespace holding only this limiter\'s state',
                                                    type: 'string',
                                                    example: 'baander:rate-limiter:{61d020e0131329c31cff1092c253794a7b7e3468b31b7ce86c054e2c7a5d7bce}',
                                                ),
                                            ],
                                        ),
                                    ),
                                    'count' => new OA\Schema(description: 'Total number of configured rate limiters', type: 'integer'),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
        ],
    )]
    #[Route('', name: 'list', methods: ['GET'])]
    #[CliCounterpart('app:rate-limiter:list')]
    public function list(): JsonResponse
    {
        $limiters = [];
        foreach ($this->rateLimiters->list() as $limiter) {
            $limiters[$limiter->name] = [
                'policy'      => $limiter->policy,
                'limit'       => $limiter->limit,
                'interval'    => $limiter->interval,
                'description' => $limiter->description,
                'storageNamespace'   => $limiter->storageNamespace,
            ];
        }

        return $this->successResponse([
            'limiters' => $limiters,
            'count'    => count($limiters),
        ]);
    }

    /**
     * Clear the state of every rate limiter.
     *
     * Use to unblock rate-limited clients after configuration changes or during incidents.
     */
    #[OA\Operation\Delete(
        path: '/api/monitor/rate-limiters/clear',
        summary: 'Clear all rate limiter state',
        description: 'Clears the stored state of every configured rate limiter. Requires ?confirm=true.',
        parameters: [
            new OA\Parameter(
                name: 'confirm',
                in: 'query',
                description: 'Must be "true" to confirm',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['true'],
                ),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'All rate limiter state cleared',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'cleared' => new OA\Schema(type: 'boolean'),
                                    'limiters' => new OA\Schema(
                                        type: 'array',
                                        items: new OA\Schema(type: 'string'),
                                    ),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Missing confirm parameter',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ValidationError::class),
                ),
            ),
            new OA\Response(
                response: '503',
                description: 'Limiter state could not be cleared',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/clear', name: 'clear_all', methods: ['DELETE'])]
    #[CliCounterpart('app:rate-limiter:clear')]
    public function clearAll(Request $request): JsonResponse
    {
        if (!$this->isConfirmed($request)) {
            return $this->missingConfirmation();
        }

        try {
            $cleared = $this->rateLimiters->clearAll();
        } catch (RateLimiterClearFailedException $e) {
            return $this->clearFailed($e);
        }

        return $this->successResponse([
            'cleared'  => true,
            'limiters' => $cleared,
        ]);
    }

    /**
     * Clear the state of one rate limiter, leaving every other limiter intact.
     */
    #[OA\Operation\Delete(
        path: '/api/monitor/rate-limiters/{name}/clear',
        summary: 'Clear one rate limiter\'s state',
        description: 'Clears the stored state of the named rate limiter only. Other limiters keep their state. Requires ?confirm=true.',
        parameters: [
            new OA\Parameter(
                name: 'name',
                in: 'path',
                description: 'Rate limiter name',
                required: true,
                schema: new OA\Schema(type: 'string'),
                example: 'auth_login_ip',
            ),
            new OA\Parameter(
                name: 'confirm',
                in: 'query',
                description: 'Must be "true" to confirm',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['true'],
                ),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Rate limiter state cleared',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'cleared' => new OA\Schema(type: 'boolean'),
                                    'limiter' => new OA\Schema(type: 'string'),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Unknown limiter name',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Missing confirm parameter',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ValidationError::class),
                ),
            ),
            new OA\Response(
                response: '503',
                description: 'Storage namespace clear failed',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/{name}/clear', name: 'clear', methods: ['DELETE'])]
    #[CliCounterpart('app:rate-limiter:clear')]
    public function clear(Request $request, string $name): JsonResponse
    {
        if (!$this->isConfirmed($request)) {
            return $this->missingConfirmation();
        }

        try {
            $this->rateLimiters->clear($name);
        } catch (UnknownRateLimiterException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (RateLimiterClearFailedException $e) {
            return $this->clearFailed($e);
        }

        return $this->successResponse([
            'cleared' => true,
            'limiter' => $name,
        ]);
    }

    private function clearFailed(RateLimiterClearFailedException $exception): JsonResponse
    {
        $response = $this->errorResponse($exception->getMessage(), Response::HTTP_SERVICE_UNAVAILABLE);
        $response->headers->set('Retry-After', '1');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function isConfirmed(Request $request): bool
    {
        return $request->query->get('confirm') === 'true';
    }

    private function missingConfirmation(): JsonResponse
    {
        return $this->errorResponse(
            'Missing confirm parameter. Pass ?confirm=true to confirm the operation.',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
