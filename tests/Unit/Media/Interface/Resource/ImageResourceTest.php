<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Interface\Resource;

use App\Media\Domain\Model\Image;
use App\Media\Interface\Resource\ImageResource;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ImageResourceTest extends TestCase
{
    public function testFromTransformsImageToArray(): void
    {
        $id = Uuid::v4();
        $publicId = PublicId::fromString(str_repeat('a', 21));
        $createdAt = new DateTimeImmutable('2025-06-01 12:00:00');
        $updatedAt = new DateTimeImmutable('2025-06-02 15:30:00');
        $albumId = Uuid::v4();

        $image = Image::reconstitute(
            new \App\Media\Domain\Model\ImageState(
                id: $id,
                publicId: $publicId,
                path: 'images/album-cover.jpg',
                extension: 'jpg',
                mimeType: 'image/jpeg',
                blurhash: 'LEHV6nWB2yk8pyo0adR*.7kCMdnj',
                size: 2048,
                width: 800,
                height: 600,
                imageableType: 'album',
                albumId: $albumId,
                artistId: null,
                playlistId: null,
                createdAt: $createdAt,
                updatedAt: $updatedAt,
            ),
        );

        $result = ImageResource::from($image);

        $this->assertSame($id->toString(), $result['id']);
        $this->assertSame($publicId->toString(), $result['publicId']);
        $this->assertSame('images/album-cover.jpg', $result['path']);
        $this->assertSame('jpg', $result['extension']);
        $this->assertSame('image/jpeg', $result['mimeType']);
        $this->assertSame('LEHV6nWB2yk8pyo0adR*.7kCMdnj', $result['blurhash']);
        $this->assertSame(2048, $result['size']);
        $this->assertSame(800, $result['width']);
        $this->assertSame(600, $result['height']);
        $this->assertSame('album', $result['imageableType']);
        $this->assertSame($albumId->toString(), $result['albumId']);
        $this->assertNull($result['artistId']);
        $this->assertNull($result['playlistId']);
        $this->assertSame('2025-06-01T12:00:00+00:00', $result['createdAt']);
        $this->assertSame('2025-06-02T15:30:00+00:00', $result['updatedAt']);
    }

    public function testFromTransformsImageWithNullOwnerIds(): void
    {
        $image = Image::reconstitute(
            new \App\Media\Domain\Model\ImageState(
                id: Uuid::v4(),
                publicId: PublicId::fromString(str_repeat('b', 21)),
                path: 'images/artist-photo.png',
                extension: 'png',
                mimeType: 'image/png',
                blurhash: null,
                size: 4096,
                width: 500,
                height: 500,
                imageableType: 'artist',
                albumId: null,
                artistId: null,
                playlistId: null,
                createdAt: new DateTimeImmutable(),
                updatedAt: new DateTimeImmutable(),
            ),
        );

        $result = ImageResource::from($image);

        $this->assertNull($result['albumId']);
        $this->assertNull($result['artistId']);
        $this->assertNull($result['playlistId']);
        $this->assertNull($result['blurhash']);
    }

    public function testFromTransformsImageWithAllOwnerIds(): void
    {
        $albumId = Uuid::v4();
        $artistId = Uuid::v4();
        $playlistId = Uuid::v4();

        $image = Image::reconstitute(
            new \App\Media\Domain\Model\ImageState(
                id: Uuid::v4(),
                publicId: PublicId::fromString(str_repeat('c', 21)),
                path: 'images/multi.jpg',
                extension: 'jpg',
                mimeType: 'image/jpeg',
                blurhash: null,
                size: 1000,
                width: 100,
                height: 100,
                imageableType: 'playlist',
                albumId: $albumId,
                artistId: $artistId,
                playlistId: $playlistId,
                createdAt: new DateTimeImmutable(),
                updatedAt: new DateTimeImmutable(),
            ),
        );

        $result = ImageResource::from($image);

        $this->assertSame($albumId->toString(), $result['albumId']);
        $this->assertSame($artistId->toString(), $result['artistId']);
        $this->assertSame($playlistId->toString(), $result['playlistId']);
    }

    public function testCollectionTransformsMultipleImages(): void
    {
        $image1 = Image::reconstitute(
            new \App\Media\Domain\Model\ImageState(
                id: Uuid::v4(),
                publicId: PublicId::fromString(str_repeat('d', 21)),
                path: 'images/one.jpg',
                extension: 'jpg',
                mimeType: 'image/jpeg',
                blurhash: null,
                size: 100,
                width: 10,
                height: 10,
                imageableType: 'album',
                albumId: null,
                artistId: null,
                playlistId: null,
                createdAt: new DateTimeImmutable(),
                updatedAt: new DateTimeImmutable(),
            ),
        );

        $image2 = Image::reconstitute(
            new \App\Media\Domain\Model\ImageState(
                id: Uuid::v4(),
                publicId: PublicId::fromString(str_repeat('e', 21)),
                path: 'images/two.png',
                extension: 'png',
                mimeType: 'image/png',
                blurhash: null,
                size: 200,
                width: 20,
                height: 20,
                imageableType: 'artist',
                albumId: null,
                artistId: null,
                playlistId: null,
                createdAt: new DateTimeImmutable(),
                updatedAt: new DateTimeImmutable(),
            ),
        );

        $result = ImageResource::collection([$image1, $image2]);

        $this->assertCount(2, $result);
        $this->assertSame('images/one.jpg', $result[0]['path']);
        $this->assertSame('images/two.png', $result[1]['path']);
    }
}
