<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Controller;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Application\Port\SleeperInterface;
use App\Transcode\Application\DTO\MediaTrack;
use App\Transcode\Application\DTO\TranscodeRenditionSet;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Transcode\Application\Port\TranscodeStreamingPortInterface;
use App\Transcode\Application\Port\PlaybackPortInterface;
use App\Transcode\Application\Port\TranscodePublicViewInterface;
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
        private readonly TranscodePublicViewInterface $publicView,
        private readonly SleeperInterface $sleeper,
    ) {
    }

    #[OA\Get(
        path: '/api/transcode/{videoId}/master.m3u8',
        summary: 'Get HLS master playlist',
        parameters: [
            new OA\Parameter(name: 'set', description: 'Canonical signed set of admitted job and generation pairs', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'videoId', description: 'Video public identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'HLS master manifest', content: new OA\MediaType(mediaType: 'application/vnd.apple.mpegurl')),
        ],
    )]
    #[Route('/{videoId}/master.m3u8', name: 'master_manifest', methods: ['GET'])]
    public function masterManifest(string $videoId, Request $request): StreamedResponse
    {
        $expiresAt = $this->signedRequest->validate($request);
        $videoUuid = $this->requireVideo($videoId);

        $manifest = $this->streamingService->getMasterManifest($videoUuid, $this->renditionSet($request), $expiresAt);

        return new StreamedResponse(static fn() => print $manifest, $this->available($manifest) ? 200 : 503, [
            'Cache-Control' => 'no-store',
            'Retry-After' => '2',
            'Content-Type' => 'application/vnd.apple.mpegurl',
        ]);
    }

    #[OA\Get(
        path: '/api/transcode/{jobPublicId}/tracks/{track}/media.m3u8',
        summary: 'Get HLS media playlist for a specific rendition',
        parameters: [
            new OA\Parameter(name: 'track', description: 'Published media component', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['video', 'audio'])),
            new OA\Parameter(name: 'generation', description: 'Pinned media execution generation', in: 'query', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'jobPublicId', description: 'Job public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'HLS media manifest', content: new OA\MediaType(mediaType: 'application/vnd.apple.mpegurl')),
        ],
    )]
    #[Route('/{jobPublicId}/tracks/{track}/media.m3u8', name: 'media_manifest', requirements: ['track' => 'video|audio'], methods: ['GET'])]
    public function mediaManifest(string $jobPublicId, MediaTrack $track, Request $request): StreamedResponse
    {
        $expiresAt = $this->signedRequest->validate($request);
        $publicId = PublicId::fromString($jobPublicId);

        $manifest = $this->streamingService->getMediaManifest($publicId, $track, $request->query->getInt('generation'), $expiresAt);

        return new StreamedResponse(static fn() => print $manifest, $this->available($manifest) ? 200 : 503, [
            'Cache-Control' => 'no-store',
            'Retry-After' => '2',
            'Content-Type' => 'application/vnd.apple.mpegurl',
        ]);
    }

    #[OA\Get(
        path: '/api/transcode/{videoId}/manifest.mpd',
        summary: 'Get DASH manifest for a video',
        parameters: [
            new OA\Parameter(name: 'set', description: 'Canonical signed set of admitted job and generation pairs', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'videoId', description: 'Video public identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'DASH manifest', content: new OA\MediaType(mediaType: 'application/dash+xml')),
        ],
    )]
    #[Route('/{videoId}/manifest.mpd', name: 'dash_manifest', methods: ['GET'])]
    public function dashManifest(string $videoId, Request $request): StreamedResponse
    {
        $expiresAt = $this->signedRequest->validate($request);
        $video = $this->requireVideo($videoId);
        $renditions = $this->renditionSet($request);
        $manifest = $this->streamingService->getDashManifest($video, $renditions, $expiresAt);
        if (str_contains($manifest, 'type="dynamic"')) {
            // Some readers ignore the update interval when a growing SegmentList is exhausted.
            $this->sleeper->sleep(1.0);
            $this->signedRequest->validate($request);
            $manifest = $this->streamingService->getDashManifest($video, $renditions, $expiresAt);
        }
        return new StreamedResponse(static fn() => print $manifest, $this->available($manifest) ? 200 : 503, [
            'Cache-Control' => 'no-store', 'Retry-After' => '2', 'Content-Type' => 'application/dash+xml',
        ]);
    }

    #[OA\Get(
        path: '/api/transcode/{videoId}/quality-ladder',
        summary: 'Get available quality tiers for a video',
        parameters: [
            new OA\Parameter(name: 'videoId', description: 'Video public identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Available quality tiers', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [new OA\Property(property: 'quality', type: 'string'), new OA\Property(property: 'width', type: 'integer'), new OA\Property(property: 'height', type: 'integer')]))])),
        ],
    )]
    #[Route('/{videoId}/quality-ladder', name: 'quality_ladder', methods: ['GET'])]
    public function qualityLadder(string $videoId): JsonResponse
    {
        $video = $this->requireVideo($videoId);
        $this->playback->assertAccess($video);
        $tiers = $this->streamingService->getQualityLadderForVideo($video, array_keys($this->playback->findSessions($video)));

        return $this->successResponse($tiers);
    }

    #[OA\Get(
        path: '/api/transcode/{jobPublicId}/subtitles/{language}/media.m3u8',
        summary: 'Get HLS subtitle playlist for a source track',
        parameters: [
            new OA\Parameter(name: 'generation', description: 'Pinned media execution generation', in: 'query', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'jobPublicId', description: 'Job public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'language', description: 'Source subtitle track key: s followed by the absolute FFprobe stream index (for example s2)', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'HLS subtitle manifest', content: new OA\MediaType(mediaType: 'application/vnd.apple.mpegurl', schema: new OA\Schema(type: 'string'))),
        ],
    )]
    #[Route('/{jobPublicId}/subtitles/{language}/media.m3u8', name: 'subtitle_manifest', methods: ['GET'])]
    public function subtitleManifest(string $jobPublicId, string $language, Request $request): StreamedResponse
    {
        $expiresAt = $this->signedRequest->validate($request);
        $manifest = $this->streamingService->getSubtitleManifest(PublicId::fromString($jobPublicId), $language, $request->query->getInt('generation'), $expiresAt);

        return new StreamedResponse(static fn() => print $manifest, $this->available($manifest) ? 200 : 503, [
            'Cache-Control' => 'no-store',
            'Retry-After' => '2',
            'Content-Type' => 'application/vnd.apple.mpegurl',
        ]);
    }

    /** @return list<array{jobId: string, generation: int}> */
    private function renditionSet(Request $request): array
    {
        $encoded = $request->query->get('set');
        if (!is_string($encoded)) {
            throw new BadRequestHttpException('A signed rendition set is required.');
        }
        try {
            return TranscodeRenditionSet::parse($encoded);
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('Invalid signed rendition set.');
        }
    }

    private function available(string $manifest): bool
    {
        return str_contains($manifest, '#EXTINF:') || str_contains($manifest, '#EXT-X-STREAM-INF:') || str_contains($manifest, '<Representation');
    }

    private function requireVideo(string $publicId): \App\Shared\Domain\Model\Uuid
    {
        try {
            $video = $this->publicView->videoId(PublicId::fromString($publicId));
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Video not found.');
        }

        return $video ?? throw new NotFoundHttpException('Video not found.');
    }
}
