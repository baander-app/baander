<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Domain\Model;

use App\Media\Domain\Model\Image;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ImageTest extends TestCase
{
    private Uuid $albumId;
    private Uuid $artistId;
    private Uuid $playlistId;

    protected function setUp(): void
    {
        $this->albumId = Uuid::v4();
        $this->artistId = Uuid::v4();
        $this->playlistId = Uuid::v4();
    }

    public function testCreateImageWithAllOwnerTypes(): void
    {
        $image = Image::create(
            path: 'images/cover.jpg',
            extension: 'jpg',
            mimeType: 'image/jpeg',
            size: 1024,
            width: 800,
            height: 600,
            imageableType: 'album',
            albumId: $this->albumId,
            artistId: $this->artistId,
            playlistId: $this->playlistId,
        );

        $this->assertSame('images/cover.jpg', $image->getPath());
        $this->assertSame('jpg', $image->getExtension());
        $this->assertSame('image/jpeg', $image->getMimeType());
        $this->assertSame(1024, $image->getSize());
        $this->assertSame(800, $image->getWidth());
        $this->assertSame(600, $image->getHeight());
        $this->assertSame('album', $image->getImageableType());
        $this->assertTrue($image->getAlbumId()->equals($this->albumId));
        $this->assertTrue($image->getArtistId()->equals($this->artistId));
        $this->assertTrue($image->getPlaylistId()->equals($this->playlistId));
    }

    public function testCreateImageWithAlbumOwnerType(): void
    {
        $image = Image::create(
            path: 'images/album-art.png',
            extension: 'png',
            mimeType: 'image/png',
            size: 2048,
            width: 1000,
            height: 1000,
            imageableType: 'album',
            albumId: $this->albumId,
        );

        $this->assertSame('album', $image->getImageableType());
        $this->assertTrue($image->getAlbumId()->equals($this->albumId));
        $this->assertNull($image->getArtistId());
        $this->assertNull($image->getPlaylistId());
    }

    public function testCreateImageWithArtistOwnerType(): void
    {
        $image = Image::create(
            path: 'images/artist-photo.jpg',
            extension: 'jpg',
            mimeType: 'image/jpeg',
            size: 4096,
            width: 500,
            height: 500,
            imageableType: 'artist',
            artistId: $this->artistId,
        );

        $this->assertSame('artist', $image->getImageableType());
        $this->assertNull($image->getAlbumId());
        $this->assertTrue($image->getArtistId()->equals($this->artistId));
        $this->assertNull($image->getPlaylistId());
    }

    public function testCreateImageWithPlaylistOwnerType(): void
    {
        $image = Image::create(
            path: 'images/playlist-cover.jpg',
            extension: 'jpg',
            mimeType: 'image/jpeg',
            size: 3072,
            width: 300,
            height: 300,
            imageableType: 'playlist',
            playlistId: $this->playlistId,
        );

        $this->assertSame('playlist', $image->getImageableType());
        $this->assertNull($image->getAlbumId());
        $this->assertNull($image->getArtistId());
        $this->assertTrue($image->getPlaylistId()->equals($this->playlistId));
    }

    public function testCreateImageWithNoOwnerIds(): void
    {
        $image = Image::create(
            path: 'images/generic.png',
            extension: 'png',
            mimeType: 'image/png',
            size: 512,
            width: 200,
            height: 200,
            imageableType: 'user',
        );

        $this->assertNull($image->getAlbumId());
        $this->assertNull($image->getArtistId());
        $this->assertNull($image->getPlaylistId());
    }

    public function testCreateImageGeneratesIdsAndTimestamps(): void
    {
        $before = new DateTimeImmutable();

        $image = Image::create(
            path: 'images/test.jpg',
            extension: 'jpg',
            mimeType: 'image/jpeg',
            size: 100,
            width: 50,
            height: 50,
            imageableType: 'album',
        );

        $after = new DateTimeImmutable();

        $this->assertInstanceOf(Uuid::class, $image->getId());
        $this->assertInstanceOf(PublicId::class, $image->getPublicId());
        $this->assertGreaterThanOrEqual($before, $image->getCreatedAt());
        $this->assertLessThanOrEqual($after, $image->getCreatedAt());
        $this->assertGreaterThanOrEqual($before, $image->getUpdatedAt());
        $this->assertLessThanOrEqual($after, $image->getUpdatedAt());
    }

    public function testCreateImageDefaultsBlurhashToNull(): void
    {
        $image = Image::create(
            path: 'images/test.jpg',
            extension: 'jpg',
            mimeType: 'image/jpeg',
            size: 100,
            width: 50,
            height: 50,
            imageableType: 'album',
        );

        $this->assertNull($image->getBlurhash());
    }

    public function testCreateThrowsOnEmptyPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Image path cannot be empty.');

        Image::create(
            path: '',
            extension: 'jpg',
            mimeType: 'image/jpeg',
            size: 100,
            width: 50,
            height: 50,
            imageableType: 'album',
        );
    }

    public function testReconstituteRestoresAllFields(): void
    {
        $id = Uuid::v4();
        $publicId = PublicId::fromString(str_repeat('a', 21));
        $createdAt = new DateTimeImmutable('2025-01-01 00:00:00');
        $updatedAt = new DateTimeImmutable('2025-01-02 00:00:00');

        $image = Image::reconstitute(
            new \App\Media\Domain\Model\ImageState(
                id: $id,
                publicId: $publicId,
                path: 'images/restored.jpg',
                extension: 'jpg',
                mimeType: 'image/jpeg',
                blurhash: 'LEHV6nWB2yk8pyo0adR*.7kCMdnj',
                size: 8192,
                width: 1200,
                height: 800,
                imageableType: 'album',
                albumId: $this->albumId,
                artistId: $this->artistId,
                playlistId: null,
                createdAt: $createdAt,
                updatedAt: $updatedAt,
            ),
        );

        $this->assertTrue($image->getId()->equals($id));
        $this->assertTrue($image->getPublicId()->equals($publicId));
        $this->assertSame('images/restored.jpg', $image->getPath());
        $this->assertSame('jpg', $image->getExtension());
        $this->assertSame('image/jpeg', $image->getMimeType());
        $this->assertSame('LEHV6nWB2yk8pyo0adR*.7kCMdnj', $image->getBlurhash());
        $this->assertSame(8192, $image->getSize());
        $this->assertSame(1200, $image->getWidth());
        $this->assertSame(800, $image->getHeight());
        $this->assertSame('album', $image->getImageableType());
        $this->assertTrue($image->getAlbumId()->equals($this->albumId));
        $this->assertTrue($image->getArtistId()->equals($this->artistId));
        $this->assertNull($image->getPlaylistId());
        $this->assertEquals($createdAt, $image->getCreatedAt());
        $this->assertEquals($updatedAt, $image->getUpdatedAt());
    }

    public function testReconstituteWithNullBlurhash(): void
    {
        $image = Image::reconstitute(
            new \App\Media\Domain\Model\ImageState(
                id: Uuid::v4(),
                publicId: PublicId::fromString(str_repeat('a', 21)),
                path: 'images/no-blur.jpg',
                extension: 'jpg',
                mimeType: 'image/jpeg',
                blurhash: null,
                size: 100,
                width: 10,
                height: 10,
                imageableType: 'artist',
                albumId: null,
                artistId: null,
                playlistId: null,
                createdAt: new DateTimeImmutable(),
                updatedAt: new DateTimeImmutable(),
            ),
        );

        $this->assertNull($image->getBlurhash());
    }

    public function testSetBlurhash(): void
    {
        $image = Image::create(
            path: 'images/test.jpg',
            extension: 'jpg',
            mimeType: 'image/jpeg',
            size: 100,
            width: 50,
            height: 50,
            imageableType: 'album',
        );

        $before = $image->getUpdatedAt();

        $image->setBlurhash('L6Pj0^i_.AyE_3t7t7R*~Co#Io');

        $this->assertSame('L6Pj0^i_.AyE_3t7t7R*~Co#Io', $image->getBlurhash());
        $this->assertGreaterThan($before, $image->getUpdatedAt());
    }

    public function testUpdatePath(): void
    {
        $image = Image::create(
            path: 'images/old-path.jpg',
            extension: 'jpg',
            mimeType: 'image/jpeg',
            size: 100,
            width: 50,
            height: 50,
            imageableType: 'album',
        );

        $before = $image->getUpdatedAt();

        $image->updatePath('images/new-path.jpg');

        $this->assertSame('images/new-path.jpg', $image->getPath());
        $this->assertGreaterThan($before, $image->getUpdatedAt());
    }

    public function testUpdatePathChangesUpdatedTimestampButNotCreatedAt(): void
    {
        $image = Image::create(
            path: 'images/test.jpg',
            extension: 'jpg',
            mimeType: 'image/jpeg',
            size: 100,
            width: 50,
            height: 50,
            imageableType: 'album',
        );

        $createdAt = $image->getCreatedAt();

        $image->updatePath('images/changed.jpg');

        $this->assertEquals($createdAt, $image->getCreatedAt());
        $this->assertGreaterThan($createdAt, $image->getUpdatedAt());
    }
}
