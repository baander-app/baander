<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Controller;

use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Catalog\Interface\Request\UpdateMovieRequest;
use App\Catalog\Interface\Resource\MovieResource;
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

#[OA\Tag(name: 'Catalog', description: 'Album, artist, song, movie, and genre management endpoints')]
#[Route('/api/movies', name: 'movie_')]
final class MovieController
{
    use ApiResponsesTrait;
    use TranslatorTrait;
    public function __construct(
        private readonly MoviePortInterface $movieService,
        private readonly VideoRepositoryInterface $videoRepository,
        private readonly GenrePortInterface $genreService,
    ) {
    }

    /**
     * List movies (paginated).
     */
    #[OA\Get(
        path: '/api/movies/',
        summary: 'List movies (paginated)',
        parameters: [
            new OA\Parameter(name: 'page', description: 'Page number', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', description: 'Items per page (max 100)', in: 'query', schema: new OA\Schema(type: 'integer', default: 50)),
            new OA\Parameter(name: 'q', description: 'Search query', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Paginated list of movies', content: new OA\JsonContent(ref: new Model(type: PaginatedResponse::class))),
        ],
    )]
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 50)));
        $offset = ($page - 1) * $limit;
        $query = (string) $request->query->get('q', '');

        $options = SearchOptions::create($query, $limit, $offset);
        $searchResult = $this->movieService->search($options);

        $results = MovieResource::collection($searchResult->getItems());
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
     * Get a single movie.
     */
    #[OA\Get(
        path: '/api/movies/{publicId}',
        summary: 'Get a single movie',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Movie public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Movie details', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: new Model(type: MovieResource::class))],
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

        $movie = $this->movieService->findByPublicId($resolvedPublicId);

        if ($movie === null) {
            return $this->notFound();
        }

        $videos = [];
        foreach ($movie->getVideoIds() as $videoIdStr) {
            $video = $this->videoRepository->findByUuid(Uuid::fromString($videoIdStr));
            if ($video !== null) {
                $videos[] = [
                    'uuid' => $video->getId()->toString(),
                    'publicId' => $video->getPublicId()->toString(),
                    'height' => $video->getHeight(),
                    'width' => $video->getWidth(),
                    'duration' => $video->getDuration(),
                    'videoBitrate' => $video->getVideoBitrate(),
                ];
            }
        }

        $data = MovieResource::fromWithPoster(
            $movie,
            posterImage: $movie->getPosterUrl() !== null ? ['url' => $movie->getPosterUrl()] : null,
            genres: [],
            videos: $videos,
        );

        return $this->successResponse($data);
    }

    /**
     * Update a movie.
     */
    #[OA\Patch(
        path: '/api/movies/{publicId}',
        summary: 'Update a movie',
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
                new OA\Property(property: 'title', type: 'string', nullable: true),
                new OA\Property(property: 'year', type: 'integer', nullable: true),
                new OA\Property(property: 'summary', type: 'string', nullable: true),
            ])),
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Movie public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Movie updated', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: new Model(type: MovieResource::class))],
                type: 'object',
            )),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/{publicId}', name: 'update', methods: ['PATCH'])]
    public function update(string $publicId, #[MapRequestPayload] UpdateMovieRequest $payload): JsonResponse
    {
        try {
            $resolvedPublicId = PublicId::fromString($publicId);
        } catch (\Throwable) {
            return $this->errorResponse($this->trans('errors.invalid_public_id'));
        }

        $movie = $this->movieService->findByPublicId($resolvedPublicId);

        if ($movie === null) {
            return $this->notFound();
        }

        try {
            $movie->updateMetadata(
                title: $payload->title,
                year: $payload->year,
                summary: $payload->summary,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage());
        }

        $this->movieService->save($movie);

        return $this->successResponse(MovieResource::from($movie));
    }

    /**
     * Delete a movie.
     */
    #[OA\Delete(
        path: '/api/movies/{publicId}',
        summary: 'Delete a movie',
        parameters: [
            new OA\Parameter(name: 'publicId', description: 'Movie public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
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

        $movie = $this->movieService->findByPublicId($resolvedPublicId);

        if ($movie === null) {
            return $this->notFound();
        }

        $this->movieService->delete($movie);

        return $this->noContent();
    }
}
