<?php

declare(strict_types=1);

namespace App\Playlist\Interface\Resource;

use App\Playlist\Domain\ReadModel\PlaylistReadView;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PlaylistResource',
    properties: [
        new OA\Property(property: 'publicId', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', minLength: 21, maxLength: 21, description: 'Public identifier'),
        new OA\Property(property: 'ownerPublicId', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', minLength: 21, maxLength: 21, description: "The owner's public id"),
        new OA\Property(property: 'collaboratorPublicIds', type: 'array', items: new OA\Items(type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', minLength: 21, maxLength: 21), description: 'The public ids of the collaborators, oldest first'),
        new OA\Property(property: 'name', type: 'string', description: 'Playlist name'),
        new OA\Property(property: 'description', type: 'string', nullable: true, description: 'Playlist description'),
        new OA\Property(property: 'isPublic', type: 'boolean', description: 'Whether the playlist is public'),
        new OA\Property(property: 'isCollaborative', type: 'boolean', description: 'Whether collaborators may change the songs'),
        new OA\Property(property: 'isSmart', type: 'boolean', description: 'Whether the playlist is auto-generated'),
        new OA\Property(property: 'songCount', type: 'integer', description: 'Number of songs the viewer can see'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', description: 'Creation timestamp'),
    ],
)]
final class PlaylistResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof PlaylistReadView);

        return [
            'publicId' => $source->getPublicId()->toString(),
            'ownerPublicId' => $source->getOwnerPublicId()->toString(),
            'collaboratorPublicIds' => array_map(
                static fn ($publicId): string => $publicId->toString(),
                $source->getCollaboratorPublicIds(),
            ),
            'name' => $source->getName(),
            'description' => $source->getDescription(),
            'isPublic' => $source->isPublic(),
            'isCollaborative' => $source->isCollaborative(),
            'isSmart' => $source->isSmart(),
            'songCount' => $source->getSongCount(),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
