<?php

declare(strict_types=1);

namespace App\Playlist\Interface\Controller;

use App\Playlist\Application\Port\PlaylistPortInterface;
use App\Playlist\Domain\Model\Playlist;
use App\Playlist\Interface\Request\AddSongRequest;
use App\Playlist\Interface\Request\CreatePlaylistRequest;
use App\Playlist\Interface\Request\ReorderSongsRequest;
use App\Playlist\Interface\Request\UpdatePlaylistRequest;
use App\Playlist\Interface\Resource\PlaylistResource;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Interface\Resource\SongResource;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Playlist', description: 'Playlist management endpoints')]
#[Route('/api/playlists', name: 'playlist_')]
final class PlaylistController
{
    use ApiResponsesTrait;
    use TranslatorTrait;

    public function __construct(
        private readonly Security $security,
        private readonly PlaylistPortInterface $playlistService,
        private readonly SongPortInterface $songService,
    ) {
    }

    /**
     * List playlists belonging to the authenticated user.
     */
    #[OA\Get(
        path: '/api/playlists/',
        summary: 'List playlists belonging to the authenticated user',
        responses: [
            new OA\Response(response: '200', description: 'Success', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: new Model(type: PlaylistResource::class)))], type: 'object',
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $playlists = $this->playlistService->findByUser(
            Uuid::fromString($user->getId()),
        );

        return $this->successResponse(PlaylistResource::collection($playlists));
    }

    /**
     * Create a new playlist.
     */
    #[OA\Post(
        path: '/api/playlists/',
        summary: 'Create a new playlist',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    required: ['name'],
                    properties: [
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'description', type: 'string', nullable: true),
                        new OA\Property(property: 'isPublic', type: 'boolean'),
                        new OA\Property(property: 'isCollaborative', type: 'boolean'),
                        new OA\Property(property: 'isSmart', type: 'boolean'),
                        new OA\Property(property: 'smartRules', type: 'array', items: new OA\Items()),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: '201', description: 'Created', content: new OA\JsonContent(ref: new Model(type: PlaylistResource::class))),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/', name: 'store', methods: ['POST'])]
    public function store(#[MapRequestPayload] CreatePlaylistRequest $payload): JsonResponse
    {
        $user = $this->security->getUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        try {
            $playlist = Playlist::create(
                name: $payload->name,
                userId: Uuid::fromString($user->getId()),
                description: $payload->description,
                isPublic: $payload->isPublic,
                isCollaborative: $payload->isCollaborative,
                isSmart: $payload->isSmart,
                smartRules: $payload->smartRules,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage());
        }

        $this->playlistService->save($playlist);

        return $this->created(PlaylistResource::from($playlist));
    }

    /**
     * Get a single playlist with its songs.
     */
    #[OA\Get(
        path: '/api/playlists/{publicId}',
        summary: 'Get a single playlist with its songs',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Playlist public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Success', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'uuid', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000'),
                        new OA\Property(property: 'publicId', type: 'string', example: 'aB3dE5fG7hJ9kL1mN3p'),
                        new OA\Property(property: 'userId', type: 'string', format: 'uuid', example: '660f9510-f30c-52e5-b827-557755550111'),
                        new OA\Property(property: 'name', type: 'string', example: 'My Favorites'),
                        new OA\Property(property: 'description', type: 'string', example: 'A collection of my favorite songs'),
                        new OA\Property(property: 'isPublic', type: 'boolean', example: true),
                        new OA\Property(property: 'isCollaborative', type: 'boolean', example: false),
                        new OA\Property(property: 'isSmart', type: 'boolean', example: false),
                        new OA\Property(property: 'songCount', type: 'integer', example: 25),
                        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'songs', type: 'array', items: new OA\Items(properties: [
                            new OA\Property(property: 'songId', type: 'string', format: 'uuid', example: '770f9510-f30c-52e5-b827-557755550222'),
                            new OA\Property(property: 'position', type: 'integer', example: 0),
                        ], type: 'object')),
                    ], type: 'object'),
                ], type: 'object',
            )),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{publicId}', name: 'show', methods: ['GET'])]
    public function show(string $publicId): JsonResponse
    {
        try {
            $resolvedPublicId = PublicId::fromString($publicId);
        } catch (\Throwable) {
            return $this->errorResponse($this->trans('errors.invalid_public_id'));
        }

        $playlist = $this->playlistService->findByPublicId($resolvedPublicId);

        if ($playlist === null) {
            return $this->notFound();
        }

        $playlistWithSongs = $this->playlistService->findWithSongs($playlist->getId());

        $songs = [];
        if ($playlistWithSongs !== null) {
            $songIds = array_map(
                static fn(\App\Playlist\Domain\Model\PlaylistSong $s) => $s->getSongId(),
                $playlistWithSongs->getSongs(),
            );

            $songMap = $this->songService->findByUuids($songIds);
            $artistNames = $this->songService->getArtistNamesForSongs($songIds);

            $albumIds = array_map(
                static fn(\App\Catalog\Domain\Model\Song $s) => $s->getAlbumId(),
                array_values(array_filter($songMap)),
            );
            $albumTitles = $this->songService->getAlbumTitlesByIds($albumIds);

            foreach ($playlistWithSongs->getSongs() as $playlistSong) {
                $songUuid = $playlistSong->getSongId()->toString();
                $song = $songMap[$songUuid] ?? null;
                $songs[] = array_merge(
                    ['position' => $playlistSong->getPosition()],
                    $song !== null
                        ? SongResource::fromWithMeta($song, $artistNames, $albumTitles)
                        : ['uuid' => $songUuid],
                );
            }
        }

        return $this->successResponse(array_merge(
            PlaylistResource::from($playlistWithSongs ?? $playlist),
            ['songs' => $songs],
        ));
    }

    /**
     * Update playlist metadata.
     */
    #[OA\Patch(
        path: '/api/playlists/{publicId}',
        summary: 'Update playlist metadata',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(property: 'name', type: 'string', nullable: true),
                        new OA\Property(property: 'description', type: 'string', nullable: true),
                        new OA\Property(property: 'isPublic', type: 'boolean', nullable: true),
                    ],
                ),
            ),
        ),
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Playlist public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Success', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: new Model(type: PlaylistResource::class))], type: 'object',
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/{publicId}', name: 'update', methods: ['PATCH'])]
    public function update(string $publicId, #[MapRequestPayload] UpdatePlaylistRequest $payload): JsonResponse
    {
        $playlist = $this->resolvePlaylist($publicId);
        if ($playlist === null) {
            return $this->notFound();
        }

        $forbidden = $this->requireOwnership($playlist);
        if ($forbidden !== null) {
            return $forbidden;
        }

        $name = $payload->name ?? $playlist->getName();
        $description = $payload->description ?? $playlist->getDescription();
        $isPublic = $payload->isPublic ?? $playlist->isPublic();

        try {
            $playlist->updateMetadata($name, $description, $isPublic);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage());
        }

        $this->playlistService->save($playlist);

        return $this->successResponse(PlaylistResource::from($playlist));
    }

    /**
     * Delete a playlist.
     */
    #[OA\Delete(
        path: '/api/playlists/{publicId}',
        summary: 'Delete a playlist',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Playlist public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '204', description: 'Deleted'),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{publicId}', name: 'destroy', methods: ['DELETE'])]
    public function destroy(string $publicId): JsonResponse
    {
        $playlist = $this->resolvePlaylist($publicId);
        if ($playlist === null) {
            return $this->notFound();
        }

        $forbidden = $this->requireOwnership($playlist);
        if ($forbidden !== null) {
            return $forbidden;
        }

        $this->playlistService->delete($playlist);

        return $this->noContent();
    }

    /**
     * Add a song to a playlist.
     */
    #[OA\Post(
        path: '/api/playlists/{publicId}/songs',
        summary: 'Add a song to a playlist',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    required: ['songId'],
                    properties: [
                        new OA\Property(property: 'songId', type: 'string'),
                    ],
                ),
            ),
        ),
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Playlist public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '201', description: 'Created', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'songId', type: 'string', format: 'uuid', example: '770f9510-f30c-52e5-b827-557755550222'),
                    new OA\Property(property: 'position', type: 'integer', example: 0),
                ], type: 'object',
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/{publicId}/songs', name: 'add_song', methods: ['POST'])]
    public function addSong(string $publicId, #[MapRequestPayload] AddSongRequest $payload): JsonResponse
    {
        $playlist = $this->resolvePlaylistWithSongs($publicId);
        if ($playlist === null) {
            return $this->notFound();
        }

        $forbidden = $this->requireOwnership($playlist);
        if ($forbidden !== null) {
            return $forbidden;
        }

        try {
            $songId = Uuid::fromString($payload->songId);
        } catch (\Throwable) {
            return $this->errorResponse($this->trans('errors.invalid_song_id_format'));
        }

        $position = count($playlist->getSongs());

        try {
            $playlist->addSong($songId, $position);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage());
        }

        $this->playlistService->save($playlist);

        return $this->created([
            'songId' => $songId->toString(),
            'position' => $position,
        ]);
    }

    /**
     * Remove a song from a playlist.
     */
    #[OA\Delete(
        path: '/api/playlists/{publicId}/songs/{songId}',
        summary: 'Remove a song from a playlist',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Playlist public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'songId', description: 'Song UUID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '204', description: 'Deleted'),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{publicId}/songs/{songId}', name: 'remove_song', methods: ['DELETE'])]
    public function removeSong(string $publicId, string $songId): JsonResponse
    {
        $playlist = $this->resolvePlaylistWithSongs($publicId);
        if ($playlist === null) {
            return $this->notFound();
        }

        $forbidden = $this->requireOwnership($playlist);
        if ($forbidden !== null) {
            return $forbidden;
        }

        try {
            $resolvedSongId = Uuid::fromString($songId);
        } catch (\Throwable) {
            return $this->errorResponse($this->trans('errors.invalid_song_id'));
        }

        $playlist->removeSong($resolvedSongId);
        $this->playlistService->save($playlist);

        return $this->noContent();
    }

    /**
     * Reorder songs in a playlist.
     */
    #[OA\Post(
        path: '/api/playlists/{publicId}/reorder',
        summary: 'Reorder songs in a playlist',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    required: ['songIds'],
                    properties: [
                        new OA\Property(property: 'songIds', type: 'array', items: new OA\Items(type: 'string')),
                    ],
                ),
            ),
        ),
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Playlist public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Success', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Songs reordered successfully.'),
                ], type: 'object')], type: 'object',
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/{publicId}/reorder', name: 'reorder', methods: ['POST'])]
    public function reorder(string $publicId, #[MapRequestPayload] ReorderSongsRequest $payload): JsonResponse
    {
        $playlist = $this->resolvePlaylistWithSongs($publicId);
        if ($playlist === null) {
            return $this->notFound();
        }

        $forbidden = $this->requireOwnership($playlist);
        if ($forbidden !== null) {
            return $forbidden;
        }

        try {
            $uuids = array_map(static fn(string $id) => Uuid::fromString($id), $payload->songIds);
            $playlist->reorderSongs($uuids);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage());
        } catch (\Throwable) {
            return $this->errorResponse($this->trans('errors.invalid_song_ids_format'));
        }

        $this->playlistService->save($playlist);

        return $this->successResponse(['message' => $this->trans('success.reordered', domain: 'playlist')]);
    }

    /**
     * Resolve a playlist by its public ID and load its songs.
     */
    private function resolvePlaylistWithSongs(string $publicId): ?Playlist
    {
        try {
            $resolvedPublicId = PublicId::fromString($publicId);
        } catch (\Throwable) {
            return null;
        }

        $playlist = $this->playlistService->findByPublicId($resolvedPublicId);

        if ($playlist === null) {
            return null;
        }

        return $this->playlistService->findWithSongs($playlist->getId());
    }

    private function resolvePlaylist(string $publicId): ?Playlist
    {
        try {
            $resolvedPublicId = PublicId::fromString($publicId);
        } catch (\Throwable) {
            return null;
        }

        return $this->playlistService->findByPublicId($resolvedPublicId);
    }

    private function requireOwnership(Playlist $playlist): ?JsonResponse
    {
        $user = $this->security->getUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        if ($playlist->getUserId()->toString() !== $user->getId()) {
            return $this->errorResponse('You do not own this playlist.', 403);
        }

        return null;
    }
}
