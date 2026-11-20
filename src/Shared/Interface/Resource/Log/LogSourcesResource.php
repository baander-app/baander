<?php

declare(strict_types=1);

namespace App\Shared\Interface\Resource\Log;

use App\Shared\Interface\Resource\AbstractResource;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    required: ['items', 'nodes', 'channels', 'coverage'],
    properties: [
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: new Model(type: LogSourceResource::class))),
        new OA\Property(property: 'nodes', type: 'array', items: new OA\Items(ref: new Model(type: LogNodeResource::class))),
        new OA\Property(property: 'channels', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'coverage', ref: new Model(type: LogCoverageResource::class)),
    ],
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
