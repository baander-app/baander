<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use App\Transcode\Domain\Model\TranscodeJob;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'TranscodeJobResource',
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid', description: 'Job UUID'),
        new OA\Property(property: 'publicId', type: 'string', format: 'uuid', description: 'Public identifier'),
        new OA\Property(property: 'videoId', type: 'string', format: 'uuid', description: 'Video UUID'),
        new OA\Property(property: 'qualityTier', type: 'string', description: 'Quality tier name'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'in_progress', 'completed', 'failed', 'cancelled'], description: 'Job status'),
        new OA\Property(property: 'referenceCount', type: 'integer', description: 'Number of references'),
        new OA\Property(property: 'totalSegments', type: 'integer', description: 'Total segments to transcode'),
        new OA\Property(property: 'completedSegments', type: 'integer', description: 'Completed segments'),
        new OA\Property(property: 'progress', type: 'number', description: 'Progress from 0 to 1'),
        new OA\Property(property: 'videoCodec', type: 'string', nullable: true, description: 'Video codec'),
        new OA\Property(property: 'audioCodec', type: 'string', nullable: true, description: 'Audio codec'),
        new OA\Property(property: 'width', type: 'integer', nullable: true, description: 'Video width in pixels'),
        new OA\Property(property: 'height', type: 'integer', nullable: true, description: 'Video height in pixels'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', description: 'Creation timestamp'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time', description: 'Last update timestamp'),
    ],
)]
final class TranscodeJobResource extends AbstractResource
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
            'referenceCount' => $source->getReferenceCount(),
            'totalSegments' => $source->getTotalSegments(),
            'completedSegments' => $source->getCompletedSegments(),
            'progress' => $source->getProgress(),
            'videoCodec' => $source->getVideoCodec(),
            'audioCodec' => $source->getAudioCodec(),
            'width' => $source->getWidth(),
            'height' => $source->getHeight(),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $source->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
