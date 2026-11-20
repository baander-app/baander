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
        new OA\Property(property: 'publicId', description: 'Public identifier', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'title', description: 'Album title', type: 'string'),
        new OA\Property(property: 'type', description: 'Album type', type: 'string'),
        new OA\Property(property: 'year', description: 'Release year', type: 'integer', nullable: true),
        new OA\Property(property: 'label', description: 'Record label', type: 'string', nullable: true),
        new OA\Property(property: 'barcode', description: 'Barcode', type: 'string', nullable: true),
        new OA\Property(property: 'country', description: 'Country of release', type: 'string', nullable: true),
        new OA\Property(property: 'language', description: 'Release language as stored; usually a language code, but tag readers may store free text', type: 'string', nullable: true),
        new OA\Property(property: 'mbid', description: 'MusicBrainz release group ID', type: 'string', nullable: true),
        new OA\Property(property: 'releaseMbid', description: 'MusicBrainz release (edition) the album was matched to; null until a match is applied', type: 'string', nullable: true),
        new OA\Property(property: 'lockedFields', description: 'Fields locked from automatic updates', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'createdAt', description: 'Creation timestamp', type: 'string', format: 'date-time'),
    ],
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
