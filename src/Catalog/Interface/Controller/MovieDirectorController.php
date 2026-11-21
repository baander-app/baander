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
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use OpenApi\Spec\Parameter;
use OpenApi\Spec\Schema as ParameterSchema;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Catalog', description: 'Album, artist, song, movie, and genre management endpoints')]
#[Route('/api/movies/directors', name: 'movie_director_', requirements: ['publicId' => '[0-9a-zA-Z_-]{21}'])]
final class MovieDirectorController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly DirectorPortInterface $directors,
        private readonly LibraryReadScopeProviderInterface $scopeProvider,
    ) {}

    #[OA\Get(
        path: '/api/movies/directors',
        summary: 'List directors of accessible movies',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Paginated directors',
                content: new OA\JsonContent(allOf: [
                    new OA\Schema(ref: new Model(type: PaginatedResponse::class)),
                    new OA\Schema(required: ['data'], properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(ref: new Model(type: DirectorResource::class)),
                        ),
                    ]),
                ]),
            ),
        ],
    )]
    #[Parameter(
        name: 'page',
        in: 'query',
        schema: new ParameterSchema(
            type: 'integer',
            default: 1,
        ),
    )]
    #[Parameter(
        name: 'limit',
        in: 'query',
        schema: new ParameterSchema(
            type: 'integer',
            default: 50,
            maximum: 100,
        ),
    )]
    #[Parameter(
        name: 'q',
        in: 'query',
        schema: new ParameterSchema(
            type: 'string',
        ),
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

    #[OA\Get(
        path: '/api/movies/directors/{publicId}',
        summary: 'Get a director of accessible movies',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Director',
                content: new OA\JsonContent(required: ['data'], properties: [
                    new OA\Property(property: 'data', ref: new Model(type: DirectorResource::class)),
                ]),
            ),
            new OA\Response(response: '404', description: 'Director not found'),
        ],
    )]
    #[Parameter(
        name: 'publicId',
        in: 'path',
        required: true,
        schema: new ParameterSchema(
            type: 'string',
            pattern: '^[0-9a-zA-Z_-]{21}$',
        ),
    )]
    #[Route('/{publicId}', name: 'show', methods: ['GET'])]
    public function show(string $publicId): JsonResponse
    {
        $director = $this->directors->findVisibleByPublicId(PublicId::fromString($publicId), $this->scopeProvider->current());

        return $director === null ? $this->notFound() : $this->successResponse(DirectorResource::from($director));
    }

    #[OA\Get(
        path: '/api/movies/directors/{publicId}/movies',
        summary: 'List accessible movies by a director',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Paginated movies',
                content: new OA\JsonContent(allOf: [
                    new OA\Schema(ref: new Model(type: PaginatedResponse::class)),
                    new OA\Schema(required: ['data'], properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(ref: new Model(type: MovieResource::class)),
                        ),
                    ]),
                ]),
            ),
            new OA\Response(response: '404', description: 'Director not found'),
        ],
    )]
    #[Parameter(
        name: 'publicId',
        in: 'path',
        required: true,
        schema: new ParameterSchema(
            type: 'string',
            pattern: '^[0-9a-zA-Z_-]{21}$',
        ),
    )]
    #[Parameter(
        name: 'page',
        in: 'query',
        schema: new ParameterSchema(
            type: 'integer',
            default: 1,
        ),
    )]
    #[Parameter(
        name: 'limit',
        in: 'query',
        schema: new ParameterSchema(
            type: 'integer',
            default: 50,
            maximum: 100,
        ),
    )]
    #[Parameter(
        name: 'q',
        in: 'query',
        schema: new ParameterSchema(
            type: 'string',
        ),
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
