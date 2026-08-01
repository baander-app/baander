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
        new OA\Property(property: 'albumIds', type: 'array', items: new OA\Items(type: 'string'), description: 'List of album UUIDs in this duplicate group'),
        new OA\Property(property: 'confidence', type: 'number', description: 'Confidence score between 0 and 1'),
        new OA\Property(property: 'albumCount', type: 'integer', description: 'Number of albums in the group'),
        new OA\Property(property: 'albums', type: 'array', items: new OA\Items(type: 'object'), description: 'Album data for each duplicate'),
    ],
)]
final class DuplicateGroupResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof DuplicateGroup);

        return [
            'albumIds' => array_map(
                fn($id) => $id->toString(),
                $source->getAlbumIds(),
            ),
            'confidence' => $source->getConfidence(),
            'albumCount' => $source->getAlbumCount(),
            'albums' => $source->getAlbums(),
        ];
    }
}
