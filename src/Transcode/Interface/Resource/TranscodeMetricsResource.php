<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use App\Transcode\Domain\Model\TranscodeJob;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'TranscodeMetricsResource',
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid', description: 'Job UUID'),
        new OA\Property(property: 'publicId', type: 'string', format: 'uuid', description: 'Public identifier'),
        new OA\Property(property: 'videoId', type: 'string', format: 'uuid', description: 'Video UUID'),
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
        assert($source instanceof TranscodeJob);

        return [
            'uuid' => $source->getId()->toString(),
            'publicId' => $source->getPublicId()->toString(),
            'videoId' => $source->getVideoId()->toString(),
            'qualityTier' => $source->getQualityTierName(),
            'status' => $source->getStatus()->value,
            'progress' => round($source->getProgress(), 2),
            'totalSegments' => $source->getTotalSegments(),
            'completedSegments' => $source->getCompletedSegments(),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $source->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
