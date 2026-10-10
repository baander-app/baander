<?php

declare(strict_types=1);

namespace App\Library\Interface\Resource;

use App\Library\Application\DTO\LibraryAccess;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'LibraryAccessResource',
    properties: [
        new OA\Property(property: 'libraryId', type: 'string', format: 'uuid', description: 'Library UUID'),
        new OA\Property(property: 'name', type: 'string', description: 'Library name'),
        new OA\Property(property: 'slug', type: 'string', description: 'URL-friendly slug'),
        new OA\Property(property: 'type', type: 'string', enum: ['music', 'podcast', 'audiobook', 'movie', 'tv_show'], description: 'Library type'),
        new OA\Property(property: 'granted', type: 'boolean', description: 'Whether the user may see the library'),
    ],
)]
final class LibraryAccessResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof LibraryAccess);

        return [
            'libraryId' => $source->library->getId()->toString(),
            'name' => $source->library->getName(),
            'slug' => $source->library->getSlug()->toString(),
            'type' => $source->library->getType()->value,
            'granted' => $source->granted,
        ];
    }
}
