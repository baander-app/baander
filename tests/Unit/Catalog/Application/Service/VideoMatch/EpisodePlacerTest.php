<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\Service\VideoMatch;

use App\Catalog\Application\Service\VideoMatch\EpisodePlacer;
use App\Catalog\Domain\Model\VideoPlacement;
use App\Metadata\Application\DTO\Video\VideoEpisode;
use App\Metadata\Application\DTO\Video\VideoSeason;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class EpisodePlacerTest extends TestCase
{
    public function testOrdinarySpanKeepsListedAndUnlistedEpisodesVisible(): void
    {
        $placement = $this->placement(['seasonNumber' => 5, 'episodeNumbers' => [12, 13]]);
        $result = $this->place([new VideoSeason(5, episodes: [$this->episode('listed', 12)])], $placement);

        self::assertSame([
            'targets' => [$this->target('listed', 5, 12), $this->target(null, 5, 13)],
            'reviewReason' => 'no_candidates',
        ], $result);
    }

    public function testSeasonZeroUsesItsOwnEpisodeNumbers(): void
    {
        $placement = $this->placement(['seasonNumber' => 0, 'episodeNumbers' => [7]]);
        $result = $this->place([new VideoSeason(0, episodes: [$this->episode('special', 7)])], $placement);

        self::assertSame(['targets' => [$this->target('special', 0, 7)], 'reviewReason' => null], $result);
    }

    public function testExplicitAbsoluteNumbersTakePriorityOverCounting(): void
    {
        $placement = $this->placement(['absoluteNumber' => 137]);
        $seasons = [new VideoSeason(5, episodes: [$this->episode('later', 12, 137)])];

        self::assertSame(
            ['targets' => [$this->target('later', 5, 12)], 'reviewReason' => null],
            $this->place($seasons, $placement, aired: $seasons),
        );
    }

    public function testAMissingExplicitAbsoluteNumberDoesNotUseCounting(): void
    {
        $placement = $this->placement(['absoluteNumber' => 1]);
        $seasons = [new VideoSeason(1, episodes: [$this->episode('later', 1, 137)])];

        self::assertSame(
            ['targets' => [], 'reviewReason' => 'no_candidates'],
            $this->place($seasons, $placement, aired: $seasons),
        );
    }

    public function testFallbackCountsSortedAiredEpisodesAndSkipsSpecials(): void
    {
        $placement = $this->placement(['absoluteNumber' => 3]);
        $aired = [
            new VideoSeason(5, episodes: [$this->episode('third', 12)]),
            new VideoSeason(0, episodes: [$this->episode('special', 1)]),
            new VideoSeason(1, episodes: [$this->episode('second', 2), $this->episode('first', 1)]),
        ];

        self::assertSame(
            ['targets' => [$this->target('third', 5, 12)], 'reviewReason' => null],
            $this->place($aired, $placement, aired: $aired),
        );
    }

    public function testFallbackUsesAiredIdentityUnderTheSelectedOrdering(): void
    {
        $placement = $this->placement(['absoluteNumber' => 2]);
        $aired = [new VideoSeason(1, episodes: [$this->episode('first', 1), $this->episode('second', 2)])];
        $dvd = [new VideoSeason(3, episodes: [$this->episode('second', 8)])];

        self::assertSame(
            ['targets' => [$this->target('second', 3, 8)], 'reviewReason' => null],
            $this->place($dvd, $placement, aired: $aired),
        );
    }

    public function testCountingRequiresAnAiredStructure(): void
    {
        $placement = $this->placement(['absoluteNumber' => 1]);

        self::assertSame(
            ['targets' => [], 'reviewReason' => 'no_candidates'],
            $this->place([new VideoSeason(1, episodes: [$this->episode('first', 1)])], $placement),
        );
    }

    public function testUniqueAirDatePlacesTheFile(): void
    {
        $placement = $this->placement(['airDate' => '2026-11-28']);
        $episode = new VideoEpisode('day', 4, 'Episode', airDate: '2026-11-28');

        self::assertSame(
            ['targets' => [$this->target('day', 2, 4)], 'reviewReason' => null],
            $this->place([new VideoSeason(2, episodes: [$episode])], $placement),
        );
    }

    public function testADateWithoutAMatchingEpisodeNeedsReview(): void
    {
        $placement = $this->placement(['airDate' => '2024-03-14']);
        $episode = new VideoEpisode('previous-day', 4, 'Previous day', airDate: '2024-03-13');

        self::assertSame(
            ['targets' => [], 'reviewReason' => 'no_candidates'],
            $this->place([new VideoSeason(2, episodes: [$episode])], $placement),
        );
    }

    public function testAnAmbiguousAirDateNeedsReview(): void
    {
        $placement = $this->placement(['airDate' => '2026-11-28']);
        $episodes = [
            new VideoEpisode('a', 1, 'First', airDate: '2026-11-28'),
            new VideoEpisode('b', 2, 'Second', airDate: '2026-11-28'),
        ];

        self::assertSame(
            ['targets' => [], 'reviewReason' => 'no_candidates'],
            $this->place([new VideoSeason(1, episodes: $episodes)], $placement),
        );
    }

    public function testNativeIdentitySurvivesASwapAndReportsTheFilenameDisagreement(): void
    {
        $placement = $this->placement(['seasonNumber' => 1, 'episodeNumbers' => [1]]);
        $local = Uuid::v7();
        $placement->assignEpisodes([$local]);
        $seasons = [new VideoSeason(1, episodes: [$this->episode('other', 1), $this->episode('original', 2)])];

        self::assertSame(
            ['targets' => [$this->target('original', 1, 2)], 'reviewReason' => 'name_disagreement'],
            $this->place($seasons, $placement, [$local->toString() => 'original']),
        );
    }

    public function testNativeIdentityReportsDateAndAbsoluteDisagreements(): void
    {
        $local = Uuid::v7();
        $episode = new VideoEpisode('original', 2, 'Episode', absoluteNumber: 3, airDate: '2026-11-27');
        foreach ([['absoluteNumber' => 2], ['airDate' => '2026-11-28']] as $raw) {
            $placement = $this->placement($raw);
            $placement->assignEpisodes([$local]);
            self::assertSame(
                ['targets' => [$this->target('original', 1, 2)], 'reviewReason' => 'name_disagreement'],
                $this->place([new VideoSeason(1, episodes: [$episode])], $placement, [$local->toString() => 'original']),
            );
        }
    }

    public function testUnchangedNativeIdentityNeedsNoReview(): void
    {
        $placement = $this->placement(['seasonNumber' => 1, 'episodeNumbers' => [2]]);
        $local = Uuid::v7();
        $placement->assignEpisodes([$local]);

        self::assertSame(
            ['targets' => [$this->target('original', 1, 2)], 'reviewReason' => null],
            $this->place([new VideoSeason(1, episodes: [$this->episode('original', 2)])], $placement, [$local->toString() => 'original']),
        );
    }

    public function testEmptyAndMalformedParsesNeedReview(): void
    {
        foreach ([[], ['seasonNumber' => '1', 'episodeNumbers' => ['2']], ['absoluteNumber' => 0]] as $raw) {
            self::assertSame(
                ['targets' => [], 'reviewReason' => 'no_candidates'],
                $this->place([], $this->placement($raw)),
            );
        }
    }

    public function testARemovedNativeEpisodeDoesNotSwitchToTheNewOccupantOfItsNumber(): void
    {
        $placement = $this->placement(['seasonNumber' => 1, 'episodeNumbers' => [1]]);
        $local = Uuid::v7();
        $placement->assignEpisodes([$local]);

        self::assertSame(
            ['targets' => [], 'reviewReason' => 'no_candidates'],
            $this->place([new VideoSeason(1, episodes: [$this->episode('different', 1)])], $placement, [$local->toString() => 'removed']),
        );
    }

    public function testAnIncompleteNativeSpanKeepsTheSurvivingEpisodesAndNeedsReview(): void
    {
        $placement = $this->placement(['seasonNumber' => 1, 'episodeNumbers' => [1, 2]]);
        $first = Uuid::v7();
        $second = Uuid::v7();
        $placement->assignEpisodes([$first, $second]);

        self::assertSame(
            ['targets' => [$this->target('surviving', 1, 3)], 'reviewReason' => 'no_candidates'],
            $this->place(
                [new VideoSeason(1, episodes: [$this->episode('surviving', 3)])],
                $placement,
                [$first->toString() => 'surviving', $second->toString() => 'removed'],
            ),
        );
    }

    public function testARefreshFillsAnUnlistedPartOfANativeSpan(): void
    {
        $placement = $this->placement(['seasonNumber' => 5, 'episodeNumbers' => [12, 13]]);
        $listed = Uuid::v7();
        $provisional = Uuid::v7();
        $placement->assignEpisodes([$listed, $provisional]);
        $seasons = [new VideoSeason(5, episodes: [$this->episode('listed', 12), $this->episode('new', 13)])];

        self::assertSame([
            'targets' => [$this->target('listed', 5, 12), $this->target('new', 5, 13)],
            'reviewReason' => null,
        ], $this->place($seasons, $placement, [$listed->toString() => 'listed']));
    }

    public function testPlacementsAreReturnedByIdentityWithoutMutatingTheirLinks(): void
    {
        $first = $this->placement(['seasonNumber' => 1, 'episodeNumbers' => [1]]);
        $second = $this->placement(['seasonNumber' => 1, 'episodeNumbers' => [2]]);
        $seasons = [new VideoSeason(1, episodes: [$this->episode('first', 1), $this->episode('second', 2)])];

        self::assertSame([
            $first->getId()->toString() => ['targets' => [$this->target('first', 1, 1)], 'reviewReason' => null],
            $second->getId()->toString() => ['targets' => [$this->target('second', 1, 2)], 'reviewReason' => null],
        ], new EpisodePlacer()->place($seasons, [$first, $second], []));
        self::assertSame([], $first->getEpisodeIds());
        self::assertSame([], $second->getEpisodeIds());
    }

    public function testComputedAbsoluteNumbersCanBePersistedUnderAlternateCoordinates(): void
    {
        $aired = [
            new VideoSeason(2, episodes: [$this->episode('third', 1)]),
            new VideoSeason(0, episodes: [$this->episode('special', 1)]),
            new VideoSeason(1, episodes: [$this->episode('second', 2), $this->episode('first', 1)]),
        ];
        $selected = [new VideoSeason(3, episodes: [$this->episode('third', 7), $this->episode('second', 8)])];
        $placement = $this->placement(['absoluteNumber' => 2]);
        $plan = new EpisodePlacer()->plan($selected, [$placement], [], $aired);

        self::assertSame(['second' => 2, 'third' => 3], new EpisodePlacer()->absoluteNumbers($selected, $aired));
        self::assertSame(['second' => 2, 'third' => 3], $plan['absoluteNumbers']);
        self::assertSame([
            'targets' => [$this->target('second', 3, 8)],
            'reviewReason' => null,
        ], $plan['placements'][$placement->getId()->toString()]);
    }

    public function testComputedAbsoluteNumbersKeepTheExplicitMappingAndExcludeSpecials(): void
    {
        $seasons = [
            new VideoSeason(0, episodes: [$this->episode('special', 1, 1)]),
            new VideoSeason(5, episodes: [$this->episode('later', 12, 137), $this->episode('unlisted', 13)]),
        ];

        self::assertSame(['later' => 137], new EpisodePlacer()->absoluteNumbers($seasons, $seasons));
        self::assertSame([], new EpisodePlacer()->absoluteNumbers([new VideoSeason(1, episodes: [$this->episode('first', 1)])]));
    }

    /** @param array<string, mixed> $raw */
    private function placement(array $raw): VideoPlacement
    {
        return VideoPlacement::create(Uuid::v7(), Uuid::v7(), 'Show/Episode.mkv', $raw, seriesId: Uuid::v7());
    }

    private function episode(string $id, int $number, ?int $absolute = null): VideoEpisode
    {
        return new VideoEpisode($id, $number, 'Episode ' . $number, absoluteNumber: $absolute);
    }

    /** @return array{providerId:?string,seasonNumber:int,episodeNumber:int} */
    private function target(?string $id, int $season, int $episode): array
    {
        return ['providerId' => $id, 'seasonNumber' => $season, 'episodeNumber' => $episode];
    }

    /** @param list<VideoSeason> $seasons
     * @param array<string, string> $ids
     * @param list<VideoSeason>|null $aired
     * @return array{targets:list<array{providerId:?string,seasonNumber:int,episodeNumber:int}>,reviewReason:?string}
     */
    private function place(array $seasons, VideoPlacement $placement, array $ids = [], ?array $aired = null): array
    {
        return new EpisodePlacer()->place($seasons, [$placement], $ids, $aired)[$placement->getId()->toString()];
    }
}
