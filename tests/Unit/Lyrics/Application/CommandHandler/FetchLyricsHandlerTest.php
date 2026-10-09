<?php

declare(strict_types=1);

namespace App\Tests\Unit\Lyrics\Application\CommandHandler;

use App\Catalog\Application\Port\SongLookupInterface;
use App\Catalog\Application\Port\SongLyricSignature;
use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Lyrics\Application\CommandHandler\FetchLyricsHandler;
use App\Lyrics\Application\DTO\LrclibResult;
use App\Lyrics\Application\Port\LrclibClientInterface;
use App\Lyrics\Domain\Model\Lyrics;
use App\Lyrics\Domain\Repository\LyricsRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Unit\Lyrics\InMemoryQueuedLyricsFetches;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class FetchLyricsHandlerTest extends TestCase
{
    private SongLookupInterface&Stub $songs;
    private LrclibClientInterface&Stub $lrclibClient;
    private LyricsRepositoryInterface&Stub $lyricsRepository;
    private LoggerInterface&Stub $logger;
    private FetchLyricsHandler $handler;
    private InMemoryQueuedLyricsFetches $marks;

    protected function setUp(): void
    {
        $this->marks = new InMemoryQueuedLyricsFetches();
        $this->songs = $this->createStub(SongLookupInterface::class);
        $this->lrclibClient = $this->createStub(LrclibClientInterface::class);
        $this->lyricsRepository = $this->createStub(LyricsRepositoryInterface::class);
        $this->logger = $this->createStub(LoggerInterface::class);

        $this->handler = $this->createFetchLyricsHandlerFixture();
    }

    private function createFetchLyricsHandlerFixture(): FetchLyricsHandler
    {
        return new FetchLyricsHandler(
            $this->songs,
            $this->lrclibClient,
            $this->lyricsRepository,
            $this->logger,
            $this->marks,
        );
    }

    // --- Happy path ---

    public function testBuildsProviderQueryFromContractSignatureAndStores(): void
    {
        $this->lrclibClient = $this->createMock(LrclibClientInterface::class);
        $this->lyricsRepository = $this->createMock(LyricsRepositoryInterface::class);
        $this->songs = $this->createMock(SongLookupInterface::class);
        $this->handler = $this->createFetchLyricsHandlerFixture();

        $songId = Uuid::v7();

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

        $this->songs->expects($this->once())->method('findLyricSignature')->with($songId)
            ->willReturn(new SongLyricSignature('Test Song', 'Test Artist', 'Test Album', 233.0));
        $this->lyricsRepository->expects($this->once())->method('findBySongId')->with($songId)->willReturn(null);
        $this->lrclibClient->expects($this->once())->method('getBySignatureCached')->with(
            'Test Song',
            'Test Artist',
            'Test Album',
            233.0,
        )->willReturn($result);
        $this->lrclibClient->expects($this->never())->method('getBySignature');

        $this->lyricsRepository->expects($this->once())->method('save')->with($this->isInstanceOf(Lyrics::class));

        $lyrics = ($this->handler)(new FetchLyricsCommand($songId));

        $this->assertNotNull($lyrics);
        $this->assertTrue($lyrics->getSongId()->equals($songId));
        $this->assertSame('Line 1\nLine 2', $lyrics->getLyrics());
        $this->assertSame('[00:17.12] Line 1\n[00:20.00] Line 2', $lyrics->getSyncedLyrics());
        $this->assertSame('lrclib', $lyrics->getSource());
        $this->assertSame(123, $lyrics->getLrclibId());
        $this->assertFalse($lyrics->isInstrumental());
    }

    public function testFallsBackToFullEndpointWhenCachedReturnsNull(): void
    {
        $this->lrclibClient = $this->createMock(LrclibClientInterface::class);
        $this->lyricsRepository = $this->createMock(LyricsRepositoryInterface::class);
        $this->handler = $this->createFetchLyricsHandlerFixture();

        $songId = Uuid::v7();

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

        $this->songs->method('findLyricSignature')->willReturn(new SongLyricSignature('Test Song', 'Test Artist', 'Test Album', 200.0));
        $this->lyricsRepository->expects($this->once())->method('findBySongId')->with($songId)->willReturn(null);
        $this->lrclibClient->method('getBySignatureCached')->willReturn(null);
        $this->lrclibClient->expects($this->once())->method('getBySignature')->with(
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
        $this->lyricsRepository = $this->createMock(LyricsRepositoryInterface::class);
        $this->handler = $this->createFetchLyricsHandlerFixture();

        $songId = Uuid::v7();

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

        $this->songs->method('findLyricSignature')->willReturn(new SongLyricSignature('Instrumental Track', 'Test Artist', 'Test Album', 180.0));
        $this->lyricsRepository->expects($this->once())->method('findBySongId')->with($songId)->willReturn(null);
        $this->lrclibClient->method('getBySignatureCached')->willReturn($result);

        $this->lyricsRepository->expects($this->once())->method('save');

        $lyrics = ($this->handler)(new FetchLyricsCommand($songId));

        $this->assertNotNull($lyrics);
        $this->assertTrue($lyrics->isInstrumental());
    }

    // --- Skip conditions ---

    public function testReturnsNullWhenSongNotFound(): void
    {
        $this->lrclibClient = $this->createMock(LrclibClientInterface::class);
        $this->lyricsRepository = $this->createMock(LyricsRepositoryInterface::class);
        $this->songs = $this->createMock(SongLookupInterface::class);
        $this->handler = $this->createFetchLyricsHandlerFixture();

        $songId = Uuid::v7();

        $this->songs->expects($this->once())->method('findLyricSignature')->with($songId)->willReturn(null);
        $this->lyricsRepository->expects($this->never())->method('findBySongId');
        $this->lrclibClient->expects($this->never())->method('getBySignatureCached');
        $this->lyricsRepository->expects($this->never())->method('save');

        $lyrics = ($this->handler)(new FetchLyricsCommand($songId));

        $this->assertNull($lyrics);
    }

    public function testReturnsExistingLyricsWithoutFetching(): void
    {
        $this->lrclibClient = $this->createMock(LrclibClientInterface::class);
        $this->lyricsRepository = $this->createMock(LyricsRepositoryInterface::class);
        $this->handler = $this->createFetchLyricsHandlerFixture();

        $songId = Uuid::v7();
        $existingLyrics = Lyrics::create($songId, 'Existing lyrics', 'embedded');

        $this->songs->method('findLyricSignature')->willReturn(new SongLyricSignature('Test', 'Test Artist', 'Test Album', 200.0));
        $this->lyricsRepository->expects($this->once())->method('findBySongId')->with($songId)->willReturn($existingLyrics);
        $this->lrclibClient->expects($this->never())->method('getBySignatureCached');
        $this->lyricsRepository->expects($this->never())->method('save');

        $result = ($this->handler)(new FetchLyricsCommand($songId));

        $this->assertSame($existingLyrics, $result);
    }

    public function testReturnsNullWhenNoArtistName(): void
    {
        $this->assertSkippedWithoutProviderCall(new SongLyricSignature('Test Song', null, 'Test Album', 200.0));
    }

    public function testReturnsNullWhenArtistNameIsEmpty(): void
    {
        $this->assertSkippedWithoutProviderCall(new SongLyricSignature('Test Song', '  ', 'Test Album', 200.0));
    }

    public function testReturnsNullWhenSongHasNoDuration(): void
    {
        $this->assertSkippedWithoutProviderCall(new SongLyricSignature('Test Song', 'Test Artist', 'Test Album', null));
    }

    public function testReturnsNullWhenNoLyricsFoundOnLrclib(): void
    {
        $this->lyricsRepository = $this->createMock(LyricsRepositoryInterface::class);
        $this->handler = $this->createFetchLyricsHandlerFixture();

        $songId = Uuid::v7();

        $this->songs->method('findLyricSignature')->willReturn(new SongLyricSignature('Obscure Song', 'Unknown Artist', 'Obscure Album', 300.0));
        $this->lyricsRepository->expects($this->once())->method('findBySongId')->with($songId)->willReturn(null);
        $this->lrclibClient->method('getBySignatureCached')->willReturn(null);
        $this->lrclibClient->method('getBySignature')->willReturn(null);
        $this->lyricsRepository->expects($this->never())->method('save');

        $lyrics = ($this->handler)(new FetchLyricsCommand($songId));

        $this->assertNull($lyrics);
    }

    public function testUsesEmptyAlbumNameWhenAlbumTitleIsUnknown(): void
    {
        $this->lrclibClient = $this->createMock(LrclibClientInterface::class);
        $this->lyricsRepository = $this->createMock(LyricsRepositoryInterface::class);
        $this->handler = $this->createFetchLyricsHandlerFixture();

        $songId = Uuid::v7();

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

        $this->songs->method('findLyricSignature')->willReturn(new SongLyricSignature('Test Song', 'Test Artist', null, 200.0));
        $this->lyricsRepository->expects($this->once())->method('findBySongId')->with($songId)->willReturn(null);
        $this->lrclibClient->expects($this->once())->method('getBySignatureCached')->with(
            'Test Song',
            'Test Artist',
            '',
            200.0,
        )->willReturn($result);

        $this->lyricsRepository->expects($this->once())->method('save');

        $lyrics = ($this->handler)(new FetchLyricsCommand($songId));

        $this->assertNotNull($lyrics);
    }

    // --- Fetches queued by a bulk run ---

    public function testAFetchOfACancelledBulkRunIsSkippedAndReleasesItsSong(): void
    {
        $this->songs = $this->createMock(SongLookupInterface::class);
        $this->lrclibClient = $this->createMock(LrclibClientInterface::class);
        $this->handler = $this->createFetchLyricsHandlerFixture();
        $songId = Uuid::v7();
        $runId = Uuid::v7();
        $this->marks->markQueued($songId, 60);
        $this->marks->markRunCancelled($runId, 60);

        $this->songs->expects($this->never())->method('findLyricSignature');
        $this->lrclibClient->expects($this->never())->method('getBySignatureCached');
        $this->lrclibClient->expects($this->never())->method('getBySignature');

        $this->assertNull(($this->handler)(new FetchLyricsCommand($songId, $runId)));
        $this->assertSame([], $this->marks->queued);
    }

    public function testAFetchOfABulkRunReleasesItsSongOnceItRan(): void
    {
        $this->lrclibClient = $this->createMock(LrclibClientInterface::class);
        $this->handler = $this->createFetchLyricsHandlerFixture();
        $songId = Uuid::v7();
        $this->marks->markQueued($songId, 60);

        $this->songs->method('findLyricSignature')->willReturn(new SongLyricSignature('Test Song', 'Test Artist', 'Test Album', 200.0));
        $this->lyricsRepository->method('findBySongId')->willReturn(null);
        $this->lrclibClient->expects($this->once())->method('getBySignatureCached')->willReturn(null);
        $this->lrclibClient->expects($this->once())->method('getBySignature')->willReturn(null);

        $this->assertNull(($this->handler)(new FetchLyricsCommand($songId, Uuid::v7())));
        $this->assertSame([], $this->marks->queued);
    }

    public function testAFetchOutsideABulkRunLeavesTheQueuedMarksAlone(): void
    {
        $songId = Uuid::v7();
        $this->marks->markQueued($songId, 60);
        $this->songs->method('findLyricSignature')->willReturn(null);

        $this->assertNull(($this->handler)(new FetchLyricsCommand($songId)));
        $this->assertSame([$songId->toString() => 60], $this->marks->queued);
    }

    private function assertSkippedWithoutProviderCall(SongLyricSignature $signature): void
    {
        $this->lrclibClient = $this->createMock(LrclibClientInterface::class);
        $this->lyricsRepository = $this->createMock(LyricsRepositoryInterface::class);
        $this->handler = $this->createFetchLyricsHandlerFixture();

        $songId = Uuid::v7();

        $this->songs->method('findLyricSignature')->willReturn($signature);
        $this->lyricsRepository->expects($this->once())->method('findBySongId')->with($songId)->willReturn(null);
        $this->lrclibClient->expects($this->never())->method('getBySignatureCached');
        $this->lrclibClient->expects($this->never())->method('getBySignature');
        $this->lyricsRepository->expects($this->never())->method('save');

        $this->assertNull(($this->handler)(new FetchLyricsCommand($songId)));
    }
}
