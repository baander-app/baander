<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use App\Shared\Domain\Model\PublicId;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'publicId' => new OA\Schema(type: 'string', minLength: PublicId::LENGTH, maxLength: PublicId::LENGTH, pattern: PublicId::SCHEMA_PATTERN),
        'number' => new OA\Schema(type: 'integer'),
        'name' => new OA\Schema(type: 'string', nullable: true),
        'ownedCount' => new OA\Schema(type: 'integer'),
        'totalCount' => new OA\Schema(type: 'integer'),
        'watchedCount' => new OA\Schema(type: 'integer'),
    ],
    required: ['publicId', 'number', 'name', 'ownedCount', 'totalCount', 'watchedCount'],
    component: 'TvSeasonResource',
)]
final class TvSeasonResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert(is_array($source));

        return array_intersect_key($source, array_flip(['publicId', 'number', 'name', 'ownedCount', 'totalCount', 'watchedCount']));
    }
}
