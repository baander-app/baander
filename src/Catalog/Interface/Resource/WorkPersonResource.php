<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'publicId' => new OA\Schema(type: 'string', minLength: PublicId::LENGTH, maxLength: PublicId::LENGTH, pattern: PublicId::SCHEMA_PATTERN),
        'personPublicId' => new OA\Schema(type: 'string', minLength: PublicId::LENGTH, maxLength: PublicId::LENGTH, pattern: PublicId::SCHEMA_PATTERN),
        'name' => new OA\Schema(type: 'string'),
        'profileUrl' => new OA\Schema(type: 'string', nullable: true),
        'role' => new OA\Schema(ref: CreditRoleResource::class),
    ],
    required: ['publicId', 'personPublicId', 'name', 'profileUrl', 'role'],
    component: 'WorkPersonResource',
)]
final class WorkPersonResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert(is_array($source));

        return array_intersect_key($source, array_flip(['publicId', 'personPublicId', 'name', 'profileUrl', 'role']));
    }
}
