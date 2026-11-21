<?php

declare(strict_types=1);

namespace App\Favorites\Interface\Resource;

use App\Favorites\Domain\Model\UserFavorite;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'publicId' => new OA\Schema(
            description: 'Public identifier',
            type: 'string',
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
        ),
        'entityType' => new OA\Schema(
            description: 'Entity type',
            type: 'string',
            enum: ['song', 'album', 'artist'],
        ),
        'entityPublicId' => new OA\Schema(description: 'Entity public ID', type: 'string'),
        'createdAt' => new OA\Schema(
            description: 'Creation timestamp',
            type: 'string',
            format: 'date-time',
        ),
    ],
    required: ['publicId', 'entityType', 'entityPublicId', 'createdAt'],
    component: 'FavoriteResource',
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
