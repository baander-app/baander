<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Application\Query\MusicBrainzMatch\ArtistCandidate;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchCandidates;
use App\Catalog\Application\Query\MusicBrainzMatch\RecordingCandidate;
use App\Catalog\Application\Query\MusicBrainzMatch\ReleaseCandidate;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

/** The `data` of GET /api/admin/metadata/match/{type}/{publicId}/candidates, and of `app:metadata:match:search --json`. */
#[OA\Schema(
    type: 'object',
    properties: [
        'type' => new OA\Schema(
            description: 'What was searched for: an album matches a release, a song a recording, an artist an artist',
            type: 'string',
            enum: ['album', 'song', 'artist'],
        ),
        'publicId' => new OA\Schema(
            description: 'The album\'s, song\'s or artist\'s public ID',
            type: 'string',
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
        ),
        'title' => new OA\Schema(
            description: 'The title searched last, or for an artist the name: the item\'s own unless the request edited it, or an album\'s own title without the comma-separated list in square brackets at its end when the full title found no release',
            type: 'string',
        ),
        'artist' => new OA\Schema(
            description: 'The artist searched; null for an artist search, or when the item has no primary artist and the request named none',
            type: 'string',
            nullable: true,
        ),
        'candidates' => new OA\Schema(
            description: 'At most five candidates, best fit first: releases for an album, recordings for a song, artists for an artist',
            type: 'array',
            items: new OA\Schema(
                oneOf: [
                    new OA\Schema(ref: MatchReleaseCandidateResource::class),
                    new OA\Schema(ref: MatchRecordingCandidateResource::class),
                    new OA\Schema(ref: MatchArtistCandidateResource::class),
                ],
            ),
        ),
    ],
    required: ['type', 'publicId', 'title', 'artist', 'candidates'],
    component: 'MatchCandidatesResource',
)]
final class MatchCandidatesResource extends AbstractResource
{
    /** @return array<string, mixed> */
    #[\Override]
    public static function from(mixed $source): array
    {
        assert($source instanceof MatchCandidates);

        return [
            'type' => $source->type->value,
            'publicId' => $source->publicId,
            'title' => $source->title,
            'artist' => $source->artist,
            'candidates' => array_map(self::candidate(...), $source->candidates),
        ];
    }

    /** @return array<string, mixed> */
    private static function candidate(ReleaseCandidate|RecordingCandidate|ArtistCandidate $candidate): array
    {
        return match (true) {
            $candidate instanceof ReleaseCandidate => MatchReleaseCandidateResource::from($candidate),
            $candidate instanceof RecordingCandidate => MatchRecordingCandidateResource::from($candidate),
            $candidate instanceof ArtistCandidate => MatchArtistCandidateResource::from($candidate),
        };
    }
}
