<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Controller;

use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Interface\Request\UpdateSongRequest;
use App\Catalog\Interface\Resource\SongResource;
use App\Shared\Domain\Model\Cursor;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Infrastructure\Pagination\CursorCodec;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use App\Shared\Interface\DTO\CursorPaginatedResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Catalog', description: 'Album, artist, song, movie, and genre management endpoints')]
#[Route('/api/songs', name: 'song_')]
final class SongController
{
    use ApiResponsesTrait;
    use TranslatorTrait;
    public function __construct(
        private readonly SongPortInterface $songService,
        private readonly CursorCodec $cursorCodec,
    ) {
    }

    /**
     * List/search songs (cursor-paginated).
     */
    #[OA\Get(
        path: '/api/songs/',
        summary: 'List songs (cursor-paginated)',
        parameters: [
            new OA\Parameter(name: 'cursor', description: 'Cursor for pagination (base64-encoded)', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', description: 'Items per page (max 100)', in: 'query', schema: new OA\Schema(type: 'integer', default: 50)),
            new OA\Parameter(name: 'q', description: 'Search query', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'genres', description: 'Comma-separated genre slugs to filter by', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'artistId', description: 'Filter by artist public ID', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'albumId', description: 'Filter by album public ID', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'publicIds', description: 'Comma-separated public IDs to filter by', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', description: 'Sort field (title, artist, album, year, added)', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'order', description: 'Sort order (asc, desc)', in: 'query', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'asc')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Cursor-paginated list of songs', content: new OA\JsonContent(ref: new Model(type: CursorPaginatedResponse::class))),
        ],
    )]
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $limit = min(100, max(1, (int) $request->query->get('limit', 50)));
        $query = (string) $request->query->get('q', '');

        $cursor = null;
        $cursorString = $request->query->get('cursor');
        if (is_string($cursorString) && $cursorString !== '') {
            $cursor = $this->cursorCodec->decode($cursorString);
        }

        $options = $this->buildSearchOptions($request, $query, $limit, $cursor);

        $page = $this->songService->searchWithCursor($options);
        $songs = $page->getItems();

        $songIds = array_map(fn (Song $s) => $s->getId(), $songs);
        $albumIds = array_map(fn (Song $s) => $s->getAlbumId(), $songs);

        $artistNames = $this->songService->getArtistNamesForSongs($songIds);
        $albumTitles = $this->songService->getAlbumTitlesByIds($albumIds);

        return $this->cursorPaginatedResponse(CursorPaginatedResponse::fromPage($page, SongResource::collectionWithMeta($songs, $artistNames, $albumTitles)));
    }

    /**
     * Get a single song.
     */
    #[OA\Get(
        path: '/api/songs/{publicId}',
        summary: 'Get a single song',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Song public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Song details', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: new Model(type: SongResource::class))],
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

        $song = $this->songService->findByPublicId($resolvedPublicId);

        if ($song === null) {
            return $this->notFound();
        }

        $artistNames = $this->songService->getArtistNamesForSongs([$song->getId()]);
        $albumTitles = $this->songService->getAlbumTitlesByIds([$song->getAlbumId()]);

        return $this->successResponse(SongResource::fromWithMeta($song, $artistNames, $albumTitles));
    }

    /**
     * Update a song.
     */
    #[OA\Patch(
        path: '/api/songs/{publicId}',
        summary: 'Update a song',
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
                new OA\Property(property: 'title', type: 'string', nullable: true),
                new OA\Property(property: 'track', type: 'integer', nullable: true),
                new OA\Property(property: 'disc', type: 'integer', nullable: true),
                new OA\Property(property: 'year', type: 'integer', nullable: true),
                new OA\Property(property: 'comment', type: 'string', nullable: true),
                new OA\Property(property: 'lyrics', type: 'string', nullable: true),
                new OA\Property(property: 'explicit', type: 'boolean', nullable: true),
            ])),
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Song public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Song updated', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: new Model(type: SongResource::class))],
                type: 'object',
            )),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/{publicId}', name: 'update', methods: ['PATCH'])]
    public function update(string $publicId, #[MapRequestPayload] UpdateSongRequest $payload): JsonResponse
    {
        try {
            $resolvedPublicId = PublicId::fromString($publicId);
        } catch (\Throwable) {
            return $this->errorResponse($this->trans('errors.invalid_public_id'));
        }

        $song = $this->songService->findByPublicId($resolvedPublicId);

        if ($song === null) {
            return $this->notFound();
        }

        try {
            $song->updateMetadata(
                title: $payload->title,
                track: $payload->track,
                disc: $payload->disc,
                year: $payload->year,
                comment: $payload->comment,
                lyrics: $payload->lyrics,
                explicit: $payload->explicit,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage());
        }

        if ($payload->lockedFields !== null) {
            $this->syncLockedFields($song, $payload->lockedFields);
        }

        $this->songService->save($song);

        $artistNames = $this->songService->getArtistNamesForSongs([$song->getId()]);
        $albumTitles = $this->songService->getAlbumTitlesByIds([$song->getAlbumId()]);

        return $this->successResponse(SongResource::fromWithMeta($song, $artistNames, $albumTitles));
    }

    /**
     * Delete a song.
     */
    #[OA\Delete(
        path: '/api/songs/{publicId}',
        summary: 'Delete a song',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Song public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
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

        $song = $this->songService->findByPublicId($resolvedPublicId);

        if ($song === null) {
            return $this->notFound();
        }

        $this->songService->delete($song);

        return $this->noContent();
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

        $sort = $request->query->get('sort');
        if (is_string($sort) && $sort !== '') {
            $order = strtolower((string) $request->query->get('order', 'asc'));
            if (!in_array($order, ['asc', 'desc'], true)) {
                $order = 'asc';
            }
            $options = $options->withSort($sort, $order);
        }

        return $options;
    }

    /**
     * Reconcile the aggregate's locked fields with the requested list.
     *
     * @param string[] $requestedFields
     */
    private function syncLockedFields(Song $song, array $requestedFields): void
    {
        $current = $song->getLockedFields();

        // Lock fields that are in the request but not yet locked
        foreach ($requestedFields as $field) {
            if (!in_array($field, $current, true)) {
                $song->lockField($field);
            }
        }

        // Unlock fields that are currently locked but not in the request
        foreach ($current as $field) {
            if (!in_array($field, $requestedFields, true)) {
                $song->unlockField($field);
            }
        }
    }
}
