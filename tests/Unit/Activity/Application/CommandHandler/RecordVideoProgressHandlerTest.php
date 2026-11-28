<?php

declare(strict_types=1);

namespace App\Tests\Unit\Activity\Application\CommandHandler;

use App\Activity\Application\Command\RecordVideoProgressCommand;
use App\Activity\Application\Command\VideoProgressAction;
use App\Activity\Application\CommandHandler\RecordVideoProgressHandler;
use App\Activity\Domain\Model\WatchedMark;
use App\Activity\Domain\Repository\ResumePositionRepositoryInterface;
use App\Activity\Domain\Repository\WatchedMarkRepositoryInterface;
use App\Catalog\Application\Dto\WatchableTarget;
use App\Catalog\Application\Dto\WatchableVideo;
use App\Catalog\Application\Port\WatchableTitlePortInterface;
use App\Party\Application\Port\WatchMembershipPortInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class RecordVideoProgressHandlerTest extends TestCase
{
    private WatchableTitlePortInterface&MockObject $titles;
    private WatchMembershipPortInterface&MockObject $parties;
    private ResumePositionRepositoryInterface&MockObject $positions;
    private WatchedMarkRepositoryInterface&MockObject $marks;
    private Uuid $memberId;
    private Uuid $videoId;
    private PublicId $videoPublicId;
    private PublicId $titlePublicId;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->titles = $this->createMock(WatchableTitlePortInterface::class);
        $this->parties = $this->createMock(WatchMembershipPortInterface::class);
        $this->positions = $this->createMock(ResumePositionRepositoryInterface::class);
        $this->marks = $this->createMock(WatchedMarkRepositoryInterface::class);
        $this->memberId = Uuid::v7();
        $this->videoId = Uuid::v7();
        $this->videoPublicId = new PublicId();
        $this->titlePublicId = new PublicId();
        $this->now = new DateTimeImmutable('2026-11-28T12:00:00+00:00');
    }

    /** @return iterable<string, array{string, string}> */
    public static function observationTimes(): iterable
    {
        yield 'older observation' => ['-5 seconds', '-5 seconds'];
        yield 'receipt-time observation' => ['+0 seconds', '+0 seconds'];
        yield 'fast client' => ['+1 day', '+0 seconds'];
    }

    #[DataProvider('observationTimes')]
    public function testObservationTimeKeepsThePastAndCapsTheFuture(string $clientOffset, string $effectiveOffset): void
    {
        $this->marks->expects(self::never())->method('saveMany');
        $this->titles->expects(self::once())->method('forProgress')->willReturn(new WatchableVideo($this->videoId, 100.0, []));
        $this->parties->expects(self::once())->method('isWatching')->willReturn(false);
        $this->positions->expects(self::once())->method('record')->with(
            $this->memberId,
            $this->videoId,
            40.0,
            $this->now->modify($effectiveOffset),
            true,
        )->willReturn(true);

        self::assertTrue($this->handler()(new RecordVideoProgressCommand(
            $this->memberId,
            $this->videoPublicId,
            'episode',
            $this->titlePublicId,
            40.0,
            VideoProgressAction::Pause,
            $this->now->modify($clientOffset),
        ))->recorded);
    }

    public function testReceiptTimeIsCapturedBeforeTransactionAndTitleWaits(): void
    {
        $this->marks->expects(self::never())->method('saveMany');
        $clock = new MockClock($this->now);
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(static function (callable $operation) use ($clock): mixed {
            $clock->sleep(5);

            return $operation();
        });
        $this->titles->expects(self::once())->method('forProgress')->willReturnCallback(function () use ($clock): WatchableVideo {
            $clock->sleep(5);

            return new WatchableVideo($this->videoId, 100.0, []);
        });
        $this->parties->expects(self::once())->method('isWatching')->willReturn(false);
        $this->positions->expects(self::once())->method('record')
            ->with($this->memberId, $this->videoId, 40.0, $this->now, true)->willReturn(true);
        $handler = new RecordVideoProgressHandler($this->titles, $this->parties, $this->positions, $this->marks, $transaction, $clock);

        self::assertTrue($handler(new RecordVideoProgressCommand(
            $this->memberId,
            $this->videoPublicId,
            'episode',
            $this->titlePublicId,
            40.0,
            VideoProgressAction::Pause,
            $this->now->modify('+1 day'),
        ))->recorded);
        self::assertEquals($this->now->modify('+10 seconds'), $clock->now());
    }

    /** @return iterable<string, array{float, VideoProgressAction, ?float, ?float, bool, bool}> */
    public static function reportCases(): iterable
    {
        yield 'exactly ninety percent' => [90.0, VideoProgressAction::Progress, 100.0, 90.0, false, false];
        yield 'past ninety percent' => [90.001, VideoProgressAction::Progress, 100.0, null, true, true];
        yield 'past probed duration' => [500.0, VideoProgressAction::Progress, 100.0, null, true, true];
        yield 'forced pause below cadence' => [1.0, VideoProgressAction::Pause, 100.0, 1.0, true, false];
        yield 'seek starts over' => [0.0, VideoProgressAction::Seek, 100.0, 0.0, true, false];
        yield 'early end does not finish' => [40.0, VideoProgressAction::End, 100.0, 40.0, true, false];
        yield 'unknown duration' => [500.0, VideoProgressAction::End, null, 500.0, true, false];
        yield 'zero duration' => [500.0, VideoProgressAction::End, 0.0, 500.0, true, false];
    }

    #[DataProvider('reportCases')]
    public function testProgressUsesTheFileDurationAndAction(float $position, VideoProgressAction $action, ?float $duration, ?float $storedPosition, bool $force, bool $finished): void
    {
        $episodeId = Uuid::v7();
        $this->titles->expects(self::once())->method('forProgress')
            ->with($this->memberId, $this->videoPublicId, 'episode', $this->titlePublicId)
            ->willReturn(new WatchableVideo($this->videoId, $duration, [new WatchableTarget('episode', $episodeId)]));
        $this->parties->expects(self::once())->method('isWatching')->with($this->memberId, $this->videoId)->willReturn(false);
        $this->positions->expects(self::once())->method('record')->with($this->memberId, $this->videoId, $storedPosition, $this->now, $force)->willReturn(true);
        $this->marks->expects($finished ? self::once() : self::never())->method('saveMany');
        $this->marks->expects(self::never())->method('deleteForMemberTargets');

        $result = $this->handler()(new RecordVideoProgressCommand($this->memberId, $this->videoPublicId, 'episode', $this->titlePublicId, $position, $action, $this->now));

        self::assertTrue($result->recorded);
    }

    public function testFinishingASpanMarksOnlyItsOpenedLibraryEpisodesForThisMember(): void
    {
        $first = Uuid::v7();
        $second = Uuid::v7();
        $this->titles->expects(self::once())->method('forProgress')->willReturn(new WatchableVideo($this->videoId, 100.0, [new WatchableTarget('episode', $first), new WatchableTarget('episode', $second)]));
        $this->parties->expects(self::once())->method('isWatching')->willReturn(false);
        $this->positions->expects(self::once())->method('record')->willReturn(true);
        $this->marks->expects(self::once())->method('saveMany')->with(self::callback(function (array $marks) use ($first, $second): bool {
            self::assertCount(2, $marks);
            self::assertSame([$first->toString(), $second->toString()], array_map(static fn (WatchedMark $mark): ?string => $mark->getEpisodeId()?->toString(), $marks));
            foreach ($marks as $mark) {
                self::assertTrue($this->memberId->equals($mark->getUserId()));
                self::assertNull($mark->getMovieId());
                self::assertEquals($this->now, $mark->getWatchedAt());
            }

            return true;
        }));

        $this->handler()($this->command(100.0));
    }

    public function testFinishingAMovieMarksThatMovie(): void
    {
        $movie = Uuid::v7();
        $this->titles->expects(self::once())->method('forProgress')->willReturn(new WatchableVideo($this->videoId, 100.0, [new WatchableTarget('movie', $movie)]));
        $this->parties->expects(self::once())->method('isWatching')->willReturn(false);
        $this->positions->expects(self::once())->method('record')->willReturn(true);
        $this->marks->expects(self::once())->method('saveMany')->with(self::callback(static function (array $marks) use ($movie): bool {
            self::assertCount(1, $marks);
            self::assertSame($movie->toString(), $marks[0]->getMovieId()?->toString());
            self::assertNull($marks[0]->getEpisodeId());

            return true;
        }));

        $this->handler()(new RecordVideoProgressCommand($this->memberId, $this->videoPublicId, 'movie', $this->titlePublicId, 100.0, VideoProgressAction::End, $this->now));
    }

    public function testARejectedLateCompletionDoesNotCreateWatchedMarks(): void
    {
        $this->titles->expects(self::once())->method('forProgress')->willReturn(new WatchableVideo($this->videoId, 100.0, [new WatchableTarget('episode', Uuid::v7())]));
        $this->parties->expects(self::once())->method('isWatching')->willReturn(false);
        $this->positions->expects(self::once())->method('record')->willReturn(false);
        $this->marks->expects(self::never())->method('saveMany');

        self::assertFalse($this->handler()($this->command(100.0))->recorded);
    }

    public function testAPartyReportWritesNoViewingState(): void
    {
        $this->titles->expects(self::once())->method('forProgress')->willReturn(new WatchableVideo($this->videoId, 100.0, [new WatchableTarget('episode', Uuid::v7())]));
        $this->parties->expects(self::once())->method('isWatching')->with($this->memberId, $this->videoId)->willReturn(true);
        $this->positions->expects(self::never())->method('record');
        $this->marks->expects(self::never())->method('saveMany');

        self::assertFalse($this->handler()($this->command(100.0))->recorded);
    }

    public function testExtraProgressPauseResumeAndCompletionNeverCreateMarks(): void
    {
        $this->titles->expects(self::exactly(4))->method('forProgress')->willReturn(new WatchableVideo($this->videoId, 100.0, []));
        $this->parties->expects(self::exactly(4))->method('isWatching')->willReturn(false);
        $expected = [[30.0, false], [40.0, true], [80.0, false], [null, true]];
        $index = 0;
        $this->positions->expects(self::exactly(4))->method('record')->willReturnCallback(function (Uuid $user, Uuid $video, ?float $position, DateTimeImmutable $reported, bool $force) use ($expected, &$index): bool {
            self::assertTrue($this->memberId->equals($user));
            self::assertTrue($this->videoId->equals($video));
            self::assertSame($expected[$index], [$position, $force]);
            ++$index;

            return true;
        });
        $this->marks->expects(self::never())->method('saveMany');
        $handler = $this->handler();
        foreach ([[30.0, VideoProgressAction::Progress], [40.0, VideoProgressAction::Pause], [80.0, VideoProgressAction::Progress], [100.0, VideoProgressAction::End]] as $offset => [$position, $action]) {
            $result = $handler(new RecordVideoProgressCommand(
                $this->memberId,
                $this->videoPublicId,
                'extra',
                $this->titlePublicId,
                $position,
                $action,
                $this->now->modify('+' . $offset . ' seconds'),
            ));
            self::assertTrue($result->recorded);
        }
    }

    public function testAPartyExtraReportNeverCreatesAResume(): void
    {
        $this->titles->expects(self::once())->method('forProgress')->willReturn(new WatchableVideo($this->videoId, 100.0, []));
        $this->parties->expects(self::once())->method('isWatching')->willReturn(true);
        $this->positions->expects(self::never())->method('record');
        $this->marks->expects(self::never())->method('saveMany');

        $result = $this->handler()(new RecordVideoProgressCommand($this->memberId, $this->videoPublicId, 'extra', $this->titlePublicId, 100.0, VideoProgressAction::End, $this->now));

        self::assertFalse($result->recorded);
    }

    public function testAnUnavailableTitleWritesNothing(): void
    {
        $this->titles->expects(self::once())->method('forProgress')->willReturn(null);
        $this->parties->expects(self::never())->method('isWatching');
        $this->positions->expects(self::never())->method('record');
        $this->marks->expects(self::never())->method('saveMany');
        $this->expectException(NotFoundException::class);

        $this->handler()($this->command(50.0));
    }

    /** @return iterable<string, array{float}> */
    public static function invalidPositions(): iterable
    {
        yield 'negative' => [-1.0];
        yield 'not a number' => [NAN];
        yield 'positive infinity' => [INF];
        yield 'negative infinity' => [-INF];
    }

    #[DataProvider('invalidPositions')]
    public function testInvalidPositionsAreRejectedBeforeReadingTheTitle(float $position): void
    {
        $this->titles->expects(self::never())->method('forProgress');
        $this->parties->expects(self::never())->method('isWatching');
        $this->marks->expects(self::never())->method('saveMany');
        $this->positions->expects(self::never())->method('record');
        $this->expectException(InvalidInputException::class);

        $this->handler()($this->command($position));
    }

    private function command(float $position): RecordVideoProgressCommand
    {
        return new RecordVideoProgressCommand($this->memberId, $this->videoPublicId, 'episode', $this->titlePublicId, $position, VideoProgressAction::Progress, $this->now);
    }

    private function handler(): RecordVideoProgressHandler
    {
        $transaction = new class implements TransactionPortInterface {
            public function run(callable $operation): mixed { return $operation(); }
        };

        return new RecordVideoProgressHandler($this->titles, $this->parties, $this->positions, $this->marks, $transaction, new MockClock($this->now));
    }
}
