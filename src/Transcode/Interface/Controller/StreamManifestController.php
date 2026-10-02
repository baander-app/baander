<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Controller;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\Async;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Transcode\Application\Port\TranscodeStreamingPortInterface;
use App\Transcode\Application\Port\PlaybackPortInterface;
use App\Transcode\Interface\Security\SignedStreamRequest;
use Symfony\Component\HttpFoundation\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Streaming', description: 'HLS/DASH streaming endpoints')]
#[Route('/api/transcode', name: 'stream_')]
final class StreamManifestController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly TranscodeStreamingPortInterface $streamingService,
        private readonly SignedStreamRequest $signedRequest,
        private readonly PlaybackPortInterface $playback,
    ) {
    }

    #[OA\Get(
        path: '/api/transcode/{videoId}/master.m3u8',
        summary: 'Get HLS master playlist',
        parameters: [
            new OA\Parameter(name: 'videoId', description: 'Video UUID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'HLS master manifest', content: new OA\MediaType(mediaType: 'application/vnd.apple.mpegurl')),
        ],
    )]
    #[Route('/{videoId}/master.m3u8', name: 'master_manifest', methods: ['GET'])]
    public function masterManifest(string $videoId, Request $request): StreamedResponse
    {
        $this->signedRequest->validate($request);
        $videoUuid = Uuid::fromString($videoId);

        $manifest = $this->streamingService->getMasterManifest($videoUuid);

        return new StreamedResponse(static fn() => print $manifest, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
        ]);
    }

    #[OA\Get(
        path: '/api/transcode/{jobPublicId}/media.m3u8',
        summary: 'Get HLS media playlist for a specific rendition',
        parameters: [
            new OA\Parameter(name: 'jobPublicId', description: 'Job public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'HLS media manifest', content: new OA\MediaType(mediaType: 'application/vnd.apple.mpegurl')),
        ],
    )]
    #[Route('/{jobPublicId}/media.m3u8', name: 'media_manifest', methods: ['GET'])]
    public function mediaManifest(string $jobPublicId, Request $request): StreamedResponse
    {
        $this->signedRequest->validate($request);
        $publicId = PublicId::fromString($jobPublicId);

        // Wait for the encoding loop to finish probing and populate totalSegments
        // before serving the full VOD playlist.
        $manifest = '';
        $elapsed = 0.0;
        while ($elapsed < 10.0) {
            $manifest = $this->streamingService->getMediaManifest($publicId, 'streaming_stereo');
            if (str_contains($manifest, '#EXTINF:')) {
                break;
            }
            Async::sleep(0.5);
            $elapsed += 0.5;
        }

        return new StreamedResponse(static fn() => print $manifest, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
        ]);
    }

    #[OA\Get(
        path: '/api/transcode/{videoId}/manifest.mpd',
        summary: 'Get DASH manifest for a video',
        parameters: [
            new OA\Parameter(name: 'videoId', description: 'Video UUID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'DASH manifest', content: new OA\MediaType(mediaType: 'application/dash+xml')),
        ],
    )]
    #[Route('/{videoId}/manifest.mpd', name: 'dash_manifest', methods: ['GET'])]
    public function dashManifest(string $videoId, Request $request): StreamedResponse
    {
        $this->signedRequest->validate($request);
        $manifest = $this->streamingService->getDashManifest(Uuid::fromString($videoId));

        return new StreamedResponse(static fn() => print $manifest, 200, [
            'Content-Type' => 'application/dash+xml',
        ]);
    }

    #[OA\Get(
        path: '/api/transcode/{videoId}/quality-ladder',
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
        $this->playback->assertAccess(Uuid::fromString($videoId));
        $tiers = $this->streamingService->getQualityLadderForVideo(Uuid::fromString($videoId));

        return $this->successResponse($tiers);
    }

    #[OA\Get(
        path: '/api/transcode/{jobPublicId}/subtitles/{language}/media.m3u8',
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
    public function subtitleManifest(string $jobPublicId, string $language, Request $request): StreamedResponse
    {
        $this->signedRequest->validate($request);
        $manifest = $this->streamingService->getSubtitleManifest(PublicId::fromString($jobPublicId), $language);

        return new StreamedResponse(static fn() => print $manifest, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
        ]);
    }
}
