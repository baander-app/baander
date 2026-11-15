<?php

declare(strict_types=1);

namespace App\Metadata\Interface\Controller;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzSlotWait;
use App\Metadata\Infrastructure\Api\MusicBrainz\MusicBrainzAdapter;
use App\Metadata\Infrastructure\Api\Discogs\DiscogsAdapter;
use App\Metadata\Infrastructure\Api\LastFm\LastFmAdapter;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use OpenApi\Attributes as OA;
use Nelmio\ApiDocBundle\Attribute\Model;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Searches of the external metadata sources for any signed-in user.
 *
 * A MusicBrainz search first spends the user's own allowance of one call per ten seconds
 * (429 when spent), then waits at most ten seconds for a turn under the rate limit every
 * process shares (503 beyond), so one member cannot queue ahead of the matcher.
 */
#[OA\Tag(name: 'Metadata', description: 'External metadata enrichment endpoints')]
#[Route('/api/metadata/search', name: 'metadata_search_')]
final class MetadataSearchController
{
    use ApiResponsesTrait;
    use TranslatorTrait;
    public function __construct(
        private readonly MusicBrainzAdapter $musicBrainz,
        private readonly DiscogsAdapter $discogs,
        private readonly LastFmAdapter $lastFm,
        private readonly RateLimiterFactoryInterface $musicbrainzUserLimiter,
    ) {
    }

    #[OA\Get(
        path: '/api/metadata/search/artist',
        summary: 'Search for artists across external sources',
        parameters: [
            new OA\Parameter(name: 'q', description: 'Search query', in: 'query', required: true, schema: new OA\Schema(type: 'string'), example: 'Radiohead'),
            new OA\Parameter(name: 'source', description: 'Data source: musicbrainz, discogs, or lastfm', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'musicbrainz', enum: ['musicbrainz', 'discogs', 'lastfm'])),
            new OA\Parameter(name: 'limit', description: 'Maximum number of results', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100, minimum: 1)),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Search results from the selected source',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(description: 'Source-specific artist search results', type: 'object'))],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '400', description: 'Empty query parameter', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '429', description: 'The user already searched MusicBrainz in the last ten seconds', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '503', description: 'No turn under the shared MusicBrainz rate limit within ten seconds', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/artist', name: 'artist', methods: ['GET'])]
    public function searchArtist(Request $request, #[CurrentUser] AuthenticatedUserIdentityInterface $user): JsonResponse
    {
        $query = $request->query->get('q', '');
        $source = $request->query->get('source', 'musicbrainz');
        $limit = (int) $request->query->get('limit', 25);

        if (trim($query) === '') {
            return $this->errorResponse($this->trans('errors.query_required', domain: 'metadata'));
        }

        $results = match ($source) {
            'discogs' => $this->discogs->searchArtist($query, $limit),
            'lastfm' => $this->lastFm->searchArtist($query, $limit),
            default => $this->musicBrainzFor($user)->searchArtist($query, $limit, wait: MusicBrainzSlotWait::Bounded),
        };

        return $this->successResponse(is_array($results) ? $results : get_object_vars($results));
    }

    #[OA\Get(
        path: '/api/metadata/search/album',
        summary: 'Search for albums/release groups across external sources',
        parameters: [
            new OA\Parameter(name: 'q', description: 'Search query', in: 'query', required: true, schema: new OA\Schema(type: 'string'), example: 'OK Computer'),
            new OA\Parameter(name: 'source', description: 'Data source: musicbrainz or discogs', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'musicbrainz', enum: ['musicbrainz', 'discogs'])),
            new OA\Parameter(name: 'artist', description: 'Filter by artist name', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', description: 'Maximum number of results', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100, minimum: 1)),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Search results from the selected source',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(description: 'Source-specific release group search results', type: 'object'))],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '400', description: 'Empty query parameter', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '429', description: 'The user already searched MusicBrainz in the last ten seconds', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '503', description: 'No turn under the shared MusicBrainz rate limit within ten seconds', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/album', name: 'album', methods: ['GET'])]
    public function searchAlbum(Request $request, #[CurrentUser] AuthenticatedUserIdentityInterface $user): JsonResponse
    {
        $query = $request->query->get('q', '');
        $source = $request->query->get('source', 'musicbrainz');
        $artist = $request->query->get('artist');
        $limit = (int) $request->query->get('limit', 25);

        if (trim($query) === '') {
            return $this->errorResponse($this->trans('errors.query_required', domain: 'metadata'));
        }

        $results = match ($source) {
            'discogs' => $this->discogs->searchRelease($query, $artist ?? null, $limit),
            default => $this->musicBrainzFor($user)->searchReleaseGroup($query, $artist ?? null, $limit, MusicBrainzSlotWait::Bounded),
        };

        return $this->successResponse(get_object_vars($results));
    }

    #[OA\Get(
        path: '/api/metadata/search/song',
        summary: 'Search for songs/recordings across external sources',
        parameters: [
            new OA\Parameter(name: 'q', description: 'Search query', in: 'query', required: true, schema: new OA\Schema(type: 'string'), example: 'Paranoid Android'),
            new OA\Parameter(name: 'source', description: 'Data source: musicbrainz or discogs', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'musicbrainz', enum: ['musicbrainz', 'discogs'])),
            new OA\Parameter(name: 'artist', description: 'Filter by artist name', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', description: 'Maximum number of results', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100, minimum: 1)),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Search results from the selected source',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(description: 'Source-specific recording search results', type: 'object'))],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '400', description: 'Empty query parameter', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '429', description: 'The user already searched MusicBrainz in the last ten seconds', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '503', description: 'No turn under the shared MusicBrainz rate limit within ten seconds', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/song', name: 'song', methods: ['GET'])]
    public function searchSong(Request $request, #[CurrentUser] AuthenticatedUserIdentityInterface $user): JsonResponse
    {
        $query = $request->query->get('q', '');
        $source = $request->query->get('source', 'musicbrainz');
        $artist = $request->query->get('artist');
        $limit = (int) $request->query->get('limit', 25);

        if (trim($query) === '') {
            return $this->errorResponse($this->trans('errors.query_required', domain: 'metadata'));
        }

        $results = match ($source) {
            'discogs' => $this->discogs->searchRelease($query, $artist ?? null, $limit),
            default => $this->musicBrainzFor($user)->searchRecording($query, $artist ?? null, $limit, MusicBrainzSlotWait::Bounded),
        };

        return $this->successResponse(get_object_vars($results));
    }

    /**
     * The MusicBrainz adapter, once the user has spent a call of their own allowance; 429 when it is spent.
     *
     * The allowance is counted per user ID, so a changed email address starts no new one.
     */
    private function musicBrainzFor(AuthenticatedUserIdentityInterface $user): MusicBrainzAdapter
    {
        $allowance = $this->musicbrainzUserLimiter->create($user->getId())->consume();
        if (!$allowance->isAccepted()) {
            throw new TooManyRequestsHttpException(
                max(1, $allowance->getRetryAfter()->getTimestamp() - time()),
                'Too many MusicBrainz searches. Please try again later.',
            );
        }

        return $this->musicBrainz;
    }
}
