<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use App\Catalog\Domain\ValueObject\VideoName\ExtraKind;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'kind' => new OA\Schema(type: 'string', enum: ['series', 'movie']),
        'publicId' => new OA\Schema(type: 'string'),
        'view' => new OA\Schema(type: 'string', enum: ['choices']),
        'collection' => new OA\Schema(type: 'string', enum: ['all', 'orderings', 'episodes', 'files']),
        'provider' => new OA\Schema(type: 'string', nullable: true, enum: VideoMatchCandidatesResource::PROVIDERS),
        'currentOrdering' => new OA\Schema(type: 'string', nullable: true),
        'orderingProblem' => new OA\Schema(type: 'string', nullable: true, enum: ['disabled', 'not_configured', 'unavailable', 'login_failed', 'refused', 'unmatched', 'not_found']),
        'orderings' => new OA\Schema(type: 'array', items: new OA\Schema(type: 'object', properties: [
            'id' => new OA\Schema(type: 'string'),
            'label' => new OA\Schema(type: 'string'),
            'kind' => new OA\Schema(type: 'string'),
        ], required: ['id', 'label', 'kind'])),
        'episodes' => new OA\Schema(type: 'object', nullable: true, properties: [
            'items' => new OA\Schema(type: 'array', items: new OA\Schema(type: 'object', properties: [
                    'publicId' => new OA\Schema(type: 'string'),
                    'seasonNumber' => new OA\Schema(type: 'integer', nullable: true),
                    'episodeNumber' => new OA\Schema(type: 'integer', nullable: true),
                    'title' => new OA\Schema(type: 'string'),
                    'absoluteNumber' => new OA\Schema(type: 'integer', nullable: true),
                    'airDate' => new OA\Schema(type: 'string', nullable: true),
                ], required: ['publicId', 'seasonNumber', 'episodeNumber', 'title', 'absoluteNumber', 'airDate'])),
            'total' => new OA\Schema(type: 'integer'),
            'page' => new OA\Schema(type: 'integer'),
            'perPage' => new OA\Schema(type: 'integer'),
            'hasNextPage' => new OA\Schema(type: 'boolean'),
        ], required: ['items', 'total', 'page', 'perPage', 'hasNextPage']),
        'files' => new OA\Schema(type: 'object', nullable: true, properties: [
            'items' => new OA\Schema(type: 'array', items: new OA\Schema(type: 'object', properties: [
                    'publicId' => new OA\Schema(type: 'string'),
                    'path' => new OA\Schema(type: 'string'),
                    'assigned' => new OA\Schema(type: 'boolean'),
                    'episodePublicIds' => new OA\Schema(type: 'array', items: new OA\Schema(type: 'string')),
                    'extraKind' => new OA\Schema(type: 'string', nullable: true, enum: VideoMatchChoicesResource::EXTRA_KINDS),
                ], required: ['publicId', 'path', 'assigned', 'episodePublicIds', 'extraKind'])),
            'total' => new OA\Schema(type: 'integer'),
            'page' => new OA\Schema(type: 'integer'),
            'perPage' => new OA\Schema(type: 'integer'),
            'hasNextPage' => new OA\Schema(type: 'boolean'),
        ], required: ['items', 'total', 'page', 'perPage', 'hasNextPage']),
    ],
    required: ['kind', 'publicId', 'view', 'collection', 'provider', 'currentOrdering', 'orderingProblem', 'orderings', 'episodes', 'files'],
    component: 'VideoMatchChoicesResource',
)]
final class VideoMatchChoicesResource extends AbstractResource
{
    public const array EXTRA_KINDS = [ExtraKind::class];

    /** @return array<string, mixed> */
    public static function from(mixed $source): array
    {
        assert(is_array($source));

        return $source;
    }
}
