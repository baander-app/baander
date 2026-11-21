<?php

declare(strict_types=1);

namespace App\Shared\Interface\Resource\Log;

use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'columns' => new OA\Schema(
            type: 'array',
            items: new OA\Schema(type: 'string'),
        ),
        'rows' => new OA\Schema(
            type: 'array',
            items: new OA\Schema(
                type: 'array',
                items: new OA\Schema(
                    anyOf: [
                        new OA\Schema(type: 'string', nullable: true),
                        new OA\Schema(type: 'number', nullable: true),
                        new OA\Schema(type: 'boolean', nullable: true),
                    ],
                ),
            ),
        ),
        'truncated' => new OA\Schema(type: 'boolean'),
        'snapshot' => new OA\Schema(ref: LogSnapshotResource::class),
        'coverage' => new OA\Schema(ref: LogCoverageResource::class),
    ],
    required: ['columns', 'rows', 'truncated', 'snapshot', 'coverage'],
)]
final class LogSqlResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        $data = is_array($source) ? $source : [];

        return [
            'columns' => $data['columns'] ?? [],
            'rows' => $data['rows'] ?? [],
            'truncated' => $data['truncated'] ?? false,
            'snapshot' => LogSnapshotResource::from($data['snapshot'] ?? []),
            'coverage' => LogCoverageResource::from($data['coverage'] ?? []),
        ];
    }
}
