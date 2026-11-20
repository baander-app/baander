<?php

declare(strict_types=1);

namespace App\Shared\Interface\Resource\Log;

use App\Shared\Interface\Resource\AbstractResource;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    required: ['columns', 'rows', 'truncated', 'snapshot', 'coverage'],
    properties: [
        new OA\Property(property: 'columns', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'rows', type: 'array', items: new OA\Items(type: 'array', items: new OA\Items(anyOf: [new OA\Schema(type: 'string', nullable: true), new OA\Schema(type: 'number', nullable: true), new OA\Schema(type: 'boolean', nullable: true)]))),
        new OA\Property(property: 'truncated', type: 'boolean'),
        new OA\Property(property: 'snapshot', ref: new Model(type: LogSnapshotResource::class)),
        new OA\Property(property: 'coverage', ref: new Model(type: LogCoverageResource::class)),
    ],
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
