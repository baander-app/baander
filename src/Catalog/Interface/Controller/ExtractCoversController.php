<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Controller;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Command\BatchExtractCoversCommand;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use OpenApi\Spec as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Messenger\MessageBusInterface;

#[OA\Tag(name: 'Album Cover')]
#[Route('/api/albums/covers/extract', name: 'album_covers_')]
#[IsGranted('ROLE_ADMIN')]
final class ExtractCoversController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly AlbumPortInterface $albumService,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[OA\Operation\Post(
        path: '/api/albums/covers/extract',
        summary: 'Extract cover art for all albums missing one',
        description: 'Queues individual extraction jobs for embedded cover art from audio files for all albums without a cover image.',
        responses: [
            new OA\Response(
                response: '202',
                description: 'Batch extraction dispatched',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'albums' => new OA\Schema(type: 'integer'),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Forbidden',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route(name: 'extract', methods: ['POST'])]
    #[CliCounterpart('app:album:extract-covers')]
    public function __invoke(): JsonResponse
    {
        $count = $this->albumService->countCoverlessAlbums();

        $this->bus->dispatch(new BatchExtractCoversCommand());

        return $this->successResponse([
            'albums' => $count,
        ], 202);
    }
}
