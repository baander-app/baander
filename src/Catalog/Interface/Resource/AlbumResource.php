<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Domain\Model\Album;
use App\Media\Domain\Model\Image;
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
        'title' => new OA\Schema(description: 'Album title', type: 'string'),
        'type' => new OA\Schema(description: 'Album type', type: 'string'),
        'year' => new OA\Schema(
            description: 'Release year',
            type: 'integer',
            nullable: true,
        ),
        'label' => new OA\Schema(
            description: 'Record label',
            type: 'string',
            nullable: true,
        ),
        'barcode' => new OA\Schema(
            description: 'Barcode',
            type: 'string',
            nullable: true,
        ),
        'country' => new OA\Schema(
            description: 'Country of release',
            type: 'string',
            nullable: true,
        ),
        'language' => new OA\Schema(
            description: 'Release language as stored; usually a language code, but tag readers may store free text',
            type: 'string',
            nullable: true,
        ),
        'mbid' => new OA\Schema(
            description: 'MusicBrainz release group ID',
            type: 'string',
            nullable: true,
        ),
        'releaseMbid' => new OA\Schema(
            description: 'MusicBrainz release (edition) the album was matched to; null until a match is applied',
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
    required: ['publicId', 'title', 'type', 'lockedFields', 'createdAt'],
    component: 'AlbumResource',
)]
final class AlbumResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof Album);

        return [
            'publicId' => $source->getPublicId()->toString(),
            'title' => $source->getTitle(),
            'type' => $source->getType(),
            'year' => $source->getYear(),
            'label' => $source->getLabel(),
            'barcode' => $source->getBarcode(),
            'country' => $source->getCountry(),
            'language' => $source->getLanguage(),
            'mbid' => $source->getMbid(),
            'releaseMbid' => $source->getReleaseMbid(),
            'lockedFields' => $source->getLockedFields(),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param array<int, array{name: string, role: string|null}> $artists
     * @return array<string, mixed>
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

    /**
     * @return array<string, mixed>
     */
    public static function fromWithCover(Album $album, ?Image $coverImage, string $baseUrl = ''): array
    {
        return self::fromWithCoverAndArtists($album, $coverImage, [], $baseUrl);
    }
}
