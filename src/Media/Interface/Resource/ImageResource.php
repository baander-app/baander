<?php

declare(strict_types=1);

namespace App\Media\Interface\Resource;

use App\Media\Domain\Model\Image;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ImageResource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid', description: 'Image UUID'),
        new OA\Property(property: 'publicId', type: 'string', format: 'uuid', description: 'Public identifier'),
        new OA\Property(property: 'path', type: 'string', description: 'File path'),
        new OA\Property(property: 'extension', type: 'string', description: 'File extension'),
        new OA\Property(property: 'mimeType', type: 'string', description: 'MIME type'),
        new OA\Property(property: 'blurhash', type: 'string', nullable: true, description: 'BlurHash representation'),
        new OA\Property(property: 'size', type: 'integer', description: 'File size in bytes'),
        new OA\Property(property: 'width', type: 'integer', description: 'Image width in pixels'),
        new OA\Property(property: 'height', type: 'integer', description: 'Image height in pixels'),
        new OA\Property(property: 'imageableType', type: 'string', description: 'Parent entity type'),
        new OA\Property(property: 'albumId', type: 'string', format: 'uuid', nullable: true, description: 'Associated album UUID'),
        new OA\Property(property: 'artistId', type: 'string', format: 'uuid', nullable: true, description: 'Associated artist UUID'),
        new OA\Property(property: 'playlistId', type: 'string', format: 'uuid', nullable: true, description: 'Associated playlist UUID'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', description: 'Creation timestamp'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time', description: 'Last update timestamp'),
    ],
)]
final class ImageResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof Image);

        return [
            'id' => $source->getId()->toString(),
            'publicId' => $source->getPublicId()->toString(),
            'path' => $source->getPath(),
            'extension' => $source->getExtension(),
            'mimeType' => $source->getMimeType(),
            'blurhash' => $source->getBlurhash(),
            'size' => $source->getSize(),
            'width' => $source->getWidth(),
            'height' => $source->getHeight(),
            'imageableType' => $source->getImageableType(),
            'albumId' => $source->getAlbumId()?->toString(),
            'artistId' => $source->getArtistId()?->toString(),
            'playlistId' => $source->getPlaylistId()?->toString(),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $source->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
