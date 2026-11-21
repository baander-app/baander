<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Domain\ReadModel\DirectorReadView;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DirectorResource',
    required: ['publicId', 'name', 'tmdbId', 'profileUrl', 'movieCount'],
    properties: [
        new OA\Property(property: 'publicId', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', minLength: 21, maxLength: 21),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'tmdbId', type: 'integer'),
        new OA\Property(property: 'profileUrl', type: 'string', nullable: true),
        new OA\Property(property: 'movieCount', type: 'integer'),
    ],
)]
final class DirectorResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof DirectorReadView);

        return [
            'publicId' => $source->publicId->toString(),
            'name' => $source->name,
            'tmdbId' => $source->tmdbId,
            'profileUrl' => $source->profileUrl,
            'movieCount' => $source->movieCount,
        ];
    }
}
