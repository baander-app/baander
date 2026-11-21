<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Controller;

use App\Catalog\Application\Command\Artist\AddArtistCreditCommand;
use App\Catalog\Application\Command\Artist\ChangeArtistCreditRoleCommand;
use App\Catalog\Application\Command\Artist\CreateArtistCommand;
use App\Catalog\Application\Command\Artist\CreditTarget;
use App\Catalog\Application\Command\Artist\DeleteArtistCommand;
use App\Catalog\Application\Command\Artist\RemoveArtistCreditCommand;
use App\Catalog\Application\Command\Artist\UpdateArtistCommand;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Library\Application\Port\LibraryReadScopeProviderInterface;
use App\Catalog\Interface\Request\ArtistAlbumRequest;
use App\Catalog\Interface\Request\ArtistSongRequest;
use App\Catalog\Interface\Request\CreateArtistRequest;
use App\Catalog\Interface\Request\UpdateArtistRequest;
use App\Catalog\Interface\Request\UpdateRoleRequest;
use App\Catalog\Interface\Resource\ArtistResource;
use App\Catalog\Interface\Resource\CatalogDeletionResource;
use App\Media\Application\Port\ImagePortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\DispatchesMessagesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use App\Shared\Interface\DTO\PaginatedResponse;
use OpenApi\Spec as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Tag(
    name: 'Catalog',
    description: 'Album, artist, song, movie, and genre management endpoints',
)]
#[Route('/api/artists', name: 'artist_')]
final class ArtistController
{
    use ApiResponsesTrait;
    use DispatchesMessagesTrait;
    use TranslatorTrait;
    public function __construct(
        private readonly ArtistPortInterface $artistService,
        private readonly LibraryReadScopeProviderInterface $libraryReadScopeProvider,
        private readonly ImagePortInterface $imagePort,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * Create a new artist.
     */
    #[OA\Operation\Post(
        path: '/api/artists/',
        summary: 'Create a new artist',
        requestBody: new OA\RequestBody(
            content: new OA\MediaType\Json(
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'name' => new OA\Schema(type: 'string'),
                        'country' => new OA\Schema(type: 'string', nullable: true),
                        'gender' => new OA\Schema(type: 'string', nullable: true),
                        'type' => new OA\Schema(type: 'string', nullable: true),
                        'disambiguation' => new OA\Schema(type: 'string', nullable: true),
                        'sortName' => new OA\Schema(type: 'string', nullable: true),
                        'biography' => new OA\Schema(type: 'string', nullable: true),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '201',
                description: 'Artist created',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(ref: ArtistResource::class),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Validation error',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ValidationError::class),
                ),
            ),
        ],
    )]
    #[Route('/', name: 'store', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[CliCounterpart('app:artist:create')]
    public function store(#[MapRequestPayload] CreateArtistRequest $payload): JsonResponse
    {
        $artist = $this->dispatch(new CreateArtistCommand(
            name: $payload->name,
            country: $payload->country,
            gender: $payload->gender,
            type: $payload->type,
            disambiguation: $payload->disambiguation,
            sortName: $payload->sortName,
            biography: $payload->biography,
        ));

        return $this->successResponse(ArtistResource::from($artist), Response::HTTP_CREATED);
    }

    /**
     * List/search artists (paginated).
     */
    #[OA\Operation\Get(
        path: '/api/artists/',
        summary: 'List artists (paginated)',
        parameters: [
            new OA\Parameter(
                name: 'page',
                in: 'query',
                description: 'Page number',
                schema: new OA\Schema(type: 'integer', default: 1),
            ),
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                description: 'Items per page (max 100)',
                schema: new OA\Schema(type: 'integer', default: 50),
            ),
            new OA\Parameter(
                name: 'q',
                in: 'query',
                description: 'Search query',
                schema: new OA\Schema(type: 'string'),
            ),
            new OA\Parameter(
                name: 'genre',
                in: 'query',
                description: 'Filter by genre slug',
                schema: new OA\Schema(type: 'string'),
            ),
            new OA\Parameter(
                name: 'sort',
                in: 'query',
                description: 'Sort field (name)',
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
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Paginated list of artists',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: PaginatedResponse::class),
                ),
            ),
        ],
    )]
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $scope = $this->libraryReadScopeProvider->current();
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

        $searchResult = $this->artistService->searchVisible($options, $scope);

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
    #[OA\Operation\Get(
        path: '/api/artists/{publicId}',
        summary: 'Get a single artist',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                description: 'Artist public ID',
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
                description: 'Artist details',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(ref: ArtistResource::class),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/{publicId}', name: 'show', methods: ['GET'])]
    public function show(string $publicId): JsonResponse
    {
        $scope = $this->libraryReadScopeProvider->current();
        try {
            $resolvedPublicId = PublicId::fromString($publicId);
        } catch (\Throwable) {
            return $this->errorResponse($this->trans('errors.invalid_public_id'));
        }

        $artist = $this->artistService->findVisibleByPublicId($resolvedPublicId, $scope);

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
    #[OA\Operation\Patch(
        path: '/api/artists/{publicId}',
        summary: 'Update an artist',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                description: 'Artist public ID',
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
                        'name' => new OA\Schema(type: 'string', nullable: true),
                        'country' => new OA\Schema(type: 'string', nullable: true),
                        'gender' => new OA\Schema(type: 'string', nullable: true),
                        'type' => new OA\Schema(type: 'string', nullable: true),
                        'disambiguation' => new OA\Schema(type: 'string', nullable: true),
                        'sortName' => new OA\Schema(type: 'string', nullable: true),
                        'biography' => new OA\Schema(type: 'string', nullable: true),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Artist updated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(ref: ArtistResource::class),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Validation error',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ValidationError::class),
                ),
            ),
        ],
    )]
    #[Route('/{publicId}', name: 'update', methods: ['PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    #[CliCounterpart('app:artist:update')]
    public function update(string $publicId, #[MapRequestPayload] UpdateArtistRequest $payload): JsonResponse
    {
        $artist = $this->dispatch(new UpdateArtistCommand(
            publicId: $publicId,
            name: $payload->name,
            country: $payload->country,
            gender: $payload->gender,
            type: $payload->type,
            disambiguation: $payload->disambiguation,
            sortName: $payload->sortName,
            biography: $payload->biography,
            lockedFields: $payload->lockedFields,
        ));

        return $this->successResponse(ArtistResource::from($artist));
    }

    /**
     * Delete an artist.
     */
    #[OA\Operation\Delete(
        path: '/api/artists/{publicId}',
        summary: 'Delete an artist',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                description: 'Artist public ID',
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
                description: 'Deleted, with the cover image',
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
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid public ID',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ValidationError::class),
                ),
            ),
        ],
    )]
    #[Route('/{publicId}', name: 'destroy', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    #[CliCounterpart('app:artist:delete')]
    public function destroy(string $publicId): JsonResponse
    {
        $result = $this->dispatch(new DeleteArtistCommand(publicId: $publicId));

        return $this->successResponse(CatalogDeletionResource::from($result));
    }

    /**
     * Add a song to an artist with a role.
     */
    #[OA\Operation\Post(
        path: '/api/artists/{publicId}/songs',
        summary: 'Add a song to an artist',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                description: 'Artist public ID',
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
                        'songId' => new OA\Schema(
                            type: 'string',
                            minLength: PublicId::LENGTH,
                            maxLength: PublicId::LENGTH,
                            pattern: PublicId::SCHEMA_PATTERN,
                        ),
                        'role' => new OA\Schema(
                            type: 'string',
                            enum: [\App\Catalog\Domain\ValueObject\ArtistRole::class],
                        ),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '204',
                description: 'Song added to artist, or the artist already had the credit',
            ),
            new OA\Response(
                response: '404',
                description: 'Artist or song not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '409',
                description: 'Another change to the same artist, song or album ran at the same time and nothing was written; `details.reason` is `credit_busy`: make the change again',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Malformed public ID or song ID, or an unknown role',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ValidationError::class),
                ),
            ),
        ],
    )]
    #[Route('/{publicId}/songs', name: 'add_song', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[CliCounterpart('app:artist:song:add')]
    public function addSong(string $publicId, #[MapRequestPayload] ArtistSongRequest $payload): JsonResponse
    {
        $this->dispatch(new AddArtistCreditCommand($publicId, CreditTarget::Song, $payload->songId, $payload->role));

        return $this->noContent();
    }

    /**
     * Remove a song from an artist.
     */
    #[OA\Operation\Delete(
        path: '/api/artists/{publicId}/songs/{songId}',
        summary: 'Remove a song from an artist',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                description: 'Artist public ID',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    minLength: PublicId::LENGTH,
                    maxLength: PublicId::LENGTH,
                    pattern: PublicId::SCHEMA_PATTERN,
                ),
            ),
            new OA\Parameter(
                name: 'songId',
                in: 'path',
                description: 'Song public identifier',
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
            new OA\Response(response: '204', description: "Every one of the artist's credits on the song removed"),
            new OA\Response(
                response: '404',
                description: 'Artist not found, or the artist has no credit on the song',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '409',
                description: 'Another change to the same artist, song or album ran at the same time and nothing was written; `details.reason` is `credit_busy`: make the change again',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Malformed public ID or song ID',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ValidationError::class),
                ),
            ),
        ],
    )]
    #[Route('/{publicId}/songs/{songId}', name: 'remove_song', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    #[CliCounterpart('app:artist:song:remove')]
    public function removeSong(string $publicId, string $songId): JsonResponse
    {
        $this->dispatch(new RemoveArtistCreditCommand($publicId, CreditTarget::Song, $songId));

        return $this->noContent();
    }

    /**
     * Update the role of an artist on a song.
     */
    #[OA\Operation\Patch(
        path: '/api/artists/{publicId}/songs/{songId}',
        summary: 'Update artist role on a song',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                description: 'Artist public ID',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    minLength: PublicId::LENGTH,
                    maxLength: PublicId::LENGTH,
                    pattern: PublicId::SCHEMA_PATTERN,
                ),
            ),
            new OA\Parameter(
                name: 'songId',
                in: 'path',
                description: 'Song public identifier',
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
                        'role' => new OA\Schema(
                            type: 'string',
                            enum: [\App\Catalog\Domain\ValueObject\ArtistRole::class],
                        ),
                        'currentRole' => new OA\Schema(
                            description: 'The role of the credit to change; required when the artist holds several roles on the song',
                            type: 'string',
                            nullable: true,
                            enum: [\App\Catalog\Domain\ValueObject\ArtistRole::class, null],
                        ),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '204',
                description: 'Role updated; a credit changed to a role the artist already holds on the song is removed',
            ),
            new OA\Response(
                response: '404',
                description: 'Artist not found, or the artist has no such credit on the song',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '409',
                description: 'Another change to the same artist, song or album ran at the same time and nothing was written; `details.reason` is `credit_busy`: make the change again',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Malformed public ID or song ID, an unknown role, or no current role while the artist holds several',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ValidationError::class),
                ),
            ),
        ],
    )]
    #[Route('/{publicId}/songs/{songId}', name: 'update_song_role', methods: ['PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    #[CliCounterpart('app:artist:song:role')]
    public function updateSongRole(string $publicId, string $songId, #[MapRequestPayload] UpdateRoleRequest $payload): JsonResponse
    {
        $this->dispatch(new ChangeArtistCreditRoleCommand($publicId, CreditTarget::Song, $songId, $payload->role, $payload->currentRole));

        return $this->noContent();
    }

    /**
     * Add an album to an artist with a role.
     */
    #[OA\Operation\Post(
        path: '/api/artists/{publicId}/albums',
        summary: 'Add an album to an artist',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                description: 'Artist public ID',
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
                        'albumId' => new OA\Schema(
                            type: 'string',
                            minLength: PublicId::LENGTH,
                            maxLength: PublicId::LENGTH,
                            pattern: PublicId::SCHEMA_PATTERN,
                        ),
                        'role' => new OA\Schema(
                            type: 'string',
                            enum: [\App\Catalog\Domain\ValueObject\ArtistRole::class],
                        ),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '204',
                description: 'Album added to artist, or the artist already had the credit',
            ),
            new OA\Response(
                response: '404',
                description: 'Artist or album not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '409',
                description: 'Another change to the same artist, song or album ran at the same time and nothing was written; `details.reason` is `credit_busy`: make the change again',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Malformed public ID or album ID, or an unknown role',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ValidationError::class),
                ),
            ),
        ],
    )]
    #[Route('/{publicId}/albums', name: 'add_album', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[CliCounterpart('app:artist:album:add')]
    public function addAlbum(string $publicId, #[MapRequestPayload] ArtistAlbumRequest $payload): JsonResponse
    {
        $this->dispatch(new AddArtistCreditCommand($publicId, CreditTarget::Album, $payload->albumId, $payload->role));

        return $this->noContent();
    }

    /**
     * Remove an album from an artist.
     */
    #[OA\Operation\Delete(
        path: '/api/artists/{publicId}/albums/{albumId}',
        summary: 'Remove an album from an artist',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                description: 'Artist public ID',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    minLength: PublicId::LENGTH,
                    maxLength: PublicId::LENGTH,
                    pattern: PublicId::SCHEMA_PATTERN,
                ),
            ),
            new OA\Parameter(
                name: 'albumId',
                in: 'path',
                description: 'Album public identifier',
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
                response: '204',
                description: "Every one of the artist's credits on the album removed",
            ),
            new OA\Response(
                response: '404',
                description: 'Artist not found, or the artist has no credit on the album',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '409',
                description: 'Another change to the same artist, song or album ran at the same time and nothing was written; `details.reason` is `credit_busy`: make the change again',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Malformed public ID or album ID',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ValidationError::class),
                ),
            ),
        ],
    )]
    #[Route('/{publicId}/albums/{albumId}', name: 'remove_album', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    #[CliCounterpart('app:artist:album:remove')]
    public function removeAlbum(string $publicId, string $albumId): JsonResponse
    {
        $this->dispatch(new RemoveArtistCreditCommand($publicId, CreditTarget::Album, $albumId));

        return $this->noContent();
    }

    /**
     * Update the role of an artist on an album.
     */
    #[OA\Operation\Patch(
        path: '/api/artists/{publicId}/albums/{albumId}',
        summary: 'Update artist role on an album',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                description: 'Artist public ID',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    minLength: PublicId::LENGTH,
                    maxLength: PublicId::LENGTH,
                    pattern: PublicId::SCHEMA_PATTERN,
                ),
            ),
            new OA\Parameter(
                name: 'albumId',
                in: 'path',
                description: 'Album public identifier',
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
                        'role' => new OA\Schema(
                            type: 'string',
                            enum: [\App\Catalog\Domain\ValueObject\ArtistRole::class],
                        ),
                        'currentRole' => new OA\Schema(
                            description: 'The role of the credit to change; required when the artist holds several roles on the album',
                            type: 'string',
                            nullable: true,
                            enum: [\App\Catalog\Domain\ValueObject\ArtistRole::class, null],
                        ),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '204',
                description: 'Role updated; a credit changed to a role the artist already holds on the album is removed',
            ),
            new OA\Response(
                response: '404',
                description: 'Artist not found, or the artist has no such credit on the album',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '409',
                description: 'Another change to the same artist, song or album ran at the same time and nothing was written; `details.reason` is `credit_busy`: make the change again',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Malformed public ID or album ID, an unknown role, or no current role while the artist holds several',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ValidationError::class),
                ),
            ),
        ],
    )]
    #[Route('/{publicId}/albums/{albumId}', name: 'update_album_role', methods: ['PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    #[CliCounterpart('app:artist:album:role')]
    public function updateAlbumRole(string $publicId, string $albumId, #[MapRequestPayload] UpdateRoleRequest $payload): JsonResponse
    {
        $this->dispatch(new ChangeArtistCreditRoleCommand($publicId, CreditTarget::Album, $albumId, $payload->role, $payload->currentRole));

        return $this->noContent();
    }
}
