<?php

declare(strict_types=1);

namespace App\Tests\Unit\Lyrics\Application\CommandHandler;

use App\Catalog\Application\Port\SongLookupInterface;
use App\Catalog\Application\Port\SongLyricSignature;
use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Lyrics\Application\CommandHandler\FetchLyricsHandler;
use App\Lyrics\Application\DTO\LrclibRejected;
use App\Lyrics\Application\DTO\LrclibResult;
use App\Lyrics\Application\DTO\LrclibUnavailable;
use App\Lyrics\Application\DTO\LyricsFetchOutcome;
use App\Lyrics\Application\DTO\LyricsFetchResult;
use App\Lyrics\Application\Exception\LyricsProviderUnavailableException;
use App\Lyrics\Application\Port\LrclibClientInterface;
use App\Lyrics\Domain\Model\Lyrics;
use App\Lyrics\Domain\Repository\LyricsRepositoryInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Unit\Lyrics\InMemoryQueuedLyricsFetches;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
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

        $this->lyricsRepository->expects($this->once())->method('add')->with($this->isInstanceOf(Lyrics::class))->willReturn(true);

        $lyrics = ($this->handler)(new FetchLyricsCommand($songId))->lyrics;

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

        $this->lyricsRepository->expects($this->once())->method('add')->willReturn(true);

        $lyrics = ($this->handler)(new FetchLyricsCommand($songId))->lyrics;

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

        $this->lyricsRepository->expects($this->once())->method('add')->willReturn(true);

        $lyrics = ($this->handler)(new FetchLyricsCommand($songId))->lyrics;

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
        $this->lyricsRepository->expects($this->never())->method('add');

        $lyrics = ($this->handler)(new FetchLyricsCommand($songId))->lyrics;

        $this->assertNull($lyrics);
    }

    /** Deliberate: a fetch never replaces lyrics a song has; apply reports a conflict instead. */
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
        $this->lyricsRepository->expects($this->never())->method('add');

        $result = ($this->handler)(new FetchLyricsCommand($songId))->lyrics;

        $this->assertSame($existingLyrics, $result);
    }

    /** An apply or another fetch stored lyrics after the check: the fetch returns the stored ones. */
    public function testLosingTheRaceToAConcurrentStoreReturnsTheStoredLyrics(): void
    {
        $this->lyricsRepository = $this->createMock(LyricsRepositoryInterface::class);
        $this->handler = $this->createFetchLyricsHandlerFixture();
        $songId = Uuid::v7();
        $stored = Lyrics::create($songId, 'Applied meanwhile', 'lrclib', lrclibId: 912345);

        $this->songs->method('findLyricSignature')->willReturn(new SongLyricSignature('Test Song', 'Test Artist', 'Test Album', 200.0));
        $this->lrclibClient->method('getBySignatureCached')->willReturn(
            new LrclibResult(123, 'Test Song', 'Test Artist', 'Test Album', 200.0, false, 'Fetched lyrics', null),
        );
        $this->lyricsRepository->expects($this->exactly(2))->method('findBySongId')->with($songId)
            ->willReturnOnConsecutiveCalls(null, $stored);
        $this->lyricsRepository->expects($this->once())->method('add')->willReturn(false);

        $result = ($this->handler)(new FetchLyricsCommand($songId));

        $this->assertSame(LyricsFetchOutcome::Found, $result->outcome);
        $this->assertSame($stored, $result->lyrics);
    }

    /** A song without a primary artist credit is not looked up, and the fetch names that cause rather than "no match". */
    #[TestWith([null])]
    #[TestWith(['  '])]
    public function testASongWithoutAnArtistIsNotAttempted(?string $artistName): void
    {
        $result = $this->fetchWithoutProviderCall(new SongLyricSignature('Test Song', $artistName, 'Test Album', 200.0));

        $this->assertSame(LyricsFetchOutcome::NoArtist, $result->outcome);
        $this->assertConflictReason('song_has_no_artist', $result);
    }

    /** A song whose length is missing or not positive is not looked up, and the fetch names that cause. */
    #[TestWith([null])]
    #[TestWith([0.0])]
    #[TestWith([-1.5])]
    public function testASongWithoutALengthIsNotAttempted(?float $length): void
    {
        $result = $this->fetchWithoutProviderCall(new SongLyricSignature('Test Song', 'Test Artist', 'Test Album', $length));

        $this->assertSame(LyricsFetchOutcome::NoLength, $result->outcome);
        $this->assertConflictReason('song_has_no_length', $result);
    }

    /** A queued fetch ends without a retry for a cause too, and carries the cause for its run. */
    public function testAQueuedFetchOfASongWithoutAnArtistEndsWithItsCauseWithoutThrowing(): void
    {
        $songId = Uuid::v7();
        $this->marks->markQueued($songId, 60);
        $this->songs->method('findLyricSignature')->willReturn(new SongLyricSignature('Rescue Me', null, 'Rescue Me', 200.0));
        $this->lyricsRepository->method('findBySongId')->willReturn(null);

        $result = ($this->handler)(new FetchLyricsCommand($songId, Uuid::v7()));

        $this->assertSame(LyricsFetchOutcome::NoArtist, $result->outcome);
        $this->assertNull($result->lyrics);
        $this->assertSame([], $this->marks->queued);
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
        $this->lyricsRepository->expects($this->never())->method('add');

        $result = ($this->handler)(new FetchLyricsCommand($songId));

        $this->assertSame(LyricsFetchOutcome::NoMatch, $result->outcome);
        $this->assertNull($result->lyricsOrFail());
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

        $this->lyricsRepository->expects($this->once())->method('add')->willReturn(true);

        $lyrics = ($this->handler)(new FetchLyricsCommand($songId))->lyrics;

        $this->assertNotNull($lyrics);
    }

    // --- LRCLIB outages ---

    public function testAnOutageIsReportedAsProviderUnavailableWithoutThrowing(): void
    {
        $this->lrclibClient = $this->createMock(LrclibClientInterface::class);
        $this->lyricsRepository = $this->createMock(LyricsRepositoryInterface::class);
        $this->handler = $this->createFetchLyricsHandlerFixture();
        $songId = Uuid::v7();

        $this->songs->method('findLyricSignature')->willReturn(new SongLyricSignature('Test Song', 'Test Artist', 'Test Album', 200.0));
        $this->lyricsRepository->method('findBySongId')->willReturn(null);
        $this->lrclibClient->expects($this->once())->method('getBySignatureCached')->willReturn(new LrclibUnavailable('HTTP 503'));
        $this->lrclibClient->expects($this->once())->method('getBySignature')->willReturn(new LrclibUnavailable('HTTP 503'));
        $this->lyricsRepository->expects($this->never())->method('add');

        // A queued fetch ends here, so Messenger acknowledges it without a retry.
        $result = ($this->handler)(new FetchLyricsCommand($songId));

        $this->assertSame(LyricsFetchOutcome::Unavailable, $result->outcome);
        $this->assertNull($result->lyrics);
        $this->expectException(LyricsProviderUnavailableException::class);
        $result->lyricsOrFail();
    }

    // --- Requests LRCLIB rejects ---

    public function testARejectedRequestIsReportedAsRejectedWithoutThrowing(): void
    {
        $this->lrclibClient = $this->createMock(LrclibClientInterface::class);
        $this->lyricsRepository = $this->createMock(LyricsRepositoryInterface::class);
        $this->handler = $this->createFetchLyricsHandlerFixture();
        $songId = Uuid::v7();

        $this->songs->method('findLyricSignature')->willReturn(new SongLyricSignature('Amore Infelice', 'Suspekt', 'Elektra', 0.238));
        $this->lyricsRepository->method('findBySongId')->willReturn(null);
        $this->lrclibClient->expects($this->once())->method('getBySignatureCached')->willReturn(new LrclibRejected(400));
        $this->lrclibClient->expects($this->once())->method('getBySignature')->willReturn(new LrclibRejected(400));
        $this->lyricsRepository->expects($this->never())->method('add');

        $result = ($this->handler)(new FetchLyricsCommand($songId));

        $this->assertSame(LyricsFetchOutcome::Rejected, $result->outcome);
        $this->assertNull($result->lyrics);
        $this->assertConflictReason('lrclib_rejected', $result);
    }

    public function testACachedEndpointOutageFallsBackToTheFullEndpoint(): void
    {
        $this->lyricsRepository = $this->createMock(LyricsRepositoryInterface::class);
        $this->handler = $this->createFetchLyricsHandlerFixture();
        $songId = Uuid::v7();

        $this->songs->method('findLyricSignature')->willReturn(new SongLyricSignature('Test Song', 'Test Artist', 'Test Album', 200.0));
        $this->lyricsRepository->method('findBySongId')->willReturn(null);
        $this->lrclibClient->method('getBySignatureCached')->willReturn(new LrclibUnavailable('timeout'));
        $this->lrclibClient->method('getBySignature')->willReturn(
            new LrclibResult(7, 'Test Song', 'Test Artist', 'Test Album', 200.0, false, 'Full endpoint lyrics', null),
        );
        $this->lyricsRepository->expects($this->once())->method('add')->willReturn(true);

        $result = ($this->handler)(new FetchLyricsCommand($songId));

        $this->assertSame(LyricsFetchOutcome::Found, $result->outcome);
        $this->assertSame('Full endpoint lyrics', $result->lyricsOrFail()?->getLyrics());
    }

    public function testAMissOnTheFullEndpointAfterACachedOutageIsNotFound(): void
    {
        $songId = Uuid::v7();
        $this->songs->method('findLyricSignature')->willReturn(new SongLyricSignature('Test Song', 'Test Artist', 'Test Album', 200.0));
        $this->lyricsRepository->method('findBySongId')->willReturn(null);
        $this->lrclibClient->method('getBySignatureCached')->willReturn(new LrclibUnavailable('timeout'));
        $this->lrclibClient->method('getBySignature')->willReturn(null);

        $result = ($this->handler)(new FetchLyricsCommand($songId));

        $this->assertSame(LyricsFetchOutcome::NoMatch, $result->outcome);
        $this->assertNull($result->lyricsOrFail());
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

        $this->assertNull(($this->handler)(new FetchLyricsCommand($songId, $runId))->lyrics);
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

        $this->assertNull(($this->handler)(new FetchLyricsCommand($songId, Uuid::v7()))->lyrics);
        $this->assertSame([], $this->marks->queued);
    }

    public function testAFetchOutsideABulkRunLeavesTheQueuedMarksAlone(): void
    {
        $songId = Uuid::v7();
        $this->marks->markQueued($songId, 60);
        $this->songs->method('findLyricSignature')->willReturn(null);

        $this->assertNull(($this->handler)(new FetchLyricsCommand($songId))->lyrics);
        $this->assertSame([$songId->toString() => 60], $this->marks->queued);
    }

    /** @return iterable<string, array{SongLyricSignature, LrclibResult|LrclibRejected|LrclibUnavailable|null, string}> */
    public static function bulkFetchOutcomes(): iterable
    {
        $signature = new SongLyricSignature('Rescue Me', 'Headhunterz; Sound Rush; Eurielle', 'Rescue Me', 200.0);

        yield 'lyrics found' => [$signature, new LrclibResult(42, 'Rescue Me', 'Headhunterz', 'Rescue Me', 200.0, false, 'Rescue me', null), 'found'];
        yield 'no match' => [$signature, null, 'no_match'];
        yield 'rejected' => [$signature, new LrclibRejected(400), 'rejected'];
        yield 'unavailable' => [$signature, new LrclibUnavailable('HTTP 503'), 'unavailable'];
        // The song lost its credit after the run queued it.
        yield 'no artist' => [new SongLyricSignature('Rescue Me', null, 'Rescue Me', 200.0), null, 'no_artist'];
    }

    #[DataProvider('bulkFetchOutcomes')]
    public function testTheOutcomeOfABulkRunsFetchIsCountedInTheRunsTally(SongLyricSignature $signature, LrclibResult|LrclibRejected|LrclibUnavailable|null $answer, string $counted): void
    {
        $songId = Uuid::v7();
        $runId = Uuid::v7();
        $this->marks->startRun($runId, 60);
        $this->songs->method('findLyricSignature')->willReturn($signature);
        $this->lyricsRepository->method('findBySongId')->willReturn(null);
        $this->lyricsRepository->method('add')->willReturn(true);
        $this->lrclibClient->method('getBySignatureCached')->willReturn($answer);
        $this->lrclibClient->method('getBySignature')->willReturn($answer);

        ($this->handler)(new FetchLyricsCommand($songId, $runId));

        $this->assertSame([$counted => 1], $this->marks->runs[$runId->toString()]['counts']);
    }

    public function testARedeliveredFetchOfTheSameRunAndSongCountsOnce(): void
    {
        $songId = Uuid::v7();
        $runId = Uuid::v7();
        $this->marks->startRun($runId, 60);
        $this->songs->method('findLyricSignature')->willReturn(new SongLyricSignature('Avarice', 'Disturbed', 'Ten Thousand Fists', 200.0));
        $this->lyricsRepository->method('findBySongId')->willReturn(null);
        $this->lrclibClient->method('getBySignatureCached')->willReturn(null);
        $this->lrclibClient->method('getBySignature')->willReturn(null);

        ($this->handler)(new FetchLyricsCommand($songId, $runId));
        ($this->handler)(new FetchLyricsCommand($songId, $runId));

        $this->assertSame(['no_match' => 1], $this->marks->runs[$runId->toString()]['counts']);
    }

    public function testAFetchSkippedBecauseItsRunWasCancelledIsNotCounted(): void
    {
        $songId = Uuid::v7();
        $runId = Uuid::v7();
        $this->marks->startRun($runId, 60);
        $this->marks->markRunCancelled($runId, 60);

        ($this->handler)(new FetchLyricsCommand($songId, $runId));

        $this->assertSame([], $this->marks->runs[$runId->toString()]['counts']);
    }

    public function testAFetchOfASongDeletedSinceItsRunQueuedItIsNotCounted(): void
    {
        $songId = Uuid::v7();
        $runId = Uuid::v7();
        $this->marks->startRun($runId, 60);
        $this->marks->markQueued($songId, 60);
        $this->songs->method('findLyricSignature')->willReturn(null);

        $this->assertSame(LyricsFetchOutcome::NoMatch, ($this->handler)(new FetchLyricsCommand($songId, $runId))->outcome);

        $this->assertSame([], $this->marks->runs[$runId->toString()]['counts']);
        $this->assertSame([], $this->marks->queued);
    }

    private function fetchWithoutProviderCall(SongLyricSignature $signature): LyricsFetchResult
    {
        $this->lrclibClient = $this->createMock(LrclibClientInterface::class);
        $this->lyricsRepository = $this->createMock(LyricsRepositoryInterface::class);
        $this->handler = $this->createFetchLyricsHandlerFixture();

        $songId = Uuid::v7();

        $this->songs->method('findLyricSignature')->willReturn($signature);
        $this->lyricsRepository->expects($this->once())->method('findBySongId')->with($songId)->willReturn(null);
        $this->lrclibClient->expects($this->never())->method('getBySignatureCached');
        $this->lrclibClient->expects($this->never())->method('getBySignature');
        $this->lyricsRepository->expects($this->never())->method('add');

        $result = ($this->handler)(new FetchLyricsCommand($songId));
        $this->assertNull($result->lyrics);

        return $result;
    }

    /** The per-song route and app:song:lyrics:fetch report the result as a conflict naming its cause. */
    private function assertConflictReason(string $reason, LyricsFetchResult $result): void
    {
        try {
            $result->lyricsOrFail();
            $this->fail(sprintf('Expected a conflict with the reason "%s".', $reason));
        } catch (ConflictException $conflict) {
            $this->assertSame($reason, $conflict->details['reason'] ?? null);
        }
    }
}
