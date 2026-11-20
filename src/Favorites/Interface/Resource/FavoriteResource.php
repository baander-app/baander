<?php

declare(strict_types=1);

namespace App\Favorites\Interface\Resource;

use App\Favorites\Domain\Model\UserFavorite;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'FavoriteResource',
    properties: [
        new OA\Property(property: 'publicId', description: 'Public identifier', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'entityType', description: 'Entity type', type: 'string', enum: ['song', 'album', 'artist']),
        new OA\Property(property: 'entityPublicId', description: 'Entity public ID', type: 'string'),
        new OA\Property(property: 'createdAt', description: 'Creation timestamp', type: 'string', format: 'date-time'),
    ],
)]
final class FavoriteResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof UserFavorite);

        return [
            'publicId' => $source->getPublicId()->toString(),
            'entityType' => $source->getEntityType()->value,
            'entityPublicId' => \App\Shared\Domain\Model\PublicId::fromString($source->getEntityPublicId())->toString(),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
