<?php

declare(strict_types=1);

namespace App\Metadata\Interface\Controller;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzSlotWait;
use App\Metadata\Infrastructure\Api\MusicBrainz\MusicBrainzAdapter;
use App\Metadata\Infrastructure\Api\Discogs\DiscogsAdapter;
use App\Metadata\Infrastructure\Api\LastFm\LastFmAdapter;
use App\Metadata\Interface\Request\SearchMetadataRequest;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use OpenApi\Spec as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Searches of the external metadata sources for any signed-in user.
 *
 * A MusicBrainz search first spends the user's own allowance of one call per ten seconds
 * (429 when spent), then waits at most ten seconds for a turn under the rate limit every
 * process shares (503 beyond), so one member cannot queue ahead of the matcher. A Discogs or
 * Last.fm search spends the user's allowance of ten such searches a minute (429 when spent),
 * so one member cannot use up the server's provider quota.
 */
#[OA\Tag(name: 'Metadata', description: 'External metadata enrichment endpoints')]
#[Route('/api/metadata/search', name: 'metadata_search_')]
final class MetadataSearchController
{
    use ApiResponsesTrait;

    private const string PROVIDER_LIMIT_MESSAGE = 'Too many Discogs and Last.fm searches. Please try again later.';

    public function __construct(
        private readonly MusicBrainzAdapter $musicBrainz,
        private readonly DiscogsAdapter $discogs,
        private readonly LastFmAdapter $lastFm,
        private readonly RateLimiterFactoryInterface $musicbrainzUserLimiter,
        private readonly RateLimiterFactoryInterface $metadataProviderUserLimiter,
    ) {
    }

    #[OA\Operation\Get(
        path: '/api/metadata/search/artist',
        summary: 'Search for artists across external sources',
        parameters: [
            new OA\Parameter(
                name: 'q',
                in: 'query',
                description: 'Search query',
                required: true,
                schema: new OA\Schema(type: 'string'),
                example: 'Radiohead',
            ),
            new OA\Parameter(
                name: 'source',
                in: 'query',
                description: 'Data source: musicbrainz, discogs, or lastfm',
                required: false,
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['musicbrainz', 'discogs', 'lastfm'],
                    default: 'musicbrainz',
                ),
            ),
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                description: 'Maximum number of results',
                required: false,
                schema: new OA\Schema(
                    type: 'integer',
                    minimum: 1,
                    maximum: 100,
                    default: 25,
                ),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Search results from the selected source',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(description: 'Source-specific artist search results', type: 'object'),
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'Empty query parameter',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'The result count is outside 1 to 100',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '429',
                description: 'The user already searched MusicBrainz in the last ten seconds,'
                    . ' or Discogs and Last.fm ten times in the last minute',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '503',
                description: 'No turn under the shared MusicBrainz rate limit within ten seconds',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/artist', name: 'artist', methods: ['GET'])]
    public function searchArtist(
        #[CurrentUser] AuthenticatedUserIdentityInterface $user,
        #[MapQueryString(validationFailedStatusCode: 422)]
        SearchMetadataRequest $request = new SearchMetadataRequest(),
    ): JsonResponse {
        if (trim($request->q) === '') {
            return $this->errorResponse($this->trans('errors.query_required', domain: 'metadata'));
        }

        $results = match ($request->source) {
            'discogs' => $this->discogsFor($user)->searchArtist($request->q, $request->limit),
            'lastfm' => $this->lastFmFor($user)->searchArtist($request->q, $request->limit),
            default => $this->musicBrainzFor($user)->searchArtist(
                $request->q,
                $request->limit,
                wait: MusicBrainzSlotWait::Bounded,
            ),
        };

        return $this->successResponse(is_array($results) ? $results : get_object_vars($results));
    }

    #[OA\Operation\Get(
        path: '/api/metadata/search/album',
        summary: 'Search for albums/release groups across external sources',
        parameters: [
            new OA\Parameter(
                name: 'q',
                in: 'query',
                description: 'Search query',
                required: true,
                schema: new OA\Schema(type: 'string'),
                example: 'OK Computer',
            ),
            new OA\Parameter(
                name: 'source',
                in: 'query',
                description: 'Data source: musicbrainz or discogs',
                required: false,
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['musicbrainz', 'discogs'],
                    default: 'musicbrainz',
                ),
            ),
            new OA\Parameter(
                name: 'artist',
                in: 'query',
                description: 'Filter by artist name',
                required: false,
                schema: new OA\Schema(type: 'string'),
            ),
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                description: 'Maximum number of results',
                required: false,
                schema: new OA\Schema(
                    type: 'integer',
                    minimum: 1,
                    maximum: 100,
                    default: 25,
                ),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Search results from the selected source',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(description: 'Source-specific release group search results', type: 'object'),
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'Empty query parameter',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'The result count is outside 1 to 100',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '429',
                description: 'The user already searched MusicBrainz in the last ten seconds,'
                    . ' or Discogs and Last.fm ten times in the last minute',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '503',
                description: 'No turn under the shared MusicBrainz rate limit within ten seconds',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/album', name: 'album', methods: ['GET'])]
    public function searchAlbum(
        #[CurrentUser] AuthenticatedUserIdentityInterface $user,
        #[MapQueryString(validationFailedStatusCode: 422)]
        SearchMetadataRequest $request = new SearchMetadataRequest(),
    ): JsonResponse {
        if (trim($request->q) === '') {
            return $this->errorResponse($this->trans('errors.query_required', domain: 'metadata'));
        }

        $results = match ($request->source) {
            'discogs' => $this->discogsFor($user)->searchRelease($request->q, $request->artist, $request->limit),
            default => $this->musicBrainzFor($user)->searchReleaseGroup(
                $request->q,
                $request->artist,
                $request->limit,
                MusicBrainzSlotWait::Bounded,
            ),
        };

        return $this->successResponse(get_object_vars($results));
    }

    #[OA\Operation\Get(
        path: '/api/metadata/search/song',
        summary: 'Search for songs/recordings across external sources',
        parameters: [
            new OA\Parameter(
                name: 'q',
                in: 'query',
                description: 'Search query',
                required: true,
                schema: new OA\Schema(type: 'string'),
                example: 'Paranoid Android',
            ),
            new OA\Parameter(
                name: 'source',
                in: 'query',
                description: 'Data source: musicbrainz or discogs',
                required: false,
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['musicbrainz', 'discogs'],
                    default: 'musicbrainz',
                ),
            ),
            new OA\Parameter(
                name: 'artist',
                in: 'query',
                description: 'Filter by artist name',
                required: false,
                schema: new OA\Schema(type: 'string'),
            ),
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                description: 'Maximum number of results',
                required: false,
                schema: new OA\Schema(
                    type: 'integer',
                    minimum: 1,
                    maximum: 100,
                    default: 25,
                ),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Search results from the selected source',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(description: 'Source-specific recording search results', type: 'object'),
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'Empty query parameter',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'The result count is outside 1 to 100',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '429',
                description: 'The user already searched MusicBrainz in the last ten seconds,'
                    . ' or Discogs and Last.fm ten times in the last minute',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '503',
                description: 'No turn under the shared MusicBrainz rate limit within ten seconds',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/song', name: 'song', methods: ['GET'])]
    public function searchSong(
        #[CurrentUser] AuthenticatedUserIdentityInterface $user,
        #[MapQueryString(validationFailedStatusCode: 422)]
        SearchMetadataRequest $request = new SearchMetadataRequest(),
    ): JsonResponse {
        if (trim($request->q) === '') {
            return $this->errorResponse($this->trans('errors.query_required', domain: 'metadata'));
        }

        $results = match ($request->source) {
            'discogs' => $this->discogsFor($user)->searchRelease($request->q, $request->artist, $request->limit),
            default => $this->musicBrainzFor($user)->searchRecording(
                $request->q,
                $request->artist,
                $request->limit,
                MusicBrainzSlotWait::Bounded,
            ),
        };

        return $this->successResponse(get_object_vars($results));
    }

    /** The MusicBrainz adapter, once the user has spent a call of their own allowance; 429 when it is spent. */
    private function musicBrainzFor(AuthenticatedUserIdentityInterface $user): MusicBrainzAdapter
    {
        self::spend($this->musicbrainzUserLimiter, $user, 'Too many MusicBrainz searches. Please try again later.');

        return $this->musicBrainz;
    }

    private function discogsFor(AuthenticatedUserIdentityInterface $user): DiscogsAdapter
    {
        self::spend($this->metadataProviderUserLimiter, $user, self::PROVIDER_LIMIT_MESSAGE);

        return $this->discogs;
    }

    private function lastFmFor(AuthenticatedUserIdentityInterface $user): LastFmAdapter
    {
        self::spend($this->metadataProviderUserLimiter, $user, self::PROVIDER_LIMIT_MESSAGE);

        return $this->lastFm;
    }

    /** The allowance is counted per user ID, so a changed email address starts no new one. */
    private static function spend(
        RateLimiterFactoryInterface $limiter,
        AuthenticatedUserIdentityInterface $user,
        string $message,
    ): void {
        $allowance = $limiter->create($user->getId())->consume();
        if (!$allowance->isAccepted()) {
            throw new TooManyRequestsHttpException(
                max(1, $allowance->getRetryAfter()->getTimestamp() - time()),
                $message,
            );
        }
    }
}
