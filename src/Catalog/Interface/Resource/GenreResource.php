<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Domain\Model\Genre;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'GenreResource',
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid', description: 'Genre UUID'),
        new OA\Property(property: 'name', type: 'string', description: 'Genre name'),
        new OA\Property(property: 'slug', type: 'string', description: 'URL-safe slug'),
        new OA\Property(property: 'parentId', type: 'string', format: 'uuid', nullable: true, description: 'Parent genre UUID'),
        new OA\Property(property: 'mbid', type: 'string', nullable: true, description: 'MusicBrainz ID'),
    ],
)]
final class GenreResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof Genre);

        return [
            'uuid' => $source->getId()->toString(),
            'name' => $source->getName(),
            'slug' => $source->getSlug(),
            'parentId' => $source->getParent()?->getId()->toString(),
            'mbid' => $source->getMbid(),
        ];
    }
}
