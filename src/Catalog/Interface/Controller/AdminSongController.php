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

#[OA\Tag(name: 'Admin - Song')]
#[Route('/api/admin/songs', name: 'admin_song_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminSongController
{
    use ApiResponsesTrait;
    use TranslatorTrait;

    public function __construct(
        private readonly SongPortInterface $songPort,
        private readonly AlbumPortInterface $albumPort,
        private readonly PlaylistRepositoryInterface $playlistRepo,
    ) {
    }

    #[OA\Get(
        path: '/api/admin/songs/{publicId}/delete-preview',
        summary: 'Preview what will be deleted when deleting a song',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Song public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Delete preview data',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'song', properties: [
                            new OA\Property(property: 'id', type: 'string'),
                            new OA\Property(property: 'title', type: 'string'),
                            new OA\Property(property: 'album', properties: [
                                new OA\Property(property: 'id', type: 'string'),
                                new OA\Property(property: 'title', type: 'string'),
                            ], type: 'object', nullable: true),
                        ], type: 'object'),
                        new OA\Property(property: 'file', properties: [
                            new OA\Property(property: 'path', type: 'string'),
                            new OA\Property(property: 'size', type: 'integer'),
                        ], type: 'object'),
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
            new OA\Response(response: '404', description: 'Song not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
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

        $song = $this->songPort->findByPublicId($resolvedPublicId);
        if ($song === null) {
            return $this->notFound();
        }

        $album = null;
        if ($song->getAlbumId() !== null) {
            $album = $this->albumPort->findByUuid($song->getAlbumId());
        }

        $playlists = $this->playlistRepo->findPlaylistNamesContainingSong($song->getId());
        $playlistNames = array_map(fn($p) => $p['name'], $playlists);

        return $this->successResponse([
            'song' => [
                'id' => $song->getPublicId()->toString(),
                'title' => $song->getTitle(),
                'album' => $album !== null ? [
                    'id' => $album->getPublicId()->toString(),
                    'title' => $album->getTitle(),
                ] : null,
            ],
            'file' => [
                'path' => $song->getPath(),
                'size' => $song->getSize(),
            ],
            'affected' => [
                'playlists' => count($playlists),
                'playlistNames' => array_values($playlistNames),
            ],
        ]);
    }

    #[OA\Delete(
        path: '/api/admin/songs/{publicId}',
        summary: 'Delete a song with optional file deletion',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Song public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'deleteFile', description: 'Whether to delete the audio file', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: '204', description: 'Song deleted successfully'),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'Song not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
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

        $song = $this->songPort->findByPublicId($resolvedPublicId);
        if ($song === null) {
            return $this->notFound();
        }

        $deleteFile = filter_var($request->query->get('deleteFile', 'false'), FILTER_VALIDATE_BOOLEAN);

        $this->songPort->delete($song, $deleteFile);

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
}
