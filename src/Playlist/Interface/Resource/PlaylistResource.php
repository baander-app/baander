<?php

declare(strict_types=1);

namespace App\Playlist\Interface\Resource;

use App\Playlist\Domain\Model\Playlist;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PlaylistResource',
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid', description: 'Playlist UUID'),
        new OA\Property(property: 'publicId', type: 'string', format: 'uuid', description: 'Public identifier'),
        new OA\Property(property: 'userId', type: 'string', format: 'uuid', description: 'Owner user UUID'),
        new OA\Property(property: 'name', type: 'string', description: 'Playlist name'),
        new OA\Property(property: 'description', type: 'string', nullable: true, description: 'Playlist description'),
        new OA\Property(property: 'isPublic', type: 'boolean', description: 'Whether the playlist is public'),
        new OA\Property(property: 'isCollaborative', type: 'boolean', description: 'Whether the playlist is collaborative'),
        new OA\Property(property: 'isSmart', type: 'boolean', description: 'Whether the playlist is auto-generated'),
        new OA\Property(property: 'songCount', type: 'integer', description: 'Number of songs'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', description: 'Creation timestamp'),
    ],
)]
final class PlaylistResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof Playlist);

        return [
            'uuid' => $source->getId()->toString(),
            'publicId' => $source->getPublicId()->toString(),
            'userId' => $source->getUserId()->toString(),
            'name' => $source->getName(),
            'description' => $source->getDescription(),
            'isPublic' => $source->isPublic(),
            'isCollaborative' => $source->isCollaborative(),
            'isSmart' => $source->isSmart(),
            'songCount' => count($source->getSongs()),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
