<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Controller;

use App\Catalog\Application\Query\MusicBrainzMatch\CompareMatchQuery;
use App\Catalog\Application\Query\MusicBrainzMatch\ListMatchReviewsQuery;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchCandidates;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchComparison;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchItemType;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchReviewPage;
use App\Catalog\Application\Query\MusicBrainzMatch\SearchMatchCandidatesQuery;
use App\Catalog\Interface\Resource\MatchCandidatesResource;
use App\Catalog\Interface\Resource\MatchComparisonResource;
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
use Symfony\Component\Routing\Requirement\EnumRequirement;
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

    /**
     * List the MusicBrainz candidates for an album, song or artist, best first.
     *
     * A search spends turns of the one-request-per-second MusicBrainz budget every process
     * shares: an album search looks up its five best hits one by one.
     */
    #[OA\Get(
        path: '/api/admin/metadata/match/{type}/{publicId}/candidates',
        description: 'Searches MusicBrainz for the item\'s title (an artist\'s name) and primary artist, or for the edited `title` and `artist`, and lists at most five candidates with a fit score from 0 to 100, best first: releases for an album, recordings for a song, artists for an artist. A blank `title` counts as none; an empty `artist` searches by the title alone, while the score still compares each candidate with the item\'s own artist.',
        summary: 'List MusicBrainz candidates for an album, song or artist',
        parameters: [
            new OA\Parameter(name: 'type', description: 'What to match', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['album', 'song', 'artist'])),
            new OA\Parameter(name: 'publicId', description: 'The album\'s, song\'s or artist\'s public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'title', description: 'Search this title, or for an artist this name, instead of the item\'s own', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'artist', description: 'Search this artist instead of the item\'s primary artist. Present but empty, the search goes by the title alone; absent, it takes the item\'s own artist. Ignored for an artist.', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'What was searched, and the candidates found; an empty list when MusicBrainz has none',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: new Model(type: MatchCandidatesResource::class))]),
            ),
            new OA\Response(response: '403', description: 'Administrator role required', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '404', description: 'No album, song or artist has the public ID', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '409', description: 'MusicBrainz rejected the request; `details.reason` is `musicbrainz_rejected`', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '422', description: 'The public ID is malformed', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '503', description: 'MusicBrainz did not answer, or no turn of the shared rate limit came free in time; `details.reason` is `musicbrainz_unavailable`', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
        ],
    )]
    #[Route('/{type}/{publicId}/candidates', name: 'candidates', requirements: ['type' => new EnumRequirement(MatchItemType::class)], methods: ['GET'])]
    #[CliCounterpart('app:metadata:match:search')]
    public function candidates(MatchItemType $type, string $publicId, Request $request): JsonResponse
    {
        $candidates = $this->dispatch(new SearchMatchCandidatesQuery(
            type: $type,
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
    #[OA\Get(
        path: '/api/admin/metadata/match/{type}/{publicId}/comparison',
        description: 'Looks the candidate up in MusicBrainz and shows, field by field and, for an album, track by track, the value the item holds and the value a match would set, with the default tick of each row and the artists the ticked credits would merge or remove. Each row has a key an apply names it by.',
        summary: 'Compare an album, song or artist with a MusicBrainz candidate',
        parameters: [
            new OA\Parameter(name: 'type', description: 'What to match', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['album', 'song', 'artist'])),
            new OA\Parameter(name: 'publicId', description: 'The album\'s, song\'s or artist\'s public ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'candidate', description: 'The candidate\'s MusicBrainz ID: a release for an album, a recording for a song, an artist for an artist', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'What a match with the candidate would set',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: new Model(type: MatchComparisonResource::class))]),
            ),
            new OA\Response(response: '403', description: 'Administrator role required', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '404', description: 'No album, song or artist has the public ID, or MusicBrainz has no such candidate', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '409', description: 'MusicBrainz rejected the request; `details.reason` is `musicbrainz_rejected`', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '422', description: 'The public ID or the candidate\'s MusicBrainz ID is malformed or missing', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '503', description: 'MusicBrainz did not answer, or no turn of the shared rate limit came free in time; `details.reason` is `musicbrainz_unavailable`', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
        ],
    )]
    #[Route('/{type}/{publicId}/comparison', name: 'comparison', requirements: ['type' => new EnumRequirement(MatchItemType::class)], methods: ['GET'])]
    #[CliCounterpart('app:metadata:match:compare')]
    public function comparison(MatchItemType $type, string $publicId, Request $request): JsonResponse
    {
        $comparison = $this->dispatch(new CompareMatchQuery($type, $publicId, $request->query->getString('candidate')));
        assert($comparison instanceof MatchComparison);

        return $this->successResponse(MatchComparisonResource::from($comparison));
    }
}
