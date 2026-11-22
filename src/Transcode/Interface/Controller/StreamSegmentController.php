<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Controller;

use App\Shared\Application\Port\SleeperInterface;
use App\Shared\Domain\Model\PublicId;
use App\Transcode\Application\DTO\MediaTrack;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\DTO\ApiError;
use App\Transcode\Application\Port\LiveDeliveryAccessPort;
use App\Transcode\Application\Port\SegmentAvailabilityInterface;
use App\Transcode\Interface\Security\SignedStreamRequest;
use App\Transcode\Application\Port\TranscodeStreamingPortInterface;
use OpenApi\Spec as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Stream', description: 'HLS streaming endpoints')]
#[Route('/api/transcode/{jobPublicId}', name: 'stream_segment_', requirements: [
    'jobPublicId' => PublicId::PATTERN,
    'language' => 's[0-9]+',
    'segment' => 'full',
])]
final class StreamSegmentController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly TranscodeStreamingPortInterface $streamingService,
        private readonly SignedStreamRequest $signedRequest,
        private readonly SegmentAvailabilityInterface $segmentAvailability,
        private readonly SleeperInterface $sleeper,
        private readonly LiveDeliveryAccessPort $ownerAccess,
    ) {
    }

    #[OA\Operation\Get(
        path: '/api/transcode/{jobPublicId}/tracks/{track}/init.mp4',
        summary: 'Get fMP4 track initialization segment',
        parameters: [
            new OA\Parameter(
                name: 'track',
                in: 'path',
                description: 'Published media component',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['video', 'audio'],
                ),
            ),
            new OA\Parameter(
                name: 'generation',
                in: 'query',
                description: 'Pinned media execution generation',
                required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1),
            ),
            new OA\Parameter(
                name: 'jobPublicId',
                in: 'path',
                description: 'Job public ID',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    minLength: PublicId::LENGTH,
                    maxLength: PublicId::LENGTH,
                    pattern: PublicId::SCHEMA_PATTERN,
                ),
            ),
            new OA\Parameter(
                name: 'sig',
                in: 'query',
                description: 'URL signature',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
            new OA\Parameter(
                name: 'exp',
                in: 'query',
                description: 'Expiry timestamp',
                required: true,
                schema: new OA\Schema(type: 'integer'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'fMP4 init segment',
                content: [
                    new OA\MediaType(
                        mediaType: 'video/mp4',
                        schema: new OA\Schema(type: 'string', format: 'binary'),
                    ),
                    new OA\MediaType(
                        mediaType: 'audio/mp4',
                        schema: new OA\Schema(type: 'string', format: 'binary'),
                    ),
                ],
            ),
            new OA\Response(
                response: '403',
                description: 'Invalid or expired signature',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'error' => new OA\Schema(type: 'string'),
                        ],
                        required: ['error'],
                    ),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '409',
                description: 'The job owner may no longer play the video',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '503',
                description: 'Init segment is not ready; empty response body',
                headers: [
                    new OA\Header(
                        header: 'Retry-After',
                        schema: new OA\Schema(type: 'integer', example: 2),
                    ),
                ],
                content: [
                    new OA\MediaType(
                        mediaType: 'video/mp4',
                        schema: new OA\Schema(type: 'string', format: 'binary'),
                    ),
                    new OA\MediaType(
                        mediaType: 'audio/mp4',
                        schema: new OA\Schema(type: 'string', format: 'binary'),
                    ),
                ],
            ),
        ],
    )]
    #[Route('/tracks/{track}/init.mp4', name: 'init_segment', requirements: ['track' => 'video|audio'], methods: ['GET'])]
    public function initSegment(string $jobPublicId, MediaTrack $track, Request $request): Response
    {
        if (!$this->signedRequest->isValid($request)) {
            return new JsonResponse(['error' => 'Invalid or expired signature'], Response::HTTP_FORBIDDEN);
        }
        $job = PublicId::fromString($jobPublicId);
        $this->ownerAccess->assertOwnerMayStream($job);

        $generation = $request->query->getInt('generation');
        $resolve = fn (): ?string => $this->streamingService->getInitSegmentPath($job, $track, $generation);
        $path = $resolve();

        if ($path === null) {
            return $this->notFound('Init segment not found.');
        }

        $valid = fn (): bool => $resolve() === $path;
        $readyPath = $this->waitForFilePath($path, 5, $valid);
        if ($readyPath === null) {
            return new StreamedResponse(
                static fn() => print '',
                Response::HTTP_SERVICE_UNAVAILABLE,
                ['Content-Type' => $track === MediaTrack::Video ? 'video/mp4' : 'audio/mp4', 'Retry-After' => '2'],
            );
        }

        return $this->streamFile($readyPath, $track === MediaTrack::Video ? 'video/mp4' : 'audio/mp4', $valid);
    }

    #[OA\Operation\Get(
        path: '/api/transcode/{jobPublicId}/tracks/{track}/segment.m4s',
        summary: 'Get fMP4 media segment (query-param routed)',
        parameters: [
            new OA\Parameter(
                name: 'track',
                in: 'path',
                description: 'Published media component',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['video', 'audio'],
                ),
            ),
            new OA\Parameter(
                name: 'generation',
                in: 'query',
                description: 'Pinned media execution generation',
                required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1),
            ),
            new OA\Parameter(
                name: 'jobPublicId',
                in: 'path',
                description: 'Job public ID',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    minLength: PublicId::LENGTH,
                    maxLength: PublicId::LENGTH,
                    pattern: PublicId::SCHEMA_PATTERN,
                ),
            ),
            new OA\Parameter(
                name: 'index',
                in: 'query',
                description: 'Zero-based generation-local published segment ordinal',
                required: true,
                schema: new OA\Schema(type: 'integer'),
            ),
            new OA\Parameter(
                name: 'sig',
                in: 'query',
                description: 'URL signature',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
            new OA\Parameter(
                name: 'exp',
                in: 'query',
                description: 'Expiry timestamp',
                required: true,
                schema: new OA\Schema(type: 'integer'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'fMP4 media segment',
                content: [
                    new OA\MediaType(mediaType: 'video/mp4'),
                    new OA\MediaType(mediaType: 'audio/mp4'),
                ],
            ),
            new OA\Response(response: '503', description: 'Published segment is temporarily unavailable'),
            new OA\Response(response: '403', description: 'Invalid or expired signature'),
            new OA\Response(
                response: '404',
                description: 'Not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '409',
                description: 'The job owner may no longer play the video',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/tracks/{track}/segment.m4s', name: 'segment', requirements: ['track' => 'video|audio'], methods: ['GET'])]
    public function segment(string $jobPublicId, MediaTrack $track, Request $request): Response
    {
        if (!$this->signedRequest->isValid($request)) {
            return new JsonResponse(['error' => 'Invalid or expired signature'], Response::HTTP_FORBIDDEN);
        }
        $job = PublicId::fromString($jobPublicId);
        $this->ownerAccess->assertOwnerMayStream($job);

        $index = $request->query->getInt('index', -1);
        if ($index < 0) {
            return new JsonResponse(['error' => 'Missing or invalid index parameter'], Response::HTTP_BAD_REQUEST);
        }

        $generation = $request->query->getInt('generation');
        $resolve = fn (): ?array => $this->streamingService->resolveSegmentAvailability($job, $track, $index, $generation);
        $resolved = $resolve();

        if ($resolved === null) {
            return $this->notFound('Segment index out of range.');
        }

        $valid = fn (): bool => ($resolve()['path'] ?? null) === $resolved['path'];
        $readyPath = $this->waitForSegment(
            $resolved['jobId'],
            $resolved['tierKey'],
            $index,
            $resolved['path'],
            5,
            $valid,
        );

        if ($readyPath === null) {
            return new StreamedResponse(
                static fn() => print '',
                Response::HTTP_SERVICE_UNAVAILABLE,
                ['Content-Type' => $track === MediaTrack::Video ? 'video/mp4' : 'audio/mp4', 'Retry-After' => '2'],
            );
        }

        return $this->streamFile($readyPath, $track === MediaTrack::Video ? 'video/mp4' : 'audio/mp4', $valid);
    }

    // --- Subtitle Segment Delivery ---

    #[OA\Operation\Get(
        path: '/api/transcode/{jobPublicId}/subtitles/{language}/{segment}.vtt',
        summary: 'Get subtitle segment (WebVTT)',
        parameters: [
            new OA\Parameter(
                name: 'generation',
                in: 'query',
                description: 'Pinned media execution generation',
                required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1),
            ),
            new OA\Parameter(
                name: 'jobPublicId',
                in: 'path',
                description: 'Job public ID',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    minLength: PublicId::LENGTH,
                    maxLength: PublicId::LENGTH,
                    pattern: PublicId::SCHEMA_PATTERN,
                ),
            ),
            new OA\Parameter(
                name: 'language',
                in: 'path',
                description: 'Source subtitle track key: s followed by the absolute FFprobe stream index (for example s2)',
                required: true,
                schema: new OA\Schema(type: 'string', pattern: '^s[0-9]+$'),
            ),
            new OA\Parameter(
                name: 'segment',
                in: 'path',
                description: 'Segment name; the whole track is one segment',
                required: true,
                schema: new OA\Schema(type: 'string', enum: ['full']),
            ),
            new OA\Parameter(
                name: 'sig',
                in: 'query',
                description: 'URL signature',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
            new OA\Parameter(
                name: 'exp',
                in: 'query',
                description: 'Expiry timestamp',
                required: true,
                schema: new OA\Schema(type: 'integer'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'WebVTT subtitle file',
                content: new OA\MediaType(
                    mediaType: 'text/vtt',
                    schema: new OA\Schema(type: 'string'),
                ),
            ),
            new OA\Response(response: '403', description: 'Invalid or expired signature'),
            new OA\Response(response: '404', description: 'Not found'),
            new OA\Response(
                response: '409',
                description: 'The job owner may no longer play the video',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/subtitles/{language}/{segment}.vtt', name: 'subtitle_segment', methods: ['GET'])]
    public function subtitleSegment(string $jobPublicId, string $language, string $segment, Request $request): Response
    {
        if (!$this->signedRequest->isValid($request)) {
            return new JsonResponse(['error' => 'Invalid or expired signature'], Response::HTTP_FORBIDDEN);
        }
        $job = PublicId::fromString($jobPublicId);
        $this->ownerAccess->assertOwnerMayStream($job);

        $generation = $request->query->getInt('generation');
        $resolve = fn (): ?string => $this->streamingService->getSubtitleSegmentPath($job, $language, $segment, $generation);
        $path = $resolve();

        if ($path === null) {
            return $this->notFound('Subtitle segment not found.');
        }

        return $this->streamFile($path, 'text/vtt', fn (): bool => $resolve() === $path);
    }

    /**
     * Use the encoder's hint only for the path resolved for this request.
     * A late readiness write must not redirect delivery to another file.
     * A nonempty, unchanged snapshot remains a readiness heuristic, not proof
     * of completed publication or encoding-attempt ownership.
     */
    private function waitForSegment(Uuid $jobId, string $tierKey, int $index, string $path, int $timeoutSeconds = 10, ?callable $valid = null): ?string
    {
        if ($valid !== null && !$valid()) {
            return null;
        }
        $ready = $this->segmentAvailability->isReady($jobId, $tierKey, $index);
        if ($ready === $path) {
            $stable = $this->isFileSizeStable($path);
            if ($stable !== null && ($valid === null || $valid())) {
                return $stable;
            }
        }

        return $this->waitForFilePath($path, $timeoutSeconds, $valid);
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

        $this->sleeper->sleep(0.25);
        return $this->readFileSnapshot($path) === $first ? $path : null;
    }

    /**
     * Poll until three consecutive fresh observations agree, or the deadline
     * expires. Detect replacement as well as growth; a missing/empty file resets
     * the observation window. Atomic producer publication is still required to
     * turn this quiet-window heuristic into a completion guarantee.
     */
    private function waitForFilePath(string $path, int $timeoutSeconds = 10, ?callable $valid = null): ?string
    {
        $deadline = hrtime(true) / 1_000_000_000 + $timeoutSeconds;
        $stableChecks = 0;
        $last = null;

        while (hrtime(true) / 1_000_000_000 < $deadline) {
            if ($valid !== null && !$valid()) {
                return null;
            }
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
            $this->sleeper->sleep(min(0.25, $remaining));
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
    private function streamFile(string $path, string $contentType, ?callable $valid = null): Response
    {
        if ($valid !== null && !$valid()) {
            return new JsonResponse(['error' => 'Media generation is unavailable'], Response::HTTP_SERVICE_UNAVAILABLE);
        }
        clearstatcache(true, $path);
        if (!is_file($path)) {
            return new JsonResponse(['error' => 'Segment file not found'], Response::HTTP_NOT_FOUND);
        }

        $size = filesize($path);
        if ($size === false || $size <= 0) {
            return new JsonResponse(['error' => 'Segment file empty'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new StreamedResponse(
            static function () use ($path, $valid): void {
                if ($valid !== null && !$valid()) {
                    return;
                }
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
                'Cache-Control' => 'no-store',
            ],
        );
    }
}
