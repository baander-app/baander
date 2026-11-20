<?php

declare(strict_types=1);

namespace App\Library\Interface\Resource;

use App\Library\Application\DTO\LibraryAccess;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'LibraryAccessResource',
    properties: [
        new OA\Property(property: 'libraryId', description: 'Library public ID', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'name', description: 'Library name', type: 'string'),
        new OA\Property(property: 'slug', description: 'URL-friendly slug', type: 'string'),
        new OA\Property(property: 'type', description: 'Library type', type: 'string', enum: ['music', 'podcast', 'audiobook', 'movie', 'tv_show']),
        new OA\Property(property: 'granted', description: 'Whether the user may see the library', type: 'boolean'),
    ],
)]
final class LibraryAccessResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof LibraryAccess);

        return [
            'libraryId' => $source->library->getPublicId()->toString(),
            'name' => $source->library->getName(),
            'slug' => $source->library->getSlug()->toString(),
            'type' => $source->library->getType()->value,
            'granted' => $source->granted,
        ];
    }
}
