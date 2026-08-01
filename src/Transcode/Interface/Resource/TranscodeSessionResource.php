<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use App\Transcode\Domain\Model\TranscodeSession;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'TranscodeSessionResource',
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid', description: 'Session UUID'),
        new OA\Property(property: 'publicId', type: 'string', format: 'uuid', description: 'Public identifier'),
        new OA\Property(property: 'userId', type: 'string', format: 'uuid', description: 'User UUID'),
        new OA\Property(property: 'jobId', type: 'string', format: 'uuid', description: 'Transcode job UUID'),
        new OA\Property(property: 'videoId', type: 'string', format: 'uuid', description: 'Video UUID'),
        new OA\Property(property: 'state', type: 'string', enum: ['pending', 'preparing', 'active', 'paused', 'completed', 'failed', 'cancelled'], description: 'Session state'),
        new OA\Property(property: 'priority', type: 'string', enum: ['critical', 'high', 'normal', 'low', 'bulk'], description: 'Session priority'),
        new OA\Property(property: 'audioProfile', type: 'object', description: 'Audio profile configuration'),
        new OA\Property(property: 'currentSegmentIndex', type: 'integer', nullable: true, description: 'Current segment being processed'),
        new OA\Property(property: 'wallClockOffset', type: 'number', nullable: true, description: 'Wall clock offset'),
        new OA\Property(property: 'metrics', type: 'object', nullable: true, description: 'Session metrics'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', description: 'Creation timestamp'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time', description: 'Last update timestamp'),
    ],
)]
final class TranscodeSessionResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof TranscodeSession);

        return [
            'uuid' => $source->getId()->toString(),
            'publicId' => $source->getPublicId()->toString(),
            'userId' => $source->getUserId()->toString(),
            'jobId' => $source->getJobId()->toString(),
            'videoId' => $source->getVideoId()->toString(),
            'state' => $source->getSessionState()->value,
            'priority' => $source->getPriority()->value,
            'audioProfile' => $source->getAudioProfile()->jsonSerialize(),
            'currentSegmentIndex' => $source->getCurrentSegmentIndex(),
            'wallClockOffset' => $source->getWallClockOffset(),
            'metrics' => $source->getMetrics(),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $source->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
