<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Application\Query\MusicBrainzMatch\ReleaseCandidate;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

/** A MusicBrainz release an album may match, as the candidates route lists it. */
#[OA\Schema(
    schema: 'MatchReleaseCandidateResource',
    description: 'A MusicBrainz release (one edition of an album) an album may match',
    required: ['id', 'score', 'title', 'artistCredit', 'date', 'country', 'status', 'disambiguation', 'barcode', 'labels', 'catalogNumbers', 'formats', 'trackCount', 'mediumCount', 'releaseGroupId', 'releaseGroupType', 'hasFrontCover'],
    properties: [
        new OA\Property(property: 'id', description: 'The release\'s MusicBrainz ID, which the comparison takes as its candidate', type: 'string'),
        new OA\Property(property: 'score', description: 'How well the release fits the album, by title, artist, track count and track lengths', type: 'integer', maximum: 100, minimum: 0),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'artistCredit', description: 'The release\'s artist credit as printed, such as "Post Malone"', type: 'string'),
        new OA\Property(property: 'date', description: 'The release date: YYYY, YYYY-MM or YYYY-MM-DD', type: 'string', nullable: true),
        new OA\Property(property: 'country', description: 'An ISO 3166-1 code, or XW for worldwide', type: 'string', nullable: true),
        new OA\Property(property: 'status', description: 'Such as Official or Promotion', type: 'string', nullable: true),
        new OA\Property(property: 'disambiguation', description: 'MusicBrainz\'s comment that tells the edition apart, such as "clean"', type: 'string', nullable: true),
        new OA\Property(property: 'barcode', type: 'string', nullable: true),
        new OA\Property(property: 'labels', description: 'The label names, each once', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'catalogNumbers', description: 'The catalogue numbers on those labels, each once', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'formats', description: 'The media formats, such as "CD" or "Digital Media", each once', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'trackCount', description: 'The tracks on all media together', type: 'integer'),
        new OA\Property(property: 'mediumCount', description: 'The media (discs) of the release', type: 'integer'),
        new OA\Property(property: 'releaseGroupId', description: 'The MusicBrainz ID of the release group, which an album stores as its mbid', type: 'string', nullable: true),
        new OA\Property(property: 'releaseGroupType', description: 'Such as Album, Single or EP', type: 'string', nullable: true),
        new OA\Property(property: 'hasFrontCover', description: 'Whether the Cover Art Archive holds a front cover for the release', type: 'boolean'),
    ],
)]
final class MatchReleaseCandidateResource extends AbstractResource
{
    /** @return array<string, mixed> */
    #[\Override]
    public static function from(mixed $source): array
    {
        assert($source instanceof ReleaseCandidate);

        return [
            'id' => $source->id,
            'score' => $source->score,
            'title' => $source->title,
            'artistCredit' => $source->artistCredit,
            'date' => $source->date,
            'country' => $source->country,
            'status' => $source->status,
            'disambiguation' => $source->disambiguation,
            'barcode' => $source->barcode,
            'labels' => $source->labels,
            'catalogNumbers' => $source->catalogNumbers,
            'formats' => $source->formats,
            'trackCount' => $source->trackCount,
            'mediumCount' => $source->mediumCount,
            'releaseGroupId' => $source->releaseGroupId,
            'releaseGroupType' => $source->releaseGroupType,
            'hasFrontCover' => $source->hasFrontCover,
        ];
    }
}
