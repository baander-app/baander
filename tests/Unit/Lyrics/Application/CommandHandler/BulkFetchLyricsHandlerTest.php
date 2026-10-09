<?php

declare(strict_types=1);

namespace App\Tests\Unit\Lyrics\Application\CommandHandler;

use App\Catalog\Application\Port\SongLookupInterface;
use App\Lyrics\Application\Command\BulkFetchLyricsCommand;
use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Lyrics\Application\CommandHandler\BulkFetchLyricsHandler;
use App\Lyrics\Domain\Model\Lyrics;
use App\Lyrics\Domain\Repository\LyricsRepositoryInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\JobCancelledException;
use App\Shared\Application\Port\CurrentJobInterface;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Messaging\CancelAtCheckpoint;
use App\Tests\Unit\Lyrics\InMemoryQueuedLyricsFetches;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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
        $lyrics = $this->createStub(LyricsRepositoryInterface::class);
        $lyrics->method('findBySongId')->willReturnCallback(
            static fn (Uuid $songId): ?Lyrics => $songId->equals($cached) ? Lyrics::create($songId, 'Cached', 'embedded') : null,
        );

        self::assertSame(99, $this->handler($songs, $lyrics)(new BulkFetchLyricsCommand(delayMs: 0)));
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

        self::assertSame(2, $this->handler($songs)(new BulkFetchLyricsCommand(limit: 3, delayMs: 0)));
        self::assertCount(2, $this->queued);
    }

    public function testQueuesEachFetchOnTheWorkersTransportSpacedByTheDelayInsteadOfSleeping(): void
    {
        $songs = $this->createStub(SongLookupInterface::class);
        $songs->method('songIdsAfter')->willReturn([Uuid::v7(), Uuid::v7(), Uuid::v7()]);

        $started = microtime(true);
        self::assertSame(3, $this->handler($songs)(new BulkFetchLyricsCommand(limit: 3, delayMs: 2000)));

        self::assertLessThan(1.0, microtime(true) - $started, 'The handler queues the fetches and returns; it does not wait between them.');
        self::assertSame([0, 2000, 4000], array_map(
            static fn (Envelope $envelope): ?int => $envelope->last(DelayStamp::class)?->getDelay(),
            $this->queued,
        ));
        foreach ($this->queued as $envelope) {
            self::assertSame(['async'], $envelope->last(TransportNamesStamp::class)?->getTransportNames());
        }
    }

    public function testWithoutALimitEverySongWithoutLyricsIsQueued(): void
    {
        $pages = [array_map(static fn (): Uuid => Uuid::v7(), range(1, 50)), [Uuid::v7()]];
        $songs = $this->createStub(SongLookupInterface::class);
        $songs->method('songIdsAfter')->willReturnCallback(static function () use (&$pages): array {
            return array_shift($pages) ?? [];
        });

        self::assertSame(51, $this->handler($songs)(new BulkFetchLyricsCommand()));
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
            $this->handler($songs, cancellation: new CancelAtCheckpoint(passes: 2))(new BulkFetchLyricsCommand(delayMs: 1500));
            self::fail('A cancelled bulk fetch must stop.');
        } catch (JobCancelledException) {
        }

        self::assertSame([$songIds[0]->toString(), $songIds[1]->toString()], $this->queuedSongIds());
        // The fetches it queued are skipped: the run is recorded cancelled until after the last one is due.
        self::assertSame([$this->queuedRunId()->toString() => 86402], $this->marks->cancelledRuns);
    }

    public function testAnOverlappingRunSkipsTheSongsThatAnEarlierRunQueued(): void
    {
        $songIds = [Uuid::v7(), Uuid::v7(), Uuid::v7()];
        $songs = $this->createStub(SongLookupInterface::class);
        $songs->method('songIdsAfter')->willReturnOnConsecutiveCalls([$songIds[0], $songIds[1]], $songIds);

        self::assertSame(2, $this->handler($songs)(new BulkFetchLyricsCommand(delayMs: 500)));
        self::assertSame(1, $this->handler($songs)(new BulkFetchLyricsCommand(delayMs: 500)));

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

        $this->handler($songs)(new BulkFetchLyricsCommand(limit: 3, delayMs: 2500));

        self::assertSame([
            $songIds[0]->toString() => 86400,
            $songIds[1]->toString() => 86403,
            $songIds[2]->toString() => 86405,
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

        $this->handler($songs, jobId: 'bulk-job')(new BulkFetchLyricsCommand(limit: 3, delayMs: 2500));

        // Cancelling the finished job finds the run, so the fetches still to come are skipped.
        self::assertSame(['bulk-job' => [$this->queuedRunId()->toString(), 86405]], $this->marks->jobRuns);
    }

    public function testARunOutsideAJobOrThatQueuedNothingRecordsNoRun(): void
    {
        $songs = $this->createStub(SongLookupInterface::class);
        $songs->method('songIdsAfter')->willReturn([Uuid::v7()]);
        $this->handler($songs)(new BulkFetchLyricsCommand());

        $lyrics = $this->createStub(LyricsRepositoryInterface::class);
        $lyrics->method('findBySongId')->willReturn(Lyrics::create(Uuid::v7(), 'Stored lyrics', 'embedded'));
        self::assertSame(0, $this->handler($songs, $lyrics, jobId: 'empty-job')(new BulkFetchLyricsCommand()));

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
    }

    private function handler(
        SongLookupInterface $songs,
        ?LyricsRepositoryInterface $lyrics = null,
        CancelAtCheckpoint $cancellation = new CancelAtCheckpoint(),
        ?string $jobId = null,
    ): BulkFetchLyricsHandler {
        if ($lyrics === null) {
            $lyrics = $this->createStub(LyricsRepositoryInterface::class);
            $lyrics->method('findBySongId')->willReturn(null);
        }
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

        return new BulkFetchLyricsHandler($songs, $lyrics, $bus, new NullLogger(), $cancellation, $this->marks, $currentJob);
    }

    private function queuedRunId(): Uuid
    {
        $runId = $this->queued[0]->getMessage()->getBulkRunId();
        self::assertInstanceOf(Uuid::class, $runId);

        return $runId;
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
