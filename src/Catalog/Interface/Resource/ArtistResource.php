<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Domain\Model\Artist;
use App\Media\Domain\Model\Image;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ArtistResource',
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid', description: 'Artist UUID'),
        new OA\Property(property: 'publicId', type: 'string', format: 'uuid', description: 'Public-facing UUID'),
        new OA\Property(property: 'name', type: 'string', description: 'Artist name'),
        new OA\Property(property: 'country', type: 'string', nullable: true, description: 'Country code'),
        new OA\Property(property: 'type', type: 'string', nullable: true, description: 'Artist type'),
        new OA\Property(property: 'disambiguation', type: 'string', nullable: true, description: 'Disambiguation text'),
        new OA\Property(property: 'sortName', type: 'string', nullable: true, description: 'Sort name'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', description: 'Creation timestamp'),
    ],
)]
final class ArtistResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof Artist);

        return [
            'uuid' => $source->getId()->toString(),
            'publicId' => $source->getPublicId()->toString(),
            'name' => $source->getName(),
            'country' => $source->getCountry(),
            'type' => $source->getType(),
            'disambiguation' => $source->getDisambiguation(),
            'sortName' => $source->getSortName(),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    public static function fromWithCover(Artist $artist, ?Image $coverImage, string $baseUrl = ''): array
    {
        $data = self::from($artist);

        if ($coverImage !== null) {
            $data['coverImage'] = [
                'url' => $baseUrl . '/api/images/' . $coverImage->getPublicId()->toString() . '/file',
                'blurhash' => $coverImage->getBlurhash(),
            ];
        } else {
            $data['coverImage'] = null;
        }

        return $data;
    }
}
