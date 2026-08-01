<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Controller;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Playlist\Domain\Repository\PlaylistRepositoryInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Tag(name: 'Admin - Album')]
#[Route('/api/admin/albums', name: 'admin_album_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminAlbumController
{
    use ApiResponsesTrait;
    use TranslatorTrait;

    public function __construct(
        private readonly AlbumPortInterface $albumPort,
        private readonly SongPortInterface $songPort,
        private readonly PlaylistRepositoryInterface $playlistRepo,
    ) {
    }

    #[OA\Get(
        path: '/api/admin/albums/{publicId}/delete-preview',
        summary: 'Preview what will be deleted when deleting an album',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Album public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Delete preview data',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'album', properties: [
                            new OA\Property(property: 'id', type: 'string'),
                            new OA\Property(property: 'title', type: 'string'),
                            new OA\Property(property: 'songCount', type: 'integer'),
                        ], type: 'object'),
                        new OA\Property(property: 'files', properties: [
                            new OA\Property(property: 'count', type: 'integer'),
                            new OA\Property(property: 'totalSize', type: 'integer'),
                        ], type: 'object'),
                        new OA\Property(property: 'coverImage', properties: [
                            new OA\Property(property: 'id', type: 'string', nullable: true),
                        ], type: 'object', nullable: true),
                        new OA\Property(property: 'affected', properties: [
                            new OA\Property(property: 'playlists', type: 'integer'),
                            new OA\Property(property: 'playlistNames', items: new OA\Items(type: 'string'), type: 'array'),
                        ], type: 'object'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'Album not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Invalid public ID', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/{publicId}/delete-preview', name: 'delete_preview', methods: ['GET'])]
    public function deletePreview(string $publicId): JsonResponse
    {
        $resolvedPublicId = $this->resolvePublicId($publicId);
        if ($resolvedPublicId === null) {
            return $this->errorResponse($this->trans('errors.invalid_public_id'), 422);
        }

        $album = $this->albumPort->findByPublicId($resolvedPublicId);
        if ($album === null) {
            return $this->notFound();
        }

        $songs = $this->songPort->findByAlbum($album->getId(), limit: 1000);
        $totalSize = array_sum(array_map(fn($s) => $s->getSize(), $songs));

        $playlistData = $this->getPlaylistDataForSongs($songs);

        $coverImageData = $album->getCoverImageId() !== null
            ? ['id' => $album->getCoverImageId()->toString()]
            : null;

        return $this->successResponse([
            'album' => [
                'id' => $album->getPublicId()->toString(),
                'title' => $album->getTitle(),
                'songCount' => count($songs),
            ],
            'files' => [
                'count' => count($songs),
                'totalSize' => $totalSize,
            ],
            'coverImage' => $coverImageData,
            'affected' => [
                'playlists' => $playlistData['count'],
                'playlistNames' => $playlistData['names'],
            ],
        ]);
    }

    #[OA\Delete(
        path: '/api/admin/albums/{publicId}',
        summary: 'Delete an album with optional file deletion',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Album public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'deleteFiles', description: 'Whether to delete audio files', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'deleteCover', description: 'Whether to delete cover image (default: true)', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: '204', description: 'Album deleted successfully'),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'Album not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Invalid public ID', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/{publicId}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $publicId, Request $request): JsonResponse
    {
        $resolvedPublicId = $this->resolvePublicId($publicId);
        if ($resolvedPublicId === null) {
            return $this->errorResponse($this->trans('errors.invalid_public_id'), 422);
        }

        $album = $this->albumPort->findByPublicId($resolvedPublicId);
        if ($album === null) {
            return $this->notFound();
        }

        $deleteFiles = filter_var($request->query->get('deleteFiles', 'false'), FILTER_VALIDATE_BOOLEAN);
        $deleteCover = filter_var($request->query->get('deleteCover', 'true'), FILTER_VALIDATE_BOOLEAN);

        $this->albumPort->delete($album, $deleteFiles, $deleteCover);

        return $this->noContent();
    }

    private function resolvePublicId(string $publicId): ?PublicId
    {
        try {
            return PublicId::fromString($publicId);
        } catch (\Throwable) {
            return null;
        }
    }

    private function getPlaylistDataForSongs(array $songs): array
    {
        $allPlaylistNames = [];
        foreach ($songs as $song) {
            $playlists = $this->playlistRepo->findPlaylistNamesContainingSong($song->getId());
            $allPlaylistNames = array_merge($allPlaylistNames, $playlists);
        }

        $uniqueNames = array_unique($allPlaylistNames, SORT_REGULAR);
        $nameOnly = array_map(fn($p) => $p['name'], $uniqueNames);

        return [
            'count' => count($uniqueNames),
            'names' => array_values($nameOnly),
        ];
    }
}
