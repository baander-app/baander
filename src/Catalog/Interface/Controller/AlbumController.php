<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Controller;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\AlbumDuplicatePortInterface;
use App\Catalog\Application\Port\AlbumMergePortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Interface\Request\MergeAlbumsRequest;
use App\Catalog\Interface\Request\UpdateAlbumRequest;
use App\Catalog\Interface\Resource\AlbumResource;
use App\Catalog\Interface\Resource\SongResource;
use App\Catalog\Interface\Resource\DuplicateGroupResource;
use App\Media\Application\Port\ImagePortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use App\Shared\Interface\DTO\ApiError;
use App\Shared\Interface\DTO\PaginatedResponse;
use App\Shared\Interface\DTO\ValidationError;
use InvalidArgumentException;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

#[OA\Tag(name: 'Catalog', description: 'Album, artist, song, movie, and genre management endpoints')]
#[Route('/api/albums', name: 'album_')]
final class AlbumController
{
    use ApiResponsesTrait;
    use TranslatorTrait;

    public function __construct(
        private readonly AlbumPortInterface $albumService,
        private readonly ImagePortInterface $imagePort,
        private readonly AlbumDuplicatePortInterface $duplicatePort,
        private readonly AlbumMergePortInterface $mergePort,
    )
    {
    }

    /**
     * List albums (paginated).
     */
    #[OA\Get(
        path: '/api/albums/',
        summary: 'List albums (paginated)',
        parameters: [
            new OA\Parameter(name: 'page', description: 'Page number', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', description: 'Items per page (max 100)', in: 'query', schema: new OA\Schema(type: 'integer', default: 50)),
            new OA\Parameter(name: 'q', description: 'Search query', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', description: 'Sort field (title, year, artist, added)', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'order', description: 'Sort order (asc, desc)', in: 'query', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'asc')),
            new OA\Parameter(name: 'artistId', description: 'Filter by artist public ID', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'genre', description: 'Filter by genre slug', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Paginated list of albums', content: new OA\JsonContent(ref: new Model(type: PaginatedResponse::class))),
        ],
    )]
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int)$request->query->get('page', 1));
        $limit = min(100, max(1, (int)$request->query->get('limit', 50)));
        $offset = ($page - 1) * $limit;
        $query = (string)$request->query->get('q', '');

        $filters = [];

        $artistId = $request->query->get('artistId');
        if (is_string($artistId) && $artistId !== '') {
            $filters[] = [
                'field' => 'artistId',
                'operator' => '=',
                'value' => $artistId,
            ];
        }

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

        $searchResult = $this->albumService->search($options);

        $albums = $searchResult->getItems();
        $coverImageIds = array_values(array_filter(
            array_map(fn(Album $album) => $album->getCoverImageId(), $albums),
        ));
        $images = $coverImageIds !== [] ? $this->imagePort->findByUuids($coverImageIds) : [];

        $baseUrl = $request->getSchemeAndHttpHost();

        $results = array_map(
            fn(Album $album) => AlbumResource::fromWithCoverAndArtists(
                $album,
                $images[$album->getCoverImageId()?->toString()] ?? null,
                $this->albumService->getArtistNamesForAlbum($album->getId()),
                $baseUrl,
            ),
            $albums,
        );
        $total = $searchResult->getTotal();

        return $this->paginatedResponse(new PaginatedResponse(
            data: $results,
            currentPage: $page,
            lastPage: (int)ceil($total / $limit) ?: 1,
            perPage: $limit,
            total: $total,
        ));
    }

    /**
     * Get a single album with its songs.
     */
    #[OA\Get(
        path: '/api/albums/{publicId}',
        summary: 'Get a single album with its songs',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Album public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Album with songs', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'publicId', type: 'string'),
                        new OA\Property(property: 'title', type: 'string'),
                        new OA\Property(property: 'type', type: 'string'),
                        new OA\Property(property: 'year', type: 'integer'),
                        new OA\Property(property: 'label', type: 'string'),
                        new OA\Property(property: 'barcode', type: 'string'),
                        new OA\Property(property: 'country', type: 'string'),
                        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'songs', type: 'array', items: new OA\Items(ref: new Model(type: SongResource::class))),
                        new OA\Property(property: 'coverImage', properties: [
                            new OA\Property(property: 'url', type: 'string'),
                            new OA\Property(property: 'blurhash', type: 'string', nullable: true),
                        ], type: 'object', nullable: true),
                    ], type: 'object'),
                ],
                type: 'object',
            )),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
        ],
    )]
    #[Route('/{publicId}', name: 'show', methods: ['GET'])]
    public function show(string $publicId, Request $request): JsonResponse
    {
        try {
            $resolvedPublicId = PublicId::fromString($publicId);
        } catch (Throwable) {
            return $this->errorResponse($this->trans('errors.invalid_public_id'));
        }

        $album = $this->albumService->findByPublicId($resolvedPublicId);

        if ($album === null) {
            return $this->notFound();
        }

        $result = $this->albumService->findWithSongs($album->getId());

        $songs = [];
        if ($result !== null) {
            $songs = SongResource::collection($result[1]);
        }

        $coverImage = null;
        $coverImageId = $album->getCoverImageId();
        if ($coverImageId !== null) {
            $coverImage = $this->imagePort->findByUuid($coverImageId);
        }

        return $this->successResponse(array_merge(
            AlbumResource::fromWithCoverAndArtists(
                $album,
                $coverImage,
                $this->albumService->getArtistNamesForAlbum($album->getId()),
                $request->getSchemeAndHttpHost(),
            ),
            ['songs' => $songs],
        ));
    }

    /**
     * Get album cover image.
     */
    #[OA\Get(
        path: '/api/albums/{publicId}/cover',
        summary: 'Get album cover image',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Album public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '302', description: 'Redirect to cover image file'),
            new OA\Response(response: '404', description: 'Album or cover image not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{publicId}/cover', name: 'cover', methods: ['GET'])]
    public function cover(string $publicId, Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $resolvedPublicId = PublicId::fromString($publicId);
        } catch (Throwable) {
            return $this->errorResponse($this->trans('errors.invalid_public_id'), 404);
        }

        $album = $this->albumService->findByPublicId($resolvedPublicId);

        if ($album === null) {
            return $this->notFound();
        }

        $coverImageId = $album->getCoverImageId();
        if ($coverImageId === null) {
            return $this->notFound();
        }

        $coverImage = $this->imagePort->findByUuid($coverImageId);
        if ($coverImage === null) {
            return $this->notFound();
        }

        $url = '/api/images/' . $coverImage->getPublicId()->toString() . '/file';
        $queryString = $request->getQueryString();
        if ($queryString !== '' && $queryString !== null) {
            $url .= '?' . $queryString;
        }
        return $this->redirect($url);
    }

    /**
     * Get duplicate albums for a specific album.
     */
    #[OA\Get(
        path: '/api/albums/{publicId}/duplicates',
        summary: 'Get duplicate albums for a specific album',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Album public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'List of duplicate groups containing this album', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: new Model(type: DuplicateGroupResource::class))),
                ],
            )),
            new OA\Response(response: '404', description: 'Album not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{publicId}/duplicates', name: 'duplicates', methods: ['GET'])]
    public function duplicates(string $publicId): JsonResponse
    {
        try {
            $resolvedPublicId = PublicId::fromString($publicId);
        } catch (Throwable) {
            return $this->errorResponse($this->trans('errors.invalid_public_id'), 404);
        }

        $album = $this->albumService->findByPublicId($resolvedPublicId);

        if ($album === null) {
            return $this->notFound();
        }

        $groups = $this->duplicatePort->findDuplicatesForAlbum($album->getId());

        return $this->successResponse(array_map(
            fn($group) => DuplicateGroupResource::from($group),
            $groups,
        ));
    }

    /**
     * Update an album.
     */
    #[OA\Patch(
        path: '/api/albums/{publicId}',
        summary: 'Update an album',
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
                new OA\Property(property: 'title', type: 'string', nullable: true),
                new OA\Property(property: 'type', type: 'string', nullable: true),
                new OA\Property(property: 'year', type: 'integer', nullable: true),
                new OA\Property(property: 'label', type: 'string', nullable: true),
                new OA\Property(property: 'catalogNumber', type: 'string', nullable: true),
                new OA\Property(property: 'barcode', type: 'string', nullable: true),
                new OA\Property(property: 'country', type: 'string', nullable: true),
                new OA\Property(property: 'language', type: 'string', nullable: true),
                new OA\Property(property: 'disambiguation', type: 'string', nullable: true),
                new OA\Property(property: 'annotation', type: 'string', nullable: true),
            ])),
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Album public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Album updated', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: new Model(type: AlbumResource::class))],
                type: 'object',
            )),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: ValidationError::class))),
        ],
    )]
    #[Route('/{publicId}', name: 'update', methods: ['PATCH'])]
    public function update(string $publicId, #[MapRequestPayload] UpdateAlbumRequest $payload): JsonResponse
    {
        try {
            $resolvedPublicId = PublicId::fromString($publicId);
        } catch (Throwable) {
            return $this->errorResponse($this->trans('errors.invalid_public_id'));
        }

        $album = $this->albumService->findByPublicId($resolvedPublicId);

        if ($album === null) {
            return $this->notFound();
        }

        try {
            $album->updateMetadata(
                title: $payload->title,
                type: $payload->type,
                year: $payload->year,
                label: $payload->label,
                catalogNumber: $payload->catalogNumber,
                barcode: $payload->barcode,
                country: $payload->country,
                language: $payload->language,
                disambiguation: $payload->disambiguation,
                annotation: $payload->annotation,
            );
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage());
        }

        if ($payload->lockedFields !== null) {
            $this->syncLockedFields($album, $payload->lockedFields);
        }

        $this->albumService->save($album);

        return $this->successResponse(AlbumResource::from($album));
    }

    /**
     * Merge albums.
     */
    #[OA\Post(
        path: '/api/albums/merge',
        summary: 'Merge a source album into a target album',
        requestBody: new OA\RequestBody(content: new OA\JsonContent(ref: new Model(type: MergeAlbumsRequest::class))),
        responses: [
            new OA\Response(response: '200', description: 'Albums merged', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: new Model(type: AlbumResource::class))],
                type: 'object',
            )),
            new OA\Response(response: '400', description: 'Invalid merge request', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '404', description: 'Album not found', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: ValidationError::class))),
        ],
    )]
    #[Route('/merge', name: 'merge', methods: ['POST'])]
    public function merge(#[MapRequestPayload] MergeAlbumsRequest $payload): JsonResponse
    {
        try {
            $targetPublicId = PublicId::fromString($payload->targetPublicId);
            $sourcePublicId = PublicId::fromString($payload->sourcePublicId);
        } catch (Throwable) {
            return $this->errorResponse($this->trans('errors.invalid_public_id'));
        }

        $targetAlbum = $this->albumService->findByPublicId($targetPublicId);
        $sourceAlbum = $this->albumService->findByPublicId($sourcePublicId);

        if ($targetAlbum === null) {
            return $this->errorResponse('Target album not found.', 404);
        }

        if ($sourceAlbum === null) {
            return $this->errorResponse('Source album not found.', 404);
        }

        try {
            $mergedAlbum = $this->mergePort->mergeAlbums($targetAlbum->getId(), $sourceAlbum->getId());
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }

        return $this->successResponse(AlbumResource::from($mergedAlbum));
    }

    /**
     * Delete an album.
     */
    #[OA\Delete(
        path: '/api/albums/{publicId}',
        summary: 'Delete an album',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Album public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '204', description: 'Deleted'),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
        ],
    )]
    #[Route('/{publicId}', name: 'destroy', methods: ['DELETE'])]
    public function destroy(string $publicId): JsonResponse
    {
        try {
            $resolvedPublicId = PublicId::fromString($publicId);
        } catch (Throwable) {
            return $this->errorResponse($this->trans('errors.invalid_public_id'));
        }

        $album = $this->albumService->findByPublicId($resolvedPublicId);

        if ($album === null) {
            return $this->notFound();
        }

        $this->albumService->delete($album);

        return $this->noContent();
    }

    /**
     * Reconcile the aggregate's locked fields with the requested list.
     *
     * @param string[] $requestedFields
     */
    private function syncLockedFields(Album $album, array $requestedFields): void
    {
        $current = $album->getLockedFields();

        foreach ($requestedFields as $field) {
            if (!in_array($field, $current, true)) {
                $album->lockField($field);
            }
        }

        foreach ($current as $field) {
            if (!in_array($field, $requestedFields, true)) {
                $album->unlockField($field);
            }
        }
    }
}
