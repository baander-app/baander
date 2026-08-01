<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Resource;

use App\Catalog\Domain\Model\Album;
use App\Catalog\Interface\Resource\AlbumResource;
use App\Media\Domain\Model\Image;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class AlbumResourceTest extends TestCase
{
    private Album $album;

    protected function setUp(): void
    {
        $this->album = Album::create(
            Uuid::v4(),
            'Abbey Road',
            'Album',
            mbid: '1f4b1bf0-1f3b-4d2b-8f0d-1a2b3c4d5e6f',
            discogsId: '12345',
            spotifyId: '6nS5rofSTANWiGm2ENb7Os',
            year: 1969,
            label: 'Apple Records',
            catalogNumber: 'PCS 7088',
            barcode: '5099708040234',
            country: 'United Kingdom',
            language: 'English',
            disambiguation: 'Original UK release',
            annotation: 'Eleventh studio album',
        );
    }

    public function testFromReturnsExpectedKeys(): void
    {
        $result = AlbumResource::from($this->album);

        $this->assertArrayHasKey('uuid', $result);
        $this->assertArrayHasKey('publicId', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('type', $result);
        $this->assertArrayHasKey('year', $result);
        $this->assertArrayHasKey('label', $result);
        $this->assertArrayHasKey('barcode', $result);
        $this->assertArrayHasKey('country', $result);
        $this->assertArrayHasKey('createdAt', $result);
    }

    public function testFromTransformsValues(): void
    {
        $result = AlbumResource::from($this->album);

        $this->assertSame('Abbey Road', $result['title']);
        $this->assertSame('Album', $result['type']);
        $this->assertSame(1969, $result['year']);
        $this->assertSame('Apple Records', $result['label']);
        $this->assertSame('5099708040234', $result['barcode']);
        $this->assertSame('United Kingdom', $result['country']);
    }

    public function testFromContainsUuidAsNonEmptyString(): void
    {
        $result = AlbumResource::from($this->album);

        $this->assertIsString($result['uuid']);
        $this->assertNotEmpty($result['uuid']);
    }

    public function testFromContainsPublicIdAsNonEmptyString(): void
    {
        $result = AlbumResource::from($this->album);

        $this->assertIsString($result['publicId']);
        $this->assertNotEmpty($result['publicId']);
    }

    public function testFromContainsCreatedAtInAtomFormat(): void
    {
        $result = AlbumResource::from($this->album);

        $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $result['createdAt']);
        $this->assertNotFalse($parsed, 'createdAt should be a valid ATOM-formatted string');
        $this->assertSame(
            $this->album->getCreatedAt()->format(\DateTimeInterface::ATOM),
            $result['createdAt'],
        );
    }

    public function testFromDoesNotExposeSensitiveFields(): void
    {
        $result = AlbumResource::from($this->album);

        $this->assertArrayNotHasKey('annotation', $result);
        $this->assertArrayNotHasKey('disambiguation', $result);
        $this->assertArrayNotHasKey('catalogNumber', $result);
        $this->assertArrayNotHasKey('language', $result);
        $this->assertArrayNotHasKey('mbid', $result);
        $this->assertArrayNotHasKey('discogsId', $result);
        $this->assertArrayNotHasKey('spotifyId', $result);
        $this->assertArrayNotHasKey('updatedAt', $result);
        $this->assertArrayNotHasKey('lockedFields', $result);
    }

    public function testFromHandlesNullOptionalFields(): void
    {
        $album = Album::create(Uuid::v4(), 'Minimal Album', 'EP');

        $result = AlbumResource::from($album);

        $this->assertNull($result['year']);
        $this->assertNull($result['label']);
        $this->assertNull($result['barcode']);
        $this->assertNull($result['country']);
    }

    public function testFromWithCoverIncludesAllBaseFields(): void
    {
        $result = AlbumResource::fromWithCover($this->album, null);

        $this->assertSame($this->album->getId()->toString(), $result['uuid']);
        $this->assertSame($this->album->getPublicId()->toString(), $result['publicId']);
        $this->assertSame('Abbey Road', $result['title']);
        $this->assertSame('Album', $result['type']);
        $this->assertSame(1969, $result['year']);
    }

    public function testFromWithCoverReturnsNullWhenNoCoverImage(): void
    {
        $result = AlbumResource::fromWithCover($this->album, null);

        $this->assertNull($result['coverImage']);
    }

    public function testFromWithCoverIncludesCoverUrlAndBlurhash(): void
    {
        $imageId = Uuid::v4();
        $image = Image::reconstitute(
            new \App\Media\Domain\Model\ImageState(
                id: $imageId,
                publicId: new PublicId(),
                path: '/storage/covers/cover.jpg',
                extension: 'jpg',
                mimeType: 'image/jpeg',
                blurhash: 'L6Pj0^i_.AyE_3t7t7R**0o#DgR4',
                size: 1024,
                width: 500,
                height: 500,
                imageableType: 'album',
                albumId: $this->album->getId(),
                artistId: null,
                playlistId: null,
                createdAt: new \DateTimeImmutable(),
                updatedAt: new \DateTimeImmutable(),
            ),
        );

        $result = AlbumResource::fromWithCover($this->album, $image, 'https://example.com');

        $this->assertNotNull($result['coverImage']);
        $this->assertSame('https://example.com/api/images/' . $image->getPublicId()->toString() . '/file', $result['coverImage']['url']);
        $this->assertSame('L6Pj0^i_.AyE_3t7t7R**0o#DgR4', $result['coverImage']['blurhash']);
    }

    public function testFromWithCoverConstructsFullUrlFromImagePublicId(): void
    {
        $publicId = new PublicId();

        $image = Image::reconstitute(
            new \App\Media\Domain\Model\ImageState(
                id: Uuid::v4(),
                publicId: $publicId,
                path: '/storage/covers/cover.jpg',
                extension: 'jpg',
                mimeType: 'image/jpeg',
                blurhash: null,
                size: 1024,
                width: 500,
                height: 500,
                imageableType: 'album',
                albumId: $this->album->getId(),
                artistId: null,
                playlistId: null,
                createdAt: new \DateTimeImmutable(),
                updatedAt: new \DateTimeImmutable(),
            ),
        );

        $result = AlbumResource::fromWithCover($this->album, $image, 'https://baander.example.com');

        $this->assertSame('https://baander.example.com/api/images/' . $publicId->toString() . '/file', $result['coverImage']['url']);
        $this->assertNull($result['coverImage']['blurhash']);
    }

    public function testFromWithCoverDefaultsToRelativeUrlWithoutBaseUrl(): void
    {
        $publicId = new PublicId();

        $image = Image::reconstitute(
            new \App\Media\Domain\Model\ImageState(
                id: Uuid::v4(),
                publicId: $publicId,
                path: '/storage/covers/cover.jpg',
                extension: 'jpg',
                mimeType: 'image/jpeg',
                blurhash: null,
                size: 1024,
                width: 500,
                height: 500,
                imageableType: 'album',
                albumId: $this->album->getId(),
                artistId: null,
                playlistId: null,
                createdAt: new \DateTimeImmutable(),
                updatedAt: new \DateTimeImmutable(),
            ),
        );

        $result = AlbumResource::fromWithCover($this->album, $image);

        $this->assertSame('/api/images/' . $publicId->toString() . '/file', $result['coverImage']['url']);
    }

    public function testFromDoesNotExposeCoverImage(): void
    {
        $result = AlbumResource::from($this->album);

        $this->assertArrayNotHasKey('coverImage', $result);
    }

    public function testCollectionTransformsMultipleAlbums(): void
    {
        $album1 = Album::create(Uuid::v4(), 'Album One', 'Album', year: 2020);
        $album2 = Album::create(Uuid::v4(), 'Album Two', 'Album', year: 2021);

        $result = AlbumResource::collection([$album1, $album2]);

        $this->assertCount(2, $result);
        $this->assertSame('Album One', $result[0]['title']);
        $this->assertSame('Album Two', $result[1]['title']);
        $this->assertSame(2020, $result[0]['year']);
        $this->assertSame(2021, $result[1]['year']);
    }

    public function testCollectionReturnsEmptyArrayForEmptyInput(): void
    {
        $result = AlbumResource::collection([]);

        $this->assertSame([], $result);
    }
}
