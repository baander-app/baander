<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use App\Transcode\Application\DTO\TranscodeSessionDto;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'TranscodeSessionResource',
    properties: [
        new OA\Property(property: 'publicId', description: 'Public identifier', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'userId', description: 'User public identifier', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'jobId', description: 'Transcode job public identifier', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'videoId', description: 'Video public identifier', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'state', description: 'Session state', type: 'string', enum: ['pending', 'preparing', 'active', 'paused', 'completed', 'failed', 'cancelled']),
        new OA\Property(property: 'priority', description: 'Session priority', type: 'string', enum: ['critical', 'high', 'normal', 'low', 'bulk']),
        new OA\Property(property: 'audioProfile', description: 'Audio profile configuration', type: 'object'),
        new OA\Property(property: 'currentSegmentIndex', description: 'Current segment being processed', type: 'integer', nullable: true),
        new OA\Property(property: 'wallClockOffset', description: 'Wall clock offset', type: 'number', nullable: true),
        new OA\Property(property: 'playback', description: 'Current durable execution generation, readiness, desired position/status and signed manifest URL', type: 'object', nullable: true),
        new OA\Property(property: 'metrics', description: 'Session metrics', type: 'object', nullable: true),
        new OA\Property(property: 'createdAt', description: 'Creation timestamp', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updatedAt', description: 'Last update timestamp', type: 'string', format: 'date-time'),
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
            'wallClockOffset', 'metrics', 'createdAt', 'updatedAt', 'playback',
        ]));
    }
}
