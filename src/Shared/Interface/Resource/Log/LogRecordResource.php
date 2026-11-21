<?php

declare(strict_types=1);

namespace App\Shared\Interface\Resource\Log;

use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'id' => new OA\Schema(type: 'string'),
        'sourceId' => new OA\Schema(type: 'string'),
        'timestamp' => new OA\Schema(type: 'string'),
        'level' => new OA\Schema(type: 'string'),
        'channel' => new OA\Schema(type: 'string', nullable: true),
        'message' => new OA\Schema(type: 'string'),
        'context' => new OA\Schema(
            oneOf: [
                new OA\Schema(type: 'object', additionalProperties: true),
                new OA\Schema(
                    type: 'array',
                    items: new OA\Schema(),
                ),
            ],
        ),
        'extra' => new OA\Schema(
            oneOf: [
                new OA\Schema(type: 'object', additionalProperties: true),
                new OA\Schema(
                    type: 'array',
                    items: new OA\Schema(),
                ),
            ],
        ),
        'raw' => new OA\Schema(type: 'string'),
        'correlationId' => new OA\Schema(type: 'string', nullable: true),
        'node' => new OA\Schema(type: 'string'),
        'service' => new OA\Schema(type: 'string', nullable: true),
        'stream' => new OA\Schema(type: 'string', nullable: true),
        'truncated' => new OA\Schema(type: 'boolean'),
        'format' => new OA\Schema(type: 'string'),
    ],
    required: [
        'id',
        'sourceId',
        'timestamp',
        'level',
        'channel',
        'message',
        'context',
        'extra',
        'raw',
        'correlationId',
        'node',
        'service',
        'stream',
        'truncated',
        'format',
    ],
)]
final class LogRecordResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        $data = is_array($source) ? $source : [];

        return array_intersect_key($data, array_flip(['id', 'sourceId', 'timestamp', 'level', 'channel', 'message', 'context', 'extra', 'raw', 'correlationId', 'node', 'service', 'stream', 'truncated', 'format']));
    }
}
