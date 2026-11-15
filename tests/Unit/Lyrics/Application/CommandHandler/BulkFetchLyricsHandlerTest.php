<?php

declare(strict_types=1);

namespace App\Tests\Unit\Lyrics\Application\CommandHandler;

use App\Catalog\Application\Port\SongLookupInterface;
use App\Catalog\Application\Port\SongLyricSignature;
use App\Lyrics\Application\Command\BulkFetchLyricsCommand;
use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Lyrics\Application\CommandHandler\BulkFetchLyricsHandler;
use App\Lyrics\Application\DTO\BulkFetchLyricsResult;
use App\Lyrics\Domain\Model\Lyrics;
use App\Lyrics\Domain\Repository\LyricsRepositoryInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\JobCancelledException;
use App\Shared\Application\Port\CurrentJobInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Tests\Fixtures\Messaging\CancelAtCheckpoint;
use App\Tests\Unit\Lyrics\InMemoryQueuedLyricsFetches;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class BulkFetchLyricsHandlerTest extends TestCase
{
    /** @var list<Envelope> */
    private array $queued = [];

    private InMemoryQueuedLyricsFetches $marks;

    /** @var list<list<string>> the song IDs of each signature lookup, in order */
    private array $signatureLookups = [];

    /** @var list<list<string>> the song IDs of each lookup of which songs have lyrics, in order */
    private array $lyricsLookups = [];

    protected function setUp(): void
    {
        $this->marks = new InMemoryQueuedLyricsFetches();
    }

    public function testWalksSongIdCursorToTheEndAndQueuesOneFetchPerSongWithoutLyrics(): void
    {
        $firstPage = array_map(static fn (): Uuid => Uuid::v7(), range(1, 50));
        $secondPage = array_map(static fn (): Uuid => Uuid::v7(), range(1, 50));
        $cached = $secondPage[10];
        $calls = [];
        $songs = $this->createMock(SongLookupInterface::class);
        $songs->expects($this->exactly(3))->method('songIdsAfter')
            ->willReturnCallback(static function (?Uuid $after, int $limit) use (&$calls, $firstPage, $secondPage): array {
                $calls[] = [$after?->toString(), $limit];

                return match ($after?->toString()) {
                    null => $firstPage,
                    end($firstPage)->toString() => $secondPage,
                    end($secondPage)->toString() => [],
                    default => self::fail('Unexpected continuation ' . $after->toString()),
                };
            });

        self::assertSame(99, $this->handler($songs, $this->lyricsFor([$cached]))(new BulkFetchLyricsCommand())->queued);
        self::assertSame([
            [null, 50],
            [end($firstPage)->toString(), 50],
            [end($secondPage)->toString(), 50],
        ], $calls);
        $expected = array_values(array_filter(
            array_map(static fn (Uuid $id): string => $id->toString(), [...$firstPage, ...$secondPage]),
            static fn (string $id): bool => $id !== $cached->toString(),
        ));
        self::assertSame($expected, $this->queuedSongIds());
    }

    public function testShortPageEndsTheWalkAndLimitBoundsThePageSize(): void
    {
        $songs = $this->createMock(SongLookupInterface::class);
        $songs->expects($this->once())->method('songIdsAfter')->with(null, 3)->willReturn([Uuid::v7(), Uuid::v7()]);

        self::assertSame(2, $this->handler($songs)(new BulkFetchLyricsCommand(limit: 3))->queued);
        self::assertCount(2, $this->queued);
    }

    public function testQueuesEachFetchOnTheWorkersTransportSpacedByTheDelayInsteadOfSleeping(): void
    {
        $songs = $this->createStub(SongLookupInterface::class);
        $songs->method('songIdsAfter')->willReturn([Uuid::v7(), Uuid::v7(), Uuid::v7()]);

        $started = microtime(true);
        self::assertSame(3, $this->handler($songs)(new BulkFetchLyricsCommand(limit: 3, delayMs: 4000))->queued);

        self::assertLessThan(1.0, microtime(true) - $started, 'The handler queues the fetches and returns; it does not wait between them.');
        self::assertSame([0, 4000, 8000], $this->queuedDelays());
        foreach ($this->queued as $envelope) {
            self::assertSame(['async'], $envelope->last(TransportNamesStamp::class)?->getTransportNames());
        }
    }

    /** @return iterable<string, array{BulkFetchLyricsCommand, int}> */
    public static function runsAndTheirSpacing(): iterable
    {
        yield 'no delay' => [new BulkFetchLyricsCommand(limit: 3), 3000];
        yield 'a delay below the minimum' => [new BulkFetchLyricsCommand(limit: 3, delayMs: 500), 3000];
        yield 'a longer delay' => [new BulkFetchLyricsCommand(limit: 3, delayMs: 10_000), 10_000];
        // Built as the scheduler builds a command, from the parameters stored with the schedule.
        yield 'a schedule that stored a delay below the minimum' => [new BulkFetchLyricsCommand(...['limit' => 3, 'delayMs' => 500]), 3000];
    }

    /**
     * A turn under LRCLIB's pace comes every two seconds and a request takes time of its own,
     * so fetches queued less than three seconds apart pile up on the workers. A run spaces its
     * fetches three seconds apart unless it is asked for more, logs the spacing it used, and
     * marks each fetch as queued.
     */
    #[DataProvider('runsAndTheirSpacing')]
    public function testARunSpacesItsFetchesAtLeastThreeSecondsApartAndMarksThemAsQueued(BulkFetchLyricsCommand $command, int $spacing): void
    {
        $songs = $this->createStub(SongLookupInterface::class);
        $songs->method('songIdsAfter')->willReturn([Uuid::v7(), Uuid::v7(), Uuid::v7()]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with(
            'Bulk lyrics fetch queued',
            $this->callback(static fn (array $context): bool => $context['delay_ms'] === $spacing),
        );

        $this->handler($songs, logger: $logger)($command);

        self::assertSame([0, $spacing, 2 * $spacing], $this->queuedDelays());
        foreach ($this->queued as $envelope) {
            self::assertTrue($envelope->getMessage()->isQueued());
        }
    }

    public function testASchedulesFormOffersTheSpacingARunUsesByDefault(): void
    {
        self::assertSame(3000, BulkFetchLyricsCommand::schedulerParameters()['delayMs']['default']);
    }

    public function testWithoutALimitEverySongWithoutLyricsIsQueued(): void
    {
        $pages = [array_map(static fn (): Uuid => Uuid::v7(), range(1, 50)), [Uuid::v7()]];
        $songs = $this->createStub(SongLookupInterface::class);
        $songs->method('songIdsAfter')->willReturnCallback(static function () use (&$pages): array {
            return array_shift($pages) ?? [];
        });

        self::assertSame(51, $this->handler($songs)(new BulkFetchLyricsCommand())->queued);
    }

    /** @return iterable<string, array{BulkFetchLyricsCommand}> */
    public static function invalidCommands(): iterable
    {
        yield 'zero limit' => [new BulkFetchLyricsCommand(limit: 0)];
        yield 'negative limit' => [new BulkFetchLyricsCommand(limit: -5)];
        yield 'negative delay' => [new BulkFetchLyricsCommand(limit: 10, delayMs: -1)];
    }

    #[DataProvider('invalidCommands')]
    public function testRejectsALimitBelowOneAndANegativeDelayBeforeQueuing(BulkFetchLyricsCommand $command): void
    {
        $songs = $this->createMock(SongLookupInterface::class);
        $songs->expects($this->never())->method('songIdsAfter');

        $this->expectException(InvalidInputException::class);

        $this->handler($songs)($command);
    }

    public function testACancelledJobStopsBeforeItsNextSongAndKeepsWhatItQueued(): void
    {
        $songIds = [Uuid::v7(), Uuid::v7(), Uuid::v7()];
        $songs = $this->createStub(SongLookupInterface::class);
        $songs->method('songIdsAfter')->willReturn($songIds);

        try {
            $this->handler($songs, cancellation: new CancelAtCheckpoint(passes: 2))(new BulkFetchLyricsCommand(delayMs: 4500));
            self::fail('A cancelled bulk fetch must stop.');
        } catch (JobCancelledException) {
        }

        self::assertSame([$songIds[0]->toString(), $songIds[1]->toString()], $this->queuedSongIds());
        // The fetches it queued are skipped: the run is recorded cancelled until after the last one is due.
        self::assertSame([$this->queuedRunId()->toString() => 86405], $this->marks->cancelledRuns);
    }

    public function testAnOverlappingRunSkipsTheSongsThatAnEarlierRunQueued(): void
    {
        $songIds = [Uuid::v7(), Uuid::v7(), Uuid::v7()];
        $songs = $this->createStub(SongLookupInterface::class);
        $songs->method('songIdsAfter')->willReturnOnConsecutiveCalls([$songIds[0], $songIds[1]], $songIds);

        self::assertSame(2, $this->handler($songs)(new BulkFetchLyricsCommand())->queued);
        self::assertSame(1, $this->handler($songs)(new BulkFetchLyricsCommand())->queued);

        self::assertSame(
            [$songIds[0]->toString(), $songIds[1]->toString(), $songIds[2]->toString()],
            $this->queuedSongIds(),
        );
        // The second run paces its own fetches from its start.
        self::assertSame(0, $this->queued[2]->last(DelayStamp::class)?->getDelay());
    }

    public function testEachSongStaysMarkedQueuedUntilADayAfterItsFetchIsDue(): void
    {
        $songIds = [Uuid::v7(), Uuid::v7(), Uuid::v7()];
        $songs = $this->createStub(SongLookupInterface::class);
        $songs->method('songIdsAfter')->willReturn($songIds);

        $this->handler($songs)(new BulkFetchLyricsCommand(limit: 3, delayMs: 3500));

        self::assertSame([
            $songIds[0]->toString() => 86400,
            $songIds[1]->toString() => 86404,
            $songIds[2]->toString() => 86407,
        ], $this->marks->queued);
    }

    public function testTheFetchesOfOneRunNameTheRunAndEachRunHasItsOwn(): void
    {
        $songs = $this->createStub(SongLookupInterface::class);
        $songs->method('songIdsAfter')->willReturnOnConsecutiveCalls([Uuid::v7(), Uuid::v7()], [Uuid::v7()]);

        $this->handler($songs)(new BulkFetchLyricsCommand());
        $this->handler($songs)(new BulkFetchLyricsCommand());

        $runIds = array_map(
            static fn (Envelope $envelope): ?string => $envelope->getMessage()->getBulkRunId()?->toString(),
            $this->queued,
        );
        self::assertNotNull($runIds[0]);
        self::assertSame($runIds[0], $runIds[1]);
        self::assertNotNull($runIds[2]);
        self::assertNotSame($runIds[0], $runIds[2]);
    }

    public function testAFinishedRunIsRecordedAgainstItsJobUntilADayAfterItsLastFetchIsDue(): void
    {
        $songs = $this->createStub(SongLookupInterface::class);
        $songs->method('songIdsAfter')->willReturn([Uuid::v7(), Uuid::v7(), Uuid::v7()]);

        $this->handler($songs, jobId: 'bulk-job')(new BulkFetchLyricsCommand(limit: 3, delayMs: 3500));

        // Cancelling the finished job finds the run, so the fetches still to come are skipped.
        self::assertSame(['bulk-job' => [$this->queuedRunId()->toString(), 86407]], $this->marks->jobRuns);
    }

    public function testARunOutsideAJobOrThatQueuedNothingLeavesNoJobRunToCancel(): void
    {
        $songId = Uuid::v7();
        $songs = $this->createStub(SongLookupInterface::class);
        $songs->method('songIdsAfter')->willReturn([$songId]);
        $this->handler($songs)(new BulkFetchLyricsCommand());

        self::assertSame(0, $this->handler($songs, $this->lyricsFor([$songId]), jobId: 'empty-job')(new BulkFetchLyricsCommand())->queued);

        self::assertSame([], $this->marks->jobRuns);
    }

    public function testARunCancelledWhileItQueuesRecordsNoRunForItsJob(): void
    {
        $songs = $this->createStub(SongLookupInterface::class);
        $songs->method('songIdsAfter')->willReturn([Uuid::v7(), Uuid::v7()]);

        try {
            $this->handler($songs, cancellation: new CancelAtCheckpoint(passes: 1), jobId: 'bulk-job')(new BulkFetchLyricsCommand());
            self::fail('A cancelled bulk fetch must stop.');
        } catch (JobCancelledException) {
        }

        // The job ends cancelled with its run already cancelled; there is nothing left to cancel.
        self::assertSame([], $this->marks->jobRuns);
        self::assertCount(1, $this->marks->cancelledRuns);
        // Its walk did not finish, so it does not replace the latest run.
        self::assertNull($this->marks->latestRun());
    }

    // --- The run's tally ---

    /** A song a lookup cannot be made for is counted per cause and not queued; skipping it leaves the limit for the rest. */
    public function testSongsWithoutAnArtistOrALengthAreCountedPerCauseInsteadOfQueued(): void
    {
        $songIds = array_map(static fn (): Uuid => Uuid::v7(), range(1, 6));
        $songs = $this->createStub(SongLookupInterface::class);
        $songs->method('songIdsAfter')->willReturn($songIds);

        $result = $this->handler($songs, signatures: [
            $songIds[0]->toString() => new SongLyricSignature('Rescue Me', null, 'Rescue Me', 200.0),
            $songIds[1]->toString() => new SongLyricSignature('Avarice', '  ', 'Ten Thousand Fists', 200.0),
            $songIds[2]->toString() => new SongLyricSignature('Finer Things', 'Post Malone', 'Austin', 0.0),
            $songIds[3]->toString() => new SongLyricSignature('Amore Infelice', 'Suspekt', 'Elektra', null),
            // Deleted after its ID page was read: neither queued nor counted.
            $songIds[4]->toString() => null,
        ])(new BulkFetchLyricsCommand(limit: 1));

        self::assertEquals(new BulkFetchLyricsResult(queued: 1, skippedNoArtist: 2, skippedNoLength: 2), $result);
        self::assertSame([$songIds[5]->toString()], $this->queuedSongIds());
        self::assertSame([$songIds[5]->toString()], array_keys($this->marks->queued), 'A skipped song is not marked queued.');
    }

    /** The walk reads the signatures of a page of songs in one lookup, not one per song. */
    public function testTheWalkReadsTheSignaturesOfEachPageInOneLookup(): void
    {
        $pages = [array_map(static fn (): Uuid => Uuid::v7(), range(1, 50)), [Uuid::v7()]];
        $songs = $this->createStub(SongLookupInterface::class);
        $songs->method('songIdsAfter')->willReturnOnConsecutiveCalls(...$pages);

        self::assertSame(51, $this->handler($songs)(new BulkFetchLyricsCommand())->queued);

        self::assertSame(
            array_map(static fn (array $page): array => array_map(static fn (Uuid $id): string => $id->toString(), $page), $pages),
            $this->signatureLookups,
        );
    }

    /**
     * The walk reads which songs of a page have lyrics in one lookup, not one per song. A song
     * with lyrics is skipped before its signature is read, so it is never counted as skipped.
     */
    public function testTheWalkReadsWhichSongsOfEachPageHaveLyricsInOneLookupAndSkipsThem(): void
    {
        $pages = [array_map(static fn (): Uuid => Uuid::v7(), range(1, 50)), [Uuid::v7(), Uuid::v7()]];
        $songs = $this->createStub(SongLookupInterface::class);
        $songs->method('songIdsAfter')->willReturnOnConsecutiveCalls(...$pages);
        $withLyrics = [$pages[0][3], $pages[0][4], $pages[1][0]];

        $result = $this->handler($songs, $this->lyricsFor($withLyrics), signatures: [
            // Has lyrics as well as no artist: skipped for its lyrics, not counted.
            $pages[0][4]->toString() => new SongLyricSignature('Rescue Me', null, 'Rescue Me', 200.0),
            $pages[0][5]->toString() => new SongLyricSignature('Avarice', null, 'Ten Thousand Fists', 200.0),
        ])(new BulkFetchLyricsCommand());

        self::assertEquals(new BulkFetchLyricsResult(queued: 48, skippedNoArtist: 1, skippedNoLength: 0), $result);
        $notQueued = array_map(static fn (Uuid $id): string => $id->toString(), [...$withLyrics, $pages[0][5]]);
        $expected = array_values(array_filter(
            array_map(static fn (Uuid $id): string => $id->toString(), [...$pages[0], ...$pages[1]]),
            static fn (string $id): bool => !in_array($id, $notQueued, true),
        ));
        self::assertSame($expected, $this->queuedSongIds());
        self::assertSame(
            array_map(static fn (array $page): array => array_map(static fn (Uuid $id): string => $id->toString(), $page), $pages),
            $this->lyricsLookups,
        );
        $withLyricsIds = array_map(static fn (Uuid $id): string => $id->toString(), $withLyrics);
        self::assertSame(
            array_map(static fn (array $page): array => array_values(array_filter(
                array_map(static fn (Uuid $id): string => $id->toString(), $page),
                static fn (string $id): bool => !in_array($id, $withLyricsIds, true),
            )), $pages),
            $this->signatureLookups,
            'Signatures are read only for the songs without lyrics.',
        );
    }

    public function testAFinishedRunRecordsItsTallyWithItsJobAndBecomesTheLatestRun(): void
    {
        $songIds = [Uuid::v7(), Uuid::v7(), Uuid::v7()];
        $songs = $this->createStub(SongLookupInterface::class);
        $songs->method('songIdsAfter')->willReturn($songIds);

        $this->handler($songs, jobId: 'bulk-job', signatures: [
            $songIds[0]->toString() => new SongLyricSignature('Rescue Me', null, 'Rescue Me', 200.0),
        ])(new BulkFetchLyricsCommand(delayMs: 3500));

        $tally = $this->marks->latestRun();
        self::assertNotNull($tally);
        self::assertSame('bulk-job', $tally->jobId);
        self::assertSame(2, $tally->queued);
        self::assertSame(1, $tally->skippedNoArtist);
        self::assertSame(0, $tally->skippedNoLength);
        self::assertSame(0, $tally->found);
        // It lasts as long as the run's tracking: a day after its last fetch is due.
        self::assertSame(86404, $this->marks->runs[$this->queuedRunId()->toString()]['ttl']);
    }

    /** A run that queues nothing is still the latest run, with its skipped counts, for at least the grace period. */
    public function testARunThatQueuesNothingStillRecordsItsTally(): void
    {
        $songIds = [Uuid::v7(), Uuid::v7()];
        $songs = $this->createStub(SongLookupInterface::class);
        $songs->method('songIdsAfter')->willReturn($songIds);

        $result = $this->handler($songs, jobId: 'empty-job', signatures: [
            $songIds[0]->toString() => new SongLyricSignature('Rescue Me', null, 'Rescue Me', 200.0),
            $songIds[1]->toString() => new SongLyricSignature('Avarice', null, 'Ten Thousand Fists', 200.0),
        ])(new BulkFetchLyricsCommand());

        self::assertEquals(new BulkFetchLyricsResult(queued: 0, skippedNoArtist: 2, skippedNoLength: 0), $result);
        self::assertSame([], $this->queued);
        $tally = $this->marks->latestRun();
        self::assertNotNull($tally);
        self::assertSame('empty-job', $tally->jobId);
        self::assertSame(0, $tally->queued);
        self::assertSame(2, $tally->skippedNoArtist);
        self::assertSame(86400, $this->marks->runs[(string) $this->marks->latestRunId]['ttl']);
        // Nothing waits, so the finished job has nothing to cancel.
        self::assertSame([], $this->marks->jobRuns);
    }

    /**
     * @param array<string, SongLyricSignature|null> $signatures song ID => its signature, or null for a deleted song;
     *                                                every other song has an artist and a length
     */
    private function handler(
        SongLookupInterface $songs,
        ?LyricsRepositoryInterface $lyrics = null,
        CancelAtCheckpoint $cancellation = new CancelAtCheckpoint(),
        ?string $jobId = null,
        array $signatures = [],
        LoggerInterface $logger = new NullLogger(),
    ): BulkFetchLyricsHandler {
        $lyrics ??= $this->lyricsFor();
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (FetchLyricsCommand $command, array $stamps = []): Envelope {
            $envelope = new Envelope($command, $stamps);
            $this->queued[] = $envelope;

            return $envelope;
        });

        $currentJob = new readonly class ($jobId) implements CurrentJobInterface {
            public function __construct(private ?string $jobId)
            {
            }

            public function currentJobId(): ?string
            {
                return $this->jobId;
            }
        };

        $lookup = self::withSignatures($songs, $signatures, function (array $songIds): void {
            $this->signatureLookups[] = $songIds;
        });

        return new BulkFetchLyricsHandler($lookup, $lyrics, $bus, $logger, $cancellation, $this->marks, $currentJob);
    }

    /**
     * The song pages of $songs, with the given signatures, read a page at a time.
     *
     * @param array<string, SongLyricSignature|null> $signatures
     * @param \Closure(list<string>): void $onLookup told the song IDs of each signature lookup
     */
    private static function withSignatures(SongLookupInterface $songs, array $signatures, \Closure $onLookup): SongLookupInterface
    {
        return new readonly class ($songs, $signatures, $onLookup) implements SongLookupInterface {
            /**
             * @param array<string, SongLyricSignature|null> $signatures
             * @param \Closure(list<string>): void $onLookup
             */
            public function __construct(private SongLookupInterface $songs, private array $signatures, private \Closure $onLookup)
            {
            }

            public function findVisibleSongId(PublicId $publicId, LibraryReadScope $scope): ?Uuid
            {
                return $this->songs->findVisibleSongId($publicId, $scope);
            }

            public function findLyricSignature(Uuid $songId): ?SongLyricSignature
            {
                throw new \LogicException('The bulk walk reads signatures a page at a time.');
            }

            public function findLyricSignatures(array $songIds): array
            {
                ($this->onLookup)(array_map(static fn (Uuid $id): string => $id->toString(), $songIds));
                $found = [];
                foreach ($songIds as $songId) {
                    $key = $songId->toString();
                    $signature = array_key_exists($key, $this->signatures)
                        ? $this->signatures[$key]
                        : new SongLyricSignature('Track', 'Artist', 'Album', 200.0);
                    if ($signature !== null) {
                        $found[$key] = $signature;
                    }
                }

                return $found;
            }

            public function songIdsAfter(?Uuid $after, int $limit): array
            {
                return $this->songs->songIdsAfter($after, $limit);
            }

            public function visibleSongIds(array $songIds, LibraryReadScope $scope): array
            {
                return $this->songs->visibleSongIds($songIds, $scope);
            }
        };
    }

    /**
     * Stored lyrics for the given songs, read a page at a time.
     *
     * @param list<Uuid> $songIds the songs that have lyrics
     */
    private function lyricsFor(array $songIds = []): LyricsRepositoryInterface
    {
        $withLyrics = array_fill_keys(array_map(static fn (Uuid $id): string => $id->toString(), $songIds), true);

        return new readonly class ($withLyrics, function (array $songIds): void {
            $this->lyricsLookups[] = $songIds;
        }) implements LyricsRepositoryInterface {
            /**
             * @param array<string, true> $withLyrics
             * @param \Closure(list<string>): void $onLookup
             */
            public function __construct(private array $withLyrics, private \Closure $onLookup)
            {
            }

            public function songIdsWithLyrics(array $songIds): array
            {
                $ids = array_map(static fn (Uuid $id): string => $id->toString(), $songIds);
                ($this->onLookup)($ids);

                return array_intersect_key($this->withLyrics, array_flip($ids));
            }

            public function findBySongId(Uuid $songId): ?Lyrics
            {
                throw new \LogicException('The bulk walk reads which songs have lyrics a page at a time.');
            }

            public function add(Lyrics $lyrics): bool
            {
                throw new \LogicException('The bulk walk stores no lyrics.');
            }

            public function save(Lyrics $lyrics): void
            {
                throw new \LogicException('The bulk walk stores no lyrics.');
            }

            public function delete(Lyrics $lyrics): void
            {
                throw new \LogicException('The bulk walk deletes no lyrics.');
            }
        };
    }

    private function queuedRunId(): Uuid
    {
        $runId = $this->queued[0]->getMessage()->getBulkRunId();
        self::assertInstanceOf(Uuid::class, $runId);

        return $runId;
    }

    /** @return list<int|null> the milliseconds each queued fetch is delayed by, in queue order */
    private function queuedDelays(): array
    {
        return array_map(
            static fn (Envelope $envelope): ?int => $envelope->last(DelayStamp::class)?->getDelay(),
            $this->queued,
        );
    }

    /** @return list<string> */
    private function queuedSongIds(): array
    {
        return array_map(
            static fn (Envelope $envelope): string => $envelope->getMessage()->getSongId()->toString(),
            $this->queued,
        );
    }
}
