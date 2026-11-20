<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Application\Query\MusicBrainzMatch\MatchArtistAction;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchComparison;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchFieldRow;
use App\Shared\Interface\Resource\AbstractResource;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * The `data` of GET /api/admin/metadata/match/{type}/{publicId}/comparison, and of
 * `app:metadata:match:compare --json`.
 *
 * Every row has a key an apply names it by: the field, or "credits", for a row of the item itself,
 * and "<song public ID>:<field>" or "<song public ID>:credits" for a row of one of an album's
 * songs. Current values are passed on as the item holds them, since an apply rechecks them. Artists
 * and the cover appear by public values only: no internal ID is in the data.
 *
 * @phpstan-import-type FieldRowData from MatchFieldRowResource
 * @phpstan-import-type CreditsRowData from MatchCreditsRowResource
 * @phpstan-import-type TrackData from MatchTrackResource
 * @phpstan-import-type ArtistActionData from MatchArtistActionResource
 *
 * @phpstan-type ComparisonData array{type: string, publicId: string, candidateId: string, fields: list<FieldRowData>, credits: CreditsRowData|null, tracks: list<TrackData>, artistActions: list<ArtistActionData>}
 */
#[OA\Schema(
    schema: 'MatchComparisonResource',
    required: ['type', 'publicId', 'candidateId', 'fields', 'credits', 'tracks', 'artistActions'],
    properties: [
        new OA\Property(property: 'type', description: 'What is compared: an album with a release, a song with a recording, an artist with an artist', type: 'string', enum: ['album', 'song', 'artist']),
        new OA\Property(property: 'publicId', description: 'The album\'s, song\'s or artist\'s public ID', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'candidateId', description: 'The MusicBrainz ID compared: a release, recording or artist', type: 'string'),
        new OA\Property(property: 'fields', description: 'The item\'s own field rows; a field MusicBrainz gives no value for has no row', type: 'array', items: new OA\Items(ref: new Model(type: MatchFieldRowResource::class))),
        new OA\Property(property: 'credits', description: 'The album\'s or song\'s own credits row; null for an artist, or when MusicBrainz credits no artist', nullable: true, oneOf: [new OA\Schema(ref: new Model(type: MatchCreditsRowResource::class))]),
        new OA\Property(property: 'tracks', description: 'For an album, its songs and the release\'s tracks: in release order, then the songs that pair with no track; empty for a song or artist', type: 'array', items: new OA\Items(ref: new Model(type: MatchTrackResource::class))),
        new OA\Property(property: 'artistActions', description: 'The artists the match would merge or remove', type: 'array', items: new OA\Items(ref: new Model(type: MatchArtistActionResource::class))),
    ],
)]
final class MatchComparisonResource extends AbstractResource
{
    /** The row name of a credits row, which a key carries in place of a field. */
    public const string CREDITS = 'credits';

    /**
     * The key an apply names a row by.
     *
     * @param string      $row          the field, or CREDITS
     * @param string|null $songPublicId the album's song the row belongs to; null for a row of the item itself
     */
    public static function rowKey(string $row, ?string $songPublicId): string
    {
        return $songPublicId === null ? $row : $songPublicId . ':' . $row;
    }

    /** @return ComparisonData */
    #[\Override]
    public static function from(mixed $source): array
    {
        assert($source instanceof MatchComparison);

        return [
            'type' => $source->type->value,
            'publicId' => $source->publicId,
            'candidateId' => $source->candidateId,
            'fields' => array_map(static fn (MatchFieldRow $row): array => MatchFieldRowResource::from($row), $source->fields),
            'credits' => $source->credits === null ? null : MatchCreditsRowResource::from($source->credits),
            'tracks' => array_map(MatchTrackResource::from(...), $source->tracks),
            'artistActions' => array_map(
                static fn (MatchArtistAction $action): array => MatchArtistActionResource::from($action, $source),
                $source->artistActions,
            ),
        ];
    }
}
