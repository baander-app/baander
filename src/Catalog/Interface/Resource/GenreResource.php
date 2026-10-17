<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Domain\Model\Genre;
use App\Catalog\Domain\ReadModel\GenreReadView;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'GenreResource',
    properties: [
        new OA\Property(property: 'publicId', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', minLength: 21, maxLength: 21, description: 'Genre public ID'),
        new OA\Property(property: 'name', type: 'string', description: 'Genre name'),
        new OA\Property(property: 'slug', type: 'string', description: 'URL-safe slug'),
        new OA\Property(property: 'parentId', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', minLength: 21, maxLength: 21, nullable: true, description: 'Parent genre public ID'),
        new OA\Property(property: 'mbid', type: 'string', nullable: true, description: 'MusicBrainz ID'),
    ],
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
