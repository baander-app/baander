<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Shared\Application\Port\ServerDiagnosticsInterface;
use App\Shared\Interface\Attribute\CliCounterpart;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Debug', description: 'Debug and profiling endpoints')]
final readonly class CoroutineStatsController
{
    public function __construct(
        private ServerDiagnosticsInterface $diagnostics,
    ) {
    }

    #[OA\Get(
        path: '/api/debug/coroutines',
        summary: 'Swoole coroutine and channel statistics of every HTTP worker',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Coroutine and channel stats per worker',
                content: new OA\JsonContent(properties: [
                    new OA\Property(
                        property: 'workers',
                        description: 'One entry per HTTP worker that answered: worker_id, coroutines, active_cids, channels',
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
                ]),
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
