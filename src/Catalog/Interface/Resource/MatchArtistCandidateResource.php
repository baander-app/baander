<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Application\Query\MusicBrainzMatch\ArtistCandidate;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

/** A MusicBrainz artist a library artist may match, as the candidates route lists it. */
#[OA\Schema(
    description: 'A MusicBrainz artist a library artist may match',
    type: 'object',
    properties: [
        'id' => new OA\Schema(
            description: 'The artist\'s MusicBrainz ID, which the comparison takes as its candidate',
            type: 'string',
        ),
        'score' => new OA\Schema(
            description: 'How well the artist fits, by name and, when the library artist has one, disambiguation',
            type: 'integer',
            minimum: 0,
            maximum: 100,
        ),
        'name' => new OA\Schema(type: 'string'),
        'sortName' => new OA\Schema(type: 'string', nullable: true),
        'disambiguation' => new OA\Schema(
            description: 'MusicBrainz\'s comment that tells the artist apart, such as "Dutch hardstyle DJ"',
            type: 'string',
            nullable: true,
        ),
        'type' => new OA\Schema(
            description: 'Such as Person or Group',
            type: 'string',
            nullable: true,
        ),
        'country' => new OA\Schema(
            description: 'An ISO 3166-1 code',
            type: 'string',
            nullable: true,
        ),
        'lifeSpanBegin' => new OA\Schema(
            description: 'When the artist was born or began: YYYY, YYYY-MM or YYYY-MM-DD',
            type: 'string',
            nullable: true,
        ),
        'lifeSpanEnd' => new OA\Schema(
            description: 'When the artist died or ended: YYYY, YYYY-MM or YYYY-MM-DD',
            type: 'string',
            nullable: true,
        ),
        'ended' => new OA\Schema(
            description: 'Whether the artist has ended, even when the end date is unknown',
            type: 'boolean',
        ),
    ],
    required: [
        'id',
        'score',
        'name',
        'sortName',
        'disambiguation',
        'type',
        'country',
        'lifeSpanBegin',
        'lifeSpanEnd',
        'ended',
    ],
    component: 'MatchArtistCandidateResource',
)]
final class MatchArtistCandidateResource extends AbstractResource
{
    /** @return array<string, mixed> */
    #[\Override]
    public static function from(mixed $source): array
    {
        assert($source instanceof ArtistCandidate);

        return [
            'id' => $source->id,
            'score' => $source->score,
            'name' => $source->name,
            'sortName' => $source->sortName,
            'disambiguation' => $source->disambiguation,
            'type' => $source->type,
            'country' => $source->country,
            'lifeSpanBegin' => $source->lifeSpanBegin,
            'lifeSpanEnd' => $source->lifeSpanEnd,
            'ended' => $source->ended,
        ];
    }
}
