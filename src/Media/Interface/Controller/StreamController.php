<?php

declare(strict_types=1);

namespace App\Media\Interface\Controller;

use App\Media\Application\Port\MediaReadScopeProviderInterface;
use App\Media\Application\Port\StreamPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use App\Transcode\Application\Exception\AudioRenditionFailedException;
use App\Transcode\Application\Port\AudioRendition;
use App\Transcode\Application\Port\AudioRenditionFormat;
use App\Transcode\Application\Port\AudioRenditionPortInterface;
use App\Transcode\Application\Settings\TranscodeSettingDefinitions;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use OpenApi\Attributes as OA;
use Nelmio\ApiDocBundle\Attribute\Model;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use SwooleBundle\SwooleBundle\Bridge\Symfony\HttpFoundation\SwooleBinaryFileResponse;

#[OA\Tag(name: 'Media', description: 'Media file and streaming endpoints')]
#[Route('/api/stream', name: 'stream_')]
final class StreamController
{
    use ApiResponsesTrait;
    use TranslatorTrait;

    public function __construct(
        private readonly StreamPortInterface $streamService,
        private readonly MediaReadScopeProviderInterface $scopes,
        private readonly AudioRenditionPortInterface $renditions,
        private readonly SystemSettingsPortInterface $settings,
    ) {
    }

    /**
     * Stream a track by its PublicId with HTTP Range (206) support.
     *
     * With `format` (and optionally `bitrate`) the track is transcoded. A cached
     * rendition streams with Range support; otherwise the response streams the
     * encode while it runs, without byte ranges.
     */
    #[OA\Get(
        path: '/api/stream/track',
        summary: 'Stream a track by PublicId with HTTP Range support',
        parameters: [
            new OA\Parameter(name: 'id', description: 'PublicId of the track', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'format', description: 'Transcode to this audio format. Without it the original file is streamed.', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['opus', 'aac', 'mp3'])),
            new OA\Parameter(name: 'bitrate', description: 'Target bitrate in bits per second; requires format. Fitted to the format\'s supported range (opus 32000-256000, aac and mp3 32000-320000) in whole kilobits. Never above the server\'s transcode.max_bitrate, which also applies when no bitrate is given.', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Full file (audio/video stream). A transcode that is still encoding streams progressively with Accept-Ranges: none.'),
            new OA\Response(response: '206', description: 'Partial content (single byte range request)'),
            new OA\Response(response: '400', description: 'Unsupported format or invalid bitrate', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '403', description: 'No access to the track, or a format was requested while transcode.enabled is off', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '416', description: 'Requested byte range is not satisfiable'),
            new OA\Response(response: '404', description: 'Track not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '500', description: 'Transcoding failed', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
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

        $scope = $this->scopes->current();

        if ($scope->getActorId() === null) {
            return $this->unauthorized($this->trans('errors.authentication_required', domain: 'media'));
        }

        $libraryId = $this->streamService->getLibraryIdForTrack($trackId);
        if ($libraryId === null) {
            return $this->notFound($this->trans('errors.track_not_found', domain: 'media'));
        }

        if (!$scope->getLibraries()->allows($libraryId)) {
            return $this->forbidden($this->trans('errors.forbidden', domain: 'messages'));
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

        $format = (string) $request->query->get('format', '');
        $bitrate = (string) $request->query->get('bitrate', '');
        if ($format === '') {
            if ($bitrate !== '') {
                return $this->errorResponse($this->trans('errors.invalid_transcode_bitrate', domain: 'media'));
            }

            return $this->fileResponse($fullPath, $metadata->mimeType);
        }

        $renditionFormat = AudioRenditionFormat::tryFrom(strtolower($format));
        if ($renditionFormat === null) {
            return $this->errorResponse($this->trans('errors.unsupported_transcode_format', domain: 'media'));
        }
        if ($bitrate !== '' && (!ctype_digit($bitrate) || (int) $bitrate === 0)) {
            return $this->errorResponse($this->trans('errors.invalid_transcode_bitrate', domain: 'media'));
        }
        if ($this->settings->get(TranscodeSettingDefinitions::ENABLED) !== true) {
            return $this->errorResponse($this->trans('errors.transcode_disabled', domain: 'media'), Response::HTTP_FORBIDDEN);
        }
        // transcode.max_bitrate is in kilobits; without a requested bitrate the cap applies.
        $maxBitrate = (int) $this->settings->get(TranscodeSettingDefinitions::MAX_BITRATE) * 1000;
        $targetBitrate = $bitrate === '' ? $maxBitrate : min((int) $bitrate, $maxBitrate);

        try {
            $rendition = $this->renditions->open($trackId->toString(), $fullPath, $renditionFormat, $targetBitrate);
        } catch (AudioRenditionFailedException) {
            return $this->errorResponse(
                $this->trans('errors.transcode_failed', domain: 'media'),
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return $rendition->isComplete()
            ? $this->fileResponse((string) $rendition->path, $renditionFormat->mimeType())
            : $this->progressiveResponse($rendition);
    }

    private function fileResponse(string $path, string $mimeType): Response
    {
        $response = new SwooleBinaryFileResponse($path);
        $response->headers->set('Content-Type', $mimeType);
        // Every request must recheck library access, including byte-range retries.
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function progressiveResponse(AudioRendition $rendition): Response
    {
        return new StreamedResponse(
            static function () use ($rendition): void {
                foreach ($rendition->chunks() as $chunk) {
                    echo $chunk;
                    flush();
                }
            },
            Response::HTTP_OK,
            [
                'Content-Type' => $rendition->format->mimeType(),
                // The rendition is still being written, so its length is unknown.
                'Accept-Ranges' => 'none',
                'Cache-Control' => 'private, no-store',
            ],
        );
    }
}
