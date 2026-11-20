<?php

declare(strict_types=1);

namespace App\Shared\Interface\Resource\Log;

use App\Shared\Interface\Resource\AbstractResource;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    required: ['id', 'createdAt', 'expiresAt', 'filters', 'nodeSnapshots'],
    properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'createdAt', type: 'string'),
        new OA\Property(property: 'expiresAt', type: 'string'),
        new OA\Property(property: 'filters', type: 'string'),
        new OA\Property(property: 'nodeSnapshots', type: 'object', additionalProperties: true),
    ],
)]
final class LogSnapshotResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        $data = is_array($source) ? $source : [];

        return array_intersect_key($data, array_flip(['id', 'createdAt', 'expiresAt', 'filters', 'nodeSnapshots']));
    }
}
