<?php

declare(strict_types=1);

namespace App\Shared\Interface\Resource\Log;

use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'items' => new OA\Schema(
            type: 'array',
            items: new OA\Schema(ref: LogSourceResource::class),
        ),
        'nodes' => new OA\Schema(
            type: 'array',
            items: new OA\Schema(ref: LogNodeResource::class),
        ),
        'channels' => new OA\Schema(
            type: 'array',
            items: new OA\Schema(type: 'string'),
        ),
        'coverage' => new OA\Schema(ref: LogCoverageResource::class),
    ],
    required: ['items', 'nodes', 'channels', 'coverage'],
)]
final class LogSourcesResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        $data = is_array($source) ? $source : [];

        return [
            'items' => LogSourceResource::collection($data['items'] ?? []),
            'nodes' => LogNodeResource::collection($data['nodes'] ?? []),
            'channels' => $data['channels'] ?? [],
            'coverage' => LogCoverageResource::from($data['coverage'] ?? []),
        ];
    }
}
