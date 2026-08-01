<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Shared\Infrastructure\OpenTelemetry\SpanBridge;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Debug endpoint exposing recent OpenTelemetry spans for the PhpStorm plugin.
 *
 * Returns the last N spans from the in-memory SpanBridge ring buffer.
 * Should only be available in dev/test environments.
 */
#[OA\Tag(name: 'Debug', description: 'Debug and profiling endpoints')]
final class SpanDebugController
{
    public function __construct(
        private readonly ?SpanBridge $spanBridge = null,
    ) {
    }

    #[OA\Get(
        path: '/api/debug/spans',
        summary: 'Recent OpenTelemetry spans',
        responses: [
            new OA\Response(
                response: '200',
                description: 'List of recent spans',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(type: 'object'),
                ),
            ),
        ],
    )]
    #[Route('/api/debug/spans', name: 'debug_spans', methods: ['GET'])]
    public function spans(): JsonResponse
    {
        if ($this->spanBridge === null) {
            return new JsonResponse([], 200);
        }

        $limit = (int) ($_GET['limit'] ?? 100);
        $limit = min($limit, 500);

        return new JsonResponse($this->spanBridge->getRecentSpans($limit));
    }

    #[OA\Delete(
        path: '/api/debug/spans',
        summary: 'Clear in-memory spans',
        responses: [
            new OA\Response(response: '200', description: 'Spans cleared', content: new OA\JsonContent(properties: [new OA\Property(property: 'status', type: 'string')])),
        ],
    )]
    #[Route('/api/debug/spans', name: 'debug_spans_clear', methods: ['DELETE'])]
    public function clear(): JsonResponse
    {
        $this->spanBridge?->clear();

        return new JsonResponse(['status' => 'cleared']);
    }
}
