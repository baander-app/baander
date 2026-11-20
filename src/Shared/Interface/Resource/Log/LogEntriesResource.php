<?php

declare(strict_types=1);

namespace App\Shared\Interface\Resource\Log;

use App\Shared\Interface\Resource\AbstractResource;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    required: ['items', 'nextCursor', 'snapshot', 'coverage'],
    properties: [
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: new Model(type: LogRecordResource::class))),
        new OA\Property(property: 'nextCursor', type: 'string', nullable: true),
        new OA\Property(property: 'snapshot', ref: new Model(type: LogSnapshotResource::class)),
        new OA\Property(property: 'coverage', ref: new Model(type: LogCoverageResource::class)),
    ],
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
