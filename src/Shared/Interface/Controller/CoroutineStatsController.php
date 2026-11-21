<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Shared\Application\Port\ServerDiagnosticsInterface;
use App\Shared\Interface\Attribute\CliCounterpart;
use OpenApi\Spec as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Debug', description: 'Debug and profiling endpoints')]
final readonly class CoroutineStatsController
{
    public function __construct(
        private ServerDiagnosticsInterface $diagnostics,
    ) {
    }

    #[OA\Operation\Get(
        path: '/api/debug/coroutines',
        summary: 'Swoole coroutine and channel statistics of every HTTP worker',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Coroutine and channel stats per worker',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'workers' => new OA\Schema(
                                description: 'One entry per HTTP worker that answered: worker_id, coroutines, active_cids, channels',
                                type: 'array',
                                items: new OA\Schema(additionalProperties: true),
                            ),
                            'missing_workers' => new OA\Schema(
                                description: 'Workers that did not answer in time',
                                type: 'array',
                                items: new OA\Schema(type: 'integer'),
                            ),
                            'worker_errors' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(
                                    properties: [
                                        'worker_id' => new OA\Schema(type: 'integer'),
                                        'error' => new OA\Schema(type: 'string'),
                                    ],
                                ),
                            ),
                        ],
                    ),
                ),
            ),
        ],
    )]
    #[Route('/api/debug/coroutines', name: 'debug_coroutines', methods: ['GET'])]
    #[CliCounterpart('app:server:coroutines')]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse($this->diagnostics->coroutines());
    }
}
