<?php

declare(strict_types=1);

namespace App\Shared\Interface\Resource\Log;

use App\Shared\Interface\Resource\AbstractResource;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    required: ['id', 'node', 'name', 'service', 'kind', 'format', 'size', 'status', 'available', 'lastCollectedAt', 'retainedFrom', 'issues'],
    properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'node', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'service', type: 'string', nullable: true),
        new OA\Property(property: 'kind', type: 'string'),
        new OA\Property(property: 'format', type: 'string'),
        new OA\Property(property: 'size', type: 'integer'),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'available', type: 'boolean'),
        new OA\Property(property: 'lastCollectedAt', type: 'string', nullable: true),
        new OA\Property(property: 'retainedFrom', type: 'string', nullable: true),
        new OA\Property(property: 'issues', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'stream', type: 'string', nullable: true),
        new OA\Property(property: 'archive', type: 'string', nullable: true),
        new OA\Property(property: 'member', type: 'string', nullable: true),
        new OA\Property(property: 'container', type: 'string', nullable: true),
        new OA\Property(property: 'error', type: 'string', nullable: true),
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
