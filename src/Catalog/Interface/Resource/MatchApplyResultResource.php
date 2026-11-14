<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Application\Command\MusicBrainzMatch\MatchApplyResult;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

/**
 * The `data` of POST /api/admin/metadata/match/{type}/{publicId}/apply, and of
 * `app:metadata:match:apply --json`: what the match wrote.
 *
 * @phpstan-type ApplyResultData array{type: string, publicId: string|null, candidateId: string, fields: list<string>, creditsChanged: bool, songsChanged: int, mergedArtists: list<string>, removedArtists: list<string>}
 */
#[OA\Schema(
    schema: 'MatchApplyResultResource',
    required: ['type', 'publicId', 'candidateId', 'fields', 'creditsChanged', 'songsChanged', 'mergedArtists', 'removedArtists'],
    properties: [
        new OA\Property(property: 'type', description: 'What was matched', type: 'string', enum: ['album', 'song', 'artist']),
        new OA\Property(property: 'publicId', description: 'The album\'s, song\'s or artist\'s public ID', type: 'string', maxLength: 21, minLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$'),
        new OA\Property(property: 'candidateId', description: 'The MusicBrainz ID applied: a release, recording or artist', type: 'string'),
        new OA\Property(property: 'fields', description: 'The item\'s own fields the match wrote, by field name', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'creditsChanged', description: 'Whether the item\'s own artist credits changed', type: 'boolean'),
        new OA\Property(property: 'songsChanged', description: 'How many of an album\'s songs took fields or credits', type: 'integer', minimum: 0),
        new OA\Property(property: 'mergedArtists', description: 'The public IDs of the artists merged into another artist', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'removedArtists', description: 'The public IDs of the artists removed', type: 'array', items: new OA\Items(type: 'string')),
    ],
)]
final class MatchApplyResultResource extends AbstractResource
{
    /** @return ApplyResultData */
    #[\Override]
    public static function from(mixed $source): array
    {
        assert($source instanceof MatchApplyResult);

        return [
            'type' => $source->type->value,
            'publicId' => $source->publicId,
            'candidateId' => $source->candidateId,
            'fields' => $source->fields,
            'creditsChanged' => $source->creditsChanged,
            'songsChanged' => $source->songsChanged,
            'mergedArtists' => $source->mergedArtists,
            'removedArtists' => $source->removedArtists,
        ];
    }
}
