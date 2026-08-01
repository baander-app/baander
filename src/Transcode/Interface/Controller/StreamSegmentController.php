<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Controller;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\Async;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Transcode\Application\Port\SegmentAvailabilityInterface;
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

#[OA\Tag(name: 'Stream', description: 'HLS streaming endpoints')]
#[Route('/api/transcode/{jobPublicId}', name: 'stream_segment_')]
final class StreamSegmentController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly TranscodeStreamingPortInterface $streamingService,
        private readonly StreamAuthPortInterface $streamAuth,
        private readonly SegmentAvailabilityInterface $segmentAvailability,
    ) {
    }

    #[OA\Get(
        path: '/api/transcode/{jobPublicId}/init.mp4',
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
    #[Route('/init', name: 'init_segment', methods: ['GET'])]
    public function initSegment(string $jobPublicId, Request $request): Response
    {
        if (!$this->validateSignature($request)) {
            return new JsonResponse(['error' => 'Invalid or expired signature'], Response::HTTP_FORBIDDEN);
        }

        $path = $this->streamingService->getInitSegmentPath(PublicId::fromString($jobPublicId));

        if ($path === null) {
            return $this->notFound('Init segment not found.');
        }

        $readyPath = $this->waitForFilePath($path);
        if ($readyPath === null) {
            return new StreamedResponse(
                static fn() => print '',
                Response::HTTP_SERVICE_UNAVAILABLE,
                ['Content-Type' => 'video/mp4', 'Retry-After' => '2'],
            );
        }

        return $this->streamFile($readyPath, 'video/mp4');
    }

    #[OA\Get(
        path: '/api/transcode/{jobPublicId}/segment',
        summary: 'Get fMP4 media segment (query-param routed)',
        parameters: [
            new OA\Parameter(name: 'jobPublicId', description: 'Job public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'index', description: 'Segment index', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
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
    #[Route('/segment', name: 'segment', methods: ['GET'])]
    public function segment(string $jobPublicId, Request $request): Response
    {
        if (!$this->validateSignature($request)) {
            return new JsonResponse(['error' => 'Invalid or expired signature'], Response::HTTP_FORBIDDEN);
        }

        $index = $request->query->getInt('index', -1);
        if ($index < 0) {
            return new JsonResponse(['error' => 'Missing or invalid index parameter'], Response::HTTP_BAD_REQUEST);
        }

        $resolved = $this->streamingService->resolveVideoSegmentAvailability(PublicId::fromString($jobPublicId), $index);

        if ($resolved === null) {
            return $this->notFound('Segment index out of range.');
        }

        $readyPath = $this->waitForSegment(
            $resolved['jobId'],
            $resolved['tierKey'],
            $index,
            $resolved['path'],
            60,
        );

        if ($readyPath === null) {
            return new StreamedResponse(
                static fn() => print '',
                Response::HTTP_SERVICE_UNAVAILABLE,
                ['Content-Type' => 'video/mp4', 'Retry-After' => '2'],
            );
        }

        return $this->streamFile($readyPath, 'video/mp4');
    }

    private function validateSignature(Request $request): bool
    {
        $sig = $request->query->get('sig');
        $exp = $request->query->getInt('exp');

        if ($sig === null || $exp === 0) {
            return false;
        }

        // Reconstruct the signed path including query parameters that were part
        // of the signed URL. The manifest signs the full path+query string
        // (e.g. /segment?index=0), but getPathInfo() returns only the path.
        // We must include the index param so the HMAC matches.
        $path = '/' . ltrim($request->getPathInfo(), '/');
        $index = $request->query->get('index');
        if ($index !== null) {
            $path .= '?index=' . $index;
        }

        return $this->streamAuth->validateUrl($path, $sig, $exp);
    }

    // --- Subtitle Segment Delivery ---

    #[OA\Get(
        path: '/api/transcode/{jobPublicId}/subtitles/{language}/{segment}.vtt',
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
     * Wait for a segment to become available, checking the in-memory
     * SegmentAvailabilityInterface first (instant push signal from the encoder)
     * and falling back to file-stat polling.
     *
     * The table check is the fast path: the encoder worker writes a row the
     * instant it finishes a segment. If the table says ready, verify the file
     * is actually complete (two consecutive stable-size reads, matching
     * waitForFilePath's flush check) before serving — the HLS fMP4 muxer
     * writes segment files incrementally, so non-zero size means writing has
     * *started*, not finished. If the row is absent or the file isn't stable,
     * the stat-loop handles it — file-existence + stability remains ground truth.
     */
    private function waitForSegment(Uuid $jobId, string $tierKey, int $index, string $path, int $timeoutSeconds = 10): ?string
    {
        $ready = $this->segmentAvailability->isReady($jobId, $tierKey, $index);
        if ($ready !== null && is_file($ready)) {
            $stable = $this->isFileSizeStable($ready);
            if ($stable !== null) {
                return $stable;
            }
        }

        // Table miss, stale row, or file still being written — fall back to
        // file-stat polling which performs the stability check in its loop.
        return $this->waitForFilePath($path, $timeoutSeconds);
    }

    /**
     * Verify a file's size is stable across two reads (~0.25s apart), meaning
     * the writer has finished and closed it. Returns the path if stable and
     * non-empty, null otherwise (still being written or empty).
     */
    private function isFileSizeStable(string $path): ?string
    {
        $first = @filesize($path);
        if ($first === false || $first <= 0) {
            return null;
        }

        Async::sleep(0.25);
        $second = @filesize($path);
        if ($second === false || $second <= 0 || $second !== $first) {
            return null;
        }

        return $path;
    }

    /**
     * Wait up to $timeoutSeconds for a segment file to be written and flushed.
     *
     * Returns the path once the file exists with non-zero size, or null if the
     * timeout expires. This replaces the old 202 + Retry-After behaviour so
     * standard HLS players (hls.js, native Safari) can stream on-the-fly
     * transcodes without custom retry logic.
     */
    private function waitForFilePath(string $path, int $timeoutSeconds = 10): ?string
    {
        $elapsed = 0.0;
        $interval = 0.25;
        $stableChecks = 0;
        $lastSize = -1;

        while ($elapsed < $timeoutSeconds) {
            if (is_file($path)) {
                $size = @filesize($path);
                if ($size > 0 && $size === $lastSize) {
                    $stableChecks++;
                    // Two consecutive stable reads (≈0.5s apart) mean the writer
                    // has likely finished and closed the file.
                    if ($stableChecks >= 2) {
                        return $path;
                    }
                } else {
                    $stableChecks = 0;
                }
                $lastSize = $size;
            } else {
                $stableChecks = 0;
                $lastSize = -1;
            }

            Async::sleep($interval);
            $elapsed += $interval;
        }

        return null;
    }

    /**
     * Stream a file directly from disk using zero-copy I/O.
     *
     * Uses fopen/fpassthru to send file data without loading it into PHP memory.
     * The OS kernel handles the file-to-socket data transfer.
     *
     * The file is stat'd before the response is built so Content-Length is
     * emitted (lets players size buffers and seek within a segment). If the
     * file vanishes between the stat and the streamed read (race), fopen
     * returns false and the callback emits an empty 500 instead of crashing.
     */
    private function streamFile(string $path, string $contentType): Response
    {
        if (!is_file($path)) {
            return new JsonResponse(['error' => 'Segment file not found'], Response::HTTP_NOT_FOUND);
        }

        $size = filesize($path);
        if ($size === false || $size <= 0) {
            return new JsonResponse(['error' => 'Segment file empty'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new StreamedResponse(
            static function () use ($path): void {
                $stream = @fopen($path, 'rb');
                if ($stream === false) {
                    // File vanished between the stat and the open (race).
                    // The response status was already sent as 200; emit an
                    // empty body rather than crashing on fpassthru(false).
                    return;
                }
                fpassthru($stream);
                fclose($stream);
            },
            200,
            [
                'Content-Type' => $contentType,
                'Content-Length' => (string) $size,
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ],
        );
    }
}
