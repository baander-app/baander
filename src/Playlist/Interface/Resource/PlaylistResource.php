<?php

declare(strict_types=1);

namespace App\Playlist\Interface\Resource;

use App\Playlist\Domain\ReadModel\PlaylistReadView;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PlaylistResource',
    properties: [
        new OA\Property(property: 'publicId', description: 'Public identifier', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'ownerPublicId', description: "The owner's public id", type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'collaboratorPublicIds', description: 'The public ids of the collaborators, oldest first', type: 'array', items: new OA\Items(type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21)),
        new OA\Property(property: 'name', description: 'Playlist name', type: 'string'),
        new OA\Property(property: 'description', description: 'Playlist description', type: 'string', nullable: true),
        new OA\Property(property: 'isPublic', description: 'Whether the playlist is public', type: 'boolean'),
        new OA\Property(property: 'isCollaborative', description: 'Whether collaborators may change the songs', type: 'boolean'),
        new OA\Property(property: 'isSmart', description: 'Whether the playlist is auto-generated', type: 'boolean'),
        new OA\Property(property: 'songCount', description: 'Number of songs the viewer can see', type: 'integer'),
        new OA\Property(property: 'createdAt', description: 'Creation timestamp', type: 'string', format: 'date-time'),
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
