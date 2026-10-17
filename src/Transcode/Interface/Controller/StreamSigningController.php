<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Controller;

use App\Shared\Domain\Model\PublicId;

use App\Shared\Interface\Controller\ApiResponsesTrait;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Transcode\Application\Port\PlaybackPortInterface;
use App\Transcode\Application\Port\TranscodePublicViewInterface;
use App\Transcode\Application\Port\StreamAuthPortInterface;
use App\Transcode\Application\Port\TranscodeStreamingPortInterface;
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
        private readonly TranscodePublicViewInterface $publicView,
        private readonly TranscodeStreamingPortInterface $streaming,
    ) {
    }

    #[OA\Post(
        path: '/api/stream/sign',
        summary: 'Generate a signed URL for a stream resource',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'path', type: 'string', example: '/api/transcode/0123456789abABCD_-xyz/master.m3u8'),
                    new OA\Property(property: 'expiresInSeconds', type: 'int', example: 86400),
                    new OA\Property(property: 'refresh', type: 'boolean', description: 'Read current playback state without creating another admission'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: '200', description: 'Signed URL generated', content: new OA\JsonContent(properties: [new OA\Property(property: 'url', type: 'string', nullable: true), new OA\Property(property: 'sig', type: 'string'), new OA\Property(property: 'exp', type: 'integer'), new OA\Property(property: 'generation', type: 'integer'), new OA\Property(property: 'jobId', type: 'string'), new OA\Property(property: 'sessionId', type: 'string', nullable: true), new OA\Property(property: 'revision', type: 'integer'), new OA\Property(property: 'ready', type: 'boolean'), new OA\Property(property: 'position', type: 'number'), new OA\Property(property: 'status', type: 'string'), new OA\Property(property: 'startTime', type: 'number', nullable: true), new OA\Property(property: 'duration', type: 'number', nullable: true), new OA\Property(property: 'renditionSet', type: 'string')])),
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
        $refresh = array_key_exists('refresh', $data) ? $data['refresh'] : false;
        $expiresInSeconds = array_key_exists('expiresInSeconds', $data) ? $data['expiresInSeconds'] : 86400;
        if (!is_string($path) || !preg_match('~^/api/transcode/([0-9a-zA-Z_-]{21})/(?:master\.m3u8|manifest\.mpd)$~D', $path, $matches)
            || !is_bool($refresh) || !is_int($expiresInSeconds) || $expiresInSeconds < 1 || $expiresInSeconds > 86400) {
            return $this->errorResponse('Expected a video manifest path and a lifetime from 1 to 86400 seconds.', 400);
        }
        try {
            $video = $this->publicView->videoId(PublicId::fromString($matches[1]))
                ?? throw new NotFoundHttpException('Video not found.');
            $videoId = $video;
        } catch (\InvalidArgumentException) {
            return $this->errorResponse('Invalid video identifier.', 400);
        }
        if ($refresh) {
            $this->playback->assertAccess($videoId);
        } else {
            $this->playback->start($videoId);
        }
        $sessions = $this->playback->findSessions($videoId);
        if ($sessions === []) {
            return new JsonResponse(['error' => 'Owned media execution is unavailable'], 503, ['Retry-After' => '2']);
        }
        $state = $this->streaming->getPlaybackState($videoId, array_keys($sessions));
        if ($state === null || !isset($sessions[$state['jobId']])) {
            return new JsonResponse(['error' => 'Media execution is unavailable'], 503, ['Retry-After' => '2']);
        }
        $state['sessionId'] = $sessions[$state['jobId']]->toString();
        $result = ['url' => null];
        if ($state['ready']) {
            $path .= '?'.http_build_query(['set' => $state['renditionSet']]);
            $result = $this->streamAuth->signUrl($path, $expiresInSeconds);
        }

        return new JsonResponse($result + $state, headers: ['Cache-Control' => 'no-store']);
    }
}
