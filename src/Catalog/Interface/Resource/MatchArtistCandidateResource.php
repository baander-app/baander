<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Application\Query\MusicBrainzMatch\ArtistCandidate;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

/** A MusicBrainz artist a library artist may match, as the candidates route lists it. */
#[OA\Schema(
    schema: 'MatchArtistCandidateResource',
    description: 'A MusicBrainz artist a library artist may match',
    required: ['id', 'score', 'name', 'sortName', 'disambiguation', 'type', 'country', 'lifeSpanBegin', 'lifeSpanEnd', 'ended'],
    properties: [
        new OA\Property(property: 'id', description: 'The artist\'s MusicBrainz ID, which the comparison takes as its candidate', type: 'string'),
        new OA\Property(property: 'score', description: 'How well the artist fits, by name and, when the library artist has one, disambiguation', type: 'integer', maximum: 100, minimum: 0),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'sortName', type: 'string', nullable: true),
        new OA\Property(property: 'disambiguation', description: 'MusicBrainz\'s comment that tells the artist apart, such as "Dutch hardstyle DJ"', type: 'string', nullable: true),
        new OA\Property(property: 'type', description: 'Such as Person or Group', type: 'string', nullable: true),
        new OA\Property(property: 'country', description: 'An ISO 3166-1 code', type: 'string', nullable: true),
        new OA\Property(property: 'lifeSpanBegin', description: 'When the artist was born or began: YYYY, YYYY-MM or YYYY-MM-DD', type: 'string', nullable: true),
        new OA\Property(property: 'lifeSpanEnd', description: 'When the artist died or ended: YYYY, YYYY-MM or YYYY-MM-DD', type: 'string', nullable: true),
        new OA\Property(property: 'ended', description: 'Whether the artist has ended, even when the end date is unknown', type: 'boolean'),
    ],
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
