<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Application\Command\MusicBrainzMatch\MatchApplyResult;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

/**
 * The `data` of POST /api/admin/metadata/match/{type}/{publicId}/apply, and of
 * `app:metadata:match:apply --json`: what the match wrote.
 *
 * @phpstan-type ApplyResultData array{type: string, publicId: string|null, candidateId: string, fields: list<string>, creditsChanged: bool, songsChanged: int, mergedArtists: list<string>, removedArtists: list<string>}
 */
#[OA\Schema(
    type: 'object',
    properties: [
        'type' => new OA\Schema(
            description: 'What was matched',
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
        'candidateId' => new OA\Schema(
            description: 'The MusicBrainz ID applied: a release, recording or artist',
            type: 'string',
        ),
        'fields' => new OA\Schema(
            description: 'The item\'s own fields the match wrote, by field name',
            type: 'array',
            items: new OA\Schema(type: 'string'),
        ),
        'creditsChanged' => new OA\Schema(description: 'Whether the item\'s own artist credits changed', type: 'boolean'),
        'songsChanged' => new OA\Schema(
            description: 'How many of an album\'s songs took fields or credits',
            type: 'integer',
            minimum: 0,
        ),
        'mergedArtists' => new OA\Schema(
            description: 'The public IDs of the artists merged into another artist',
            type: 'array',
            items: new OA\Schema(type: 'string'),
        ),
        'removedArtists' => new OA\Schema(
            description: 'The public IDs of the artists removed',
            type: 'array',
            items: new OA\Schema(type: 'string'),
        ),
    ],
    required: [
        'type',
        'publicId',
        'candidateId',
        'fields',
        'creditsChanged',
        'songsChanged',
        'mergedArtists',
        'removedArtists',
    ],
    component: 'MatchApplyResultResource',
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
