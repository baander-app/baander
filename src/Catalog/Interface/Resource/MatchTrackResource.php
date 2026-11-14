<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Application\Query\MusicBrainzMatch\ComparedReleaseTrack;
use App\Catalog\Application\Query\MusicBrainzMatch\ComparedSong;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchFieldRow;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchTrack;
use App\Shared\Interface\Resource\AbstractResource;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * One line of an album's MusicBrainz match comparison: a song paired with a release track, or either left unpaired.
 *
 * @phpstan-import-type FieldRowData from MatchFieldRowResource
 * @phpstan-import-type CreditsRowData from MatchCreditsRowResource
 *
 * @phpstan-type ComparedSongData array{publicId: string, title: string, disc: int|null, track: int|null, lengthSeconds: float|null}
 * @phpstan-type ReleaseTrackData array{disc: int, position: int, number: string, title: string, lengthMs: int|null, artistCredit: string, recordingId: string|null}
 * @phpstan-type TrackData array{song: ComparedSongData|null, release: ReleaseTrackData|null, fields: list<FieldRowData>, credits: CreditsRowData|null}
 */
#[OA\Schema(
    schema: 'MatchTrackResource',
    description: 'A local song paired with a track of the release, with the song\'s rows, or a track or song no pair was found for. An apply writes paired songs only.',
    required: ['song', 'release', 'fields', 'credits'],
    properties: [
        new OA\Property(property: 'song', description: 'The local song; null for a release track no song pairs with', required: ['publicId', 'title', 'disc', 'track', 'lengthSeconds'], properties: [
            new OA\Property(property: 'publicId', type: 'string', maxLength: 21, minLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$'),
            new OA\Property(property: 'title', type: 'string'),
            new OA\Property(property: 'disc', description: 'The tagged disc number', type: 'integer', nullable: true),
            new OA\Property(property: 'track', description: 'The tagged track number', type: 'integer', nullable: true),
            new OA\Property(property: 'lengthSeconds', description: 'The length measured from the audio', type: 'number', nullable: true),
        ], type: 'object', nullable: true),
        new OA\Property(property: 'release', description: 'The release track; null for a song that pairs with no track', required: ['disc', 'position', 'number', 'title', 'lengthMs', 'artistCredit', 'recordingId'], properties: [
            new OA\Property(property: 'disc', description: 'The position of the track\'s medium in the release, from 1', type: 'integer'),
            new OA\Property(property: 'position', description: 'The track\'s position on its medium, from 1', type: 'integer'),
            new OA\Property(property: 'number', description: 'The track number as printed, such as "A2"', type: 'string'),
            new OA\Property(property: 'title', type: 'string'),
            new OA\Property(property: 'lengthMs', description: 'The length in milliseconds; null when MusicBrainz knows none', type: 'integer', nullable: true),
            new OA\Property(property: 'artistCredit', description: 'The track\'s artist credit as printed', type: 'string'),
            new OA\Property(property: 'recordingId', description: 'The recording on the track; null when the editions the candidate stands for disagree on it', type: 'string', nullable: true),
        ], type: 'object', nullable: true),
        new OA\Property(property: 'fields', description: 'The paired song\'s field rows; empty unless paired', type: 'array', items: new OA\Items(ref: new Model(type: MatchFieldRowResource::class))),
        new OA\Property(property: 'credits', description: 'The paired song\'s credits row; null unless paired', nullable: true, oneOf: [new OA\Schema(ref: new Model(type: MatchCreditsRowResource::class))]),
    ],
)]
final class MatchTrackResource extends AbstractResource
{
    /** @return TrackData */
    #[\Override]
    public static function from(mixed $source): array
    {
        assert($source instanceof MatchTrack);
        $songPublicId = $source->song?->publicId;

        return [
            'song' => $source->song === null ? null : self::song($source->song),
            'release' => $source->release === null ? null : self::release($source->release),
            'fields' => array_map(static fn (MatchFieldRow $row): array => MatchFieldRowResource::from($row, $songPublicId), $source->fields),
            'credits' => $source->credits === null ? null : MatchCreditsRowResource::from($source->credits, $songPublicId),
        ];
    }

    /** @return ComparedSongData */
    private static function song(ComparedSong $song): array
    {
        return [
            'publicId' => $song->publicId,
            'title' => $song->title,
            'disc' => $song->disc,
            'track' => $song->track,
            'lengthSeconds' => $song->lengthSeconds,
        ];
    }

    /** @return ReleaseTrackData */
    private static function release(ComparedReleaseTrack $track): array
    {
        return [
            'disc' => $track->disc,
            'position' => $track->position,
            'number' => $track->number,
            'title' => $track->title,
            'lengthMs' => $track->lengthMs,
            'artistCredit' => $track->artistCredit,
            'recordingId' => $track->recordingId,
        ];
    }
}
