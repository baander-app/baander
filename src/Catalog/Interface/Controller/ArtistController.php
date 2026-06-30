<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Controller;

use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Domain\Model\Artist;
use App\Catalog\Domain\ValueObject\ArtistRole;
use App\Catalog\Interface\Request\ArtistAlbumRequest;
use App\Catalog\Interface\Request\ArtistSongRequest;
use App\Catalog\Interface\Request\CreateArtistRequest;
use App\Catalog\Interface\Request\UpdateArtistRequest;
use App\Catalog\Interface\Request\UpdateRoleRequest;
use App\Catalog\Interface\Resource\ArtistResource;
use App\Media\Application\Port\ImagePortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use App\Shared\Interface\DTO\PaginatedResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Tag(name: 'Catalog', description: 'Album, artist, song, movie, and genre management endpoints')]
#[Route('/api/artists', name: 'artist_')]
final class ArtistController
{
    use ApiResponsesTrait;
    use TranslatorTrait;
    public function __construct(
        private readonly ArtistPortInterface $artistService,
        private readonly ImagePortInterface $imagePort,
    ) {
    }

    /**
     * Create a new artist.
     */
    #[OA\Post(
        path: '/api/artists/',
        summary: 'Create a new artist',
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'country', type: 'string', nullable: true),
                new OA\Property(property: 'gender', type: 'string', nullable: true),
                new OA\Property(property: 'type', type: 'string', nullable: true),
                new OA\Property(property: 'disambiguation', type: 'string', nullable: true),
                new OA\Property(property: 'sortName', type: 'string', nullable: true),
                new OA\Property(property: 'biography', type: 'string', nullable: true),
            ])),
        responses: [
            new OA\Response(response: '201', description: 'Artist created', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: new Model(type: ArtistResource::class))],
                type: 'object',
            )),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/', name: 'store', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function store(#[MapRequestPayload] CreateArtistRequest $payload): JsonResponse
    {
        try {
            $artist = Artist::create(
                name: $payload->name,
                country: $payload->country,
                gender: $payload->gender,
                type: $payload->type,
                disambiguation: $payload->disambiguation,
                sortName: $payload->sortName,
                biography: $payload->biography,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage());
        }

        $this->artistService->save($artist);

        return $this->created(ArtistResource::from($artist));
    }

    /**
     * List/search artists (paginated).
     */
    #[OA\Get(
        path: '/api/artists/',
        summary: 'List artists (paginated)',
        parameters: [
            new OA\Parameter(name: 'page', description: 'Page number', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', description: 'Items per page (max 100)', in: 'query', schema: new OA\Schema(type: 'integer', default: 50)),
            new OA\Parameter(name: 'q', description: 'Search query', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'genre', description: 'Filter by genre slug', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', description: 'Sort field (name)', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'order', description: 'Sort order (asc, desc)', in: 'query', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'asc')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Paginated list of artists', content: new OA\JsonContent(ref: new Model(type: PaginatedResponse::class))),
        ],
    )]
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 50)));
        $offset = ($page - 1) * $limit;
        $query = (string) $request->query->get('q', '');

        $filters = [];

        $genre = $request->query->get('genre');
        if (is_string($genre) && $genre !== '') {
            $filters[] = [
                'field' => 'genre',
                'operator' => '=',
                'value' => $genre,
            ];
        }

        $options = SearchOptions::create($query, $limit, $offset)
            ->withFilters($filters);

        $sort = $request->query->get('sort');
        if (is_string($sort) && $sort !== '') {
            $order = strtolower((string) $request->query->get('order', 'asc'));
            if (!in_array($order, ['asc', 'desc'], true)) {
                $order = 'asc';
            }
            $options = $options->withSort($sort, $order);
        }

        $searchResult = $this->artistService->search($options);

        $results = ArtistResource::collection($searchResult->getItems());
        $total = $searchResult->getTotal();

        return $this->paginatedResponse(new PaginatedResponse(
            data: $results,
            currentPage: $page,
            lastPage: (int) ceil($total / $limit) ?: 1,
            perPage: $limit,
            total: $total,
        ));
    }

    /**
     * Get a single artist.
     */
    #[OA\Get(
        path: '/api/artists/{publicId}',
        summary: 'Get a single artist',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Artist public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Artist details', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: new Model(type: ArtistResource::class))],
                type: 'object',
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

        $artist = $this->artistService->findByPublicId($resolvedPublicId);

        if ($artist === null) {
            return $this->notFound();
        }

        $coverImage = null;
        $coverImageId = $artist->getCoverImageId();
        if ($coverImageId !== null) {
            $coverImage = $this->imagePort->findByUuid($coverImageId);
        }

        return $this->successResponse(ArtistResource::fromWithCover($artist, $coverImage));
    }

    /**
     * Update an artist.
     */
    #[OA\Patch(
        path: '/api/artists/{publicId}',
        summary: 'Update an artist',
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
                new OA\Property(property: 'name', type: 'string', nullable: true),
                new OA\Property(property: 'country', type: 'string', nullable: true),
                new OA\Property(property: 'gender', type: 'string', nullable: true),
                new OA\Property(property: 'type', type: 'string', nullable: true),
                new OA\Property(property: 'disambiguation', type: 'string', nullable: true),
                new OA\Property(property: 'sortName', type: 'string', nullable: true),
                new OA\Property(property: 'biography', type: 'string', nullable: true),
            ])),
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Artist public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Artist updated', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: new Model(type: ArtistResource::class))],
                type: 'object',
            )),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/{publicId}', name: 'update', methods: ['PATCH'])]
    public function update(string $publicId, #[MapRequestPayload] UpdateArtistRequest $payload): JsonResponse
    {
        try {
            $resolvedPublicId = PublicId::fromString($publicId);
        } catch (\Throwable) {
            return $this->errorResponse($this->trans('errors.invalid_public_id'));
        }

        $artist = $this->artistService->findByPublicId($resolvedPublicId);

        if ($artist === null) {
            return $this->notFound();
        }

        try {
            $artist->updateMetadata(
                name: $payload->name,
                country: $payload->country,
                gender: $payload->gender,
                type: $payload->type,
                disambiguation: $payload->disambiguation,
                sortName: $payload->sortName,
                biography: $payload->biography,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage());
        }

        if ($payload->lockedFields !== null) {
            $this->syncLockedFields($artist, $payload->lockedFields);
        }

        $this->artistService->save($artist);

        return $this->successResponse(ArtistResource::from($artist));
    }

    /**
     * Delete an artist.
     */
    #[OA\Delete(
        path: '/api/artists/{publicId}',
        summary: 'Delete an artist',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Artist public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '204', description: 'Deleted'),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{publicId}', name: 'destroy', methods: ['DELETE'])]
    public function destroy(string $publicId): JsonResponse
    {
        try {
            $resolvedPublicId = PublicId::fromString($publicId);
        } catch (\Throwable) {
            return $this->errorResponse($this->trans('errors.invalid_public_id'));
        }

        $artist = $this->artistService->findByPublicId($resolvedPublicId);

        if ($artist === null) {
            return $this->notFound();
        }

        $this->artistService->delete($artist);

        return $this->noContent();
    }

    /**
     * Add a song to an artist with a role.
     */
    #[OA\Post(
        path: '/api/artists/{publicId}/songs',
        summary: 'Add a song to an artist',
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
                new OA\Property(property: 'songId', type: 'string'),
                new OA\Property(property: 'role', type: 'string'),
            ])),
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Artist public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '204', description: 'Song added to artist'),
            new OA\Response(response: '404', description: 'Artist or song not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/{publicId}/songs', name: 'add_song', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function addSong(string $publicId, #[MapRequestPayload] ArtistSongRequest $payload): JsonResponse
    {
        $artist = $this->resolveArtist($publicId);

        if ($artist === null) {
            return $this->notFound();
        }

        if (!ArtistRole::tryFrom($payload->role)) {
            return $this->errorResponse('Invalid role. Valid roles: ' . implode(', ', array_map(fn (ArtistRole $r) => $r->value, ArtistRole::cases())));
        }

        try {
            $songId = Uuid::fromString($payload->songId);
        } catch (\Throwable) {
            return $this->errorResponse('Invalid song ID format.');
        }

        $this->artistService->addSongToArtist($artist->getId(), $songId, $payload->role);

        return $this->noContent();
    }

    /**
     * Remove a song from an artist.
     */
    #[OA\Delete(
        path: '/api/artists/{publicId}/songs/{songId}',
        summary: 'Remove a song from an artist',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Artist public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'songId', description: 'Song UUID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '204', description: 'Song removed from artist'),
            new OA\Response(response: '404', description: 'Artist not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{publicId}/songs/{songId}', name: 'remove_song', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function removeSong(string $publicId, string $songId): JsonResponse
    {
        $artist = $this->resolveArtist($publicId);

        if ($artist === null) {
            return $this->notFound();
        }

        try {
            $resolvedSongId = Uuid::fromString($songId);
        } catch (\Throwable) {
            return $this->errorResponse('Invalid song ID format.');
        }

        $this->artistService->removeSongFromArtist($artist->getId(), $resolvedSongId);

        return $this->noContent();
    }

    /**
     * Update the role of an artist on a song.
     */
    #[OA\Patch(
        path: '/api/artists/{publicId}/songs/{songId}',
        summary: 'Update artist role on a song',
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
                new OA\Property(property: 'role', type: 'string'),
            ])),
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Artist public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'songId', description: 'Song UUID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '204', description: 'Role updated'),
            new OA\Response(response: '404', description: 'Artist not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/{publicId}/songs/{songId}', name: 'update_song_role', methods: ['PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    public function updateSongRole(string $publicId, string $songId, #[MapRequestPayload] UpdateRoleRequest $payload): JsonResponse
    {
        $artist = $this->resolveArtist($publicId);

        if ($artist === null) {
            return $this->notFound();
        }

        if (!ArtistRole::tryFrom($payload->role)) {
            return $this->errorResponse('Invalid role. Valid roles: ' . implode(', ', array_map(fn (ArtistRole $r) => $r->value, ArtistRole::cases())));
        }

        try {
            $resolvedSongId = Uuid::fromString($songId);
        } catch (\Throwable) {
            return $this->errorResponse('Invalid song ID format.');
        }

        $this->artistService->updateSongRole($artist->getId(), $resolvedSongId, $payload->role);

        return $this->noContent();
    }

    /**
     * Add an album to an artist with a role.
     */
    #[OA\Post(
        path: '/api/artists/{publicId}/albums',
        summary: 'Add an album to an artist',
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
                new OA\Property(property: 'albumId', type: 'string'),
                new OA\Property(property: 'role', type: 'string'),
            ])),
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Artist public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '204', description: 'Album added to artist'),
            new OA\Response(response: '404', description: 'Artist or album not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/{publicId}/albums', name: 'add_album', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function addAlbum(string $publicId, #[MapRequestPayload] ArtistAlbumRequest $payload): JsonResponse
    {
        $artist = $this->resolveArtist($publicId);

        if ($artist === null) {
            return $this->notFound();
        }

        if (!ArtistRole::tryFrom($payload->role)) {
            return $this->errorResponse('Invalid role. Valid roles: ' . implode(', ', array_map(fn (ArtistRole $r) => $r->value, ArtistRole::cases())));
        }

        try {
            $albumId = Uuid::fromString($payload->albumId);
        } catch (\Throwable) {
            return $this->errorResponse('Invalid album ID format.');
        }

        $this->artistService->addAlbumToArtist($artist->getId(), $albumId, $payload->role);

        return $this->noContent();
    }

    /**
     * Remove an album from an artist.
     */
    #[OA\Delete(
        path: '/api/artists/{publicId}/albums/{albumId}',
        summary: 'Remove an album from an artist',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Artist public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'albumId', description: 'Album UUID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '204', description: 'Album removed from artist'),
            new OA\Response(response: '404', description: 'Artist not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{publicId}/albums/{albumId}', name: 'remove_album', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function removeAlbum(string $publicId, string $albumId): JsonResponse
    {
        $artist = $this->resolveArtist($publicId);

        if ($artist === null) {
            return $this->notFound();
        }

        try {
            $resolvedAlbumId = Uuid::fromString($albumId);
        } catch (\Throwable) {
            return $this->errorResponse('Invalid album ID format.');
        }

        $this->artistService->removeAlbumFromArtist($artist->getId(), $resolvedAlbumId);

        return $this->noContent();
    }

    /**
     * Update the role of an artist on an album.
     */
    #[OA\Patch(
        path: '/api/artists/{publicId}/albums/{albumId}',
        summary: 'Update artist role on an album',
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
                new OA\Property(property: 'role', type: 'string'),
            ])),
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Artist public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'albumId', description: 'Album UUID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '204', description: 'Role updated'),
            new OA\Response(response: '404', description: 'Artist not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/{publicId}/albums/{albumId}', name: 'update_album_role', methods: ['PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    public function updateAlbumRole(string $publicId, string $albumId, #[MapRequestPayload] UpdateRoleRequest $payload): JsonResponse
    {
        $artist = $this->resolveArtist($publicId);

        if ($artist === null) {
            return $this->notFound();
        }

        if (!ArtistRole::tryFrom($payload->role)) {
            return $this->errorResponse('Invalid role. Valid roles: ' . implode(', ', array_map(fn (ArtistRole $r) => $r->value, ArtistRole::cases())));
        }

        try {
            $resolvedAlbumId = Uuid::fromString($albumId);
        } catch (\Throwable) {
            return $this->errorResponse('Invalid album ID format.');
        }

        $this->artistService->updateAlbumRole($artist->getId(), $resolvedAlbumId, $payload->role);

        return $this->noContent();
    }

    /**
     * Reconcile the aggregate's locked fields with the requested list.
     *
     * @param string[] $requestedFields
     */
    private function syncLockedFields(Artist $artist, array $requestedFields): void
    {
        $current = $artist->getLockedFields();

        foreach ($requestedFields as $field) {
            if (!in_array($field, $current, true)) {
                $artist->lockField($field);
            }
        }

        foreach ($current as $field) {
            if (!in_array($field, $requestedFields, true)) {
                $artist->unlockField($field);
            }
        }
    }

    private function resolveArtist(string $publicId): ?Artist
    {
        try {
            $resolvedPublicId = PublicId::fromString($publicId);
        } catch (\Throwable) {
            return null;
        }

        return $this->artistService->findByPublicId($resolvedPublicId);
    }
}
