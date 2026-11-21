<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Controller;

use App\Catalog\Application\Port\PeopleReadPortInterface;
use App\Catalog\Interface\Resource\TvEpisodeResource;
use App\Catalog\Interface\Resource\TvSeriesResource;
use App\Catalog\Interface\Resource\WorkPersonResource;
use App\Library\Application\Port\LibraryReadScopeProviderInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\DTO\PaginatedResponse;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Catalog', description: 'Album, artist, song, movie, and genre management endpoints')]
#[Route('/api/tv', requirements: ['publicId' => PublicId::PATTERN])]
final readonly class TvReadController
{
    use ApiResponsesTrait;

    public function __construct(
        private PeopleReadPortInterface $people,
        private LibraryReadScopeProviderInterface $scopes,
    ) {}

    #[OA\Operation\Get(
        path: '/api/tv/series',
        operationId: 'tvSeriesIndex',
        summary: 'List accessible television series',
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
                                        items: new OA\Schema(ref: TvSeriesResource::class),
                                    ),
                                ],
                                required: ['data'],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(response: '404', description: 'Resource not found'),
        ],
    )]
    #[Route('/series', name: 'tv_series_index', methods: ['GET'])]
    public function series(Request $request): JsonResponse
    {
        return $this->page($request, $this->people->series(self::options($request), $this->scopes->current()), TvSeriesResource::class);
    }

    #[OA\Operation\Get(
        path: '/api/tv/series/{publicId}',
        operationId: 'tvSeriesShow',
        summary: 'Get an accessible television series',
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
                            'data' => new OA\Schema(ref: TvSeriesResource::class),
                        ],
                        required: ['data'],
                    ),
                ),
            ),
            new OA\Response(response: '404', description: 'Resource not found'),
        ],
    )]
    #[Route('/series/{publicId}', name: 'tv_series_show', methods: ['GET'])]
    public function seriesDetail(string $publicId): JsonResponse
    {
        $series = $this->people->seriesDetail(PublicId::fromString($publicId), $this->scopes->current());

        return $series === null ? $this->notFound() : $this->successResponse(TvSeriesResource::from($series));
    }

    #[OA\Operation\Get(
        path: '/api/tv/series/{publicId}/episodes',
        operationId: 'tvSeriesEpisodes',
        summary: 'List registered episodes of an accessible series',
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
                                        items: new OA\Schema(ref: TvEpisodeResource::class),
                                    ),
                                ],
                                required: ['data'],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(response: '404', description: 'Resource not found'),
        ],
    )]
    #[Route('/series/{publicId}/episodes', name: 'tv_series_episodes', methods: ['GET'])]
    public function episodes(string $publicId, Request $request): JsonResponse
    {
        $episodes = $this->people->episodes(PublicId::fromString($publicId), self::options($request), $this->scopes->current());

        return $episodes === null ? $this->notFound() : $this->page($request, $episodes, TvEpisodeResource::class);
    }

    #[OA\Operation\Get(
        path: '/api/tv/episodes/{publicId}',
        operationId: 'tvEpisodeShow',
        summary: 'Get an accessible television episode',
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
                            'data' => new OA\Schema(ref: TvEpisodeResource::class),
                        ],
                        required: ['data'],
                    ),
                ),
            ),
            new OA\Response(response: '404', description: 'Resource not found'),
        ],
    )]
    #[Route('/episodes/{publicId}', name: 'tv_episode_show', methods: ['GET'])]
    public function episode(string $publicId): JsonResponse
    {
        $episode = $this->people->episodeDetail(PublicId::fromString($publicId), $this->scopes->current());

        return $episode === null ? $this->notFound() : $this->successResponse(TvEpisodeResource::from($episode));
    }

    #[OA\Operation\Get(
        path: '/api/tv/series/{publicId}/credits',
        operationId: 'tvSeriesCredits',
        summary: 'List people credited to an accessible series',
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
        ],
    )]
    #[Route('/series/{publicId}/credits', name: 'tv_series_credits', methods: ['GET'])]
    public function seriesCredits(string $publicId, Request $request): JsonResponse
    {
        return $this->workCredits('series', $publicId, $request);
    }

    #[OA\Operation\Get(
        path: '/api/tv/episodes/{publicId}/credits',
        operationId: 'tvEpisodeCredits',
        summary: 'List people credited to an accessible episode',
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
        ],
    )]
    #[Route('/episodes/{publicId}/credits', name: 'tv_episode_credits', methods: ['GET'])]
    public function episodeCredits(string $publicId, Request $request): JsonResponse
    {
        return $this->workCredits('episode', $publicId, $request);
    }

    private function workCredits(string $kind, string $publicId, Request $request): JsonResponse
    {
        $credits = $this->people->workCredits($kind, PublicId::fromString($publicId), self::options($request), $this->scopes->current());

        return $credits === null ? $this->notFound() : $this->page($request, $credits, WorkPersonResource::class);
    }

    /** @param class-string<AbstractResource> $resource */
    private function page(Request $request, SearchResult $result, string $resource): JsonResponse
    {
        $options = self::options($request);

        return $this->paginatedResponse(new PaginatedResponse(
            data: $resource::collection($result->getItems()),
            currentPage: intdiv($options->getOffset(), $options->getLimit()) + 1,
            lastPage: max(1, (int) ceil($result->getTotal() / $options->getLimit())),
            perPage: $options->getLimit(),
            total: $result->getTotal(),
        ));
    }

    private static function options(Request $request): SearchOptions
    {
        $limit = min(100, max(1, $request->query->getInt('limit', 50)));
        $page = min(intdiv(PHP_INT_MAX, $limit), max(1, $request->query->getInt('page', 1)));

        return SearchOptions::create($request->query->getString('q'), $limit, ($page - 1) * $limit);
    }
}
