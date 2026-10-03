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
        path: '/api/transcode/{jobPublicId}/init',
        summary: 'Get CMAF init segment',
        parameters: [
            new OA\Parameter(name: 'jobPublicId', description: 'Job public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sig', description: 'URL signature', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'exp', description: 'Expiry timestamp', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'fMP4 init segment', content: new OA\MediaType(mediaType: 'video/mp4', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(response: '403', description: 'Invalid or expired signature', content: new OA\JsonContent(
                required: ['error'],
                properties: [new OA\Property(property: 'error', type: 'string')],
            )),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '503', description: 'Init segment is not ready; empty response body', headers: [
                new OA\Header(header: 'Retry-After', schema: new OA\Schema(type: 'integer', example: 2)),
            ], content: new OA\MediaType(mediaType: 'video/mp4', schema: new OA\Schema(type: 'string', format: 'binary'))),
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
     * Use the encoder's hint only for the path resolved for this request.
     * A late readiness write must not redirect delivery to another file.
     * A nonempty, unchanged snapshot remains a readiness heuristic, not proof
     * of completed publication or encoding-attempt ownership.
     */
    private function waitForSegment(Uuid $jobId, string $tierKey, int $index, string $path, int $timeoutSeconds = 10): ?string
    {
        $ready = $this->segmentAvailability->isReady($jobId, $tierKey, $index);
        if ($ready === $path) {
            $stable = $this->isFileSizeStable($path);
            if ($stable !== null) {
                return $stable;
            }
        }

        return $this->waitForFilePath($path, $timeoutSeconds);
    }

    /**
     * Observe the same nonempty regular file across two fresh samples.
     * An unchanged snapshot does not prove that the writer has closed the file.
     */
    private function isFileSizeStable(string $path): ?string
    {
        $first = $this->readFileSnapshot($path);
        if ($first === null) {
            return null;
        }

        Async::sleep(0.25);
        return $this->readFileSnapshot($path) === $first ? $path : null;
    }

    /**
     * Poll until three consecutive fresh observations agree, or the deadline
     * expires. Detect replacement as well as growth; a missing/empty file resets
     * the observation window. Atomic producer publication is still required to
     * turn this quiet-window heuristic into a completion guarantee.
     */
    private function waitForFilePath(string $path, int $timeoutSeconds = 10): ?string
    {
        $deadline = hrtime(true) / 1_000_000_000 + $timeoutSeconds;
        $stableChecks = 0;
        $last = null;

        while (hrtime(true) / 1_000_000_000 < $deadline) {
            $snapshot = $this->readFileSnapshot($path);
            if ($snapshot !== null && $snapshot === $last) {
                if (++$stableChecks >= 2) {
                    return $path;
                }
            } else {
                $stableChecks = 0;
            }
            $last = $snapshot;

            $remaining = $deadline - hrtime(true) / 1_000_000_000;
            if ($remaining <= 0) {
                break;
            }
            Async::sleep(min(0.25, $remaining));
        }

        return null;
    }

    /** @return array{device: int, inode: int, size: int, modified: int, changed: int}|null */
    private function readFileSnapshot(string $path): ?array
    {
        // FFmpeg runs outside this PHP process, so its writes do not invalidate
        // PHP's stat cache. Clear it before every observation, including the first.
        clearstatcache(true, $path);
        $stat = @stat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] <= 0) {
            return null;
        }

        return [
            'device' => $stat['dev'],
            'inode' => $stat['ino'],
            'size' => $stat['size'],
            'modified' => $stat['mtime'],
            'changed' => $stat['ctime'],
        ];
    }

    /**
     * Stream a file without loading its complete contents into PHP memory.
     * Refresh metadata before constructing Content-Length. The file can still
     * change before the callback opens it; the already-sent status cannot be
     * changed if the file disappears at that point.
     */
    private function streamFile(string $path, string $contentType): Response
    {
        clearstatcache(true, $path);
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
