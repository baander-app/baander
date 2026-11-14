<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Application\Query\MusicBrainzMatch\PredictedArtist;
use OpenApi\Attributes as OA;

/**
 * The library artist a MusicBrainz credit would go to, or the one a match would create, named by public values only.
 *
 * @phpstan-type PredictedArtistData array{publicId: string|null, name: string, takesId: bool}
 */
#[OA\Schema(
    schema: 'MatchPredictedArtistResource',
    description: 'The library artist a MusicBrainz credit would go to if the match were applied now, or the artist the match would create',
    required: ['publicId', 'name', 'takesId'],
    properties: [
        new OA\Property(property: 'publicId', description: 'The artist\'s public ID; null for an artist the match would create', type: 'string', maxLength: 21, minLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$', nullable: true),
        new OA\Property(property: 'name', description: 'The artist\'s name, or the name a new artist would get', type: 'string'),
        new OA\Property(property: 'takesId', description: 'Whether a library artist without a MusicBrainz ID would take on the credit\'s ID', type: 'boolean'),
    ],
    type: 'object',
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
