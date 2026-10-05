<?php

declare(strict_types=1);

namespace App\Media\Interface\Controller;

use App\Media\Application\Port\MediaReadScopeProviderInterface;
use App\Media\Application\Port\StreamPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use OpenApi\Attributes as OA;
use Nelmio\ApiDocBundle\Attribute\Model;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
            new OA\Response(response: '206', description: 'Partial content (single byte range request)'),
            new OA\Response(response: '416', description: 'Requested byte range is not satisfiable'),
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

        $response = new SwooleBinaryFileResponse($fullPath);
        $response->headers->set('Content-Type', $metadata->mimeType);
        // Every request must recheck library access, including byte-range retries.
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
