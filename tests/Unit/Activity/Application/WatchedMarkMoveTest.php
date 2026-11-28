<?php

declare(strict_types=1);

namespace App\Tests\Unit\Activity\Application;

use App\Activity\Application\DTO\WatchedMarkEpisodeMerge;
use App\Activity\Application\DTO\WatchedMarkMapping;
use App\Activity\Application\DTO\WatchedMarkVideoMove;
use App\Activity\Domain\Model\WatchedMark;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class WatchedMarkMoveTest extends TestCase
{
    public function testChainUsesTheOriginalMarksOfEachFile(): void
    {
        [$first, $second, $third, $alice, $bob] = array_map(static fn (): Uuid => Uuid::v7(), range(1, 5));
        $marks = [WatchedMark::episode($alice, $first), WatchedMark::episode($bob, $second)];
        $mapping = new WatchedMarkMapping($marks, [
            new WatchedMarkVideoMove(Uuid::v7(), [$first], [$second]),
            new WatchedMarkVideoMove(Uuid::v7(), [$second], [$third]),
        ], []);

        self::assertSame([
            $alice->toString() . ':' . $second->toString(),
            $bob->toString() . ':' . $third->toString(),
        ], $this->keys($mapping->marks));
        self::assertCount(2, $mapping->removedMarks);
    }

    public function testCycleSwapsRatherThanPropagatingMarks(): void
    {
        [$first, $second, $alice, $bob] = array_map(static fn (): Uuid => Uuid::v7(), range(1, 4));
        $mapping = new WatchedMarkMapping([
            WatchedMark::episode($alice, $first), WatchedMark::episode($bob, $second),
        ], [
            new WatchedMarkVideoMove(Uuid::v7(), [$first], [$second]),
            new WatchedMarkVideoMove(Uuid::v7(), [$second], [$first]),
        ], []);

        self::assertSame([
            $alice->toString() . ':' . $second->toString(),
            $bob->toString() . ':' . $first->toString(),
        ], $this->keys($mapping->marks));
    }

    public function testAnotherVersionRetainsTheSourceAndMultiEpisodeFileCopiesEachMark(): void
    {
        [$first, $second, $third, $member] = array_map(static fn (): Uuid => Uuid::v7(), range(1, 4));
        $mapping = new WatchedMarkMapping([WatchedMark::episode($member, $first)], [
            new WatchedMarkVideoMove(Uuid::v7(), [$first], [$second, $third]),
        ], [$first]);

        self::assertCount(2, $mapping->marks);
        self::assertSame([], $mapping->removedMarks);
    }

    public function testFiveWatchedFilesMoveToTheirAbsoluteEpisodes(): void
    {
        $member = Uuid::v7();
        $marks = [];
        $videos = [];
        $expected = [];
        for ($number = 1; $number <= 5; ++$number) {
            $before = Uuid::v7();
            $after = Uuid::v7();
            $marks[] = WatchedMark::episode($member, $before);
            $videos[] = new WatchedMarkVideoMove(Uuid::v7(), [$before], [$after]);
            $expected[] = $member . ':' . $after;
        }
        $mapping = new WatchedMarkMapping($marks, $videos, []);

        self::assertSame($expected, $this->keys($mapping->marks));
        self::assertCount(5, $mapping->removedMarks);
    }

    public function testMergedEpisodeMarksUseTheLatestInstant(): void
    {
        $member = Uuid::v7();
        $first = Uuid::v7();
        $second = Uuid::v7();
        $target = Uuid::v7();
        $older = new DateTimeImmutable('2026-11-27T12:00:00+00:00');
        $later = new DateTimeImmutable('2026-11-28T12:00:00+00:00');
        $mapping = new WatchedMarkMapping([
            WatchedMark::episode($member, $first, $older),
            WatchedMark::episode($member, $second, $later),
        ], [], [], [
            new WatchedMarkEpisodeMerge($first, $target),
            new WatchedMarkEpisodeMerge($second, $target),
        ]);

        self::assertCount(1, $mapping->marks);
        self::assertEquals($later, $mapping->marks[0]->getWatchedAt());
        self::assertCount(2, $mapping->removedMarks);
    }

    /**
     * @param list<WatchedMark> $marks
     * @return list<string>
     */
    private function keys(array $marks): array
    {
        return array_map(static fn (WatchedMark $mark): string => $mark->getUserId()->toString() . ':' . $mark->getEpisodeId()?->toString(), $marks);
    }
}
