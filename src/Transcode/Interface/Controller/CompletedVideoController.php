<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Controller;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\DTO\ApiError;
use App\Transcode\Application\DTO\MediaTrack;
use App\Transcode\Application\Port\CompletedVideoDeliveryPort;
use App\Transcode\Interface\Security\SignedStreamRequest;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Streaming', description: 'HLS/DASH streaming endpoints')]
#[Route('/api/transcode/artifacts/{jobPublicId}/{generation}', name: 'stream_artifact_', requirements: [
    'jobPublicId' => '[0-9a-zA-Z_-]{21}',
    'generation' => '[1-9][0-9]*',
    'track' => 'video|audio',
])]
final readonly class CompletedVideoController
{
    public function __construct(
        private CompletedVideoDeliveryPort $delivery,
        private SignedStreamRequest $signedRequest,
    ) {
    }

    #[OA\Get(summary: 'Get the HLS master playlist for completed video', responses: [
        new OA\Response(response: '200', description: 'Completed HLS master playlist', content: new OA\MediaType(mediaType: 'application/vnd.apple.mpegurl')),
        new OA\Response(response: '403', description: 'Invalid or expired signature', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
        new OA\Response(response: '404', description: 'Completed media is unavailable'),
    ])]
    #[Route('/master.m3u8', name: 'master', methods: ['GET'])]
    public function master(string $jobPublicId, int $generation, Request $request): Response
    {
        $expiresAt = $this->signedRequest->validate($request);
        return $this->manifest($this->delivery->master(PublicId::fromString($jobPublicId), $generation, $expiresAt));
    }

    #[OA\Get(summary: 'Get a completed HLS track playlist', responses: [
        new OA\Response(response: '200', description: 'Completed HLS media playlist', content: new OA\MediaType(mediaType: 'application/vnd.apple.mpegurl')),
        new OA\Response(response: '403', description: 'Invalid or expired signature', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
        new OA\Response(response: '404', description: 'Completed media is unavailable'),
    ])]
    #[Route('/tracks/{track}/media.m3u8', name: 'media', methods: ['GET'])]
    public function media(string $jobPublicId, int $generation, MediaTrack $track, Request $request): Response
    {
        $expiresAt = $this->signedRequest->validate($request);
        return $this->manifest($this->delivery->playlist(PublicId::fromString($jobPublicId), $generation, $track, $expiresAt));
    }

    #[OA\Get(summary: 'Get completed track initialization media', responses: [
        new OA\Response(response: '200', description: 'MP4 initialization media'),
        new OA\Response(response: '206', description: 'Requested byte range'),
        new OA\Response(response: '403', description: 'Invalid or expired signature', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
        new OA\Response(response: '404', description: 'Completed media is unavailable'),
    ])]
    #[Route('/tracks/{track}/init.mp4', name: 'init', methods: ['GET'])]
    public function init(string $jobPublicId, int $generation, MediaTrack $track, Request $request): BinaryFileResponse
    {
        $expiresAt = $this->signedRequest->validate($request);
        return $this->file($this->delivery->path(PublicId::fromString($jobPublicId), $generation, $track, null, $expiresAt), $track);
    }

    #[OA\Get(summary: 'Get a completed track segment', parameters: [
        new OA\Parameter(name: 'index', in: 'query', required: true, schema: new OA\Schema(type: 'integer', minimum: 0)),
    ], responses: [
        new OA\Response(response: '200', description: 'MP4 media segment'),
        new OA\Response(response: '206', description: 'Requested byte range'),
        new OA\Response(response: '400', description: 'Missing or invalid segment index'),
        new OA\Response(response: '403', description: 'Invalid or expired signature', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
        new OA\Response(response: '404', description: 'Completed media is unavailable'),
    ])]
    #[Route('/tracks/{track}/segment.m4s', name: 'segment', methods: ['GET'])]
    public function segment(string $jobPublicId, int $generation, MediaTrack $track, Request $request): BinaryFileResponse
    {
        $expiresAt = $this->signedRequest->validate($request);
        $index = $request->query->all()['index'] ?? null;
        if (!is_string($index) || !ctype_digit($index)
            || filter_var($index, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
            throw new BadRequestHttpException('A nonnegative segment index is required.');
        }

        return $this->file($this->delivery->path(PublicId::fromString($jobPublicId), $generation, $track, (int) $index, $expiresAt), $track);
    }

    private function manifest(?string $manifest): Response
    {
        if ($manifest === null) {
            throw new NotFoundHttpException('Completed media is unavailable.');
        }

        return new Response($manifest, Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function file(?string $path, MediaTrack $track): BinaryFileResponse
    {
        if ($path === null || !is_file($path) || !is_readable($path)) {
            throw new NotFoundHttpException('Completed media is unavailable.');
        }

        return new BinaryFileResponse($path, Response::HTTP_OK, [
            'Content-Type' => $track === MediaTrack::Video ? 'video/mp4' : 'audio/mp4',
            'Cache-Control' => 'private, no-store',
        ], false);
    }
}
