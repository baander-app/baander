<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Domain\Model\Artist;
use App\Media\Domain\Model\Image;
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
        'name' => new OA\Schema(description: 'Artist name', type: 'string'),
        'country' => new OA\Schema(
            description: 'Country code',
            type: 'string',
            nullable: true,
        ),
        'type' => new OA\Schema(
            description: 'Artist type',
            type: 'string',
            nullable: true,
        ),
        'disambiguation' => new OA\Schema(
            description: 'Disambiguation text',
            type: 'string',
            nullable: true,
        ),
        'sortName' => new OA\Schema(
            description: 'Sort name',
            type: 'string',
            nullable: true,
        ),
        'mbid' => new OA\Schema(
            description: 'MusicBrainz artist ID',
            type: 'string',
            nullable: true,
        ),
        'lockedFields' => new OA\Schema(
            description: 'Fields locked from automatic updates',
            type: 'array',
            items: new OA\Schema(type: 'string'),
        ),
        'createdAt' => new OA\Schema(
            description: 'Creation timestamp',
            type: 'string',
            format: 'date-time',
        ),
    ],
    required: ['publicId', 'name', 'lockedFields', 'createdAt'],
    component: 'ArtistResource',
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
