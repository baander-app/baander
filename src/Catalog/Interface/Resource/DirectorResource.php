<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Domain\ReadModel\DirectorReadView;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'publicId' => new OA\Schema(
            type: 'string',
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
        ),
        'name' => new OA\Schema(type: 'string'),
        'tmdbId' => new OA\Schema(type: 'integer'),
        'profileUrl' => new OA\Schema(type: 'string', nullable: true),
        'movieCount' => new OA\Schema(type: 'integer'),
    ],
    required: ['publicId', 'name', 'tmdbId', 'profileUrl', 'movieCount'],
    component: 'DirectorResource',
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
