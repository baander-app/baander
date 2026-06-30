<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Shared\Infrastructure\Swoole\CoroutineStatsProvider;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Debug', description: 'Debug and profiling endpoints')]
final class CoroutineStatsController
{
    public function __construct(
        private readonly CoroutineStatsProvider $coroutineStatsProvider,
    )
    {
    }

    #[OA\Get(
        path: '/api/debug/coroutines',
        summary: 'Swoole coroutine and channel statistics',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Coroutine and channel stats',
                content: new OA\JsonContent(type: 'object'),
            ),
        ],
    )]
    #[Route('/api/debug/coroutines', name: 'debug_coroutines', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse($this->coroutineStatsProvider->getStats());
    }
}
