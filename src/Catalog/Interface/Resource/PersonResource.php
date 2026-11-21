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
        'profileUrl' => new OA\Schema(type: 'string', nullable: true),
        'biography' => new OA\Schema(type: 'string', nullable: true),
        'aliases' => new OA\Schema(type: 'array', items: new OA\Schema(type: 'string')),
        'birthDate' => new OA\Schema(type: 'string', nullable: true),
        'deathDate' => new OA\Schema(type: 'string', nullable: true),
        'birthplace' => new OA\Schema(type: 'string', nullable: true),
        'sex' => new OA\Schema(type: 'string', nullable: true),
        'gender' => new OA\Schema(type: 'string', nullable: true),
        'age' => new OA\Schema(type: 'integer', nullable: true),
        'isDeceased' => new OA\Schema(type: 'boolean'),
        'movieCount' => new OA\Schema(type: 'integer'),
        'seriesCount' => new OA\Schema(type: 'integer'),
        'episodeCount' => new OA\Schema(type: 'integer'),
        'songCount' => new OA\Schema(type: 'integer'),
        'albumCount' => new OA\Schema(type: 'integer'),
    ],
    required: ['publicId', 'name', 'profileUrl', 'biography', 'aliases', 'birthDate', 'deathDate', 'birthplace', 'sex', 'gender', 'age', 'isDeceased', 'movieCount', 'seriesCount', 'episodeCount', 'songCount', 'albumCount'],
    component: 'PersonResource',
)]
final class PersonResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert(is_array($source));

        return array_intersect_key($source, array_flip(['publicId', 'name', 'profileUrl', 'biography', 'aliases', 'birthDate', 'deathDate', 'birthplace', 'sex', 'gender', 'age', 'isDeceased', 'movieCount', 'seriesCount', 'episodeCount', 'songCount', 'albumCount']));
    }
}
