<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use App\Transcode\Application\DTO\TranscodeJobDto;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'TranscodeMetricsResource',
    properties: [
        new OA\Property(property: 'publicId', type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$', description: 'Public identifier'),
        new OA\Property(property: 'videoId', type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$', description: 'Video public identifier'),
        new OA\Property(property: 'qualityTier', type: 'string', description: 'Quality tier name'),
        new OA\Property(property: 'status', type: 'string', description: 'Job status'),
        new OA\Property(property: 'progress', type: 'number', description: 'Progress from 0 to 1'),
        new OA\Property(property: 'totalSegments', type: 'integer', description: 'Total segments'),
        new OA\Property(property: 'completedSegments', type: 'integer', description: 'Completed segments'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', description: 'Creation timestamp'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time', description: 'Last update timestamp'),
    ],
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
