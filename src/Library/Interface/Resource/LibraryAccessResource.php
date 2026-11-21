<?php

declare(strict_types=1);

namespace App\Library\Interface\Resource;

use App\Library\Application\DTO\LibraryAccess;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'libraryId' => new OA\Schema(
            description: 'Library public ID',
            type: 'string',
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
        ),
        'name' => new OA\Schema(description: 'Library name', type: 'string'),
        'slug' => new OA\Schema(description: 'URL-friendly slug', type: 'string'),
        'type' => new OA\Schema(
            description: 'Library type',
            type: 'string',
            enum: ['music', 'podcast', 'audiobook', 'movie', 'tv_show'],
        ),
        'granted' => new OA\Schema(description: 'Whether the user may see the library', type: 'boolean'),
    ],
    required: ['libraryId', 'name', 'slug', 'type', 'granted'],
    component: 'LibraryAccessResource',
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
