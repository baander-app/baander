<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use App\Transcode\Application\DTO\TranscodeJobDto;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'publicId' => new OA\Schema(
            description: 'Public identifier',
            type: 'string',
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
        ),
        'videoId' => new OA\Schema(
            description: 'Video public identifier',
            type: 'string',
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
        ),
        'qualityTier' => new OA\Schema(description: 'Quality tier name', type: 'string'),
        'status' => new OA\Schema(description: 'Job status', type: 'string'),
        'progress' => new OA\Schema(description: 'Progress from 0 to 1', type: 'number'),
        'totalSegments' => new OA\Schema(description: 'Total segments', type: 'integer'),
        'completedSegments' => new OA\Schema(description: 'Completed segments', type: 'integer'),
        'createdAt' => new OA\Schema(
            description: 'Creation timestamp',
            type: 'string',
            format: 'date-time',
        ),
        'updatedAt' => new OA\Schema(
            description: 'Last update timestamp',
            type: 'string',
            format: 'date-time',
        ),
    ],
    required: [
        'publicId',
        'videoId',
        'qualityTier',
        'status',
        'progress',
        'totalSegments',
        'completedSegments',
        'createdAt',
        'updatedAt',
    ],
    component: 'TranscodeMetricsResource',
)]
final class TranscodeMetricsResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof TranscodeJobDto);

        return array_intersect_key($source->toArray(), array_flip([
            'publicId', 'videoId', 'qualityTier', 'status',
            'progress', 'totalSegments', 'completedSegments', 'createdAt',
            'updatedAt',
        ]));
    }
}
