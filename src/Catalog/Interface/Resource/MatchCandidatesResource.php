<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Application\Query\MusicBrainzMatch\ArtistCandidate;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchCandidates;
use App\Catalog\Application\Query\MusicBrainzMatch\RecordingCandidate;
use App\Catalog\Application\Query\MusicBrainzMatch\ReleaseCandidate;
use App\Shared\Interface\Resource\AbstractResource;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/** The `data` of GET /api/admin/metadata/match/{type}/{publicId}/candidates, and of `app:metadata:match:search --json`. */
#[OA\Schema(
    schema: 'MatchCandidatesResource',
    required: ['type', 'publicId', 'title', 'artist', 'candidates'],
    properties: [
        new OA\Property(property: 'type', description: 'What was searched for: an album matches a release, a song a recording, an artist an artist', type: 'string', enum: ['album', 'song', 'artist']),
        new OA\Property(property: 'publicId', description: 'The album\'s, song\'s or artist\'s public ID', type: 'string', maxLength: 21, minLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$'),
        new OA\Property(property: 'title', description: 'The title searched, or for an artist the name: the item\'s own unless the request edited it', type: 'string'),
        new OA\Property(property: 'artist', description: 'The artist searched; null for an artist search, or when the item has no primary artist and the request named none', type: 'string', nullable: true),
        new OA\Property(property: 'candidates', description: 'At most five candidates, best fit first: releases for an album, recordings for a song, artists for an artist', type: 'array', items: new OA\Items(oneOf: [
            new OA\Schema(ref: new Model(type: MatchReleaseCandidateResource::class)),
            new OA\Schema(ref: new Model(type: MatchRecordingCandidateResource::class)),
            new OA\Schema(ref: new Model(type: MatchArtistCandidateResource::class)),
        ])),
    ],
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
