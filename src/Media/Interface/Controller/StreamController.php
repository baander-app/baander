<?php

declare(strict_types=1);

namespace App\Media\Interface\Controller;

use App\Filesystem\Mime\MimeDetector;
use App\Media\Application\Port\StreamPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use OpenApi\Attributes as OA;
use Nelmio\ApiDocBundle\Attribute\Model;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use SwooleBundle\SwooleBundle\Bridge\Symfony\HttpFoundation\SwooleRangeFileResponse;

#[OA\Tag(name: 'Media', description: 'Media file and streaming endpoints')]
#[Route('/api/stream', name: 'stream_')]
final class StreamController
{
    use ApiResponsesTrait;
    use TranslatorTrait;

    public function __construct(
        private readonly string $mediaBasePath,
        private readonly MimeDetector $mimeDetector,
        private readonly StreamPortInterface $streamService,
    ) {
    }

    /**
     * Stream a track by its PublicId with HTTP Range (206) support.
     */
    #[OA\Get(
        path: '/api/stream/track',
        summary: 'Stream a track by PublicId with HTTP Range support',
        parameters: [
            new OA\Parameter(name: 'id', description: 'PublicId of the track', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'format', description: 'Target audio codec (e.g. opus, aac, mp3)', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'bitrate', description: 'Target bitrate in bps', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Full file (audio/video stream)'),
            new OA\Response(response: '206', description: 'Partial content (byte range request)'),
            new OA\Response(response: '404', description: 'Track not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/track', name: 'track', methods: ['GET'])]
    public function streamById(Request $request): Response
    {
        $id = $request->query->get('id');

        if ($id === null || trim((string) $id) === '') {
            return $this->notFound($this->trans('errors.missing_track_id', domain: 'media'));
        }

        try {
            $trackId = PublicId::fromString((string) $id);
        } catch (\InvalidArgumentException) {
            return $this->notFound($this->trans('errors.invalid_track_id_format', domain: 'media'));
        }

        try {
            $metadata = $this->streamService->getTrackMetadata($trackId);
        } catch (\InvalidArgumentException) {
            return $this->notFound($this->trans('errors.track_not_found', domain: 'media'));
        }

        $fullPath = $this->streamService->resolveTrackPath($trackId);

        if (!file_exists($fullPath)) {
            return $this->notFound($this->trans('errors.file_not_found', domain: 'media'));
        }

        $fileSize = filesize($fullPath);
        $mimeType = $metadata->mimeType;

        $rangeHeader = $request->headers->get('Range');

        if ($rangeHeader !== null && preg_match('/^bytes=(\d*)-(\d*)$/', $rangeHeader, $matches)) {
            $start = $matches[1] !== '' ? (int) $matches[1] : null;
            $end = $matches[2] !== '' ? (int) $matches[2] : null;

            if ($start === null && $end !== null) {
                $start = max(0, $fileSize - $end);
                $end = $fileSize - 1;
            } elseif ($end === null) {
                $end = $fileSize - 1;
            }

            if ($start >= $fileSize) {
                return new Response('', Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE, [
                    'Content-Range' => sprintf('bytes */%d', $fileSize),
                ]);
            }

            return $this->streamRange($fullPath, $fileSize, $mimeType, $start, $end);
        }

        $response = new BinaryFileResponse($fullPath);
        $response->headers->set('Content-Type', $mimeType);
        $response->headers->set('Accept-Ranges', 'bytes');
        $response->setCache([
            'max_age' => 86400,
            'public' => true,
        ]);

        return $response;
    }

    /**
     * Stream a media file by path with HTTP Range (206) support.
     * @deprecated Use streamById() instead. This endpoint will be removed in a future version.
     */
    #[OA\Get(
        path: '/api/stream/media',
        summary: 'Stream a media file by path with HTTP Range support (deprecated)',
        parameters: [
            new OA\Parameter(name: 'path', description: 'Relative path to the media file within the media base directory', in: 'query', required: true, schema: new OA\Schema(type: 'string'), example: 'music/Album/song.flac'),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Full file (audio/video stream)',
                headers: [
                    new OA\Header(header: 'Content-Type', description: 'MIME type of the media file', schema: new OA\Schema(type: 'string')),
                    new OA\Header(header: 'Content-Length', description: 'Total file size in bytes', schema: new OA\Schema(type: 'integer')),
                    new OA\Header(header: 'Accept-Ranges', description: 'Supported range unit (bytes)', schema: new OA\Schema(type: 'string')),
                ],
            ),
            new OA\Response(response: '206', description: 'Partial content (byte range request)',
                headers: [
                    new OA\Header(header: 'Content-Type', description: 'MIME type of the media file', schema: new OA\Schema(type: 'string')),
                    new OA\Header(header: 'Content-Range', description: 'Byte range being served, e.g. bytes 0-8191/102400', schema: new OA\Schema(type: 'string')),
                    new OA\Header(header: 'Content-Length', description: 'Size of the returned byte range', schema: new OA\Schema(type: 'integer')),
                    new OA\Header(header: 'Accept-Ranges', description: 'Supported range unit (bytes)', schema: new OA\Schema(type: 'string')),
                ],
            ),
            new OA\Response(response: '404', description: 'File not found or path outside media directory', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/media', name: 'media', methods: ['GET'])]
    public function stream(Request $request): Response
    {
        $path = $request->query->get('path');

        if ($path === null || trim((string) $path) === '') {
            return $this->notFound($this->trans('errors.missing_path', domain: 'media'));
        }

        // Security: only allow paths within the media base path
        $fullPath = $this->mediaBasePath . '/' . ltrim((string) $path, '/');

        if (!str_starts_with(realpath($fullPath) ?: '', realpath($this->mediaBasePath) ?: '')) {
            return $this->notFound($this->trans('errors.invalid_file_path', domain: 'media'));
        }

        if (!file_exists($fullPath)) {
            return $this->notFound($this->trans('errors.file_not_found', domain: 'media'));
        }

        $fileSize = filesize($fullPath);
        $mimeType = $this->mimeDetector->detect($fullPath);

        // Handle range requests
        $rangeHeader = $request->headers->get('Range');

        if ($rangeHeader !== null && preg_match('/^bytes=(\d*)-(\d*)$/', $rangeHeader, $matches)) {
            $start = $matches[1] !== '' ? (int) $matches[1] : null;
            $end = $matches[2] !== '' ? (int) $matches[2] : null;

            // Suffix range: bytes=-500 means last 500 bytes
            if ($start === null && $end !== null) {
                $start = max(0, $fileSize - $end);
                $end = $fileSize - 1;
            } elseif ($end === null) {
                $end = $fileSize - 1;
            }

            if ($start >= $fileSize) {
                return new Response('', Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE, [
                    'Content-Range' => sprintf('bytes */%d', $fileSize),
                ]);
            }

            return $this->streamRange($fullPath, $fileSize, $mimeType, $start, $end);
        }

        $response = new BinaryFileResponse($fullPath);
        $response->headers->set('Content-Type', $mimeType);
        $response->headers->set('Accept-Ranges', 'bytes');
        $response->setCache([
            'max_age' => 86400,
            'public' => true,
        ]);

        return $response;
    }

    private function streamRange(string $path, int $fileSize, string $mimeType, int $start, int $end): SwooleRangeFileResponse
    {
        $end = min($end, $fileSize - 1);
        $length = $end - $start + 1;

        return new SwooleRangeFileResponse(
            path: $path,
            offset: $start,
            length: $length,
            fileSize: $fileSize,
            mimeType: $mimeType,
        );
    }
}
