<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Controller;

use App\Catalog\Application\Command\Genre\CreateGenreCommand;
use App\Catalog\Application\Command\Genre\DeleteGenreCommand;
use App\Catalog\Application\Command\Genre\UpdateGenreCommand;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Library\Application\Port\LibraryReadScopeProviderInterface;
use App\Catalog\Interface\Request\CreateGenreRequest;
use App\Catalog\Interface\Request\GenreAlbumRequest;
use App\Catalog\Interface\Request\GenreSongRequest;
use App\Catalog\Interface\Request\UpdateGenreRequest;
use App\Catalog\Interface\Resource\GenreResource;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Tag(name: 'Catalog', description: 'Album, artist, song, movie, and genre management endpoints')]
#[Route('/api/genres', name: 'genre_')]
final class GenreController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly GenrePortInterface $genreService,
        private readonly LibraryReadScopeProviderInterface $libraryReadScopeProvider,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * List all genres (flat list, no pagination).
     */
    #[OA\Get(
        path: '/api/genres/',
        summary: 'List all genres (flat list)',
        parameters: [
            new OA\Parameter(name: 'flat', description: 'Return flat list of all genres instead of root-only', in: 'query', required: false, schema: new OA\Schema(type: 'boolean', default: false)),
        ],
        responses: [
            new OA\Response(response: '200', description: 'List of genres', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: new Model(type: GenreResource::class))),
                ],
                type: 'object',
            )),
        ],
    )]
    #[Route('/', name: 'index', methods: ['GET'])]
    #[CliCounterpart('app:genre:list')]
    public function index(Request $request): JsonResponse
    {
        $scope = $this->libraryReadScopeProvider->current();
        if ($request->query->getBoolean('flat')) {
            $genres = $this->genreService->findAllVisible($scope);
        } else {
            $genres = $this->genreService->findVisibleRootGenres($scope);
        }

        return $this->successResponse(GenreResource::collection($genres));
    }

    /**
     * Create a new genre.
     */
    #[OA\Post(
        path: '/api/genres/',
        summary: 'Create a new genre',
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'slug', type: 'string'),
                new OA\Property(property: 'parentId', type: 'string', nullable: true),
                new OA\Property(property: 'mbid', type: 'string', nullable: true),
            ])),
        responses: [
            new OA\Response(response: '201', description: 'Genre created', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: new Model(type: GenreResource::class))],
                type: 'object',
            )),
            new OA\Response(response: '409', description: 'Another genre has the slug', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Invalid name, slug or MusicBrainz ID, or a parent that is not an existing genre', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/', name: 'store', methods: ['POST'])]
    #[CliCounterpart('app:genre:create')]
    #[IsGranted('ROLE_ADMIN')]
    public function store(#[MapRequestPayload] CreateGenreRequest $payload): JsonResponse
    {
        $genre = $this->dispatch(new CreateGenreCommand(
            name: $payload->name,
            slug: $payload->slug,
            parentId: $payload->parentId,
            mbid: $payload->mbid,
        ));

        return $this->successResponse(GenreResource::from($genre), Response::HTTP_CREATED);
    }

    /**
     * Get a single genre with its children.
     */
    #[OA\Get(
        path: '/api/genres/{slug}',
        summary: 'Get a single genre with its children',
        parameters: [
            new OA\Parameter(name: 'slug', description: 'Genre slug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Genre with children', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'slug', type: 'string'),
                        new OA\Property(property: 'parentId', type: 'string', format: 'uuid', nullable: true),
                        new OA\Property(property: 'mbid', type: 'string'),
                        new OA\Property(property: 'children', type: 'array', items: new OA\Items(ref: new Model(type: GenreResource::class))),
                    ], type: 'object'),
                ],
                type: 'object',
            )),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{slug}', name: 'show', methods: ['GET'])]
    public function show(string $slug): JsonResponse
    {
        $scope = $this->libraryReadScopeProvider->current();
        $genre = $this->genreService->findVisibleBySlug($slug, $scope);

        if ($genre === null) {
            return $this->notFound();
        }

        $children = $this->genreService->findVisibleChildren($genre->getId(), $scope);

        return $this->successResponse(array_merge(
            GenreResource::from($genre),
            ['children' => GenreResource::collection($children)],
        ));
    }

    /**
     * Update a genre.
     */
    #[OA\Patch(
        path: '/api/genres/{slug}',
        summary: 'Update a genre',
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
                new OA\Property(property: 'name', type: 'string', nullable: true),
                new OA\Property(property: 'slug', type: 'string', nullable: true),
                new OA\Property(property: 'parentId', type: 'string', nullable: true),
                new OA\Property(property: 'mbid', type: 'string', nullable: true),
            ])),
        parameters: [
            new OA\Parameter(name: 'slug', description: 'Genre slug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Genre updated', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: new Model(type: GenreResource::class))],
                type: 'object',
            )),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '409', description: 'Another genre has the new slug', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Invalid name, slug or MusicBrainz ID, a parent that is not an existing genre, or a parent that would create a circular reference', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/{slug}', name: 'update', methods: ['PATCH'])]
    #[CliCounterpart('app:genre:update')]
    #[IsGranted('ROLE_ADMIN')]
    public function update(string $slug, #[MapRequestPayload] UpdateGenreRequest $payload): JsonResponse
    {
        $genre = $this->dispatch(new UpdateGenreCommand(
            slug: $slug,
            name: $payload->name,
            newSlug: $payload->slug,
            parentId: $payload->parentId,
            mbid: $payload->mbid,
        ));

        return $this->successResponse(GenreResource::from($genre));
    }

    /**
     * Delete a genre.
     */
    #[OA\Delete(
        path: '/api/genres/{slug}',
        summary: 'Delete a genre',
        parameters: [
            new OA\Parameter(name: 'slug', description: 'Genre slug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '204', description: 'Deleted'),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{slug}', name: 'destroy', methods: ['DELETE'])]
    #[CliCounterpart('app:genre:delete')]
    #[IsGranted('ROLE_ADMIN')]
    public function destroy(string $slug): JsonResponse
    {
        $this->dispatch(new DeleteGenreCommand($slug));

        return $this->noContent();
    }

    /**
     * Assign a genre to a song.
     */
    #[OA\Post(
        path: '/api/genres/{slug}/songs',
        summary: 'Assign a genre to a song',
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
                new OA\Property(property: 'songId', description: 'Song UUID', type: 'string'),
            ])),
        parameters: [
            new OA\Parameter(name: 'slug', description: 'Genre slug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '204', description: 'Song assigned to genre'),
            new OA\Response(response: '404', description: 'Genre or song not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{slug}/songs', name: 'add_song', methods: ['POST'])]
    #[CliCounterpart('app:genre:song:add')]
    #[IsGranted('ROLE_ADMIN')]
    public function addSong(string $slug, #[MapRequestPayload] GenreSongRequest $payload): JsonResponse
    {
        $genre = $this->genreService->findBySlug($slug);

        if ($genre === null) {
            return $this->notFound();
        }

        try {
            $songId = Uuid::fromString($payload->songId);
        } catch (\Throwable) {
            return $this->errorResponse('Invalid song ID format.');
        }

        if (!$this->genreService->addSongToGenre($genre->getId(), $songId)) {
            return $this->notFound('Song not found.');
        }

        return $this->noContent();
    }

    /**
     * Remove a song from a genre.
     */
    #[OA\Delete(
        path: '/api/genres/{slug}/songs/{songId}',
        summary: 'Remove a song from a genre',
        parameters: [
            new OA\Parameter(name: 'slug', description: 'Genre slug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'songId', description: 'Song UUID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '204', description: 'Song removed from genre'),
            new OA\Response(response: '404', description: 'Genre or song not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{slug}/songs/{songId}', name: 'remove_song', methods: ['DELETE'])]
    #[CliCounterpart('app:genre:song:remove')]
    #[IsGranted('ROLE_ADMIN')]
    public function removeSong(string $slug, string $songId): JsonResponse
    {
        $genre = $this->genreService->findBySlug($slug);

        if ($genre === null) {
            return $this->notFound();
        }

        try {
            $resolvedSongId = Uuid::fromString($songId);
        } catch (\Throwable) {
            return $this->errorResponse('Invalid song ID format.');
        }

        if (!$this->genreService->removeSongFromGenre($genre->getId(), $resolvedSongId)) {
            return $this->notFound('Song not found.');
        }

        return $this->noContent();
    }

    /**
     * Assign a genre to an album.
     */
    #[OA\Post(
        path: '/api/genres/{slug}/albums',
        summary: 'Assign a genre to an album',
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
                new OA\Property(property: 'albumId', description: 'Album UUID', type: 'string'),
            ])),
        parameters: [
            new OA\Parameter(name: 'slug', description: 'Genre slug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '204', description: 'Album assigned to genre'),
            new OA\Response(response: '404', description: 'Genre or album not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{slug}/albums', name: 'add_album', methods: ['POST'])]
    #[CliCounterpart('app:genre:album:add')]
    #[IsGranted('ROLE_ADMIN')]
    public function addAlbum(string $slug, #[MapRequestPayload] GenreAlbumRequest $payload): JsonResponse
    {
        $genre = $this->genreService->findBySlug($slug);

        if ($genre === null) {
            return $this->notFound();
        }

        try {
            $albumId = Uuid::fromString($payload->albumId);
        } catch (\Throwable) {
            return $this->errorResponse('Invalid album ID format.');
        }

        if (!$this->genreService->addAlbumToGenre($genre->getId(), $albumId)) {
            return $this->notFound('Album not found.');
        }

        return $this->noContent();
    }

    /**
     * Remove an album from a genre.
     */
    #[OA\Delete(
        path: '/api/genres/{slug}/albums/{albumId}',
        summary: 'Remove an album from a genre',
        parameters: [
            new OA\Parameter(name: 'slug', description: 'Genre slug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'albumId', description: 'Album UUID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '204', description: 'Album removed from genre'),
            new OA\Response(response: '404', description: 'Genre or album not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{slug}/albums/{albumId}', name: 'remove_album', methods: ['DELETE'])]
    #[CliCounterpart('app:genre:album:remove')]
    #[IsGranted('ROLE_ADMIN')]
    public function removeAlbum(string $slug, string $albumId): JsonResponse
    {
        $genre = $this->genreService->findBySlug($slug);

        if ($genre === null) {
            return $this->notFound();
        }

        try {
            $resolvedAlbumId = Uuid::fromString($albumId);
        } catch (\Throwable) {
            return $this->errorResponse('Invalid album ID format.');
        }

        if (!$this->genreService->removeAlbumFromGenre($genre->getId(), $resolvedAlbumId)) {
            return $this->notFound('Album not found.');
        }

        return $this->noContent();
    }

    /** A handler's exception reaches ExceptionSubscriber, which unwraps it to its 404 or 422 response. */
    private function dispatch(object $message): mixed
    {
        return $this->bus->dispatch($message)->last(HandledStamp::class)?->getResult();
    }
}
