<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Domain\Model\Album;
use App\Media\Domain\Model\Image;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AlbumResource',
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid', description: 'Album UUID'),
        new OA\Property(property: 'publicId', type: 'string', format: 'uuid', description: 'Public-facing UUID'),
        new OA\Property(property: 'title', type: 'string', description: 'Album title'),
        new OA\Property(property: 'type', type: 'string', description: 'Album type'),
        new OA\Property(property: 'year', type: 'integer', nullable: true, description: 'Release year'),
        new OA\Property(property: 'label', type: 'string', nullable: true, description: 'Record label'),
        new OA\Property(property: 'barcode', type: 'string', nullable: true, description: 'Barcode'),
        new OA\Property(property: 'country', type: 'string', nullable: true, description: 'Country of release'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', description: 'Creation timestamp'),
    ],
)]
final class AlbumResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof Album);

        return [
            'uuid' => $source->getId()->toString(),
            'publicId' => $source->getPublicId()->toString(),
            'title' => $source->getTitle(),
            'type' => $source->getType(),
            'year' => $source->getYear(),
            'label' => $source->getLabel(),
            'barcode' => $source->getBarcode(),
            'country' => $source->getCountry(),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param array<int, array{name: string, role: string|null}> $artists
     */
    public static function fromWithCoverAndArtists(Album $album, ?Image $coverImage, array $artists, string $baseUrl = ''): array
    {
        $data = self::from($album);

        if ($coverImage !== null) {
            $data['coverImage'] = [
                'url' => $baseUrl . '/api/images/' . $coverImage->getPublicId()->toString() . '/file',
                'blurhash' => $coverImage->getBlurhash(),
            ];
        } else {
            $data['coverImage'] = null;
        }

        $data['artists'] = $artists;

        return $data;
    }

    public static function fromWithCover(Album $album, ?Image $coverImage, string $baseUrl = ''): array
    {
        return self::fromWithCoverAndArtists($album, $coverImage, [], $baseUrl);
    }
}
