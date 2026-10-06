<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Infrastructure;

use App\Catalog\Application\Port\SongLyricSignature;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\Repository\AlbumRepositoryInterface;
use App\Catalog\Domain\Repository\SongRepositoryInterface;
use App\Catalog\Infrastructure\SongLookupService;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class SongLookupServiceTest extends TestCase
{
    public function testResolvesOnlyTheVisibleSongIdForAPublicIdUnderTheScope(): void
    {
        $publicId = new PublicId();
        $scope = LibraryReadScope::restricted([Uuid::v7()]);
        $song = Song::create(Uuid::v7(), 'Visible', '/visible.flac', 1, 'audio/flac');
        $songs = $this->createMock(SongRepositoryInterface::class);
        $songs->expects($this->exactly(2))->method('findVisibleByPublicId')
            ->with($publicId, $scope)
            ->willReturnOnConsecutiveCalls($song, null);
        $lookup = new SongLookupService($songs, $this->createStub(AlbumRepositoryInterface::class), $this->createStub(Connection::class));

        self::assertSame($song->getId(), $lookup->findVisibleSongId($publicId, $scope));
        self::assertNull($lookup->findVisibleSongId($publicId, $scope));
    }

    public function testLyricSignatureCarriesTitlePrimaryArtistAlbumTitleAndDuration(): void
    {
        $album = Album::create(Uuid::v7(), 'The Album', 'album');
        $song = Song::create($album->getId(), 'The Song', '/song.flac', 1, 'audio/flac', length: 233.5);
        $songs = $this->createStub(SongRepositoryInterface::class);
        $songs->method('findByUuid')->willReturnCallback(static fn (Uuid $id): ?Song => $id->equals($song->getId()) ? $song : null);
        $songs->method('getArtistNameForSong')->willReturn('The Artist');
        $albums = $this->createMock(AlbumRepositoryInterface::class);
        $albums->expects($this->once())->method('findByUuid')->with($album->getId())->willReturn($album);
        $lookup = new SongLookupService($songs, $albums, $this->createStub(Connection::class));

        self::assertEquals(new SongLyricSignature('The Song', 'The Artist', 'The Album', 233.5), $lookup->findLyricSignature($song->getId()));
        self::assertNull($lookup->findLyricSignature(Uuid::v7()));
    }

    public function testLyricSignatureKeepsMissingArtistAlbumAndDurationAsNull(): void
    {
        $song = Song::create(Uuid::v7(), 'Bare', '/bare.flac', 1, 'audio/flac');
        $songs = $this->createStub(SongRepositoryInterface::class);
        $songs->method('findByUuid')->willReturn($song);
        $songs->method('getArtistNameForSong')->willReturn(null);
        $albums = $this->createStub(AlbumRepositoryInterface::class);
        $albums->method('findByUuid')->willReturn(null);
        $lookup = new SongLookupService($songs, $albums, $this->createStub(Connection::class));

        self::assertEquals(new SongLyricSignature('Bare', null, null, null), $lookup->findLyricSignature($song->getId()));
    }
}
