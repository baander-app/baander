<?php

declare(strict_types=1);

namespace App\Playlist\Interface\Resource;

use App\Playlist\Domain\ReadModel\PlaylistReadView;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'publicId' => new OA\Schema(
            description: 'Public identifier',
            type: 'string',
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
        ),
        'ownerPublicId' => new OA\Schema(
            description: "The owner's public id",
            type: 'string',
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
        ),
        'collaboratorPublicIds' => new OA\Schema(
            description: 'The public ids of the collaborators, oldest first',
            type: 'array',
            items: new OA\Schema(
                type: 'string',
                minLength: 21,
                maxLength: 21,
                pattern: '^[0-9a-zA-Z_-]{21}$',
            ),
        ),
        'name' => new OA\Schema(description: 'Playlist name', type: 'string'),
        'description' => new OA\Schema(
            description: 'Playlist description',
            type: 'string',
            nullable: true,
        ),
        'isPublic' => new OA\Schema(description: 'Whether the playlist is public', type: 'boolean'),
        'isCollaborative' => new OA\Schema(description: 'Whether collaborators may change the songs', type: 'boolean'),
        'isSmart' => new OA\Schema(description: 'Whether the playlist is auto-generated', type: 'boolean'),
        'songCount' => new OA\Schema(description: 'Number of songs the viewer can see', type: 'integer'),
        'createdAt' => new OA\Schema(
            description: 'Creation timestamp',
            type: 'string',
            format: 'date-time',
        ),
    ],
    required: [
        'publicId',
        'ownerPublicId',
        'collaboratorPublicIds',
        'name',
        'isPublic',
        'isCollaborative',
        'isSmart',
        'songCount',
        'createdAt',
    ],
    component: 'PlaylistResource',
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
