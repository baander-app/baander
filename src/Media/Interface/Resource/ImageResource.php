<?php

declare(strict_types=1);

namespace App\Media\Interface\Resource;

use App\Media\Domain\ReadModel\ImageReadView;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ImageResource',
    properties: [
        new OA\Property(property: 'publicId', description: 'Public identifier', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'extension', description: 'File extension', type: 'string'),
        new OA\Property(property: 'mimeType', description: 'MIME type', type: 'string'),
        new OA\Property(property: 'blurhash', description: 'BlurHash representation', type: 'string', nullable: true),
        new OA\Property(property: 'size', description: 'File size in bytes', type: 'integer'),
        new OA\Property(property: 'width', description: 'Image width in pixels', type: 'integer'),
        new OA\Property(property: 'height', description: 'Image height in pixels', type: 'integer'),
        new OA\Property(property: 'imageableType', description: 'Parent entity type', type: 'string'),
        new OA\Property(property: 'albumId', description: 'Associated album public ID', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', nullable: true, maxLength: 21, minLength: 21),
        new OA\Property(property: 'artistId', description: 'Associated artist public ID', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', nullable: true, maxLength: 21, minLength: 21),
        new OA\Property(property: 'playlistId', description: 'Associated playlist public ID', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', nullable: true, maxLength: 21, minLength: 21),
        new OA\Property(property: 'createdAt', description: 'Creation timestamp', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updatedAt', description: 'Last update timestamp', type: 'string', format: 'date-time'),
    ],
)]
final class ImageResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof ImageReadView);

        return [
            'publicId' => $source->getPublicId()->toString(),
            'extension' => $source->getExtension(),
            'mimeType' => $source->getMimeType(),
            'blurhash' => $source->getBlurhash(),
            'size' => $source->getSize(),
            'width' => $source->getWidth(),
            'height' => $source->getHeight(),
            'imageableType' => $source->getImageableType(),
            'albumId' => $source->getAlbumPublicId()?->toString(),
            'artistId' => $source->getArtistPublicId()?->toString(),
            'playlistId' => $source->getPlaylistPublicId()?->toString(),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $source->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
