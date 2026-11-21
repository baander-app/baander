<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Application\Query\MusicBrainzMatch\CatalogCredit;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchCreditsRow;
use App\Catalog\Application\Query\MusicBrainzMatch\ProposedCredit;
use OpenApi\Spec as OA;

/**
 * The artist credits row of a MusicBrainz match comparison, keyed as an apply takes it back.
 *
 * @phpstan-import-type PredictedArtistData from MatchPredictedArtistResource
 *
 * @phpstan-type CurrentCreditData array{artistPublicId: string, artistName: string, role: string|null, creditedName: string}
 * @phpstan-type ProposedCreditData array{role: string, creditedName: string, musicbrainzArtistId: string, artist: PredictedArtistData}
 * @phpstan-type CreditsRowData array{key: string, songPublicId: string|null, current: list<CurrentCreditData>, proposed: list<ProposedCreditData>, ticked: bool, changes: bool}
 */
#[OA\Schema(
    description: 'The primary and featured artist credits of an album or song now and as a match would set them; credits of other roles stay',
    type: 'object',
    properties: [
        'key' => new OA\Schema(
            description: 'The row\'s key, which an apply names the row by: "credits" for the item\'s own credits, "<song public ID>:credits" for one of an album\'s songs',
            type: 'string',
        ),
        'songPublicId' => new OA\Schema(
            description: 'The song the credits belong to; null for an album\'s own credits',
            type: 'string',
            nullable: true,
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
        ),
        'current' => new OA\Schema(
            description: 'The primary and featured credits now, which an apply rechecks before it writes the row',
            type: 'array',
            items: new OA\Schema(
                type: 'object',
                properties: [
                    'artistPublicId' => new OA\Schema(
                        type: 'string',
                        minLength: 21,
                        maxLength: 21,
                        pattern: '^[0-9a-zA-Z_-]{21}$',
                    ),
                    'artistName' => new OA\Schema(type: 'string'),
                    'role' => new OA\Schema(
                        type: 'string',
                        enum: ['primary', 'featured'],
                    ),
                    'creditedName' => new OA\Schema(
                        description: 'The name the item is credited under, such as "Post Malone, Luke Combs"',
                        type: 'string',
                    ),
                ],
                required: ['artistPublicId', 'artistName', 'role', 'creditedName'],
            ),
        ),
        'proposed' => new OA\Schema(
            description: 'The credits a match would set, in MusicBrainz\'s order: the first artist primary, the rest featured',
            type: 'array',
            items: new OA\Schema(
                type: 'object',
                properties: [
                    'role' => new OA\Schema(
                        type: 'string',
                        enum: ['primary', 'featured'],
                    ),
                    'creditedName' => new OA\Schema(
                        description: 'The name the credit would show: the item\'s own credited name for the primary artist when it has one, else the name MusicBrainz credits',
                        type: 'string',
                    ),
                    'musicbrainzArtistId' => new OA\Schema(description: 'The credited artist\'s MusicBrainz ID', type: 'string'),
                    'artist' => new OA\Schema(ref: MatchPredictedArtistResource::class),
                ],
                required: ['role', 'creditedName', 'musicbrainzArtistId', 'artist'],
            ),
        ),
        'ticked' => new OA\Schema(
            description: 'Whether the row is ticked by default: it is when applying it would change the credits or give a library artist its MusicBrainz ID',
            type: 'boolean',
        ),
        'changes' => new OA\Schema(description: 'Whether applying the row would change anything', type: 'boolean'),
    ],
    required: ['key', 'songPublicId', 'current', 'proposed', 'ticked', 'changes'],
    component: 'MatchCreditsRowResource',
)]
final class MatchCreditsRowResource
{
    /**
     * @param string|null $songPublicId the album's song the row belongs to; null for the item's own credits, a song's included
     *
     * @return CreditsRowData
     */
    public static function from(MatchCreditsRow $row, ?string $songPublicId = null): array
    {
        return [
            'key' => MatchComparisonResource::rowKey(MatchComparisonResource::CREDITS, $songPublicId),
            'songPublicId' => $row->songPublicId,
            'current' => array_map(self::current(...), $row->current),
            'proposed' => array_map(self::proposed(...), $row->proposed),
            'ticked' => $row->ticked,
            'changes' => $row->changes(),
        ];
    }

    /** @return CurrentCreditData */
    private static function current(CatalogCredit $credit): array
    {
        return [
            'artistPublicId' => $credit->artistPublicId,
            'artistName' => $credit->artistName,
            'role' => $credit->role,
            'creditedName' => $credit->creditedName,
        ];
    }

    /** @return ProposedCreditData */
    private static function proposed(ProposedCredit $credit): array
    {
        return [
            'role' => $credit->role->value,
            'creditedName' => $credit->creditedName,
            'musicbrainzArtistId' => $credit->musicbrainzArtistId,
            'artist' => MatchPredictedArtistResource::from($credit->artist),
        ];
    }
}
