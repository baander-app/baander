<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Application\Query\MusicBrainzMatch\RecordingCandidate;
use App\Catalog\Application\Query\MusicBrainzMatch\RecordingCandidateRelease;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

/** A MusicBrainz recording a song may match, as the candidates route lists it. */
#[OA\Schema(
    schema: 'MatchRecordingCandidateResource',
    description: 'A MusicBrainz recording a song may match, with the releases it appears on',
    required: ['id', 'score', 'title', 'artistCredit', 'lengthMs', 'disambiguation', 'releases'],
    properties: [
        new OA\Property(property: 'id', description: 'The recording\'s MusicBrainz ID, which the comparison takes as its candidate', type: 'string'),
        new OA\Property(property: 'score', description: 'How well the recording fits the song, by title, artist and length', type: 'integer', maximum: 100, minimum: 0),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'artistCredit', description: 'The recording\'s artist credit as printed, such as "Post Malone feat. Luke Combs"', type: 'string'),
        new OA\Property(property: 'lengthMs', description: 'The length in milliseconds; null when MusicBrainz does not know it', type: 'integer', nullable: true),
        new OA\Property(property: 'disambiguation', type: 'string', nullable: true),
        new OA\Property(property: 'releases', description: 'The releases the recording appears on, at most 25', type: 'array', items: new OA\Items(
            required: ['id', 'title', 'date', 'country', 'status', 'trackCount'],
            properties: [
                new OA\Property(property: 'id', description: 'The release\'s MusicBrainz ID', type: 'string'),
                new OA\Property(property: 'title', type: 'string'),
                new OA\Property(property: 'date', description: 'The release date: YYYY, YYYY-MM or YYYY-MM-DD', type: 'string', nullable: true),
                new OA\Property(property: 'country', description: 'An ISO 3166-1 code, or XW for worldwide', type: 'string', nullable: true),
                new OA\Property(property: 'status', description: 'Such as Official or Promotion', type: 'string', nullable: true),
                new OA\Property(property: 'trackCount', description: 'The tracks on the whole release, when MusicBrainz lists them', type: 'integer', nullable: true),
            ],
            type: 'object',
        )),
    ],
)]
final class MatchRecordingCandidateResource extends AbstractResource
{
    /** @return array<string, mixed> */
    #[\Override]
    public static function from(mixed $source): array
    {
        assert($source instanceof RecordingCandidate);

        return [
            'id' => $source->id,
            'score' => $source->score,
            'title' => $source->title,
            'artistCredit' => $source->artistCredit,
            'lengthMs' => $source->lengthMs,
            'disambiguation' => $source->disambiguation,
            'releases' => array_map(self::release(...), $source->releases),
        ];
    }

    /** @return array<string, mixed> */
    private static function release(RecordingCandidateRelease $release): array
    {
        return [
            'id' => $release->id,
            'title' => $release->title,
            'date' => $release->date,
            'country' => $release->country,
            'status' => $release->status,
            'trackCount' => $release->trackCount,
        ];
    }
}
