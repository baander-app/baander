<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Application\Query\MusicBrainzMatch\MatchFieldRow;
use App\Shared\Domain\Model\PublicId;
use OpenApi\Spec as OA;

/**
 * One field row of a MusicBrainz match comparison, keyed as an apply takes it back.
 *
 * @phpstan-type FieldRowData array{key: string, field: string, songPublicId: string|null, current: string|int|bool|list<string>|null, proposed: string|int|list<string>, ticked: bool, locked: bool, changes: bool}
 */
#[OA\Schema(
    description: 'A field a match can set: the value the item holds, the value MusicBrainz gives, and whether the row is ticked by default',
    type: 'object',
    properties: [
        'key' => new OA\Schema(
            description: 'The row\'s key, which an apply names the row by: the field for a row of the item itself, "<song public ID>:<field>" for a row of one of an album\'s songs',
            type: 'string',
        ),
        'field' => new OA\Schema(
            description: 'The field the row sets',
            type: 'string',
            enum: [
                'mbid',
                'releaseMbid',
                'title',
                'year',
                'label',
                'catalogNumber',
                'barcode',
                'country',
                'disambiguation',
                'genres',
                'cover',
                'track',
                'disc',
                'name',
                'sortName',
                'type',
                'lifeSpanBegin',
                'lifeSpanEnd',
            ],
        ),
        'songPublicId' => new OA\Schema(
            description: 'The album\'s song the row belongs to; null for a row of the item itself',
            type: 'string',
            nullable: true,
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
        ),
        'current' => new OA\Schema(
            description: 'The value the item holds now, which an apply rechecks before it writes the row; null when the field is empty. The cover row holds true when the album has a cover.',
            nullable: true,
            oneOf: [
                new OA\Schema(type: 'string'),
                new OA\Schema(type: 'integer'),
                new OA\Schema(type: 'boolean'),
                new OA\Schema(
                    type: 'array',
                    items: new OA\Schema(type: 'string'),
                ),
            ],
        ),
        'proposed' => new OA\Schema(
            description: 'The value MusicBrainz gives. The cover row holds the MusicBrainz ID of the release whose front cover the match would store.',
            oneOf: [
                new OA\Schema(type: 'string'),
                new OA\Schema(type: 'integer'),
                new OA\Schema(
                    type: 'array',
                    items: new OA\Schema(type: 'string'),
                ),
            ],
        ),
        'ticked' => new OA\Schema(
            description: 'Whether the row is ticked by default: MusicBrainz IDs and empty fields are, a field holding another value is not, and a locked field never is',
            type: 'boolean',
        ),
        'locked' => new OA\Schema(
            description: 'Whether the field is locked; a locked row cannot be ticked',
            type: 'boolean',
        ),
        'changes' => new OA\Schema(
            description: 'Whether applying the row would change the field; genre names compare ignoring case and order',
            type: 'boolean',
        ),
    ],
    required: ['key', 'field', 'songPublicId', 'current', 'proposed', 'ticked', 'locked', 'changes'],
    component: 'MatchFieldRowResource',
)]
final class MatchFieldRowResource
{
    /**
     * @param string|null $songPublicId the album's song the row belongs to; null for a row of the item itself
     *
     * @return FieldRowData
     */
    public static function from(MatchFieldRow $row, ?string $songPublicId = null): array
    {
        return [
            'key' => MatchComparisonResource::rowKey($row->field->value, $songPublicId),
            'field' => $row->field->value,
            'songPublicId' => $songPublicId,
            'current' => $row->current,
            'proposed' => $row->proposed,
            'ticked' => $row->ticked,
            'locked' => $row->locked,
            'changes' => $row->changes(),
        ];
    }
}
