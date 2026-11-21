<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Application\Query\MusicBrainzMatch\MatchArtistAction;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchComparison;
use App\Shared\Domain\Model\PublicId;
use OpenApi\Spec as OA;

/**
 * An artist a MusicBrainz match would merge or remove, with the credits rows it stands on.
 *
 * @phpstan-import-type PredictedArtistData from MatchPredictedArtistResource
 *
 * @phpstan-type ArtistActionData array{kind: string, artistPublicId: string, artistName: string, target: PredictedArtistData|null, requiredRows: list<string>, ticked: bool}
 */
#[OA\Schema(
    description: 'An artist a match would merge into another artist or remove. It stands only while every row in requiredRows is ticked; unticking it keeps the artist as it is.',
    type: 'object',
    properties: [
        'kind' => new OA\Schema(
            description: 'merge moves the artist\'s credits, favorites and play history to the target and removes it; remove deletes it with its favorites',
            type: 'string',
            enum: ['merge', 'remove'],
        ),
        'artistPublicId' => new OA\Schema(
            description: 'The artist merged or removed, which an apply names the action by',
            type: 'string',
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
        ),
        'artistName' => new OA\Schema(type: 'string'),
        'target' => new OA\Schema(
            description: 'The artist a merge goes into, which may be one the match creates; null for a removal',
            nullable: true,
            oneOf: [
                new OA\Schema(ref: MatchPredictedArtistResource::class),
            ],
        ),
        'requiredRows' => new OA\Schema(
            description: 'The keys of the credits rows that must all be ticked for the action to stand; empty for the merge an artist comparison applies its ID by',
            type: 'array',
            items: new OA\Schema(type: 'string'),
        ),
        'ticked' => new OA\Schema(description: 'Whether the action is ticked by default', type: 'boolean'),
    ],
    required: ['kind', 'artistPublicId', 'artistName', 'target', 'requiredRows', 'ticked'],
    component: 'MatchArtistActionResource',
)]
final class MatchArtistActionResource
{
    /**
     * @param MatchComparison $comparison the comparison listing the action, whose own credits row a song comparison's actions stand on
     *
     * @return ArtistActionData
     */
    public static function from(MatchArtistAction $action, MatchComparison $comparison): array
    {
        return [
            'kind' => $action->kind->value,
            'artistPublicId' => $action->artistPublicId,
            'artistName' => $action->artistName,
            'target' => $action->target === null ? null : MatchPredictedArtistResource::from($action->target),
            'requiredRows' => self::requiredRows($action, $comparison),
            'ticked' => $action->ticked,
        ];
    }

    /**
     * The keys of the credits rows the action stands on: the item's own first, then its songs'. A
     * song comparison's own credits row belongs to that song, and is keyed as the item's own.
     *
     * @return list<string>
     */
    private static function requiredRows(MatchArtistAction $action, MatchComparison $comparison): array
    {
        $ownSong = $comparison->credits?->songPublicId;
        $songRows = array_map(
            static fn (string $song): string => $song === $ownSong
                ? MatchComparisonResource::CREDITS
                : MatchComparisonResource::rowKey(MatchComparisonResource::CREDITS, $song),
            $action->requiredSongs,
        );

        return [...($action->requiresAlbumCredits ? [MatchComparisonResource::CREDITS] : []), ...$songRows];
    }
}
