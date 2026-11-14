<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Application\Query\MusicBrainzMatch\MatchField;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchFieldRow;
use OpenApi\Attributes as OA;

/**
 * One field row of a MusicBrainz match comparison, keyed as an apply takes it back.
 *
 * The cover row's current value only says whether the album has a cover: the image's ID is
 * internal.
 *
 * @phpstan-type FieldRowData array{key: string, field: string, songPublicId: string|null, current: string|int|bool|list<string>|null, proposed: string|int|list<string>, ticked: bool, locked: bool, changes: bool}
 */
#[OA\Schema(
    schema: 'MatchFieldRowResource',
    description: 'A field a match can set: the value the item holds, the value MusicBrainz gives, and whether the row is ticked by default',
    required: ['key', 'field', 'songPublicId', 'current', 'proposed', 'ticked', 'locked', 'changes'],
    properties: [
        new OA\Property(property: 'key', description: 'The row\'s key, which an apply names the row by: the field for a row of the item itself, "<song public ID>:<field>" for a row of one of an album\'s songs', type: 'string'),
        new OA\Property(property: 'field', description: 'The field the row sets', type: 'string', enum: ['mbid', 'releaseMbid', 'title', 'year', 'label', 'catalogNumber', 'barcode', 'country', 'disambiguation', 'genres', 'cover', 'track', 'disc', 'name', 'sortName', 'type', 'lifeSpanBegin', 'lifeSpanEnd']),
        new OA\Property(property: 'songPublicId', description: 'The album\'s song the row belongs to; null for a row of the item itself', type: 'string', maxLength: 21, minLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$', nullable: true),
        new OA\Property(
            property: 'current',
            description: 'The value the item holds now, which an apply rechecks before it writes the row; null when the field is empty. The cover row holds true when the album has a cover.',
            nullable: true,
            oneOf: [
                new OA\Schema(type: 'string'),
                new OA\Schema(type: 'integer'),
                new OA\Schema(type: 'boolean'),
                new OA\Schema(type: 'array', items: new OA\Items(type: 'string')),
            ],
        ),
        new OA\Property(
            property: 'proposed',
            description: 'The value MusicBrainz gives. The cover row holds the MusicBrainz ID of the release whose front cover the match would store.',
            oneOf: [
                new OA\Schema(type: 'string'),
                new OA\Schema(type: 'integer'),
                new OA\Schema(type: 'array', items: new OA\Items(type: 'string')),
            ],
        ),
        new OA\Property(property: 'ticked', description: 'Whether the row is ticked by default: MusicBrainz IDs and empty fields are, a field holding another value is not, and a locked field never is', type: 'boolean'),
        new OA\Property(property: 'locked', description: 'Whether the field is locked; a locked row cannot be ticked', type: 'boolean'),
        new OA\Property(property: 'changes', description: 'Whether applying the row would change the field; genre names compare ignoring case and order', type: 'boolean'),
    ],
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
            'current' => self::current($row),
            'proposed' => $row->proposed,
            'ticked' => $row->ticked,
            'locked' => $row->locked,
            'changes' => $row->changes(),
        ];
    }

    /** @return string|int|bool|list<string>|null */
    private static function current(MatchFieldRow $row): string|int|bool|array|null
    {
        if ($row->field === MatchField::Cover) {
            return $row->current === null ? null : true;
        }

        return $row->current;
    }
}
