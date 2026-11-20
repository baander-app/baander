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
        new OA\Property(property: 'publicId', description: 'Public identifier', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'name', description: 'Artist name', type: 'string'),
        new OA\Property(property: 'country', description: 'Country code', type: 'string', nullable: true),
        new OA\Property(property: 'type', description: 'Artist type', type: 'string', nullable: true),
        new OA\Property(property: 'disambiguation', description: 'Disambiguation text', type: 'string', nullable: true),
        new OA\Property(property: 'sortName', description: 'Sort name', type: 'string', nullable: true),
        new OA\Property(property: 'mbid', description: 'MusicBrainz artist ID', type: 'string', nullable: true),
        new OA\Property(property: 'lockedFields', description: 'Fields locked from automatic updates', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'createdAt', description: 'Creation timestamp', type: 'string', format: 'date-time'),
    ],
)]
final class ArtistResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof Artist);

        return [
            'publicId' => $source->getPublicId()->toString(),
            'name' => $source->getName(),
            'country' => $source->getCountry(),
            'type' => $source->getType(),
            'disambiguation' => $source->getDisambiguation(),
            'sortName' => $source->getSortName(),
            'mbid' => $source->getMbid(),
            'lockedFields' => $source->getLockedFields(),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
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
