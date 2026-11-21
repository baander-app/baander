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
            items: new OA\Schema(ref: LogRecordResource::class),
        ),
        'nextCursor' => new OA\Schema(type: 'string', nullable: true),
        'snapshot' => new OA\Schema(ref: LogSnapshotResource::class),
        'coverage' => new OA\Schema(ref: LogCoverageResource::class),
    ],
    required: ['items', 'nextCursor', 'snapshot', 'coverage'],
)]
final class LogEntriesResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        $data = is_array($source) ? $source : [];

        return [
            'items' => LogRecordResource::collection($data['items'] ?? []),
            'nextCursor' => $data['nextCursor'] ?? null,
            'snapshot' => LogSnapshotResource::from($data['snapshot'] ?? []),
            'coverage' => LogCoverageResource::from($data['coverage'] ?? []),
        ];
    }
}
