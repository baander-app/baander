<?php

declare(strict_types=1);

namespace App\Metadata\Interface\Controller;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzSlotWait;
use App\Metadata\Infrastructure\Api\LastFm\LastFmAdapter;
use App\Metadata\Infrastructure\Api\MusicBrainz\MusicBrainzAdapter;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use App\Shared\Interface\DTO\ApiError;
use OpenApi\Spec as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * MusicBrainz lookups for any signed-in user.
 *
 * A lookup first spends the user's own allowance of one MusicBrainz call per ten seconds
 * (429 when spent), then waits at most ten seconds for a turn under the rate limit every
 * process shares (503 beyond), so one member cannot queue ahead of the matcher.
 */
#[OA\Tag(name: 'Metadata', description: 'External metadata enrichment endpoints')]
#[Route('/api/metadata/browse', name: 'metadata_browse_')]
final class MetadataBrowseController
{
    use ApiResponsesTrait;
    use TranslatorTrait;
    public function __construct(
        private readonly MusicBrainzAdapter $musicBrainz,
        private readonly LastFmAdapter $lastFm,
        private readonly RateLimiterFactoryInterface $musicbrainzUserLimiter,
    ) {
    }

    #[OA\Operation\Get(
        path: '/api/metadata/browse/artist/{mbid}',
        summary: 'Browse artist details from MusicBrainz, enriched with Last.fm data',
        parameters: [
            new OA\Parameter(
                name: 'mbid',
                in: 'path',
                description: 'MusicBrainz artist ID',
                required: true,
                schema: new OA\Schema(type: 'string'),
                example: 'a74b1b7f-71a5-4011-9441-d0b5e4122711',
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Artist details with MusicBrainz and Last.fm data',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'musicbrainz' => new OA\Schema(description: 'MusicBrainz artist data', type: 'object'),
                                    'lastfm' => new OA\Schema(
                                        description: 'Last.fm artist enrichment data, null if unavailable',
                                        type: 'object',
                                        nullable: true,
                                    ),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Artist not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '429',
                description: 'The user already called MusicBrainz in the last ten seconds',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '503',
                description: 'No turn under the shared MusicBrainz rate limit within ten seconds',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/artist/{mbid}', name: 'artist', methods: ['GET'])]
    public function artist(string $mbid, #[CurrentUser] AuthenticatedUserIdentityInterface $user): JsonResponse
    {
        $artist = $this->musicBrainzFor($user)->lookupArtist($mbid, MusicBrainzSlotWait::Bounded);

        if ($artist === null) {
            return $this->notFound($this->trans('errors.artist_not_found', domain: 'metadata'));
        }

        // Enrich with Last.fm if available
        $lastFmInfo = $this->lastFm->getArtistInfo($artist->name);

        return $this->successResponse([
            'musicbrainz' => $artist,
            'lastfm' => $lastFmInfo,
        ]);
    }

    #[OA\Operation\Get(
        path: '/api/metadata/browse/release-group/{mbid}',
        summary: 'Browse release group details from MusicBrainz',
        parameters: [
            new OA\Parameter(
                name: 'mbid',
                in: 'path',
                description: 'MusicBrainz release group ID',
                required: true,
                schema: new OA\Schema(type: 'string'),
                example: 'a1cfab6f-bf37-37c3-8cdb-167912e5d26b',
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Release group details from MusicBrainz',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(description: 'MusicBrainz release group data', type: 'object'),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Release group not found',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '429',
                description: 'The user already called MusicBrainz in the last ten seconds',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '503',
                description: 'No turn under the shared MusicBrainz rate limit within ten seconds',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/release-group/{mbid}', name: 'release_group', methods: ['GET'])]
    public function releaseGroup(string $mbid, #[CurrentUser] AuthenticatedUserIdentityInterface $user): JsonResponse
    {
        $releaseGroup = $this->musicBrainzFor($user)->lookupReleaseGroup($mbid, MusicBrainzSlotWait::Bounded);

        if ($releaseGroup === null) {
            return $this->notFound($this->trans('errors.release_group_not_found', domain: 'metadata'));
        }

        return $this->successResponse(get_object_vars($releaseGroup));
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
                'Too many MusicBrainz lookups. Please try again later.',
            );
        }

        return $this->musicBrainz;
    }
}
