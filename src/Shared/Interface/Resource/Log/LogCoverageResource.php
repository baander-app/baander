<?php

declare(strict_types=1);

namespace App\Shared\Interface\Resource\Log;

use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'complete' => new OA\Schema(type: 'boolean'),
        'reasons' => new OA\Schema(
            type: 'array',
            items: new OA\Schema(type: 'string'),
        ),
    ],
    required: ['complete', 'reasons'],
)]
final class LogCoverageResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        $data = is_array($source) ? $source : [];

        return array_intersect_key($data, array_flip(['complete', 'reasons']));
    }
}
