<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use App\Transcode\Application\DTO\TranscodeSessionDto;
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
        'userId' => new OA\Schema(
            description: 'User public identifier',
            type: 'string',
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
        ),
        'jobId' => new OA\Schema(
            description: 'Transcode job public identifier',
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
        'state' => new OA\Schema(
            description: 'Session state',
            type: 'string',
            enum: ['pending', 'preparing', 'active', 'paused', 'completed', 'failed', 'cancelled'],
        ),
        'priority' => new OA\Schema(
            description: 'Session priority',
            type: 'string',
            enum: ['critical', 'high', 'normal', 'low', 'bulk'],
        ),
        'audioProfile' => new OA\Schema(description: 'Audio profile configuration', type: 'object'),
        'currentSegmentIndex' => new OA\Schema(
            description: 'Current segment being processed',
            type: 'integer',
            nullable: true,
        ),
        'wallClockOffset' => new OA\Schema(
            description: 'Wall clock offset',
            type: 'number',
            nullable: true,
        ),
        'playback' => new OA\Schema(
            description: 'Current durable execution generation, readiness, desired position/status and signed manifest URL',
            type: 'object',
            nullable: true,
        ),
        'metrics' => new OA\Schema(
            description: 'Session metrics',
            type: 'object',
            nullable: true,
        ),
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
        'userId',
        'jobId',
        'videoId',
        'state',
        'priority',
        'audioProfile',
        'createdAt',
        'updatedAt',
    ],
    component: 'TranscodeSessionResource',
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
