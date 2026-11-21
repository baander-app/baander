<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Controller;

use App\Catalog\Application\Command\MusicBrainzMatch\ApplyMatchCommand;
use App\Catalog\Application\Command\MusicBrainzMatch\MatchApplyResult;
use App\Catalog\Application\Query\MusicBrainzMatch\CompareMatchQuery;
use App\Catalog\Application\Query\MusicBrainzMatch\ListMatchReviewsQuery;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchCandidates;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchComparison;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchItemType;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchReviewPage;
use App\Catalog\Application\Query\MusicBrainzMatch\SearchMatchCandidatesQuery;
use App\Catalog\Interface\Request\ApplyMatchRequest;
use App\Catalog\Interface\Resource\MatchApplyResultResource;
use App\Catalog\Interface\Resource\MatchCandidatesResource;
use App\Catalog\Interface\Resource\MatchComparisonResource;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\DispatchesMessagesTrait;
use App\Shared\Interface\DTO\ApiError;
use App\Shared\Interface\Request\QueryParameters;
use OpenApi\Spec as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
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
    #[OA\Operation\Get(
        path: '/api/admin/metadata/match/reviews',
        summary: 'List albums that need a MusicBrainz match review',
        description: 'Albums marked as needing a MusicBrainz match review, the most recently checked first. Pass `next_cursor` back as `cursor` for the next page.',
        parameters: [
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                description: 'Albums per page',
                required: false,
                schema: new OA\Schema(
                    type: 'integer',
                    minimum: 1,
                    maximum: ListMatchReviewsQuery::MAX_LIMIT,
                    default: ListMatchReviewsQuery::DEFAULT_LIMIT,
                ),
            ),
            new OA\Parameter(
                name: 'cursor',
                in: 'query',
                description: 'The `next_cursor` of the previous page',
                required: false,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'One page of albums that need a review',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'items' => new OA\Schema(
                                        type: 'array',
                                        items: new OA\Schema(
                                            type: 'object',
                                            properties: [
                                                'albumPublicId' => new OA\Schema(description: 'The album\'s public ID', type: 'string'),
                                                'title' => new OA\Schema(description: 'The album\'s title', type: 'string'),
                                                'reason' => new OA\Schema(
                                                    description: 'Why the matcher left the album for an admin',
                                                    type: 'string',
                                                    enum: ['no_candidates', 'low_score', 'close_runner_up', 'track_count_differs', 'track_lengths_differ'],
                                                ),
                                                'checkedAt' => new OA\Schema(
                                                    description: 'When the matcher checked the album',
                                                    type: 'string',
                                                    format: 'date-time',
                                                ),
                                                'bestScore' => new OA\Schema(
                                                    description: 'The best candidate\'s fit score, from 0 to 100; null when MusicBrainz returned no candidate',
                                                    type: 'integer',
                                                    nullable: true,
                                                    minimum: 0,
                                                    maximum: 100,
                                                ),
                                            ],
                                            required: ['albumPublicId', 'title', 'reason', 'checkedAt', 'bestScore'],
                                        ),
                                    ),
                                    'next_cursor' => new OA\Schema(
                                        description: 'Cursor for the next page; null on the last page',
                                        type: 'string',
                                        nullable: true,
                                    ),
                                    'has_next_page' => new OA\Schema(description: 'Whether another page follows', type: 'boolean'),
                                    'per_page' => new OA\Schema(description: 'The page size', type: 'integer'),
                                ],
                                required: ['items', 'next_cursor', 'has_next_page', 'per_page'],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'The limit is not an integer from 1 to the maximum',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Administrator role required',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'The cursor is not one this list issued',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
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

    /**
     * List the MusicBrainz candidates for an album, song or artist, best first.
     *
     * A search spends turns of the one-request-per-second MusicBrainz budget every process
     * shares: an album search looks up its five best hits one by one.
     */
    #[OA\Operation\Get(
        path: '/api/admin/metadata/match/{type}/{publicId}/candidates',
        summary: 'List MusicBrainz candidates for an album, song or artist',
        description: 'Searches MusicBrainz for the item\'s title (an artist\'s name) and primary artist, or for the edited `title` and `artist`, and lists at most five candidates with a fit score from 0 to 100, best first: releases for an album, recordings for a song, artists for an artist. A blank `title` counts as none; an empty `artist` searches by the title alone, while the score still compares each candidate with the item\'s own artist. An album\'s own title that finds no release and ends in a comma-separated list in square brackets is searched once more without the list; an edited `title` is searched as given, once.',
        parameters: [
            new OA\Parameter(
                name: 'type',
                in: 'path',
                description: 'What to match',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['album', 'song', 'artist'],
                ),
            ),
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                description: 'The album\'s, song\'s or artist\'s public ID',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
            new OA\Parameter(
                name: 'title',
                in: 'query',
                description: 'Search this title, or for an artist this name, instead of the item\'s own',
                required: false,
                schema: new OA\Schema(type: 'string'),
            ),
            new OA\Parameter(
                name: 'artist',
                in: 'query',
                description: 'Search this artist instead of the item\'s primary artist. Present but empty, the search goes by the title alone; absent, it takes the item\'s own artist. Ignored for an artist.',
                required: false,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'What was searched, and the candidates found; an empty list when MusicBrainz has none',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(ref: MatchCandidatesResource::class),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Administrator role required',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'No album, song or artist has the public ID; `details.reason` is `item_not_found`',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '409',
                description: 'MusicBrainz rejected the request; `details.reason` is `musicbrainz_rejected`',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'The type is not album, song or artist, or the public ID is malformed',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '503',
                description: 'MusicBrainz did not answer, or no turn of the shared rate limit came free in time; `details.reason` is `musicbrainz_unavailable`',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/{type}/{publicId}/candidates', name: 'candidates', methods: ['GET'])]
    #[CliCounterpart('app:metadata:match:search')]
    public function candidates(string $type, string $publicId, Request $request): JsonResponse
    {
        $candidates = $this->dispatch(new SearchMatchCandidatesQuery(
            type: MatchItemType::fromInput($type),
            publicId: $publicId,
            title: $request->query->getString('title'),
            artist: $request->query->has('artist') ? $request->query->getString('artist') : null,
        ));
        assert($candidates instanceof MatchCandidates);

        return $this->successResponse(MatchCandidatesResource::from($candidates));
    }

    /**
     * Compare an album, song or artist with the MusicBrainz candidate an admin picked.
     *
     * Changes nothing: the rows show what a match would set and which are ticked by default.
     */
    #[OA\Operation\Get(
        path: '/api/admin/metadata/match/{type}/{publicId}/comparison',
        summary: 'Compare an album, song or artist with a MusicBrainz candidate',
        description: 'Looks the candidate up in MusicBrainz and shows, field by field and, for an album, track by track, the value the item holds and the value a match would set, with the default tick of each row and the artists the ticked credits would merge or remove. Each row has a key an apply names it by.',
        parameters: [
            new OA\Parameter(
                name: 'type',
                in: 'path',
                description: 'What to match',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['album', 'song', 'artist'],
                ),
            ),
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                description: 'The album\'s, song\'s or artist\'s public ID',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
            new OA\Parameter(
                name: 'candidate',
                in: 'query',
                description: 'The candidate\'s MusicBrainz ID: a release for an album, a recording for a song, an artist for an artist',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'What a match with the candidate would set',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(ref: MatchComparisonResource::class),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Administrator role required',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '404',
                description: '`details.reason` is `item_not_found` (no album, song or artist has the public ID) or `candidate_not_found` (MusicBrainz has no such candidate: search again)',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '409',
                description: 'MusicBrainz rejected the request; `details.reason` is `musicbrainz_rejected`',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'The type is not album, song or artist, or the public ID or the candidate\'s MusicBrainz ID is malformed or missing',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '503',
                description: 'MusicBrainz did not answer, or no turn of the shared rate limit came free in time; `details.reason` is `musicbrainz_unavailable`',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/{type}/{publicId}/comparison', name: 'comparison', methods: ['GET'])]
    #[CliCounterpart('app:metadata:match:compare')]
    public function comparison(string $type, string $publicId, Request $request): JsonResponse
    {
        $comparison = $this->dispatch(new CompareMatchQuery(MatchItemType::fromInput($type), $publicId, $request->query->getString('candidate')));
        assert($comparison instanceof MatchComparison);

        return $this->successResponse(MatchComparisonResource::from($comparison));
    }

    /**
     * Apply a MusicBrainz candidate to an album, song or artist: write the rows the admin ticked in
     * the comparison, and merge or remove the artists the admin left ticked.
     *
     * The apply looks the candidate up again and compares it with the item as stored; each ticked
     * row must still show the current value the admin saw, and every merge or removal the match
     * would make must be in the request, ticked or kept. Either everything lands or nothing does.
     */
    #[OA\Operation\Post(
        path: '/api/admin/metadata/match/{type}/{publicId}/apply',
        summary: 'Apply a MusicBrainz match to an album, song or artist',
        description: 'Writes the ticked rows of the comparison of the item with the candidate, named by the keys the comparison gave them, each with the current value it showed. Every merge or removal the comparison lists must appear in `artistActions` (applied) or `keptArtists` (left as it is). A row that no longer shows what the admin saw, a merge or removal the request does not name, or a deadlock with another writer answers 409 and writes nothing.',
        parameters: [
            new OA\Parameter(
                name: 'type',
                in: 'path',
                description: 'What to match',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['album', 'song', 'artist'],
                ),
            ),
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                description: 'The album\'s, song\'s or artist\'s public ID',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(ref: ApplyMatchRequest::class),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'What the match wrote',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(ref: MatchApplyResultResource::class),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Administrator role required',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Nothing was written; `details.reason` is `item_not_found` (no album, song or artist has the public ID, or the item was deleted while the apply ran) or `candidate_not_found` (MusicBrainz has no such candidate: search again)',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '409',
                description: 'Nothing was written; `details.reason` is `match_stale` (the item changed since the comparison: compare again), `match_busy` (another change to the same songs or artists ran at the same time: apply again), `cover_refused` (the Cover Art Archive refused the ticked cover: apply again without the cover row to write the rest) or `musicbrainz_rejected`',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'The type is not album, song or artist, the public ID or the candidate\'s MusicBrainz ID is malformed, or the selection is malformed; `details` names each malformed entry',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '503',
                description: 'MusicBrainz or the Cover Art Archive did not answer, or no turn of the shared rate limit came free in time; `details.reason` is `musicbrainz_unavailable`',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/{type}/{publicId}/apply', name: 'apply', methods: ['POST'])]
    #[CliCounterpart('app:metadata:match:apply')]
    public function apply(
        string $type,
        string $publicId,
        #[MapRequestPayload(validationFailedStatusCode: 422)]
        ApplyMatchRequest $request,
    ): JsonResponse {
        $result = $this->dispatch(ApplyMatchCommand::manual(MatchItemType::fromInput($type), $publicId, $request->candidate, $request->selection()));
        assert($result instanceof MatchApplyResult);

        return $this->successResponse(MatchApplyResultResource::from($result));
    }
}
