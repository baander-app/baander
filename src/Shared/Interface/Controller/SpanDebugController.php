<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Shared\Application\Port\ServerDiagnosticsInterface;
use App\Shared\Interface\Attribute\CliCounterpart;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Recent server spans from the buffer every HTTP worker shares: one span per
 * HTTP request, with its method, route name, status and duration.
 */
#[OA\Tag(name: 'Debug', description: 'Debug and profiling endpoints')]
final readonly class SpanDebugController
{
    private const int DEFAULT_LIMIT = 100;

    public function __construct(
        private ServerDiagnosticsInterface $diagnostics,
    ) {
    }

    #[OA\Get(
        path: '/api/debug/spans',
        summary: 'Recent server spans, newest first',
        parameters: [
            new OA\Parameter(
                name: 'limit',
                description: 'Number of spans to return, at most 500',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', default: 100, maximum: 500, minimum: 0),
            ),
        ],
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
    #[CliCounterpart('app:server:spans')]
    public function spans(Request $request): JsonResponse
    {
        return new JsonResponse($this->diagnostics->spans($request->query->getInt('limit', self::DEFAULT_LIMIT)));
    }

    #[OA\Delete(
        path: '/api/debug/spans',
        summary: 'Clear the span buffer of every worker',
        responses: [
            new OA\Response(response: '200', description: 'Spans cleared', content: new OA\JsonContent(properties: [new OA\Property(property: 'status', type: 'string')])),
        ],
    )]
    #[Route('/api/debug/spans', name: 'debug_spans_clear', methods: ['DELETE'])]
    #[CliCounterpart('app:server:spans')]
    public function clear(): JsonResponse
    {
        $this->diagnostics->clearSpans();

        return new JsonResponse(['status' => 'cleared']);
    }
}
