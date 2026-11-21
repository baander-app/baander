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
        'name' => new OA\Schema(type: 'string'),
        'overview' => new OA\Schema(type: 'string', nullable: true),
        'posterUrl' => new OA\Schema(type: 'string', nullable: true),
    ],
    required: ['publicId', 'name', 'overview', 'posterUrl'],
    component: 'TvSeriesResource',
)]
final class TvSeriesResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert(is_array($source));

        return array_intersect_key($source, array_flip(['publicId', 'name', 'overview', 'posterUrl']));
    }
}
