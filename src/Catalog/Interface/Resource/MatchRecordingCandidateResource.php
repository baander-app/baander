<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Application\Query\MusicBrainzMatch\RecordingCandidate;
use App\Catalog\Application\Query\MusicBrainzMatch\RecordingCandidateRelease;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

/** A MusicBrainz recording a song may match, as the candidates route lists it. */
#[OA\Schema(
    description: 'A MusicBrainz recording a song may match, with the releases it appears on',
    type: 'object',
    properties: [
        'id' => new OA\Schema(
            description: 'The recording\'s MusicBrainz ID, which the comparison takes as its candidate',
            type: 'string',
        ),
        'score' => new OA\Schema(
            description: 'How well the recording fits the song, by title, artist and length',
            type: 'integer',
            minimum: 0,
            maximum: 100,
        ),
        'title' => new OA\Schema(type: 'string'),
        'artistCredit' => new OA\Schema(
            description: 'The recording\'s artist credit as printed, such as "Post Malone feat. Luke Combs"',
            type: 'string',
        ),
        'lengthMs' => new OA\Schema(
            description: 'The length in milliseconds; null when MusicBrainz does not know it',
            type: 'integer',
            nullable: true,
        ),
        'disambiguation' => new OA\Schema(type: 'string', nullable: true),
        'releases' => new OA\Schema(
            description: 'The releases the recording appears on, at most 25',
            type: 'array',
            items: new OA\Schema(
                type: 'object',
                properties: [
                    'id' => new OA\Schema(description: 'The release\'s MusicBrainz ID', type: 'string'),
                    'title' => new OA\Schema(type: 'string'),
                    'date' => new OA\Schema(
                        description: 'The release date: YYYY, YYYY-MM or YYYY-MM-DD',
                        type: 'string',
                        nullable: true,
                    ),
                    'country' => new OA\Schema(
                        description: 'An ISO 3166-1 code, or XW for worldwide',
                        type: 'string',
                        nullable: true,
                    ),
                    'status' => new OA\Schema(
                        description: 'Such as Official or Promotion',
                        type: 'string',
                        nullable: true,
                    ),
                    'trackCount' => new OA\Schema(
                        description: 'The tracks on the whole release, when MusicBrainz lists them',
                        type: 'integer',
                        nullable: true,
                    ),
                ],
                required: ['id', 'title', 'date', 'country', 'status', 'trackCount'],
            ),
        ),
    ],
    required: ['id', 'score', 'title', 'artistCredit', 'lengthMs', 'disambiguation', 'releases'],
    component: 'MatchRecordingCandidateResource',
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
