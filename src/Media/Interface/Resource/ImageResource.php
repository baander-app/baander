<?php

declare(strict_types=1);

namespace App\Media\Interface\Resource;

use App\Media\Domain\ReadModel\ImageReadView;
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
        'extension' => new OA\Schema(description: 'File extension', type: 'string'),
        'mimeType' => new OA\Schema(description: 'MIME type', type: 'string'),
        'blurhash' => new OA\Schema(
            description: 'BlurHash representation',
            type: 'string',
            nullable: true,
        ),
        'size' => new OA\Schema(description: 'File size in bytes', type: 'integer'),
        'width' => new OA\Schema(description: 'Image width in pixels', type: 'integer'),
        'height' => new OA\Schema(description: 'Image height in pixels', type: 'integer'),
        'imageableType' => new OA\Schema(description: 'Parent entity type', type: 'string'),
        'albumId' => new OA\Schema(
            description: 'Associated album public ID',
            type: 'string',
            nullable: true,
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
        ),
        'artistId' => new OA\Schema(
            description: 'Associated artist public ID',
            type: 'string',
            nullable: true,
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
        ),
        'playlistId' => new OA\Schema(
            description: 'Associated playlist public ID',
            type: 'string',
            nullable: true,
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
        ),
        'createdAt' => new OA\Schema(
            description: 'Creation timestamp',
            type: 'string',
            format: 'date-time',
        ),
        'updatedAt' => new OA\Schema(
            description: 'Last update timestamp',
            type: 'string',
            format: 'date-time',
        ),
    ],
    required: [
        'publicId',
        'extension',
        'mimeType',
        'size',
        'width',
        'height',
        'imageableType',
        'createdAt',
        'updatedAt',
    ],
    component: 'ImageResource',
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
