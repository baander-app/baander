<?php

declare(strict_types=1);

namespace App\Tests\Unit\Lyrics\Application\CommandHandler;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Song;
use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Lyrics\Application\CommandHandler\FetchLyricsHandler;
use App\Lyrics\Application\DTO\LrclibResult;
use App\Lyrics\Application\Port\LrclibClientInterface;
use App\Lyrics\Domain\Model\Lyrics;
use App\Lyrics\Domain\Repository\LyricsRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class FetchLyricsHandlerTest extends TestCase
{
    private SongPortInterface&MockObject $songPort;
    private AlbumPortInterface&MockObject $albumPort;
    private LrclibClientInterface&MockObject $lrclibClient;
    private LyricsRepositoryInterface&MockObject $lyricsRepository;
    private LoggerInterface&MockObject $logger;
    private FetchLyricsHandler $handler;

    protected function setUp(): void
    {
        $this->songPort = $this->createMock(SongPortInterface::class);
        $this->albumPort = $this->createMock(AlbumPortInterface::class);
        $this->lrclibClient = $this->createMock(LrclibClientInterface::class);
        $this->lyricsRepository = $this->createMock(LyricsRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new FetchLyricsHandler(
            $this->songPort,
            $this->albumPort,
            $this->lrclibClient,
            $this->lyricsRepository,
            $this->logger,
        );
    }

    // --- Happy path ---

    public function testFetchesFromCachedEndpointAndStores(): void
    {
        $songId = Uuid::v7();
        $albumId = Uuid::v7();
        $song = $this->createSong($albumId, 'Test Song', 233.0);
        $album = $this->createAlbum($albumId, 'Test Album');

        $result = new LrclibResult(
            id: 123,
            trackName: 'Test Song',
            artistName: 'Test Artist',
            albumName: 'Test Album',
            duration: 233.0,
            instrumental: false,
            plainLyrics: 'Line 1\nLine 2',
            syncedLyrics: '[00:17.12] Line 1\n[00:20.00] Line 2',
        );

        $this->songPort->method('findByUuid')->with($songId)->willReturn($song);
        $this->lyricsRepository->method('findBySongId')->with($songId)->willReturn(null);
        $this->songPort->method('getArtistNameForSong')->with($songId)->willReturn('Test Artist');
        $this->albumPort->method('findByUuid')->with($albumId)->willReturn($album);
        $this->lrclibClient->method('getBySignatureCached')->with(
            'Test Song',
            'Test Artist',
            'Test Album',
            233.0,
        )->willReturn($result);
        $this->lrclibClient->expects($this->never())->method('getBySignature');

        $this->lyricsRepository->expects($this->once())->method('save')->with($this->isInstanceOf(Lyrics::class));

        $lyrics = ($this->handler)(new FetchLyricsCommand($songId));

        $this->assertNotNull($lyrics);
        $this->assertSame('Line 1\nLine 2', $lyrics->getLyrics());
        $this->assertSame('[00:17.12] Line 1\n[00:20.00] Line 2', $lyrics->getSyncedLyrics());
        $this->assertSame('lrclib', $lyrics->getSource());
        $this->assertSame(123, $lyrics->getLrclibId());
        $this->assertFalse($lyrics->isInstrumental());
    }

    public function testFallsBackToFullEndpointWhenCachedReturnsNull(): void
    {
        $songId = Uuid::v7();
        $albumId = Uuid::v7();
        $song = $this->createSong($albumId, 'Test Song', 200.0);
        $album = $this->createAlbum($albumId, 'Test Album');

        $result = new LrclibResult(
            id: 456,
            trackName: 'Test Song',
            artistName: 'Test Artist',
            albumName: 'Test Album',
            duration: 200.0,
            instrumental: false,
            plainLyrics: 'Lyrics here',
            syncedLyrics: null,
        );

        $this->songPort->method('findByUuid')->with($songId)->willReturn($song);
        $this->lyricsRepository->method('findBySongId')->with($songId)->willReturn(null);
        $this->songPort->method('getArtistNameForSong')->with($songId)->willReturn('Test Artist');
        $this->albumPort->method('findByUuid')->with($albumId)->willReturn($album);
        $this->lrclibClient->method('getBySignatureCached')->willReturn(null);
        $this->lrclibClient->method('getBySignature')->with(
            'Test Song',
            'Test Artist',
            'Test Album',
            200.0,
        )->willReturn($result);

        $this->lyricsRepository->expects($this->once())->method('save');

        $lyrics = ($this->handler)(new FetchLyricsCommand($songId));

        $this->assertNotNull($lyrics);
        $this->assertSame('Lyrics here', $lyrics->getLyrics());
        $this->assertNull($lyrics->getSyncedLyrics());
    }

    public function testHandlesInstrumentalTrack(): void
    {
        $songId = Uuid::v7();
        $albumId = Uuid::v7();
        $song = $this->createSong($albumId, 'Instrumental Track', 180.0);
        $album = $this->createAlbum($albumId, 'Test Album');

        $result = new LrclibResult(
            id: 789,
            trackName: 'Instrumental Track',
            artistName: 'Test Artist',
            albumName: 'Test Album',
            duration: 180.0,
            instrumental: true,
            plainLyrics: null,
            syncedLyrics: null,
        );

        $this->songPort->method('findByUuid')->with($songId)->willReturn($song);
        $this->lyricsRepository->method('findBySongId')->with($songId)->willReturn(null);
        $this->songPort->method('getArtistNameForSong')->with($songId)->willReturn('Test Artist');
        $this->albumPort->method('findByUuid')->with($albumId)->willReturn($album);
        $this->lrclibClient->method('getBySignatureCached')->willReturn($result);

        $this->lyricsRepository->expects($this->once())->method('save');

        $lyrics = ($this->handler)(new FetchLyricsCommand($songId));

        $this->assertNotNull($lyrics);
        $this->assertTrue($lyrics->isInstrumental());
    }

    // --- Skip conditions ---

    public function testReturnsNullWhenSongNotFound(): void
    {
        $songId = Uuid::v7();

        $this->songPort->method('findByUuid')->with($songId)->willReturn(null);
        $this->lrclibClient->expects($this->never())->method('getBySignatureCached');
        $this->lyricsRepository->expects($this->never())->method('save');

        $lyrics = ($this->handler)(new FetchLyricsCommand($songId));

        $this->assertNull($lyrics);
    }

    public function testReturnsExistingLyricsWithoutFetching(): void
    {
        $songId = Uuid::v7();
        $existingLyrics = Lyrics::create($songId, 'Existing lyrics', 'embedded');

        $this->songPort->method('findByUuid')->with($songId)->willReturn(
            $this->createSong(Uuid::v7(), 'Test', 200.0),
        );
        $this->lyricsRepository->method('findBySongId')->with($songId)->willReturn($existingLyrics);
        $this->lrclibClient->expects($this->never())->method('getBySignatureCached');
        $this->lyricsRepository->expects($this->never())->method('save');

        $result = ($this->handler)(new FetchLyricsCommand($songId));

        $this->assertSame($existingLyrics, $result);
    }

    public function testReturnsNullWhenNoArtistName(): void
    {
        $songId = Uuid::v7();
        $song = $this->createSong(Uuid::v7(), 'Test Song', 200.0);

        $this->songPort->method('findByUuid')->with($songId)->willReturn($song);
        $this->lyricsRepository->method('findBySongId')->with($songId)->willReturn(null);
        $this->songPort->method('getArtistNameForSong')->with($songId)->willReturn(null);
        $this->lrclibClient->expects($this->never())->method('getBySignatureCached');

        $lyrics = ($this->handler)(new FetchLyricsCommand($songId));

        $this->assertNull($lyrics);
    }

    public function testReturnsNullWhenArtistNameIsEmpty(): void
    {
        $songId = Uuid::v7();
        $song = $this->createSong(Uuid::v7(), 'Test Song', 200.0);

        $this->songPort->method('findByUuid')->with($songId)->willReturn($song);
        $this->lyricsRepository->method('findBySongId')->with($songId)->willReturn(null);
        $this->songPort->method('getArtistNameForSong')->with($songId)->willReturn('  ');
        $this->lrclibClient->expects($this->never())->method('getBySignatureCached');

        $lyrics = ($this->handler)(new FetchLyricsCommand($songId));

        $this->assertNull($lyrics);
    }

    public function testReturnsNullWhenSongHasNoDuration(): void
    {
        $songId = Uuid::v7();
        $song = $this->createSong(Uuid::v7(), 'Test Song', null);

        $this->songPort->method('findByUuid')->with($songId)->willReturn($song);
        $this->lyricsRepository->method('findBySongId')->with($songId)->willReturn(null);
        $this->songPort->method('getArtistNameForSong')->with($songId)->willReturn('Test Artist');
        $this->lrclibClient->expects($this->never())->method('getBySignatureCached');

        $lyrics = ($this->handler)(new FetchLyricsCommand($songId));

        $this->assertNull($lyrics);
    }

    public function testReturnsNullWhenNoLyricsFoundOnLrclib(): void
    {
        $songId = Uuid::v7();
        $albumId = Uuid::v7();
        $song = $this->createSong($albumId, 'Obscure Song', 300.0);
        $album = $this->createAlbum($albumId, 'Obscure Album');

        $this->songPort->method('findByUuid')->with($songId)->willReturn($song);
        $this->lyricsRepository->method('findBySongId')->with($songId)->willReturn(null);
        $this->songPort->method('getArtistNameForSong')->with($songId)->willReturn('Unknown Artist');
        $this->albumPort->method('findByUuid')->with($albumId)->willReturn($album);
        $this->lrclibClient->method('getBySignatureCached')->willReturn(null);
        $this->lrclibClient->method('getBySignature')->willReturn(null);
        $this->lyricsRepository->expects($this->never())->method('save');

        $lyrics = ($this->handler)(new FetchLyricsCommand($songId));

        $this->assertNull($lyrics);
    }

    public function testWorksWhenAlbumNotFound(): void
    {
        $songId = Uuid::v7();
        $albumId = Uuid::v7();
        $song = $this->createSong($albumId, 'Test Song', 200.0);

        $result = new LrclibResult(
            id: 999,
            trackName: 'Test Song',
            artistName: 'Test Artist',
            albumName: '',
            duration: 200.0,
            instrumental: false,
            plainLyrics: 'Some lyrics',
            syncedLyrics: null,
        );

        $this->songPort->method('findByUuid')->with($songId)->willReturn($song);
        $this->lyricsRepository->method('findBySongId')->with($songId)->willReturn(null);
        $this->songPort->method('getArtistNameForSong')->with($songId)->willReturn('Test Artist');
        $this->albumPort->method('findByUuid')->with($albumId)->willReturn(null);
        $this->lrclibClient->method('getBySignatureCached')->with(
            'Test Song',
            'Test Artist',
            '',
            200.0,
        )->willReturn($result);

        $this->lyricsRepository->expects($this->once())->method('save');

        $lyrics = ($this->handler)(new FetchLyricsCommand($songId));

        $this->assertNotNull($lyrics);
    }

    // --- Helpers ---

    private function createSong(Uuid $albumId, string $title, ?float $length): Song
    {
        return Song::create(
            album: $albumId,
            title: $title,
            path: '/music/test.flac',
            size: 1000,
            mimeType: 'audio/flac',
            length: $length,
        );
    }

    private function createAlbum(Uuid $albumId, string $title): Album
    {
        return Album::create(
            libraryId: Uuid::v7(),
            title: $title,
            type: 'album',
        );
    }
}
