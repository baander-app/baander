<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Domain\Model\Genre;
use App\Catalog\Domain\ReadModel\GenreReadView;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'publicId' => new OA\Schema(
            description: 'Genre public ID',
            type: 'string',
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
        ),
        'name' => new OA\Schema(description: 'Genre name', type: 'string'),
        'slug' => new OA\Schema(description: 'URL-safe slug', type: 'string'),
        'parentId' => new OA\Schema(
            description: 'Parent genre public ID',
            type: 'string',
            nullable: true,
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
        ),
        'mbid' => new OA\Schema(
            description: 'MusicBrainz ID',
            type: 'string',
            nullable: true,
        ),
    ],
    required: ['publicId', 'name', 'slug'],
    component: 'GenreResource',
)]
final class GenreResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof Genre || $source instanceof GenreReadView);

        return [
            'publicId' => $source->getPublicId()->toString(),
            'name' => $source->getName(),
            'slug' => $source->getSlug(),
            'parentId' => $source->getParentPublicId()?->toString(),
            'mbid' => $source->getMbid(),
        ];
    }
}
