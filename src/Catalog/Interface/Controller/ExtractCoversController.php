<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Controller;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Metadata\Application\Command\BatchExtractCoversCommand;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use OpenApi\Attributes as OA;
use Nelmio\ApiDocBundle\Attribute\Model;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Messenger\MessageBusInterface;

#[OA\Tag(name: 'Album Cover')]
#[Route('/api/albums/covers/extract', name: 'extract_covers_')]
#[IsGranted('ROLE_ADMIN')]
final class ExtractCoversController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly AlbumPortInterface $albumService,
        private readonly MessageBusInterface $bus,
    )
    {
    }

    #[OA\Post(
        path: '/api/albums/covers/extract',
        description: 'Dispatches an async batch job to extract embedded cover art from audio files for all albums without a cover image.',
        summary: 'Extract cover art for all albums missing one',
        responses: [
            new OA\Response(response: '202', description: 'Batch extraction dispatched', content: new OA\JsonContent(properties: [new OA\Property(property: 'albums', type: 'integer')])),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route(methods: ['POST'])]
    public function __invoke(): JsonResponse
    {
        $count = $this->albumService->countCoverlessAlbums();

        $this->bus->dispatch(new BatchExtractCoversCommand());

        return $this->successResponse([
            'albums' => $count,
        ], 202);
    }
}
