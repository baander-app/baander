<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'items' => new OA\Schema(type: 'array', items: new OA\Schema(
            type: 'object',
            properties: [
                'kind' => new OA\Schema(type: 'string', enum: ['series', 'movie', 'file']),
                'publicId' => new OA\Schema(type: 'string'),
                'ownerKind' => new OA\Schema(type: 'string', enum: ['series', 'movie']),
                'ownerPublicId' => new OA\Schema(type: 'string'),
                'title' => new OA\Schema(type: 'string'),
                'path' => new OA\Schema(type: 'string', nullable: true),
                'libraryPublicId' => new OA\Schema(type: 'string'),
                'libraryName' => new OA\Schema(type: 'string'),
                'reason' => new OA\Schema(type: 'string'),
                'checkedAt' => new OA\Schema(type: 'string', format: 'date-time'),
                'bestScore' => new OA\Schema(type: 'integer', nullable: true),
            ],
            required: ['kind', 'publicId', 'ownerKind', 'ownerPublicId', 'title', 'path', 'libraryPublicId', 'libraryName', 'reason', 'checkedAt', 'bestScore'],
        )),
        'nextCursor' => new OA\Schema(type: 'string', nullable: true),
        'hasNextPage' => new OA\Schema(type: 'boolean'),
        'perPage' => new OA\Schema(type: 'integer'),
        'pausedProviders' => new OA\Schema(type: 'array', items: new OA\Schema(
            type: 'object',
            properties: [
                'provider' => new OA\Schema(type: 'string', enum: ['tvdb', 'tmdb', 'local']),
                'retryAt' => new OA\Schema(type: 'string', format: 'date-time'),
                'failureKind' => new OA\Schema(type: 'string'),
            ],
            required: ['provider', 'retryAt', 'failureKind'],
        )),
    ],
    required: ['items', 'nextCursor', 'hasNextPage', 'perPage', 'pausedProviders'],
    component: 'VideoMatchReviewPageResource',
)]
final class VideoMatchReviewPageResource extends AbstractResource
{
    /** @return array<string, mixed> */
    public static function from(mixed $source): array
    {
        assert(is_array($source));

        return [
            'items' => $source['items'],
            'nextCursor' => $source['nextCursor'],
            'hasNextPage' => $source['hasNextPage'],
            'perPage' => $source['perPage'],
            'pausedProviders' => $source['pausedProviders'],
        ];
    }
}
