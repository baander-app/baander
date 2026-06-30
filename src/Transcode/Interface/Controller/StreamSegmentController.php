<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Controller;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Transcode\Application\Port\StreamAuthPortInterface;
use App\Transcode\Application\Port\TranscodeStreamingPortInterface;
use OpenApi\Attributes as OA;
use Nelmio\ApiDocBundle\Attribute\Model;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Tag(name: 'Stream', description: 'HLS segment delivery endpoints')]
#[Route('/api/stream/{jobPublicId}', name: 'stream_segment_')]
#[IsGranted('ROLE_USER')]
final class StreamSegmentController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly TranscodeStreamingPortInterface $streamingService,
        private readonly StreamAuthPortInterface $streamAuth,
    ) {
    }

    #[OA\Get(
        path: '/api/stream/{jobPublicId}/init.mp4',
        summary: 'Get CMAF init segment',
        parameters: [
            new OA\Parameter(name: 'jobPublicId', description: 'Job public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sig', description: 'URL signature', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'exp', description: 'Expiry timestamp', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'fMP4 init segment', content: new OA\MediaType(mediaType: 'video/mp4')),
            new OA\Response(response: '403', description: 'Invalid or expired signature'),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/init.mp4', name: 'init_segment', methods: ['GET'])]
    public function initSegment(string $jobPublicId, Request $request): Response
    {
        if (!$this->validateSignature($request)) {
            return new JsonResponse(['error' => 'Invalid or expired signature'], Response::HTTP_FORBIDDEN);
        }

        $path = $this->streamingService->getInitSegmentPath(PublicId::fromString($jobPublicId));

        if ($path === null) {
            return $this->notFound('Init segment not found.');
        }

        return $this->streamFile($path, 'video/mp4');
    }

    #[OA\Get(
        path: '/api/stream/{jobPublicId}/seg_{index}.m4s',
        summary: 'Get fMP4 media segment',
        parameters: [
            new OA\Parameter(name: 'jobPublicId', description: 'Job public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'index', description: 'Segment index', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'sig', description: 'URL signature', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'exp', description: 'Expiry timestamp', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'fMP4 media segment', content: new OA\MediaType(mediaType: 'video/mp4')),
            new OA\Response(response: '202', description: 'Segment not yet encoded'),
            new OA\Response(response: '403', description: 'Invalid or expired signature'),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/seg_{index}.m4s', name: 'segment', methods: ['GET'])]
    public function segment(string $jobPublicId, int $index, Request $request): Response
    {
        if (!$this->validateSignature($request)) {
            return new JsonResponse(['error' => 'Invalid or expired signature'], Response::HTTP_FORBIDDEN);
        }

        $path = $this->streamingService->getSegmentPath(PublicId::fromString($jobPublicId), $index);

        if ($path === null) {
            return new StreamedResponse(
                callback: static fn() => print '',
                status: Response::HTTP_ACCEPTED,
                headers: [
                    'Content-Type' => 'video/mp4',
                    'Retry-After' => '2',
                ],
            );
        }

        return $this->streamFile($path, 'video/mp4');
    }

    private function validateSignature(Request $request): bool
    {
        $sig = $request->query->get('sig');
        $exp = $request->query->getInt('exp');

        if ($sig === null || $exp === 0) {
            return false;
        }

        $path = '/' . ltrim($request->getPathInfo(), '/');

        return $this->streamAuth->validateUrl($path, $sig, $exp);
    }

    // --- Audio Segment Delivery ---

    #[OA\Get(
        path: '/api/stream/{jobPublicId}/audio/{language}/init.mp4',
        summary: 'Get audio init segment',
        parameters: [
            new OA\Parameter(name: 'jobPublicId', description: 'Job public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'language', description: 'BCP-47 language tag', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sig', description: 'URL signature', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'exp', description: 'Expiry timestamp', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Audio fMP4 init segment', content: new OA\MediaType(mediaType: 'video/mp4', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(response: '403', description: 'Invalid or expired signature'),
            new OA\Response(response: '404', description: 'Not found'),
        ],
    )]
    #[Route('/audio/{language}/init.mp4', name: 'audio_init_segment', methods: ['GET'])]
    public function audioInitSegment(string $jobPublicId, string $language, Request $request): Response
    {
        if (!$this->validateSignature($request)) {
            return new JsonResponse(['error' => 'Invalid or expired signature'], Response::HTTP_FORBIDDEN);
        }

        $path = $this->streamingService->getAudioInitSegmentPath(PublicId::fromString($jobPublicId), $language);

        if ($path === null) {
            return $this->notFound('Audio init segment not found.');
        }

        return $this->streamFile($path, 'video/mp4');
    }

    #[OA\Get(
        path: '/api/stream/{jobPublicId}/audio/{language}/seg_{index}.m4s',
        summary: 'Get audio media segment',
        parameters: [
            new OA\Parameter(name: 'jobPublicId', description: 'Job public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'language', description: 'BCP-47 language tag', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'index', description: 'Segment index', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'sig', description: 'URL signature', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'exp', description: 'Expiry timestamp', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Audio fMP4 media segment', content: new OA\MediaType(mediaType: 'video/mp4', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(response: '202', description: 'Segment not yet encoded'),
            new OA\Response(response: '403', description: 'Invalid or expired signature'),
            new OA\Response(response: '404', description: 'Not found'),
        ],
    )]
    #[Route('/audio/{language}/seg_{index}.m4s', name: 'audio_segment', methods: ['GET'])]
    public function audioSegment(string $jobPublicId, string $language, int $index, Request $request): Response
    {
        if (!$this->validateSignature($request)) {
            return new JsonResponse(['error' => 'Invalid or expired signature'], Response::HTTP_FORBIDDEN);
        }

        $path = $this->streamingService->getAudioSegmentPath(PublicId::fromString($jobPublicId), $language, $index);

        if ($path === null) {
            return new StreamedResponse(
                callback: static fn() => print '',
                status: Response::HTTP_ACCEPTED,
                headers: ['Content-Type' => 'video/mp4', 'Retry-After' => '2'],
            );
        }

        return $this->streamFile($path, 'video/mp4');
    }

    // --- Subtitle Segment Delivery ---

    #[OA\Get(
        path: '/api/stream/{jobPublicId}/subtitles/{language}/{segment}.vtt',
        summary: 'Get subtitle segment (WebVTT)',
        parameters: [
            new OA\Parameter(name: 'jobPublicId', description: 'Job public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'language', description: 'BCP-47 language tag', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'segment', description: 'Segment name', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sig', description: 'URL signature', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'exp', description: 'Expiry timestamp', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'WebVTT subtitle file', content: new OA\MediaType(mediaType: 'text/vtt', schema: new OA\Schema(type: 'string'))),
            new OA\Response(response: '403', description: 'Invalid or expired signature'),
            new OA\Response(response: '404', description: 'Not found'),
        ],
    )]
    #[Route('/subtitles/{language}/{segment}.vtt', name: 'subtitle_segment', methods: ['GET'])]
    public function subtitleSegment(string $jobPublicId, string $language, string $segment, Request $request): Response
    {
        if (!$this->validateSignature($request)) {
            return new JsonResponse(['error' => 'Invalid or expired signature'], Response::HTTP_FORBIDDEN);
        }

        $path = $this->streamingService->getSubtitleSegmentPath(PublicId::fromString($jobPublicId), $language, $segment);

        if ($path === null) {
            return $this->notFound('Subtitle segment not found.');
        }

        return $this->streamFile($path, 'text/vtt');
    }

    /**
     * Stream a file directly from disk using zero-copy I/O.
     *
     * Uses fopen/fpassthru to send file data without loading it into PHP memory.
     * The OS kernel handles the file-to-socket data transfer.
     */
    private function streamFile(string $path, string $contentType): StreamedResponse
    {
        return new StreamedResponse(
            callback: static function () use ($path): void {
                $stream = fopen($path, 'rb');
                fpassthru($stream);
                fclose($stream);
            },
            headers: [
                'Content-Type' => $contentType,
                'Content-Length' => filesize($path),
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ],
        );
    }
}
