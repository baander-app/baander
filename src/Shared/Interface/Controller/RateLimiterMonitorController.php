<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Shared\Application\Exception\RateLimiterClearFailedException;
use App\Shared\Application\Exception\UnknownRateLimiterException;
use App\Shared\Application\Port\RateLimiterAdministrationInterface;
use App\Shared\Interface\Attribute\CliCounterpart;
use OpenApi\Attributes as OA;
use Nelmio\ApiDocBundle\Attribute\Model;
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
    )
    {
    }

    /**
     * List every configured rate limiter with its effective configuration.
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
                            additionalProperties: new OA\AdditionalProperties(properties: [
                                new OA\Property(property: 'policy', type: 'string', example: 'fixed_window'),
                                new OA\Property(property: 'limit', type: 'integer'),
                                new OA\Property(property: 'interval', type: 'string', example: '60 seconds', nullable: true),
                                new OA\Property(property: 'description', type: 'string', nullable: true),
                                new OA\Property(property: 'cachePool', description: 'Cache pool holding only this limiter\'s state', type: 'string', example: 'cache.rate_limiter.auth_login_ip'),
                            ], type: 'object'),
                        ),
                        new OA\Property(property: 'count', description: 'Total number of configured rate limiters', type: 'integer'),
                    ], type: 'object')],
                    type: 'object',
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
                'cachePool'   => $limiter->cachePool,
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
    #[OA\Delete(
        path: '/api/monitor/rate-limiters/clear',
        description: 'Clears the stored state of every configured rate limiter. Requires ?confirm=true.',
        summary: 'Clear all rate limiter state',
        parameters: [
            new OA\Parameter(name: 'confirm', description: 'Must be "true" to confirm', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['true'])),
        ],
        responses: [
            new OA\Response(response: '200', description: 'All rate limiter state cleared',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'cleared', type: 'boolean'),
                        new OA\Property(property: 'limiters', type: 'array', items: new OA\Items(type: 'string')),
                    ], type: 'object')],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '422', description: 'Missing confirm parameter', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
            new OA\Response(response: '503', description: 'A cache pool could not be cleared', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
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
            return $this->errorResponse($e->getMessage(), Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->successResponse([
            'cleared'  => true,
            'limiters' => $cleared,
        ]);
    }

    /**
     * Clear the state of one rate limiter, leaving every other limiter intact.
     */
    #[OA\Delete(
        path: '/api/monitor/rate-limiters/{name}/clear',
        description: 'Clears the stored state of the named rate limiter only. Other limiters keep their state. Requires ?confirm=true.',
        summary: 'Clear one rate limiter\'s state',
        parameters: [
            new OA\Parameter(name: 'name', description: 'Rate limiter name', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'auth_login_ip'),
            new OA\Parameter(name: 'confirm', description: 'Must be "true" to confirm', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['true'])),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Rate limiter state cleared',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'cleared', type: 'boolean'),
                        new OA\Property(property: 'limiter', type: 'string'),
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
            return $this->errorResponse($e->getMessage(), Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->successResponse([
            'cleared' => true,
            'limiter' => $name,
        ]);
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
