<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Domain\ValueObject\DuplicateGroup;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

/**
 * Transforms DuplicateGroup value object to API response.
 */
#[OA\Schema(
    type: 'object',
    properties: [
        'albumIds' => new OA\Schema(
            description: 'List of album public identifiers in this duplicate group',
            type: 'array',
            items: new OA\Schema(
                type: 'string',
                minLength: PublicId::LENGTH,
                maxLength: PublicId::LENGTH,
                pattern: PublicId::SCHEMA_PATTERN,
            ),
        ),
        'confidence' => new OA\Schema(description: 'Confidence score between 0 and 1', type: 'number'),
        'albumCount' => new OA\Schema(description: 'Number of albums in the group', type: 'integer'),
        'albums' => new OA\Schema(
            description: 'Album data for each duplicate',
            type: 'array',
            items: new OA\Schema(type: 'object'),
        ),
    ],
    required: ['albumIds', 'confidence', 'albumCount', 'albums'],
    component: 'DuplicateGroupResource',
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
