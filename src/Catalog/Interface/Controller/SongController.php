<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Controller;

use App\Catalog\Application\Port\SongAvailabilityPortInterface;
use App\Catalog\Application\Command\Song\DeleteSongCommand;
use App\Catalog\Application\Command\Song\UpdateSongCommand;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Application\Port\SongSortField;
use App\Library\Application\Port\LibraryReadScopeProviderInterface;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Interface\Request\UpdateSongRequest;
use App\Catalog\Interface\Resource\CatalogDeletionResource;
use App\Catalog\Interface\Resource\SongResource;
use App\Shared\Domain\Exception\CursorMismatchException;
use App\Shared\Domain\Model\Cursor;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Infrastructure\Pagination\CursorCodec;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\DispatchesMessagesTrait;
use App\Shared\Interface\DTO\CursorPaginatedResponse;
use App\Shared\Interface\Exception\InvalidQueryParameter;
use App\Shared\Interface\Request\QueryParameters;
use OpenApi\Spec as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

#[OA\Tag(
    name: 'Catalog',
    description: 'Album, artist, song, movie, and genre management endpoints',
)]
#[Route('/api/songs', name: 'song_')]
#[\Symfony\Component\Security\Http\Attribute\IsGranted('ROLE_ADMIN', methods: ['POST', 'PUT', 'PATCH', 'DELETE'])]
final class SongController
{
    use ApiResponsesTrait;
    use DispatchesMessagesTrait;
    public function __construct(
        private readonly SongPortInterface $songService,
        private readonly LibraryReadScopeProviderInterface $libraryReadScopeProvider,
        private readonly CursorCodec $cursorCodec,
        private readonly MessageBusInterface $bus,
        private readonly SongAvailabilityPortInterface $availability,
        private readonly AuthorizationCheckerInterface $authorization,
    ) {
    }

    /**
     * List/search songs (cursor-paginated).
     */
    #[OA\Operation\Get(
        path: '/api/songs/',
        summary: 'List songs (cursor-paginated)',
        parameters: [
            new OA\Parameter(
                name: 'cursor',
                in: 'query',
                description: 'Cursor for pagination (base64-encoded)',
                schema: new OA\Schema(type: 'string'),
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
                name: 'genres',
                in: 'query',
                description: 'Comma-separated genre slugs to filter by',
                schema: new OA\Schema(type: 'string'),
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
                name: 'albumId',
                in: 'query',
                description: 'Filter by album public ID',
                schema: new OA\Schema(
                    type: 'string',
                    minLength: PublicId::LENGTH,
                    maxLength: PublicId::LENGTH,
                    pattern: PublicId::SCHEMA_PATTERN,
                ),
            ),
            new OA\Parameter(
                name: 'publicIds',
                in: 'query',
                description: 'Comma-separated public IDs to filter by',
                schema: new OA\Schema(type: 'string'),
            ),
            new OA\Parameter(
                name: 'sort',
                in: 'query',
                description: 'Sort field. A cursor is valid only with the sort and order that issued it.',
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['title', 'artist', 'album', 'year', 'added'],
                    default: 'title',
                ),
            ),
            new OA\Parameter(
                name: 'order',
                in: 'query',
                description: 'Sort order',
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
                description: 'Cursor-paginated list of songs',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: CursorPaginatedResponse::class),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'Unsupported sort field or order, or a cursor issued for another sort field or order',
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
        $limit = min(100, max(1, (int) $request->query->get('limit', 50)));
        $query = (string) $request->query->get('q', '');

        $cursor = null;
        $cursorString = $request->query->get('cursor');
        if (is_string($cursorString) && $cursorString !== '') {
            $cursor = $this->cursorCodec->decode($cursorString);
        }

        $options = $this->buildSearchOptions($request, $query, $limit, $cursor);

        try {
            $page = $this->songService->searchVisibleWithCursor($options, $scope);
        } catch (CursorMismatchException) {
            throw new InvalidQueryParameter('cursor', 'cursor belongs to another sort or order; request the first page again without a cursor.');
        }
        $songs = $page->getItems();

        $songIds = array_map(fn (Song $s) => $s->getId(), $songs);
        $albumIds = array_map(fn (Song $s) => $s->getAlbumId(), $songs);

        $artistNames = $this->songService->getVisibleArtistNamesForSongs($songIds, $scope);
        [$albumTitles, $albumLanguages] = SongResource::albumMaps($this->songService->getVisibleAlbumTitlesAndLanguagesByIds($albumIds, $scope));

        $resources = SongResource::collectionWithMeta($songs, $artistNames, $albumTitles, $this->availability->forSongs($songs), $albumLanguages);

        return $this->cursorPaginatedResponse(CursorPaginatedResponse::fromPage($page, $this->readsFilePaths()
            ? $resources
            : array_map(SongResource::forMember(...), $resources)));
    }

    /**
     * Get a single song.
     */
    #[OA\Operation\Get(
        path: '/api/songs/{publicId}',
        summary: 'Get a single song',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                description: 'Song public ID',
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
                description: 'Song details',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(ref: SongResource::class),
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
        } catch (\InvalidArgumentException) {
            // No song has a malformed public ID.
            return $this->notFound();
        }

        $song = $this->songService->findVisibleByPublicId($resolvedPublicId, $scope);

        if ($song === null) {
            return $this->notFound();
        }

        $artistNames = $this->songService->getVisibleArtistNamesForSongs([$song->getId()], $scope);
        [$albumTitles, $albumLanguages] = SongResource::albumMaps($this->songService->getVisibleAlbumTitlesAndLanguagesByIds([$song->getAlbumId()], $scope));
        $resource = SongResource::fromWithMeta($song, $artistNames, $albumTitles, $this->availability->forSongs([$song]), $albumLanguages);

        return $this->successResponse($this->readsFilePaths() ? $resource : SongResource::forMember($resource));
    }

    /**
     * Update a song.
     */
    #[OA\Operation\Patch(
        path: '/api/songs/{publicId}',
        summary: 'Update a song',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                description: 'Song public ID',
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
                        'track' => new OA\Schema(type: 'integer', nullable: true),
                        'disc' => new OA\Schema(type: 'integer', nullable: true),
                        'year' => new OA\Schema(type: 'integer', nullable: true),
                        'comment' => new OA\Schema(type: 'string', nullable: true),
                        'lyrics' => new OA\Schema(type: 'string', nullable: true),
                        'explicit' => new OA\Schema(type: 'boolean', nullable: true),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Song updated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(ref: SongResource::class),
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
    #[CliCounterpart('app:song:update')]
    public function update(string $publicId, #[MapRequestPayload] UpdateSongRequest $payload): JsonResponse
    {
        $song = $this->dispatch(new UpdateSongCommand(
            publicId: $publicId,
            title: $payload->title,
            track: $payload->track,
            disc: $payload->disc,
            year: $payload->year,
            comment: $payload->comment,
            lyrics: $payload->lyrics,
            explicit: $payload->explicit,
            lockedFields: $payload->lockedFields,
        ));
        assert($song instanceof Song);

        $artistNames = $this->songService->getArtistNamesForSongs([$song->getId()]);
        [$albumTitles, $albumLanguages] = SongResource::albumMaps($this->songService->getAlbumTitlesAndLanguagesByIds([$song->getAlbumId()]));

        return $this->successResponse(SongResource::fromWithMeta($song, $artistNames, $albumTitles, $this->availability->forSongs([$song]), $albumLanguages));
    }

    /**
     * Delete a song.
     */
    #[OA\Operation\Delete(
        path: '/api/songs/{publicId}',
        summary: 'Delete a song',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                description: 'Song public ID',
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
    #[CliCounterpart('app:song:delete')]
    public function destroy(string $publicId): JsonResponse
    {
        $result = $this->dispatch(new DeleteSongCommand(publicId: $publicId));

        return $this->successResponse(CatalogDeletionResource::from($result));
    }

    private function buildSearchOptions(Request $request, string $query, int $limit, ?Cursor $cursor): SearchOptions
    {
        $filters = [];

        $genres = $request->query->get('genres');
        if (is_string($genres) && $genres !== '') {
            $filters[] = [
                'field' => 'genres',
                'operator' => 'IN',
                'value' => $genres,
            ];
        }

        $artistId = $request->query->get('artistId');
        if (is_string($artistId) && $artistId !== '') {
            $filters[] = [
                'field' => 'artistId',
                'operator' => '=',
                'value' => $artistId,
            ];
        }

        $albumId = $request->query->get('albumId');
        if (is_string($albumId) && $albumId !== '') {
            $filters[] = [
                'field' => 'albumId',
                'operator' => '=',
                'value' => $albumId,
            ];
        }

        $publicIds = $request->query->get('publicIds');
        if (is_string($publicIds) && $publicIds !== '') {
            $filters[] = [
                'field' => 'publicIds',
                'operator' => 'IN',
                'value' => $publicIds,
            ];
        }

        $options = SearchOptions::create($query, $limit)
            ->withFilters($filters)
            ->withCursor($cursor);

        $sort = QueryParameters::optionalChoice($request->query, 'sort', SongSortField::values());
        $order = QueryParameters::optionalChoice($request->query, 'order', ['asc', 'desc']);
        if ($sort !== null || $order !== null) {
            $options = $options->withSort($sort ?? SongSortField::Title->value, $order ?? 'asc');
        }

        return $options;
    }

    /** Only administrators read the file paths of songs; members read the rest. */
    private function readsFilePaths(): bool
    {
        return $this->authorization->isGranted('ROLE_ADMIN');
    }
}
