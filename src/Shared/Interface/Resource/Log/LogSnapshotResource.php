<?php

declare(strict_types=1);

namespace App\Shared\Interface\Resource\Log;

use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'id' => new OA\Schema(type: 'string'),
        'createdAt' => new OA\Schema(type: 'string'),
        'expiresAt' => new OA\Schema(type: 'string'),
        'filters' => new OA\Schema(type: 'string'),
        'nodeSnapshots' => new OA\Schema(type: 'object', additionalProperties: true),
    ],
    required: ['id', 'createdAt', 'expiresAt', 'filters', 'nodeSnapshots'],
)]
final class LogSnapshotResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        $data = is_array($source) ? $source : [];

        return array_intersect_key($data, array_flip(['id', 'createdAt', 'expiresAt', 'filters', 'nodeSnapshots']));
    }
}
