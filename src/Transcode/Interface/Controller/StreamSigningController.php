<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Controller;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Transcode\Application\DTO\TranscodeRenditionSet;
use App\Transcode\Application\DTO\RequestedQuality;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Transcode\Application\Port\PlaybackPortInterface;
use App\Transcode\Application\Port\TranscodePublicViewInterface;
use App\Transcode\Application\Port\StreamAuthPortInterface;
use App\Transcode\Application\Port\TranscodeStreamingPortInterface;
use OpenApi\Spec as OA;
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
    ) {}

    #[OA\Operation\Post(
        path: '/api/stream/sign',
        summary: 'Generate a signed URL for a stream resource',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(
                    properties: [
                        'path' => new OA\Schema(type: 'string', example: '/api/transcode/0123456789abABCD_-xyz/master.m3u8'),
                        'expiresInSeconds' => new OA\Schema(type: 'int', example: 86400),
                        'refresh' => new OA\Schema(
                            description: 'Read current playback state without creating another admission',
                            type: 'boolean',
                        ),
                        'audioStreamIndex' => new OA\Schema(
                            description: 'Absolute source audio stream index; null selects the source default',
                            type: 'integer',
                            nullable: true,
                            minimum: 0,
                        ),
                        'audioOffsetMs' => new OA\Schema(
                            description: 'Source audio correction in milliseconds; positive plays later',
                            type: 'integer',
                            minimum: -10000,
                            maximum: 10000,
                            default: 0,
                        ),
                        'qualityTier' => new OA\Schema(
                            type: 'string',
                            nullable: true,
                            enum: ['360p', '480p', '720p', '1080p', '1440p', '4K'],
                        ),
                        'requestId' => new OA\Schema(
                            description: 'Stable playback client session identifier, retained across reloads and reconnects',
                            type: 'string',
                            format: 'uuid',
                        ),
                        'jobIds' => new OA\Schema(
                            description: 'Exact admitted jobs; required for refresh and omitted for new admission',
                            type: 'array',
                            items: new OA\Schema(type: 'string'),
                            minItems: 1,
                            maxItems: 3,
                            uniqueItems: true,
                        ),
                        'attachedSet' => new OA\Schema(
                            description: 'Rendition set the player has attached, as returned in renditionSet; refresh only. A rendition named here stays in the signed set on a smaller published lead than one that still has to join',
                            type: 'string',
                            example: '0123456789abABCD_-xyz.3',
                        ),
                    ],
                    required: ['path', 'requestId'],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Signed URL generated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'jobIds' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(type: 'string'),
                            ),
                            'requestId' => new OA\Schema(type: 'string', format: 'uuid'),
                            'url' => new OA\Schema(type: 'string', nullable: true),
                            'sig' => new OA\Schema(type: 'string'),
                            'exp' => new OA\Schema(type: 'integer'),
                            'generation' => new OA\Schema(type: 'integer'),
                            'jobId' => new OA\Schema(type: 'string'),
                            'sessionId' => new OA\Schema(type: 'string', nullable: true),
                            'revision' => new OA\Schema(type: 'integer'),
                            'ready' => new OA\Schema(type: 'boolean'),
                            'position' => new OA\Schema(type: 'number'),
                            'status' => new OA\Schema(type: 'string'),
                            'startTime' => new OA\Schema(type: 'number', nullable: true),
                            'duration' => new OA\Schema(type: 'number', nullable: true),
                            'renditionSet' => new OA\Schema(type: 'string'),
                        ],
                    ),
                ),
            ),
            new OA\Response(response: '400', description: 'Invalid request'),
            new OA\Response(
                response: '410',
                description: 'A refresh named only sessions that have ended. Sessions that expired may be started again; failed or cancelled ones may not',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'error' => new OA\Schema(type: 'string'),
                            'reason' => new OA\Schema(
                                type: 'string',
                                enum: ['expired', 'failed', 'cancelled'],
                            ),
                        ],
                        required: ['error', 'reason'],
                    ),
                ),
            ),
            new OA\Response(
                response: '503',
                description: 'Transcode startup is temporarily unavailable',
                headers: [
                    new OA\Header(
                        header: 'Retry-After',
                        schema: new OA\Schema(type: 'integer', example: 2),
                    ),
                ],
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
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
        $audioStreamIndex = $data['audioStreamIndex'] ?? null;
        $audioOffsetMs = array_key_exists('audioOffsetMs', $data) ? $data['audioOffsetMs'] : 0;
        if (
            ($audioStreamIndex !== null && (!is_int($audioStreamIndex) || $audioStreamIndex < 0))
            || !is_int($audioOffsetMs) || $audioOffsetMs < -10000 || $audioOffsetMs > 10000
        ) {
            return $this->errorResponse('Invalid source audio selection.', 400);
        }
        try {
            $requestedQuality = $data['qualityTier'] ?? null;
            if ($requestedQuality !== null && !is_string($requestedQuality)) {
                throw new \InvalidArgumentException('Quality tier must be a string.');
            }
            $qualityTier = $requestedQuality === null ? null : RequestedQuality::fromString($requestedQuality)->id;
        } catch (\InvalidArgumentException $error) {
            return $this->errorResponse($error->getMessage(), 400);
        }
        $path = $data['path'] ?? null;
        $requestId = $data['requestId'] ?? null;
        $jobIdentifiers = array_key_exists('jobIds', $data) ? $data['jobIds'] : [];
        $refresh = array_key_exists('refresh', $data) ? $data['refresh'] : false;
        $expiresInSeconds = array_key_exists('expiresInSeconds', $data) ? $data['expiresInSeconds'] : 86400;
        $attachedSet = $data['attachedSet'] ?? null;
        if (
            !is_string($path) || !preg_match(
                '~^/api/transcode/([0-9a-zA-Z_-]{21})/(?:master\.m3u8|manifest\.mpd)$~D',
                $path,
                $matches,
            )
            || !is_string($requestId) || !is_bool($refresh) || !is_int($expiresInSeconds) || $expiresInSeconds < 1 || $expiresInSeconds > 86400
            || !is_array($jobIdentifiers) || !array_is_list($jobIdentifiers) || count($jobIdentifiers) > 3
            || ($refresh && $jobIdentifiers === []) || (!$refresh && $jobIdentifiers !== [])
        ) {
            return $this->errorResponse(
                'Expected a video manifest path, a client session UUID, a lifetime from 1 to 86400 seconds, and exact job IDs when refreshing.',
                400,
            );
        }
        if ($attachedSet !== null && (!is_string($attachedSet) || !$refresh)) {
            return $this->errorResponse('An attached rendition set is a string and belongs to a refresh.', 400);
        }
        try {
            $attached = $attachedSet === null ? [] : TranscodeRenditionSet::parse($attachedSet);
        } catch (\InvalidArgumentException) {
            return $this->errorResponse('Invalid attached rendition set.', 400);
        }
        try {
            $requestId = Uuid::fromString($requestId)->toString();
            $jobIds = array_map(static function (mixed $id): PublicId {
                if (!is_string($id)) {
                    throw new \InvalidArgumentException('Job identifiers must be strings.');
                }
                return PublicId::fromString($id);
            }, $jobIdentifiers);
            if (count(array_unique($jobIdentifiers)) !== count($jobIdentifiers)) {
                throw new \InvalidArgumentException('Job identifiers must be unique.');
            }
            $video = $this->publicView->videoId(PublicId::fromString($matches[1]))
                ?? throw new NotFoundHttpException('Video not found.');
            $videoId = $video;
        } catch (\InvalidArgumentException) {
            return $this->errorResponse('Invalid video or client session identifier.', 400);
        }
        if ($refresh) {
            $this->playback->assertAccess($videoId);
            $sessions = $this->playback->findSessions($videoId, $requestId, $jobIds);
            if ($sessions === []) {
                // Not a retryable outage: the client holds none of these sessions any more.
                return new JsonResponse([
                    'error' => 'The playback sessions have ended.',
                    'reason' => $this->playback->endReason($videoId, $requestId, $jobIds)->value,
                ], 410, ['Cache-Control' => 'no-store']);
            }
        } else {
            $sessions = $this->playback->start(
                $videoId,
                $requestId,
                $audioStreamIndex,
                $audioOffsetMs,
                $qualityTier,
            );
        }
        if ($sessions === []) {
            return new JsonResponse(['error' => 'Owned media execution is unavailable'], 503, ['Retry-After' => '2']);
        }
        $this->playback->touchSessions(array_values($sessions));
        // The attached set is the client's word; it counts only for jobs its own sessions hold.
        $attached = array_values(array_filter(
            $attached,
            static fn(array $pair): bool => isset($sessions[$pair['jobId']]),
        ));
        $state = $this->streaming->getPlaybackState($videoId, array_keys($sessions), $attached);
        if ($state === null || !isset($sessions[$state['jobId']])) {
            return new JsonResponse(['error' => 'Media execution is unavailable'], 503, ['Retry-After' => '2']);
        }
        $state['sessionId'] = $sessions[$state['jobId']]->toString();
        $state['requestId'] = $requestId;
        $state['jobIds'] = array_keys($sessions);
        $result = ['url' => null];
        if ($state['ready']) {
            $path .= '?' . http_build_query(['set' => $state['renditionSet']]);
            $result = $this->streamAuth->signUrl($path, $expiresInSeconds);
        }

        return new JsonResponse($result + $state, headers: ['Cache-Control' => 'no-store']);
    }
}
