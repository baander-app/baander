<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Controller;

use App\Catalog\Application\Port\SongAvailabilityPortInterface;
use App\Catalog\Application\Command\Album\DeleteAlbumCommand;
use App\Catalog\Application\Command\Album\UpdateAlbumCommand;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Library\Application\Port\LibraryReadScopeProviderInterface;
use App\Catalog\Application\Port\AlbumDuplicatePortInterface;
use App\Catalog\Application\Port\AlbumMergePortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Interface\Request\MergeAlbumsRequest;
use App\Catalog\Interface\Request\UpdateAlbumRequest;
use App\Catalog\Interface\Resource\CatalogDeletionResource;
use App\Catalog\Interface\Resource\AlbumResource;
use App\Catalog\Interface\Resource\SongResource;
use App\Catalog\Interface\Resource\DuplicateGroupResource;
use App\Media\Application\Port\ImagePortInterface;
use App\Shared\Application\Http\PublicOrigins;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\DispatchesMessagesTrait;
use App\Shared\Interface\DTO\ApiError;
use App\Shared\Interface\DTO\PaginatedResponse;
use App\Shared\Interface\Request\QueryParameters;
use App\Shared\Interface\DTO\ValidationError;
use InvalidArgumentException;
use OpenApi\Spec as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Throwable;

#[OA\Tag(
    name: 'Catalog',
    description: 'Album, artist, song, movie, and genre management endpoints',
)]
#[Route('/api/albums', name: 'album_')]
#[\Symfony\Component\Security\Http\Attribute\IsGranted('ROLE_ADMIN', methods: ['POST', 'PUT', 'PATCH', 'DELETE'])]
final class AlbumController
{
    use ApiResponsesTrait;
    use DispatchesMessagesTrait;

    public function __construct(
        private readonly AlbumPortInterface $albumService,
        private readonly LibraryReadScopeProviderInterface $libraryReadScopeProvider,
        private readonly ImagePortInterface $imagePort,
        private readonly AlbumDuplicatePortInterface $duplicatePort,
        private readonly AlbumMergePortInterface $mergePort,
        private readonly MessageBusInterface $bus,
        private readonly SongAvailabilityPortInterface $availability,
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly PublicOrigins $publicOrigins,
    ) {
    }

    /**
     * List albums (paginated).
     */
    #[OA\Operation\Get(
        path: '/api/albums/',
        summary: 'List albums (paginated)',
        parameters: [
            new OA\Parameter(
                name: 'page',
                in: 'query',
                description: 'Page number',
                schema: new OA\Schema(type: 'integer', minimum: 1, default: 1),
            ),
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                description: 'Items per page',
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 50),
            ),
            new OA\Parameter(
                name: 'q',
                in: 'query',
                description: 'Search query',
                schema: new OA\Schema(type: 'string'),
            ),
            new OA\Parameter(
                name: 'sort',
                in: 'query',
                description: 'Sort field (title, year, artist, added)',
                schema: new OA\Schema(type: 'string'),
            ),
            new OA\Parameter(
                name: 'order',
                in: 'query',
                description: 'Sort order (asc, desc)',
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['asc', 'desc'],
                    default: 'asc',
                ),
            ),
            new OA\Parameter(
                name: 'artistId',
                in: 'query',
                description: 'Filter by artist public ID',
                schema: new OA\Schema(
                    type: 'string',
                    minLength: PublicId::LENGTH,
                    maxLength: PublicId::LENGTH,
                    pattern: PublicId::SCHEMA_PATTERN,
                ),
            ),
            new OA\Parameter(
                name: 'genre',
                in: 'query',
                description: 'Filter by genre slug',
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Paginated list of albums',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: PaginatedResponse::class),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'Invalid page or limit',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $scope = $this->libraryReadScopeProvider->current();
        $pagination = QueryParameters::page($request->query);
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

        $options = SearchOptions::create($query, $pagination->limit, $pagination->offset)
            ->withFilters($filters);

        $sort = $request->query->get('sort');
        if (is_string($sort) && $sort !== '') {
            $order = strtolower((string) $request->query->get('order', 'asc'));
            if (!in_array($order, ['asc', 'desc'], true)) {
                $order = 'asc';
            }
            $options = $options->withSort($sort, $order);
        }

        $searchResult = $this->albumService->searchVisible($options, $scope);

        $albums = $searchResult->getItems();
        $coverImageIds = array_values(array_filter(
            array_map(fn(Album $album) => $album->getCoverImageId(), $albums),
        ));
        $images = $coverImageIds !== [] ? $this->imagePort->findByUuids($coverImageIds) : [];

        $baseUrl = $this->publicOrigins->forRequest($request);

        $results = array_map(
            fn(Album $album) => AlbumResource::fromWithCoverAndArtists(
                $album,
                $images[$album->getCoverImageId()?->toString() ?? ''] ?? null,
                $this->albumService->getVisibleArtistNamesForAlbum($album->getId(), $scope),
                $baseUrl,
            ),
            $albums,
        );
        $total = $searchResult->getTotal();

        return $this->paginatedResponse(new PaginatedResponse(
            data: $results,
            currentPage: $pagination->page,
            lastPage: $pagination->lastPage($total),
            perPage: $pagination->limit,
            total: $total,
        ));
    }

    /**
     * Get a single album with its songs.
     */
    #[OA\Operation\Get(
        path: '/api/albums/{publicId}',
        summary: 'Get a single album with its songs',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                description: 'Album public ID',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    minLength: PublicId::LENGTH,
                    maxLength: PublicId::LENGTH,
                    pattern: PublicId::SCHEMA_PATTERN,
                ),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Album with songs',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'publicId' => new OA\Schema(
                                        type: 'string',
                                        minLength: PublicId::LENGTH,
                                        maxLength: PublicId::LENGTH,
                                        pattern: PublicId::SCHEMA_PATTERN,
                                    ),
                                    'title' => new OA\Schema(type: 'string'),
                                    'type' => new OA\Schema(type: 'string'),
                                    'year' => new OA\Schema(type: 'integer'),
                                    'label' => new OA\Schema(type: 'string'),
                                    'barcode' => new OA\Schema(type: 'string'),
                                    'country' => new OA\Schema(type: 'string'),
                                    'language' => new OA\Schema(
                                        description: 'Release language as stored; usually a language code, but tag readers may store free text',
                                        type: 'string',
                                        nullable: true,
                                    ),
                                    'createdAt' => new OA\Schema(type: 'string', format: 'date-time'),
                                    'songs' => new OA\Schema(
                                        type: 'array',
                                        items: new OA\Schema(ref: SongResource::class),
                                    ),
                                    'coverImage' => new OA\Schema(
                                        type: 'object',
                                        nullable: true,
                                        properties: [
                                            'url' => new OA\Schema(type: 'string'),
                                            'blurhash' => new OA\Schema(type: 'string', nullable: true),
                                        ],
                                    ),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/{publicId}', name: 'show', methods: ['GET'])]
    public function show(string $publicId, Request $request): JsonResponse
    {
        $scope = $this->libraryReadScopeProvider->current();
        $album = $this->findVisibleAlbum($publicId, $scope);

        if ($album === null) {
            return $this->notFound();
        }

        $result = $this->albumService->findVisibleWithSongs($album->getId(), $scope);

        $songs = [];
        if ($result !== null) {
            $songs = SongResource::collectionWithMeta($result[1], [], [], $this->availability->forSongs($result[1]));
            if (!$this->authorization->isGranted('ROLE_ADMIN')) {
                // Only administrators read the file paths of songs.
                $songs = array_map(SongResource::forMember(...), $songs);
            }
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
                $this->albumService->getVisibleArtistNamesForAlbum($album->getId(), $scope),
                $this->publicOrigins->forRequest($request),
            ),
            ['songs' => $songs],
        ));
    }

    /**
     * Get album cover image.
     */
    #[OA\Operation\Get(
        path: '/api/albums/{publicId}/cover',
        summary: 'Get album cover image',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                description: 'Album public ID',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    minLength: PublicId::LENGTH,
                    maxLength: PublicId::LENGTH,
                    pattern: PublicId::SCHEMA_PATTERN,
                ),
            ),
        ],
        responses: [
            new OA\Response(response: '302', description: 'Redirect to cover image file'),
            new OA\Response(
                response: '404',
                description: 'Album or cover image not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/{publicId}/cover', name: 'cover', methods: ['GET'])]
    public function cover(string $publicId, Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\JsonResponse
    {
        $album = $this->findVisibleAlbum($publicId, $this->libraryReadScopeProvider->current());

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
    #[OA\Operation\Get(
        path: '/api/albums/{publicId}/duplicates',
        summary: 'Get duplicate albums for a specific album',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                description: 'Album public ID',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    minLength: PublicId::LENGTH,
                    maxLength: PublicId::LENGTH,
                    pattern: PublicId::SCHEMA_PATTERN,
                ),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'List of duplicate groups containing this album',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(ref: DuplicateGroupResource::class),
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Album not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/{publicId}/duplicates', name: 'duplicates', methods: ['GET'])]
    public function duplicates(string $publicId): JsonResponse
    {
        $scope = $this->libraryReadScopeProvider->current();
        $album = $this->findVisibleAlbum($publicId, $scope);

        if ($album === null) {
            return $this->notFound();
        }

        $groups = $this->duplicatePort->findVisibleDuplicatesForAlbum($album->getId(), $scope);

        return $this->successResponse(array_map(
            fn($group) => DuplicateGroupResource::from($group),
            $groups,
        ));
    }

    /**
     * Update an album.
     */
    #[OA\Operation\Patch(
        path: '/api/albums/{publicId}',
        summary: 'Update an album',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                description: 'Album public ID',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    minLength: PublicId::LENGTH,
                    maxLength: PublicId::LENGTH,
                    pattern: PublicId::SCHEMA_PATTERN,
                ),
            ),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\MediaType\Json(
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'title' => new OA\Schema(type: 'string', nullable: true),
                        'type' => new OA\Schema(type: 'string', nullable: true),
                        'year' => new OA\Schema(type: 'integer', nullable: true),
                        'label' => new OA\Schema(type: 'string', nullable: true),
                        'catalogNumber' => new OA\Schema(type: 'string', nullable: true),
                        'barcode' => new OA\Schema(type: 'string', nullable: true),
                        'country' => new OA\Schema(type: 'string', nullable: true),
                        'language' => new OA\Schema(type: 'string', nullable: true),
                        'disambiguation' => new OA\Schema(type: 'string', nullable: true),
                        'annotation' => new OA\Schema(type: 'string', nullable: true),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Album updated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(ref: AlbumResource::class),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Validation error',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ValidationError::class),
                ),
            ),
        ],
    )]
    #[Route('/{publicId}', name: 'update', methods: ['PATCH'])]
    #[CliCounterpart('app:album:update')]
    public function update(string $publicId, #[MapRequestPayload] UpdateAlbumRequest $payload): JsonResponse
    {
        $album = $this->dispatch(new UpdateAlbumCommand(
            publicId: $publicId,
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
            lockedFields: $payload->lockedFields,
        ));

        return $this->successResponse(AlbumResource::from($album));
    }

    /**
     * Merge albums.
     */
    #[OA\Operation\Post(
        path: '/api/albums/merge',
        summary: 'Merge a source album into a target album',
        requestBody: new OA\RequestBody(
            content: new OA\MediaType\Json(
                schema: new OA\Schema(ref: MergeAlbumsRequest::class),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Albums merged',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(ref: AlbumResource::class),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'Invalid merge request',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Album not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '409',
                description: 'Another change to the same albums or their songs ran at the same time and nothing was merged; `details.reason` is `merge_busy`: merge again',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Validation error',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ValidationError::class),
                ),
            ),
        ],
    )]
    #[Route('/merge', name: 'merge', methods: ['POST'])]
    #[CliCounterpart('app:album:merge')]
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
        } catch (InvalidArgumentException) {
            return $this->errorResponse($this->trans('errors.invalid_merge_request'), 400);
        }

        return $this->successResponse(AlbumResource::from($mergedAlbum));
    }

    /**
     * Delete an album.
     */
    #[OA\Operation\Delete(
        path: '/api/albums/{publicId}',
        summary: 'Delete an album',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                description: 'Album public ID',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    minLength: PublicId::LENGTH,
                    maxLength: PublicId::LENGTH,
                    pattern: PublicId::SCHEMA_PATTERN,
                ),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Deleted',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(ref: CatalogDeletionResource::class),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid public ID',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ValidationError::class),
                ),
            ),
        ],
    )]
    #[Route('/{publicId}', name: 'destroy', methods: ['DELETE'])]
    #[CliCounterpart('app:album:delete')]
    public function destroy(string $publicId): JsonResponse
    {
        $result = $this->dispatch(new DeleteAlbumCommand(publicId: $publicId));

        return $this->successResponse(CatalogDeletionResource::from($result));
    }

    /** No album has a malformed public ID, so the lookup answers it as an unknown one. */
    private function findVisibleAlbum(string $publicId, LibraryReadScope $scope): ?Album
    {
        try {
            $resolvedPublicId = PublicId::fromString($publicId);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $this->albumService->findVisibleByPublicId($resolvedPublicId, $scope);
    }
}
