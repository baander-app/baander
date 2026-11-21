<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Application\Query\MusicBrainzMatch\ReleaseCandidate;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

/** A MusicBrainz release an album may match, as the candidates route lists it. */
#[OA\Schema(
    description: 'A MusicBrainz release (one edition of an album) an album may match',
    type: 'object',
    properties: [
        'id' => new OA\Schema(
            description: 'The release\'s MusicBrainz ID, which the comparison takes as its candidate',
            type: 'string',
        ),
        'score' => new OA\Schema(
            description: 'How well the release fits the album, by title, artist, track count and track lengths',
            type: 'integer',
            minimum: 0,
            maximum: 100,
        ),
        'title' => new OA\Schema(type: 'string'),
        'artistCredit' => new OA\Schema(
            description: 'The release\'s artist credit as printed, such as "Post Malone"',
            type: 'string',
        ),
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
        'disambiguation' => new OA\Schema(
            description: 'MusicBrainz\'s comment that tells the edition apart, such as "clean"',
            type: 'string',
            nullable: true,
        ),
        'barcode' => new OA\Schema(type: 'string', nullable: true),
        'labels' => new OA\Schema(
            description: 'The label names, each once',
            type: 'array',
            items: new OA\Schema(type: 'string'),
        ),
        'catalogNumbers' => new OA\Schema(
            description: 'The catalogue numbers on those labels, each once',
            type: 'array',
            items: new OA\Schema(type: 'string'),
        ),
        'formats' => new OA\Schema(
            description: 'The media formats, such as "CD" or "Digital Media", each once',
            type: 'array',
            items: new OA\Schema(type: 'string'),
        ),
        'trackCount' => new OA\Schema(description: 'The tracks on all media together', type: 'integer'),
        'mediumCount' => new OA\Schema(description: 'The media (discs) of the release', type: 'integer'),
        'releaseGroupId' => new OA\Schema(
            description: 'The MusicBrainz ID of the release group, which an album stores as its mbid',
            type: 'string',
            nullable: true,
        ),
        'releaseGroupType' => new OA\Schema(
            description: 'Such as Album, Single or EP',
            type: 'string',
            nullable: true,
        ),
        'hasFrontCover' => new OA\Schema(
            description: 'Whether the Cover Art Archive holds a front cover for the release',
            type: 'boolean',
        ),
    ],
    required: [
        'id',
        'score',
        'title',
        'artistCredit',
        'date',
        'country',
        'status',
        'disambiguation',
        'barcode',
        'labels',
        'catalogNumbers',
        'formats',
        'trackCount',
        'mediumCount',
        'releaseGroupId',
        'releaseGroupType',
        'hasFrontCover',
    ],
    component: 'MatchReleaseCandidateResource',
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
