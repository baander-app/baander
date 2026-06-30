<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Controller;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Transcode\Application\Port\TranscodeStreamingPortInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Tag(name: 'Streaming', description: 'HLS/DASH streaming endpoints')]
#[Route('/api/stream', name: 'stream_')]
#[IsGranted('ROLE_USER')]
final class StreamManifestController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly TranscodeStreamingPortInterface $streamingService,
    ) {
    }

    #[OA\Get(
        path: '/api/stream/{videoId}/master.m3u8',
        summary: 'Get HLS master playlist',
        parameters: [
            new OA\Parameter(name: 'videoId', description: 'Video UUID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'HLS master manifest', content: new OA\MediaType(mediaType: 'application/vnd.apple.mpegurl')),
        ],
    )]
    #[Route('/{videoId}/master.m3u8', name: 'master_manifest', methods: ['GET'])]
    public function masterManifest(string $videoId): StreamedResponse
    {
        $manifest = $this->streamingService->getMasterManifest(Uuid::fromString($videoId));

        return new StreamedResponse(static fn() => print $manifest, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
        ]);
    }

    #[OA\Get(
        path: '/api/stream/{jobPublicId}/media.m3u8',
        summary: 'Get HLS media playlist for a specific rendition',
        parameters: [
            new OA\Parameter(name: 'jobPublicId', description: 'Job public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'HLS media manifest', content: new OA\MediaType(mediaType: 'application/vnd.apple.mpegurl')),
        ],
    )]
    #[Route('/{jobPublicId}/media.m3u8', name: 'media_manifest', methods: ['GET'])]
    public function mediaManifest(string $jobPublicId): StreamedResponse
    {
        $manifest = $this->streamingService->getMediaManifest(
            PublicId::fromString($jobPublicId),
            'streaming_stereo',
        );

        return new StreamedResponse(static fn() => print $manifest, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
        ]);
    }

    #[OA\Get(
        path: '/api/stream/{videoId}/manifest.mpd',
        summary: 'Get DASH manifest for a video',
        parameters: [
            new OA\Parameter(name: 'videoId', description: 'Video UUID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'DASH manifest', content: new OA\MediaType(mediaType: 'application/dash+xml')),
        ],
    )]
    #[Route('/{videoId}/manifest.mpd', name: 'dash_manifest', methods: ['GET'])]
    public function dashManifest(string $videoId): StreamedResponse
    {
        $manifest = $this->streamingService->getDashManifest(Uuid::fromString($videoId));

        return new StreamedResponse(static fn() => print $manifest, 200, [
            'Content-Type' => 'application/dash+xml',
        ]);
    }

    #[OA\Get(
        path: '/api/stream/{videoId}/quality-ladder',
        summary: 'Get available quality tiers for a video',
        parameters: [
            new OA\Parameter(name: 'videoId', description: 'Video UUID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Available quality tiers', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [new OA\Property(property: 'quality', type: 'string'), new OA\Property(property: 'width', type: 'integer'), new OA\Property(property: 'height', type: 'integer')]))])),
        ],
    )]
    #[Route('/{videoId}/quality-ladder', name: 'quality_ladder', methods: ['GET'])]
    public function qualityLadder(string $videoId): JsonResponse
    {
        $tiers = $this->streamingService->getQualityLadderForVideo(Uuid::fromString($videoId));

        return $this->successResponse($tiers);
    }

    #[OA\Get(
        path: '/api/stream/{jobPublicId}/audio/{language}/media.m3u8',
        summary: 'Get HLS audio playlist for a language',
        parameters: [
            new OA\Parameter(name: 'jobPublicId', description: 'Job public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'language', description: 'BCP-47 language tag', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'HLS audio manifest', content: new OA\MediaType(mediaType: 'application/vnd.apple.mpegurl', schema: new OA\Schema(type: 'string'))),
        ],
    )]
    #[Route('/{jobPublicId}/audio/{language}/media.m3u8', name: 'audio_manifest', methods: ['GET'])]
    public function audioManifest(string $jobPublicId, string $language): StreamedResponse
    {
        $manifest = $this->streamingService->getAudioManifest(PublicId::fromString($jobPublicId), $language);

        return new StreamedResponse(static fn() => print $manifest, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
        ]);
    }

    #[OA\Get(
        path: '/api/stream/{jobPublicId}/subtitles/{language}/media.m3u8',
        summary: 'Get HLS subtitle playlist for a language',
        parameters: [
            new OA\Parameter(name: 'jobPublicId', description: 'Job public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'language', description: 'BCP-47 language tag', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'HLS subtitle manifest', content: new OA\MediaType(mediaType: 'application/vnd.apple.mpegurl', schema: new OA\Schema(type: 'string'))),
        ],
    )]
    #[Route('/{jobPublicId}/subtitles/{language}/media.m3u8', name: 'subtitle_manifest', methods: ['GET'])]
    public function subtitleManifest(string $jobPublicId, string $language): StreamedResponse
    {
        $manifest = $this->streamingService->getSubtitleManifest(PublicId::fromString($jobPublicId), $language);

        return new StreamedResponse(static fn() => print $manifest, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
        ]);
    }
}
