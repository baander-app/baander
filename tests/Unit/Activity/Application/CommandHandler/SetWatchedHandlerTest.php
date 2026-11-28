<?php

declare(strict_types=1);

namespace App\Tests\Unit\Activity\Application\CommandHandler;

use App\Activity\Application\Command\SetWatchedCommand;
use App\Activity\Application\CommandHandler\SetWatchedHandler;
use App\Activity\Domain\Model\WatchedMark;
use App\Activity\Domain\Repository\ResumePositionRepositoryInterface;
use App\Activity\Domain\Repository\WatchedMarkRepositoryInterface;
use App\Catalog\Application\Dto\WatchableTarget;
use App\Catalog\Application\Dto\WatchableTargets;
use App\Catalog\Application\Port\WatchableTitlePortInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class SetWatchedHandlerTest extends TestCase
{
    private WatchableTitlePortInterface&MockObject $titles;
    private ResumePositionRepositoryInterface&MockObject $positions;
    private WatchedMarkRepositoryInterface&MockObject $marks;
    private Uuid $memberId;
    private PublicId $publicId;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->titles = $this->createMock(WatchableTitlePortInterface::class);
        $this->positions = $this->createMock(ResumePositionRepositoryInterface::class);
        $this->marks = $this->createMock(WatchedMarkRepositoryInterface::class);
        $this->memberId = Uuid::v7();
        $this->publicId = new PublicId();
        $this->now = new DateTimeImmutable('2026-11-28T12:00:00+00:00');
    }

    public function testMarkingASeasonMarksItsOwnedEpisodesAndClearsAllVersionPositions(): void
    {
        $targets = [];
        $videos = [];
        for ($index = 0; $index < 8; ++$index) {
            $targets[] = new WatchableTarget('episode', Uuid::v7());
            $videos[] = Uuid::v7();
        }
        $videos[] = Uuid::v7();
        $this->titles->expects(self::once())->method('forMark')->with($this->memberId, 'season', $this->publicId)->willReturn(new WatchableTargets($targets, $videos));
        $this->marks->expects(self::once())->method('saveMany')->with(self::callback(function (array $marks) use ($targets): bool {
            self::assertCount(8, $marks);
            foreach ($marks as $index => $mark) {
                self::assertInstanceOf(WatchedMark::class, $mark);
                self::assertSame($targets[$index]->id->toString(), $mark->getEpisodeId()?->toString());
                self::assertTrue($this->memberId->equals($mark->getUserId()));
            }

            return true;
        }));
        $this->positions->expects(self::once())->method('clear')->with($this->memberId, $videos, $this->now);

        $result = $this->handler()(new SetWatchedCommand($this->memberId, 'season', $this->publicId, true));

        self::assertTrue($result->watched);
        self::assertSame(8, $result->titles);
    }

    public function testUnmarkingOneEpisodeOfASpanKeepsTheOtherEpisodeMark(): void
    {
        $secondEpisode = Uuid::v7();
        $spanVideo = Uuid::v7();
        $this->titles->expects(self::once())->method('forMark')->willReturn(new WatchableTargets([new WatchableTarget('episode', $secondEpisode)], [$spanVideo]));
        $this->marks->expects(self::never())->method('saveMany');
        $this->marks->expects(self::once())->method('deleteForMemberTargets')->with($this->memberId, [$secondEpisode], []);
        $this->positions->expects(self::once())->method('clear')->with($this->memberId, [$spanVideo], $this->now);

        $result = $this->handler()(new SetWatchedCommand($this->memberId, 'episode', $this->publicId, false));

        self::assertFalse($result->watched);
        self::assertSame(1, $result->titles);
    }

    public function testUnmarkingAMovieUsesOnlyItsMemberMovieTarget(): void
    {
        $movie = Uuid::v7();
        $video = Uuid::v7();
        $this->titles->expects(self::once())->method('forMark')->willReturn(new WatchableTargets([new WatchableTarget('movie', $movie)], [$video]));
        $this->marks->expects(self::once())->method('deleteForMemberTargets')->with($this->memberId, [], [$movie]);
        $this->positions->expects(self::once())->method('clear')->with($this->memberId, [$video], $this->now);

        $this->handler()(new SetWatchedCommand($this->memberId, 'movie', $this->publicId, false));
    }

    public function testAnUnavailableTitleChangesNoMemberState(): void
    {
        $this->titles->expects(self::once())->method('forMark')->willReturn(null);
        $this->positions->expects(self::never())->method('clear');
        $this->marks->expects(self::never())->method('saveMany');
        $this->marks->expects(self::never())->method('deleteForMemberTargets');
        $this->expectException(NotFoundException::class);

        $this->handler()(new SetWatchedCommand($this->memberId, 'series', $this->publicId, true));
    }

    private function handler(): SetWatchedHandler
    {
        $transaction = new class implements TransactionPortInterface {
            public function run(callable $operation): mixed { return $operation(); }
        };

        return new SetWatchedHandler($this->titles, $this->positions, $this->marks, $transaction, new MockClock($this->now));
    }
}
