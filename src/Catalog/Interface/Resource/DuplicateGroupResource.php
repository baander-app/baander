<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Domain\ValueObject\DuplicateGroup;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

/**
 * Transforms DuplicateGroup value object to API response.
 */
#[OA\Schema(
    schema: 'DuplicateGroupResource',
    properties: [
        new OA\Property(property: 'albumIds', description: 'List of album public identifiers in this duplicate group', type: 'array', items: new OA\Items(type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21)),
        new OA\Property(property: 'confidence', description: 'Confidence score between 0 and 1', type: 'number'),
        new OA\Property(property: 'albumCount', description: 'Number of albums in the group', type: 'integer'),
        new OA\Property(property: 'albums', description: 'Album data for each duplicate', type: 'array', items: new OA\Items(type: 'object')),
    ],
)]
final class DuplicateGroupResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof DuplicateGroup);

        return [
            'albumIds' => array_column($source->getAlbums(), 'publicId'),
            'confidence' => $source->getConfidence(),
            'albumCount' => $source->getAlbumCount(),
            'albums' => $source->getAlbums(),
        ];
    }
}
