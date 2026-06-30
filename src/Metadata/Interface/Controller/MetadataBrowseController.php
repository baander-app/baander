<?php

declare(strict_types=1);

namespace App\Metadata\Interface\Controller;

use App\Metadata\Infrastructure\Api\MusicBrainz\MusicBrainzAdapter;
use App\Metadata\Infrastructure\Api\LastFm\LastFmAdapter;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use OpenApi\Attributes as OA;
use Nelmio\ApiDocBundle\Attribute\Model;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Metadata', description: 'External metadata enrichment endpoints')]
#[Route('/api/metadata/browse', name: 'metadata_browse_')]
final class MetadataBrowseController
{
    use ApiResponsesTrait;
    use TranslatorTrait;
    public function __construct(
        private readonly MusicBrainzAdapter $musicBrainz,
        private readonly LastFmAdapter $lastFm,
    ) {
    }

    #[OA\Get(
        path: '/api/metadata/browse/artist/{mbid}',
        summary: 'Browse artist details from MusicBrainz, enriched with Last.fm data',
        parameters: [
            new OA\Parameter(name: 'mbid', description: 'MusicBrainz artist ID', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'a74b1b7f-71a5-4011-9441-d0b5e4122711'),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Artist details with MusicBrainz and Last.fm data',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'musicbrainz', description: 'MusicBrainz artist data', type: 'object'),
                            new OA\Property(property: 'lastfm', description: 'Last.fm artist enrichment data, null if unavailable', type: 'object', nullable: true),
                        ], type: 'object'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '404', description: 'Artist not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/artist/{mbid}', name: 'artist', methods: ['GET'])]
    public function artist(string $mbid): JsonResponse
    {
        $artist = $this->musicBrainz->lookupArtist($mbid);

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

    #[OA\Get(
        path: '/api/metadata/browse/release-group/{mbid}',
        summary: 'Browse release group details from MusicBrainz',
        parameters: [
            new OA\Parameter(name: 'mbid', description: 'MusicBrainz release group ID', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'a1cfab6f-bf37-37c3-8cdb-167912e5d26b'),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Release group details from MusicBrainz',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', description: 'MusicBrainz release group data', type: 'object')],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '404', description: 'Release group not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/release-group/{mbid}', name: 'release_group', methods: ['GET'])]
    public function releaseGroup(string $mbid): JsonResponse
    {
        $releaseGroup = $this->musicBrainz->lookupReleaseGroup($mbid);

        if ($releaseGroup === null) {
            return $this->notFound($this->trans('errors.release_group_not_found', domain: 'metadata'));
        }

        return $this->successResponse($releaseGroup);
    }
}
