<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Controller;

use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Port\PlaybackPortInterface;
use App\Transcode\Application\Port\StreamAuthPortInterface;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Tag(name: 'Stream', description: 'HLS streaming endpoints')]
#[Route('/api/stream', name: 'stream_signing_')]
#[IsGranted('ROLE_USER')]
final class StreamSigningController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly StreamAuthPortInterface $streamAuth,
        private readonly PlaybackPortInterface $playback,
    ) {
    }

    #[OA\Post(
        path: '/api/stream/sign',
        summary: 'Generate a signed URL for a stream resource',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'path', type: 'string', example: '/api/transcode/00000000-0000-4000-8000-000000000001/master.m3u8'),
                    new OA\Property(property: 'expiresInSeconds', type: 'int', example: 86400),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: '200', description: 'Signed URL generated', content: new OA\JsonContent(properties: [new OA\Property(property: 'url', type: 'string'), new OA\Property(property: 'sig', type: 'string'), new OA\Property(property: 'exp', type: 'integer')])),
            new OA\Response(response: '400', description: 'Invalid request'),
            new OA\Response(response: '503', description: 'Transcode startup is temporarily unavailable', headers: [
                new OA\Header(header: 'Retry-After', schema: new OA\Schema(type: 'integer', example: 2)),
            ], content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/sign', name: 'sign', methods: ['POST'])]
    public function sign(Request $request): JsonResponse
    {
        try {
            $data = $request->toArray();
        } catch (\Symfony\Component\HttpFoundation\Exception\JsonException) {
            return $this->errorResponse('Expected a JSON object.', 400);
        }
        $path = $data['path'] ?? null;
        $expiresInSeconds = array_key_exists('expiresInSeconds', $data) ? $data['expiresInSeconds'] : 86400;
        if (!is_string($path) || !preg_match('~^/api/transcode/([0-9a-fA-F-]{36})/(?:master\.m3u8|manifest\.mpd)$~D', $path, $matches)
            || !is_int($expiresInSeconds) || $expiresInSeconds < 1 || $expiresInSeconds > 86400) {
            return $this->errorResponse('Expected a video manifest path and a lifetime from 1 to 86400 seconds.', 400);
        }
        try {
            $videoId = Uuid::fromString($matches[1]);
        } catch (\InvalidArgumentException) {
            return $this->errorResponse('Invalid video identifier.', 400);
        }
        $this->playback->start($videoId);

        $result = $this->streamAuth->signUrl($path, $expiresInSeconds);

        return new JsonResponse($result);
    }
}
