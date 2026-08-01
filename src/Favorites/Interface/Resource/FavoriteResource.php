<?php

declare(strict_types=1);

namespace App\Favorites\Interface\Resource;

use App\Favorites\Domain\Model\UserFavorite;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'FavoriteResource',
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid', description: 'Favorite UUID'),
        new OA\Property(property: 'publicId', type: 'string', description: 'Public identifier'),
        new OA\Property(property: 'entityType', type: 'string', enum: ['song', 'album', 'artist'], description: 'Entity type'),
        new OA\Property(property: 'entityPublicId', type: 'string', description: 'Entity public ID'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', description: 'Creation timestamp'),
    ],
)]
final class FavoriteResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof UserFavorite);

        return [
            'uuid' => $source->getId()->toString(),
            'publicId' => $source->getPublicId()->toString(),
            'entityType' => $source->getEntityType()->value,
            'entityPublicId' => $source->getEntityPublicId(),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
