<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Shared\Application\Port\ServerDiagnosticsInterface;
use App\Shared\Interface\Attribute\CliCounterpart;
use OpenApi\Spec as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Per-worker server diagnostics, read through the server control channel. */
#[OA\Tag(name: 'System', description: 'System utilities and background job monitoring')]
#[Route('/api/debug', name: 'debug_')]
final readonly class ServerStatsControllerWithCoroutines
{
    public function __construct(
        private ServerDiagnosticsInterface $diagnostics,
    ) {
    }

    #[OA\Operation\Get(
        path: '/api/debug/stats',
        summary: 'Per-worker server diagnostics with the shared Redis figures',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Server stats snapshot',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'workers' => new OA\Schema(
                                        description: 'One entry per HTTP worker that answered: worker_id, memory, process, requests, swoole, coroutines, pools, live_updates',
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
                                            type: 'object',
                                            properties: [
                                                'worker_id' => new OA\Schema(type: 'integer'),
                                                'error' => new OA\Schema(type: 'string'),
                                            ],
                                        ),
                                    ),
                                    'redis' => new OA\Schema(additionalProperties: true),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
        ],
    )]
    #[Route('/stats', name: 'stats', methods: ['GET'])]
    #[CliCounterpart('app:server:stats')]
    public function stats(): JsonResponse
    {
        return new JsonResponse(['data' => $this->diagnostics->stats()]);
    }
}
