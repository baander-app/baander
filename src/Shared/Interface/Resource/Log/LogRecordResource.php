<?php

declare(strict_types=1);

namespace App\Shared\Interface\Resource\Log;

use App\Shared\Interface\Resource\AbstractResource;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    required: ['id', 'sourceId', 'timestamp', 'level', 'channel', 'message', 'context', 'extra', 'raw', 'correlationId', 'node', 'service', 'stream', 'truncated', 'format'],
    properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'sourceId', type: 'string'),
        new OA\Property(property: 'timestamp', type: 'string'),
        new OA\Property(property: 'level', type: 'string'),
        new OA\Property(property: 'channel', type: 'string', nullable: true),
        new OA\Property(property: 'message', type: 'string'),
        new OA\Property(property: 'context', oneOf: [new OA\Schema(type: 'object', additionalProperties: true), new OA\Schema(type: 'array', items: new OA\Items())]),
        new OA\Property(property: 'extra', oneOf: [new OA\Schema(type: 'object', additionalProperties: true), new OA\Schema(type: 'array', items: new OA\Items())]),
        new OA\Property(property: 'raw', type: 'string'),
        new OA\Property(property: 'correlationId', type: 'string', nullable: true),
        new OA\Property(property: 'node', type: 'string'),
        new OA\Property(property: 'service', type: 'string', nullable: true),
        new OA\Property(property: 'stream', type: 'string', nullable: true),
        new OA\Property(property: 'truncated', type: 'boolean'),
        new OA\Property(property: 'format', type: 'string'),
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
