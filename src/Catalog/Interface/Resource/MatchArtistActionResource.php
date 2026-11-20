<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Application\Query\MusicBrainzMatch\MatchArtistAction;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchComparison;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * An artist a MusicBrainz match would merge or remove, with the credits rows it stands on.
 *
 * @phpstan-import-type PredictedArtistData from MatchPredictedArtistResource
 *
 * @phpstan-type ArtistActionData array{kind: string, artistPublicId: string, artistName: string, target: PredictedArtistData|null, requiredRows: list<string>, ticked: bool}
 */
#[OA\Schema(
    schema: 'MatchArtistActionResource',
    description: 'An artist a match would merge into another artist or remove. It stands only while every row in requiredRows is ticked; unticking it keeps the artist as it is.',
    required: ['kind', 'artistPublicId', 'artistName', 'target', 'requiredRows', 'ticked'],
    properties: [
        new OA\Property(property: 'kind', description: 'merge moves the artist\'s credits, favorites and play history to the target and removes it; remove deletes it with its favorites', type: 'string', enum: ['merge', 'remove']),
        new OA\Property(property: 'artistPublicId', description: 'The artist merged or removed, which an apply names the action by', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'artistName', type: 'string'),
        new OA\Property(property: 'target', description: 'The artist a merge goes into, which may be one the match creates; null for a removal', nullable: true, oneOf: [new OA\Schema(ref: new Model(type: MatchPredictedArtistResource::class))]),
        new OA\Property(property: 'requiredRows', description: 'The keys of the credits rows that must all be ticked for the action to stand; empty for the merge an artist comparison applies its ID by', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'ticked', description: 'Whether the action is ticked by default', type: 'boolean'),
    ],
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
