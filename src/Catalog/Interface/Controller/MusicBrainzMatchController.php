<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Controller;

use App\Catalog\Application\Query\MusicBrainzMatch\ListMatchReviewsQuery;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchReviewPage;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\DispatchesMessagesTrait;
use App\Shared\Interface\DTO\ApiError;
use App\Shared\Interface\Request\QueryParameters;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[OA\Tag(name: 'Admin', description: 'System administration endpoints')]
#[Route('/api/admin/metadata/match', name: 'admin_metadata_match_')]
final class MusicBrainzMatchController
{
    use ApiResponsesTrait;
    use DispatchesMessagesTrait;

    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * List the albums the automatic matcher could not match confidently.
     */
    #[OA\Get(
        path: '/api/admin/metadata/match/reviews',
        description: 'Albums marked as needing a MusicBrainz match review, the most recently checked first. Pass `next_cursor` back as `cursor` for the next page.',
        summary: 'List albums that need a MusicBrainz match review',
        parameters: [
            new OA\Parameter(name: 'limit', description: 'Albums per page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: ListMatchReviewsQuery::DEFAULT_LIMIT, maximum: ListMatchReviewsQuery::MAX_LIMIT, minimum: 1)),
            new OA\Parameter(name: 'cursor', description: 'The `next_cursor` of the previous page', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'One page of albums that need a review',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', required: ['items', 'next_cursor', 'has_next_page', 'per_page'], properties: [
                            new OA\Property(property: 'items', type: 'array', items: new OA\Items(
                                required: ['albumPublicId', 'title', 'reason', 'checkedAt', 'bestScore'],
                                properties: [
                                    new OA\Property(property: 'albumPublicId', description: 'The album\'s public ID', type: 'string'),
                                    new OA\Property(property: 'title', description: 'The album\'s title', type: 'string'),
                                    new OA\Property(property: 'reason', description: 'Why the matcher left the album for an admin', type: 'string', enum: ['no_candidates', 'low_score', 'close_runner_up', 'track_count_differs', 'track_lengths_differ']),
                                    new OA\Property(property: 'checkedAt', description: 'When the matcher checked the album', type: 'string', format: 'date-time'),
                                    new OA\Property(property: 'bestScore', description: 'The best candidate\'s fit score, from 0 to 100; null when MusicBrainz returned no candidate', type: 'integer', maximum: 100, minimum: 0, nullable: true),
                                ],
                            )),
                            new OA\Property(property: 'next_cursor', description: 'Cursor for the next page; null on the last page', type: 'string', nullable: true),
                            new OA\Property(property: 'has_next_page', description: 'Whether another page follows', type: 'boolean'),
                            new OA\Property(property: 'per_page', description: 'The page size', type: 'integer'),
                        ], type: 'object'),
                    ],
                ),
            ),
            new OA\Response(response: '400', description: 'The limit is not an integer from 1 to the maximum', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '403', description: 'Administrator role required', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '422', description: 'The cursor is not one this list issued', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
        ],
    )]
    #[Route('/reviews', name: 'reviews', methods: ['GET'])]
    #[CliCounterpart('app:metadata:match:reviews')]
    public function reviews(Request $request): JsonResponse
    {
        $cursor = $request->query->getString('cursor');
        $page = $this->dispatch(new ListMatchReviewsQuery(
            limit: QueryParameters::integer($request->query, 'limit', ListMatchReviewsQuery::DEFAULT_LIMIT, 1, ListMatchReviewsQuery::MAX_LIMIT),
            cursor: $cursor === '' ? null : $cursor,
        ));
        assert($page instanceof MatchReviewPage);

        return $this->successResponse($page->toArray());
    }
}
