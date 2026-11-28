<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Controller;

use App\Catalog\Application\Command\Movie\DeleteMovieCommand;
use App\Catalog\Application\Command\Movie\UpdateMovieCommand;
use App\Catalog\Application\Port\MemberVideoCatalogReadInterface;
use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use Symfony\Bundle\SecurityBundle\Security;
use App\Catalog\Interface\Request\UpdateMovieRequest;
use App\Catalog\Interface\Resource\CatalogDeletionResource;
use App\Catalog\Interface\Resource\MovieResource;
use App\Library\Application\Port\LibraryReadScopeProviderInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\DispatchesMessagesTrait;
use App\Shared\Interface\DTO\ApiError;
use App\Shared\Interface\DTO\PaginatedResponse;
use App\Shared\Interface\DTO\ValidationError;
use App\Shared\Interface\Request\QueryParameters;
use OpenApi\Spec as OA;
use OpenApi\Spec\Parameter;
use OpenApi\Spec\Schema as ParameterSchema;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Tag(
    name: 'Catalog',
    description: 'Album, artist, song, movie, and genre management endpoints',
)]
#[Route('/api/movies', name: 'movie_')]
#[IsGranted('ROLE_ADMIN', methods: ['POST', 'PUT', 'PATCH', 'DELETE'])]
final class MovieController
{
    use ApiResponsesTrait;
    use DispatchesMessagesTrait;
    public function __construct(
        private readonly MemberVideoCatalogReadInterface $videos,
        private readonly Security $security,
        private readonly LibraryReadScopeProviderInterface $libraryReadScopeProvider,
        private readonly MessageBusInterface $bus,
    ) {}

    /**
     * List movies (paginated).
     */
    #[OA\Operation\Get(
        path: '/api/movies/',
        summary: 'List movies (paginated)',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Paginated list of movies',
                content: new OA\MediaType\Json(
                    schema: new ParameterSchema(allOf: [new ParameterSchema(ref: PaginatedResponse::class), new ParameterSchema(type: 'object', properties: ['data' => new ParameterSchema(type: 'array', items: new ParameterSchema(ref: MovieResource::class))], required: ['data'])]),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'Invalid page or limit',
                content: new OA\MediaType\Json(
                    schema: new ParameterSchema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Parameter(
        name: 'page',
        in: 'query',
        description: 'Page number',
        schema: new ParameterSchema(type: 'integer', minimum: 1, default: 1),
    )]
    #[Parameter(
        name: 'limit',
        in: 'query',
        description: 'Items per page',
        schema: new ParameterSchema(type: 'integer', minimum: 1, maximum: 100, default: 50),
    )]
    #[Parameter(
        name: 'q',
        in: 'query',
        description: 'Search query',
        schema: new ParameterSchema(type: 'string'),
    )]
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $scope = $this->libraryReadScopeProvider->current();
        $pagination = QueryParameters::page($request->query);
        $query = (string) $request->query->get('q', '');

        $options = SearchOptions::create($query, $pagination->limit, $pagination->offset);
        $searchResult = $this->videos->movies($options, $scope, $this->memberId());

        $results = MovieResource::collection($searchResult->getItems());
        $total = $searchResult->getTotal();

        $response = $this->paginatedResponse(new PaginatedResponse(
            data: $results,
            currentPage: $pagination->page,
            lastPage: $pagination->lastPage($total),
            perPage: $pagination->limit,
            total: $total,
        ));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * Get a single movie.
     */
    #[OA\Operation\Get(
        path: '/api/movies/{publicId}',
        summary: 'Get a single movie',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Movie details',
                content: new OA\MediaType\Json(
                    schema: new ParameterSchema(
                        type: 'object',
                        properties: [
                            'data' => new ParameterSchema(ref: MovieResource::class),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Not found',
                content: new OA\MediaType\Json(
                    schema: new ParameterSchema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Parameter(
        name: 'publicId',
        in: 'path',
        description: 'Movie public ID',
        required: true,
        schema: new ParameterSchema(
            type: 'string',
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
        ),
    )]
    #[Route('/{publicId}', name: 'show', methods: ['GET'])]
    public function show(string $publicId): JsonResponse
    {
        $scope = $this->libraryReadScopeProvider->current();
        try {
            $resolvedPublicId = PublicId::fromString($publicId);
        } catch (\InvalidArgumentException) {
            // No movie has a malformed public ID.
            return $this->notFound();
        }

        $movie = $this->videos->movieDetail($resolvedPublicId, $scope, $this->memberId());

        if ($movie === null) {
            return $this->notFound();
        }

        $response = $this->successResponse(MovieResource::from($movie));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * Update a movie.
     */
    #[OA\Operation\Patch(
        path: '/api/movies/{publicId}',
        summary: 'Update a movie',
        requestBody: new OA\RequestBody(
            content: new OA\MediaType\Json(
                schema: new ParameterSchema(
                    type: 'object',
                    properties: [
                        'title' => new ParameterSchema(type: 'string', nullable: true),
                        'year' => new ParameterSchema(type: 'integer', nullable: true),
                        'summary' => new ParameterSchema(type: 'string', nullable: true),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Movie updated',
                content: new OA\MediaType\Json(
                    schema: new ParameterSchema(
                        type: 'object',
                        properties: [
                            'data' => new ParameterSchema(ref: MovieResource::class),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Not found',
                content: new OA\MediaType\Json(
                    schema: new ParameterSchema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Validation error',
                content: new OA\MediaType\Json(
                    schema: new ParameterSchema(ref: ValidationError::class),
                ),
            ),
        ],
    )]
    #[Parameter(
        name: 'publicId',
        in: 'path',
        description: 'Movie public ID',
        required: true,
        schema: new ParameterSchema(
            type: 'string',
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
        ),
    )]
    #[Route('/{publicId}', name: 'update', methods: ['PATCH'])]
    #[CliCounterpart('app:movie:update')]
    public function update(string $publicId, #[MapRequestPayload] UpdateMovieRequest $payload): JsonResponse
    {
        $movie = $this->dispatch(new UpdateMovieCommand(
            publicId: $publicId,
            title: $payload->title,
            year: $payload->year,
            summary: $payload->summary,
        ));

        return $this->successResponse(MovieResource::from($movie));
    }

    /**
     * Delete a movie.
     */
    #[OA\Operation\Delete(
        path: '/api/movies/{publicId}',
        summary: 'Delete a movie',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Deleted',
                content: new OA\MediaType\Json(
                    schema: new ParameterSchema(
                        type: 'object',
                        properties: [
                            'data' => new ParameterSchema(ref: CatalogDeletionResource::class),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Not found',
                content: new OA\MediaType\Json(
                    schema: new ParameterSchema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid public ID',
                content: new OA\MediaType\Json(
                    schema: new ParameterSchema(ref: ValidationError::class),
                ),
            ),
        ],
    )]
    #[Parameter(
        name: 'publicId',
        in: 'path',
        description: 'Movie public ID',
        required: true,
        schema: new ParameterSchema(
            type: 'string',
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
        ),
    )]
    #[Route('/{publicId}', name: 'destroy', methods: ['DELETE'])]
    #[CliCounterpart('app:movie:delete')]
    public function destroy(string $publicId): JsonResponse
    {
        $result = $this->dispatch(new DeleteMovieCommand(publicId: $publicId));

        return $this->successResponse(CatalogDeletionResource::from($result));
    }
    private function memberId(): ?Uuid
    {
        $member = $this->security->getUser();

        return $member instanceof AuthenticatedUserIdentityInterface ? Uuid::fromString($member->getId()) : null;
    }

}
