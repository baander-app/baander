<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Shared\Application\Port\ServerDiagnosticsInterface;
use App\Shared\Interface\Attribute\CliCounterpart;
use OpenApi\Spec as OA;
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

    #[OA\Operation\Get(
        path: '/api/debug/spans',
        summary: 'Recent server spans, newest first',
        parameters: [
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                description: 'Number of spans to return, at most 500',
                required: false,
                schema: new OA\Schema(
                    type: 'integer',
                    minimum: 0,
                    maximum: 500,
                    default: 100,
                ),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'List of recent spans',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'array',
                        items: new OA\Schema(type: 'object'),
                    ),
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

    #[OA\Operation\Delete(
        path: '/api/debug/spans',
        summary: 'Clear the span buffer of every worker',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Spans cleared',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'status' => new OA\Schema(type: 'string'),
                        ],
                    ),
                ),
            ),
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
