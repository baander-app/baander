<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'role' => new OA\Schema(type: 'string'),
        'job' => new OA\Schema(type: 'string'),
        'characterName' => new OA\Schema(type: 'string', nullable: true),
        'department' => new OA\Schema(type: 'string', nullable: true),
        'creditedName' => new OA\Schema(type: 'string', nullable: true),
        'billingOrder' => new OA\Schema(type: 'integer', nullable: true),
    ],
    required: ['role', 'job', 'characterName', 'department', 'creditedName', 'billingOrder'],
    component: 'CreditRoleResource',
)]
final class CreditRoleResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert(is_array($source));

        return array_intersect_key($source, array_flip(['role', 'job', 'characterName', 'department', 'creditedName', 'billingOrder']));
    }
}
