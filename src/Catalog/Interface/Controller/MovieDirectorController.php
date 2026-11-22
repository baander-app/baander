<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Controller;

use App\Catalog\Application\Port\DirectorPortInterface;
use App\Catalog\Interface\Resource\DirectorResource;
use App\Catalog\Interface\Resource\MovieResource;
use App\Library\Application\Port\LibraryReadScopeProviderInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\DTO\PaginatedResponse;
use OpenApi\Spec as OA;
use OpenApi\Spec\Parameter;
use OpenApi\Spec\Schema as ParameterSchema;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(
    name: 'Catalog',
    description: 'Album, artist, song, movie, and genre management endpoints',
)]
// Matched before /api/movies/{publicId}, which takes any value so the movie routes answer a malformed ID themselves.
#[Route('/api/movies/directors', name: 'movie_director_', requirements: ['publicId' => PublicId::PATTERN], priority: 1)]
final class MovieDirectorController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly DirectorPortInterface $directors,
        private readonly LibraryReadScopeProviderInterface $scopeProvider,
    ) {}

    #[OA\Operation\Get(
        path: '/api/movies/directors',
        summary: 'List directors of accessible movies',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Paginated directors',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        allOf: [
                            new OA\Schema(ref: PaginatedResponse::class),
                            new OA\Schema(
                                type: 'object',
                                properties: [
                                    'data' => new OA\Schema(
                                        type: 'array',
                                        items: new OA\Schema(ref: DirectorResource::class),
                                    ),
                                ],
                                required: ['data'],
                            ),
                        ],
                    ),
                ),
            ),
        ],
    )]
    #[Parameter(
        name: 'page',
        in: 'query',
        schema: new ParameterSchema(type: 'integer', default: 1),
    )]
    #[Parameter(
        name: 'limit',
        in: 'query',
        schema: new ParameterSchema(
            type: 'integer',
            maximum: 100,
            default: 50,
        ),
    )]
    #[Parameter(
        name: 'q',
        in: 'query',
        schema: new ParameterSchema(type: 'string'),
    )]
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        [$options, $page, $limit] = self::options($request);
        $result = $this->directors->searchVisible($options, $this->scopeProvider->current());

        return $this->paginatedResponse(new PaginatedResponse(
            data: DirectorResource::collection($result->getItems()),
            currentPage: $page,
            lastPage: max(1, (int) ceil($result->getTotal() / $limit)),
            perPage: $limit,
            total: $result->getTotal(),
        ));
    }

    #[OA\Operation\Get(
        path: '/api/movies/directors/{publicId}',
        summary: 'Get a director of accessible movies',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Director',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(ref: DirectorResource::class),
                        ],
                        required: ['data'],
                    ),
                ),
            ),
            new OA\Response(response: '404', description: 'Director not found'),
        ],
    )]
    #[Parameter(
        name: 'publicId',
        in: 'path',
        required: true,
        schema: new ParameterSchema(type: 'string', pattern: PublicId::SCHEMA_PATTERN),
    )]
    #[Route('/{publicId}', name: 'show', methods: ['GET'])]
    public function show(string $publicId): JsonResponse
    {
        $director = $this->directors->findVisibleByPublicId(PublicId::fromString($publicId), $this->scopeProvider->current());

        return $director === null ? $this->notFound() : $this->successResponse(DirectorResource::from($director));
    }

    #[OA\Operation\Get(
        path: '/api/movies/directors/{publicId}/movies',
        summary: 'List accessible movies by a director',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Paginated movies',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        allOf: [
                            new OA\Schema(ref: PaginatedResponse::class),
                            new OA\Schema(
                                type: 'object',
                                properties: [
                                    'data' => new OA\Schema(
                                        type: 'array',
                                        items: new OA\Schema(ref: MovieResource::class),
                                    ),
                                ],
                                required: ['data'],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(response: '404', description: 'Director not found'),
        ],
    )]
    #[Parameter(
        name: 'publicId',
        in: 'path',
        required: true,
        schema: new ParameterSchema(type: 'string', pattern: PublicId::SCHEMA_PATTERN),
    )]
    #[Parameter(
        name: 'page',
        in: 'query',
        schema: new ParameterSchema(type: 'integer', default: 1),
    )]
    #[Parameter(
        name: 'limit',
        in: 'query',
        schema: new ParameterSchema(
            type: 'integer',
            maximum: 100,
            default: 50,
        ),
    )]
    #[Parameter(
        name: 'q',
        in: 'query',
        schema: new ParameterSchema(type: 'string'),
    )]
    #[Route('/{publicId}/movies', name: 'movies', methods: ['GET'])]
    public function movies(string $publicId, Request $request): JsonResponse
    {
        $scope = $this->scopeProvider->current();
        $id = PublicId::fromString($publicId);
        if ($this->directors->findVisibleByPublicId($id, $scope) === null) {
            return $this->notFound();
        }
        [$options, $page, $limit] = self::options($request);
        $result = $this->directors->searchMoviesVisible($id, $options, $scope);

        return $this->paginatedResponse(new PaginatedResponse(
            data: MovieResource::collection($result->getItems()),
            currentPage: $page,
            lastPage: max(1, (int) ceil($result->getTotal() / $limit)),
            perPage: $limit,
            total: $result->getTotal(),
        ));
    }

    /** @return array{SearchOptions, int, int} */
    private static function options(Request $request): array
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 50)));

        return [SearchOptions::create((string) $request->query->get('q', ''), $limit, ($page - 1) * $limit), $page, $limit];
    }
}
