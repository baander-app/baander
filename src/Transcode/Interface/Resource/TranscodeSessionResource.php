<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use App\Transcode\Application\DTO\TranscodeSessionDto;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'TranscodeSessionResource',
    properties: [
        new OA\Property(property: 'publicId', type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$', description: 'Public identifier'),
        new OA\Property(property: 'userId', type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$', description: 'User public identifier'),
        new OA\Property(property: 'jobId', type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$', description: 'Transcode job public identifier'),
        new OA\Property(property: 'videoId', type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$', description: 'Video public identifier'),
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
        assert($source instanceof TranscodeSessionDto);

        return array_intersect_key($source->toArray(), array_flip([
            'publicId', 'userId', 'jobId', 'videoId',
            'state', 'priority', 'audioProfile', 'currentSegmentIndex',
            'wallClockOffset', 'metrics', 'createdAt', 'updatedAt',
        ]));
    }
}
