<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Application\Query\MusicBrainzMatch\PredictedArtist;
use OpenApi\Spec as OA;

/**
 * The library artist a MusicBrainz credit would go to, or the one a match would create, named by public values only.
 *
 * @phpstan-type PredictedArtistData array{publicId: string|null, name: string, takesId: bool}
 */
#[OA\Schema(
    description: 'The library artist a MusicBrainz credit would go to if the match were applied now, or the artist the match would create',
    type: 'object',
    properties: [
        'publicId' => new OA\Schema(
            description: 'The artist\'s public ID; null for an artist the match would create',
            type: 'string',
            nullable: true,
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
        ),
        'name' => new OA\Schema(description: 'The artist\'s name, or the name a new artist would get', type: 'string'),
        'takesId' => new OA\Schema(
            description: 'Whether a library artist without a MusicBrainz ID would take on the credit\'s ID',
            type: 'boolean',
        ),
    ],
    required: ['publicId', 'name', 'takesId'],
    component: 'MatchPredictedArtistResource',
)]
final class MatchPredictedArtistResource
{
    /** @return PredictedArtistData */
    public static function from(PredictedArtist $artist): array
    {
        return [
            'publicId' => $artist->publicId,
            'name' => $artist->name,
            'takesId' => $artist->takesId,
        ];
    }
}
