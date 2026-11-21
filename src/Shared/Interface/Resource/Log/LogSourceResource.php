<?php

declare(strict_types=1);

namespace App\Shared\Interface\Resource\Log;

use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'id' => new OA\Schema(type: 'string'),
        'node' => new OA\Schema(type: 'string'),
        'name' => new OA\Schema(type: 'string'),
        'service' => new OA\Schema(type: 'string', nullable: true),
        'kind' => new OA\Schema(type: 'string'),
        'format' => new OA\Schema(type: 'string'),
        'size' => new OA\Schema(type: 'integer'),
        'status' => new OA\Schema(type: 'string'),
        'available' => new OA\Schema(type: 'boolean'),
        'lastCollectedAt' => new OA\Schema(type: 'string', nullable: true),
        'retainedFrom' => new OA\Schema(type: 'string', nullable: true),
        'issues' => new OA\Schema(
            type: 'array',
            items: new OA\Schema(type: 'string'),
        ),
        'stream' => new OA\Schema(type: 'string', nullable: true),
        'archive' => new OA\Schema(type: 'string', nullable: true),
        'member' => new OA\Schema(type: 'string', nullable: true),
        'container' => new OA\Schema(type: 'string', nullable: true),
        'error' => new OA\Schema(type: 'string', nullable: true),
    ],
    required: [
        'id',
        'node',
        'name',
        'service',
        'kind',
        'format',
        'size',
        'status',
        'available',
        'lastCollectedAt',
        'retainedFrom',
        'issues',
    ],
)]
final class LogSourceResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        $data = is_array($source) ? $source : [];

        return array_intersect_key($data, array_flip(['id', 'node', 'name', 'service', 'kind', 'format', 'size', 'status', 'available', 'lastCollectedAt', 'retainedFrom', 'issues', 'stream', 'archive', 'member', 'container', 'error']));
    }
}
