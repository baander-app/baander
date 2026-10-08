<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Shared\Application\Port\ServerDiagnosticsInterface;
use App\Shared\Interface\Attribute\CliCounterpart;
use OpenApi\Attributes as OA;
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

    #[OA\Get(
        path: '/api/debug/stats',
        summary: 'Per-worker server diagnostics with the shared Redis and SSE figures',
        responses: [
            new OA\Response(response: '200', description: 'Server stats snapshot',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(
                                property: 'workers',
                                description: 'One entry per HTTP worker that answered: worker_id, memory, process, swoole, coroutines, pools',
                                type: 'array',
                                items: new OA\Items(additionalProperties: true),
                            ),
                            new OA\Property(property: 'missing_workers', description: 'Workers that did not answer in time', type: 'array', items: new OA\Items(type: 'integer')),
                            new OA\Property(
                                property: 'worker_errors',
                                type: 'array',
                                items: new OA\Items(properties: [
                                    new OA\Property(property: 'worker_id', type: 'integer'),
                                    new OA\Property(property: 'error', type: 'string'),
                                ]),
                            ),
                            new OA\Property(property: 'redis', additionalProperties: true),
                            new OA\Property(property: 'sse', properties: [
                                new OA\Property(property: 'active_connections', type: 'integer'),
                            ]),
                        ]),
                    ],
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
