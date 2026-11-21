<?php

declare(strict_types=1);

namespace App\Shared\Interface\Resource\Log;

use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'id' => new OA\Schema(type: 'string'),
        'status' => new OA\Schema(
            type: 'string',
            enum: ['healthy', 'unreachable'],
        ),
    ],
    required: ['id', 'status'],
)]
final class LogNodeResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        $data = is_array($source) ? $source : [];

        return array_intersect_key($data, array_flip(['id', 'status']));
    }
}
