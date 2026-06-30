<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Controller;

use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Transcode\Application\Port\StreamAuthPortInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Tag(name: 'Stream', description: 'Stream URL signing')]
#[Route('/api/stream', name: 'stream_signing_')]
#[IsGranted('ROLE_USER')]
final class StreamSigningController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly StreamAuthPortInterface $streamAuth,
    ) {
    }

    #[OA\Post(
        path: '/api/stream/sign',
        summary: 'Generate a signed URL for a stream resource',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'path', type: 'string', example: '/api/stream/abc123/seg_0.m4s'),
                    new OA\Property(property: 'expiresInSeconds', type: 'int', example: 86400),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: '200', description: 'Signed URL generated', content: new OA\JsonContent(properties: [new OA\Property(property: 'url', type: 'string'), new OA\Property(property: 'sig', type: 'string'), new OA\Property(property: 'exp', type: 'integer')])),
            new OA\Response(response: '400', description: 'Invalid request'),
        ],
    )]
    #[Route('/sign', name: 'sign', methods: ['POST'])]
    public function sign(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $path = $data['path'] ?? '';

        if ($path === '') {
            return new JsonResponse(['error' => 'path is required'], 400);
        }

        $expiresInSeconds = (int) ($data['expiresInSeconds'] ?? 86400);

        $result = $this->streamAuth->signUrl($path, $expiresInSeconds);

        return new JsonResponse($result);
    }
}
