<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use App\Transcode\Application\DTO\TranscodeJobDto;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'TranscodeMetricsResource',
    properties: [
        new OA\Property(property: 'publicId', description: 'Public identifier', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'videoId', description: 'Video public identifier', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'qualityTier', description: 'Quality tier name', type: 'string'),
        new OA\Property(property: 'status', description: 'Job status', type: 'string'),
        new OA\Property(property: 'progress', description: 'Progress from 0 to 1', type: 'number'),
        new OA\Property(property: 'totalSegments', description: 'Total segments', type: 'integer'),
        new OA\Property(property: 'completedSegments', description: 'Completed segments', type: 'integer'),
        new OA\Property(property: 'createdAt', description: 'Creation timestamp', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updatedAt', description: 'Last update timestamp', type: 'string', format: 'date-time'),
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
