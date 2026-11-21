<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Controller;

use App\Catalog\Application\Port\VideoPlaybackInformationPort;
use App\Catalog\Interface\Resource\VideoPlaybackInformationResource;
use App\Library\Application\Port\LibraryReadScopeProviderInterface;
use App\Shared\Domain\Model\PublicId;
use OpenApi\Spec as OA;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(
    name: 'Catalog',
    description: 'Album, artist, song, movie, and genre management endpoints',
)]
#[Route('/api/videos/{publicId}', name: 'video_playback_', requirements: [
    'publicId' => '[0-9a-zA-Z_-]{21}',
])]
final readonly class VideoPlaybackInformationController
{
    public function __construct(
        private VideoPlaybackInformationPort $information,
        private LibraryReadScopeProviderInterface $scopes,
    ) {
    }

    #[Route('/playback-information', name: 'information', methods: ['GET'])]
    #[OA\Operation\Get(
        summary: 'Get available video tracks, chapters and timeline previews',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Playback information',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(ref: VideoPlaybackInformationResource::class),
                        ],
                    ),
                ),
            ),
            new OA\Response(response: '404', description: 'Video unavailable'),
        ],
    )]
    public function information(string $publicId): JsonResponse
    {
        $information = $this->information->information(PublicId::fromString($publicId), $this->scopes->current());
        if ($information === null) {
            throw new NotFoundHttpException('Video unavailable.');
        }

        return new JsonResponse([
            'data' => VideoPlaybackInformationResource::from($information),
        ], headers: [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    #[Route('/timeline-preview', name: 'preview', methods: ['GET'])]
    #[OA\Operation\Get(
        summary: 'Get an authorized video timeline contact sheet',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Timeline contact sheet',
                content: new OA\MediaType(mediaType: 'image/jpeg'),
            ),
            new OA\Response(response: '404', description: 'Video or preview unavailable'),
        ],
    )]
    public function preview(string $publicId): BinaryFileResponse
    {
        $image = $this->information->image(PublicId::fromString($publicId), $this->scopes->current());
        if ($image === null) {
            throw new NotFoundHttpException('Preview unavailable.');
        }

        return new BinaryFileResponse($image, headers: [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, no-store',
        ], public: false);
    }
}
