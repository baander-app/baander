<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Controller;

use App\Catalog\Application\Port\PeopleReadPortInterface;
use App\Catalog\Interface\Resource\PersonResource;
use App\Catalog\Interface\Resource\PersonWorkResource;
use App\Catalog\Interface\Resource\WorkPersonResource;
use App\Library\Application\Port\LibraryReadScopeProviderInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\DTO\PaginatedResponse;
use App\Shared\Interface\Request\QueryParameters;
use OpenApi\Spec as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Catalog', description: 'Album, artist, song, movie, and genre management endpoints')]
final readonly class PeopleController
{
    use ApiResponsesTrait;

    public function __construct(
        private PeopleReadPortInterface $people,
        private LibraryReadScopeProviderInterface $scopes,
    ) {}

    #[OA\Operation\Get(
        path: '/api/people',
        operationId: 'personIndex',
        summary: 'List people with accessible credits',
        parameters: [
            new OA\Parameter(
                name: 'page',
                in: 'query',
                schema: new OA\Schema(type: 'integer', minimum: 1, default: 1),
            ),
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 50),
            ),
            new OA\Parameter(
                name: 'q',
                in: 'query',
                schema: new OA\Schema(type: 'string'),
            ),
            new OA\Parameter(
                name: 'role',
                in: 'query',
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Accessible catalog data',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        allOf: [
                            new OA\Schema(ref: PaginatedResponse::class),
                            new OA\Schema(
                                type: 'object',
                                properties: [
                                    'data' => new OA\Schema(
                                        type: 'array',
                                        items: new OA\Schema(ref: PersonResource::class),
                                    ),
                                ],
                                required: ['data'],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(response: '404', description: 'Resource not found'),
            new OA\Response(
                response: '400',
                description: 'Invalid page or limit',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/api/people', name: 'person_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        return $this->page($request, $this->people->search(self::options($request), $this->scopes->current(), self::role($request)));
    }

    #[OA\Operation\Get(
        path: '/api/people/{publicId}',
        operationId: 'personShow',
        summary: 'Get a person with accessible credits',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', pattern: PublicId::SCHEMA_PATTERN),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Accessible catalog data',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(ref: PersonResource::class),
                        ],
                        required: ['data'],
                    ),
                ),
            ),
            new OA\Response(response: '404', description: 'Resource not found'),
        ],
    )]
    #[Route('/api/people/{publicId}', name: 'person_show', requirements: ['publicId' => PublicId::PATTERN], methods: ['GET'])]
    public function show(string $publicId): JsonResponse
    {
        $person = $this->people->profile(PublicId::fromString($publicId), $this->scopes->current());

        return $person === null ? $this->notFound() : $this->successResponse(PersonResource::from($person));
    }

    #[OA\Operation\Get(
        path: '/api/people/{publicId}/credits',
        operationId: 'personCredits',
        summary: 'List accessible works credited to a person',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', pattern: PublicId::SCHEMA_PATTERN),
            ),
            new OA\Parameter(
                name: 'page',
                in: 'query',
                schema: new OA\Schema(type: 'integer', minimum: 1, default: 1),
            ),
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 50),
            ),
            new OA\Parameter(
                name: 'q',
                in: 'query',
                schema: new OA\Schema(type: 'string'),
            ),
            new OA\Parameter(
                name: 'kind',
                in: 'query',
                schema: new OA\Schema(type: 'string', enum: ['movie', 'series', 'episode', 'song', 'album']),
            ),
            new OA\Parameter(
                name: 'role',
                in: 'query',
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Accessible catalog data',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        allOf: [
                            new OA\Schema(ref: PaginatedResponse::class),
                            new OA\Schema(
                                type: 'object',
                                properties: [
                                    'data' => new OA\Schema(
                                        type: 'array',
                                        items: new OA\Schema(ref: PersonWorkResource::class),
                                    ),
                                ],
                                required: ['data'],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(response: '404', description: 'Resource not found'),
            new OA\Response(
                response: '400',
                description: 'Invalid page or limit',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/api/people/{publicId}/credits', name: 'person_credits', requirements: ['publicId' => PublicId::PATTERN], methods: ['GET'])]
    public function credits(string $publicId, Request $request): JsonResponse
    {
        $options = self::options($request);
        $id = PublicId::fromString($publicId);
        $scope = $this->scopes->current();
        if ($this->people->profile($id, $scope) === null) {
            return $this->notFound();
        }
        $kind = $request->query->get('kind');
        if ($kind !== null && !in_array($kind, ['movie', 'series', 'episode', 'song', 'album'], true)) {
            throw new InvalidInputException('Unknown media credit kind.');
        }

        return $this->page($request, $this->people->credits($id, $options, $scope, $kind, self::role($request)), PersonWorkResource::class);
    }

    #[OA\Operation\Get(
        path: '/api/movies/{publicId}/credits',
        operationId: 'personMovieCredits',
        summary: 'List people credited to an accessible movie',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', pattern: PublicId::SCHEMA_PATTERN),
            ),
            new OA\Parameter(
                name: 'page',
                in: 'query',
                schema: new OA\Schema(type: 'integer', minimum: 1, default: 1),
            ),
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 50),
            ),
            new OA\Parameter(
                name: 'q',
                in: 'query',
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Accessible catalog data',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        allOf: [
                            new OA\Schema(ref: PaginatedResponse::class),
                            new OA\Schema(
                                type: 'object',
                                properties: [
                                    'data' => new OA\Schema(
                                        type: 'array',
                                        items: new OA\Schema(ref: WorkPersonResource::class),
                                    ),
                                ],
                                required: ['data'],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(response: '404', description: 'Resource not found'),
            new OA\Response(
                response: '400',
                description: 'Invalid page or limit',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/api/movies/{publicId}/credits', name: 'person_movie_credits', requirements: ['publicId' => PublicId::PATTERN], methods: ['GET'])]
    public function movieCredits(string $publicId, Request $request): JsonResponse
    {
        $result = $this->people->workCredits('movie', PublicId::fromString($publicId), self::options($request), $this->scopes->current());

        return $result === null ? $this->notFound() : $this->page($request, $result, WorkPersonResource::class);
    }

    #[OA\Operation\Get(
        path: '/api/artists/{publicId}/person',
        operationId: 'personArtist',
        summary: 'Get the person linked to an accessible artist',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', pattern: PublicId::SCHEMA_PATTERN),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Accessible catalog data',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(ref: PersonResource::class),
                        ],
                        required: ['data'],
                    ),
                ),
            ),
            new OA\Response(response: '404', description: 'Resource not found'),
        ],
    )]
    #[Route('/api/artists/{publicId}/person', name: 'person_artist', requirements: ['publicId' => PublicId::PATTERN], methods: ['GET'])]
    public function artist(string $publicId): JsonResponse
    {
        $person = $this->people->artistProfile(PublicId::fromString($publicId), $this->scopes->current());

        return $person === null ? $this->notFound() : $this->successResponse(PersonResource::from($person));
    }

    /** @param class-string<\App\Shared\Interface\Resource\AbstractResource> $resource */
    private function page(Request $request, SearchResult $result, string $resource = PersonResource::class): JsonResponse
    {
        $pagination = QueryParameters::page($request->query);

        return $this->paginatedResponse(new PaginatedResponse(
            data: $resource::collection($result->getItems()),
            currentPage: $pagination->page,
            lastPage: $pagination->lastPage($result->getTotal()),
            perPage: $pagination->limit,
            total: $result->getTotal(),
        ));
    }

    private static function options(Request $request): SearchOptions
    {
        $pagination = QueryParameters::page($request->query);

        return SearchOptions::create($request->query->getString('q'), $pagination->limit, $pagination->offset);
    }

    private static function role(Request $request): ?string
    {
        $role = $request->query->get('role');
        if ($role !== null && preg_match('/^[a-z][a-z0-9_ -]{0,99}$/D', $role) !== 1) {
            throw new InvalidInputException('Invalid credit role.');
        }

        return $role;
    }
}
