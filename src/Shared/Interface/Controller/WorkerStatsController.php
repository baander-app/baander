<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Shared\Application\Port\ServerDiagnosticsInterface;
use App\Shared\Interface\Attribute\CliCounterpart;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Server-level worker pool stats: HTTP workers, task workers, transcoding pool. */
final readonly class WorkerStatsController
{
    public function __construct(
        private ServerDiagnosticsInterface $diagnostics,
    ) {
    }

    #[OA\Get(
        path: '/api/debug/workers',
        summary: 'Server worker pool stats (HTTP, task, transcoding)',
        responses: [
            new OA\Response(response: '200', description: 'Worker stats', content: new OA\JsonContent(type: 'object')),
        ],
    )]
    #[Route('/api/debug/workers', name: 'debug_workers', methods: ['GET'])]
    #[CliCounterpart('app:server:workers')]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse($this->diagnostics->workers());
    }
}
