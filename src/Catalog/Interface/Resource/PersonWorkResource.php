<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'kind' => new OA\Schema(type: 'string', enum: ['movie', 'series', 'episode', 'song', 'album']),
        'publicId' => new OA\Schema(type: 'string', minLength: PublicId::LENGTH, maxLength: PublicId::LENGTH, pattern: PublicId::SCHEMA_PATTERN),
        'title' => new OA\Schema(type: 'string'),
        'roles' => new OA\Schema(type: 'array', items: new OA\Schema(ref: CreditRoleResource::class)),
    ],
    required: ['kind', 'publicId', 'title', 'roles'],
    component: 'PersonWorkResource',
)]
final class PersonWorkResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert(is_array($source));

        return array_intersect_key($source, array_flip(['kind', 'publicId', 'title', 'roles']));
    }
}
