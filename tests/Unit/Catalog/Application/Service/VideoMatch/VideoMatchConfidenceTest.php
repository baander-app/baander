<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\Service\VideoMatch;

use App\Catalog\Application\Service\VideoMatch\VideoMatchConfidence;
use App\Metadata\Application\DTO\Video\VideoCandidate;
use App\Metadata\Application\DTO\Video\VideoEpisode;
use App\Metadata\Application\DTO\Video\VideoKind;
use App\Metadata\Application\DTO\Video\VideoOrdering;
use App\Metadata\Application\DTO\Video\VideoRequest;
use App\Metadata\Application\DTO\Video\VideoSeason;
use App\Metadata\Application\DTO\Video\VideoTitle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VideoMatchConfidenceTest extends TestCase
{
    /** @return iterable<string, array{int, int, string|null}> */
    public static function scoreBoundaries(): iterable
    {
        yield 'ten point lead' => [92, 82, null];
        yield 'seven point lead' => [92, 85, 'close_runner_up'];
        yield 'four point lead' => [92, 88, 'close_runner_up'];
        yield 'minimum score and lead' => [90, 80, null];
        yield 'below minimum score' => [89, 50, 'low_score'];
        yield 'tie' => [92, 92, 'close_runner_up'];
    }

    /**
     * @param int<0, 100> $best
     * @param int<0, 100> $runner
     */
    #[DataProvider('scoreBoundaries')]
    public function testTheBestCandidateNeedsAnAbsoluteThresholdAndTenPointLead(int $best, int $runner, ?string $reason): void
    {
        $candidate = $this->title('best');
        $other = $this->title('runner');

        $decision = new VideoMatchConfidence()->decide([
            ['title' => $other, 'score' => $runner],
            ['title' => $candidate, 'score' => $best],
        ]);

        self::assertSame($reason, $decision['reason']);
        self::assertSame($best, $decision['score']);
        self::assertSame($reason === null ? $candidate : null, $decision['match']);
    }

    public function testNoCandidateHasItsOwnReviewReasonAndNoScore(): void
    {
        self::assertSame(['match' => null, 'reason' => 'no_candidates', 'score' => null], new VideoMatchConfidence()->decide([]));
    }

    public function testASingleCandidateStillNeedsTheMinimumScore(): void
    {
        $title = $this->title('single');
        $confidence = new VideoMatchConfidence();

        self::assertSame('low_score', $confidence->decide([['title' => $title, 'score' => 89]])['reason']);
        self::assertSame($title, $confidence->decide([['title' => $title, 'score' => 90]])['match']);
    }

    public function testPunctuationCaseAndApostrophesDoNotLowerAnOtherwiseExactMovieScore(): void
    {
        $local = new VideoRequest(VideoKind::Movie, 'Pan’s Labyrinth', 2006);
        $candidate = new VideoTitle(new VideoCandidate('1', 'PANS—LABYRINTH', 2006));

        self::assertSame(100, new VideoMatchConfidence()->score($local, $candidate));
    }

    public function testADifferentKnownYearLowersAnExactMovieTitleBelowConfidence(): void
    {
        $local = new VideoRequest(VideoKind::Movie, 'The Thing', 1982);
        $same = new VideoTitle(new VideoCandidate('1', 'The Thing', 1982));
        $remake = new VideoTitle(new VideoCandidate('2', 'The Thing', 2011));
        $confidence = new VideoMatchConfidence();

        self::assertSame(100, $confidence->score($local, $same));
        self::assertLessThan(90, $confidence->score($local, $remake));
        self::assertLessThan(100, $confidence->score(new VideoRequest(VideoKind::Movie, 'The Thing'), $same));
    }

    public function testATitleTypoScoresBetweenAnExactTitleAndAnUnrelatedTitle(): void
    {
        $local = new VideoRequest(VideoKind::Movie, 'Interstellar', 2014);
        $confidence = new VideoMatchConfidence();
        $exact = $confidence->score($local, new VideoTitle(new VideoCandidate('1', 'Interstellar', 2014)));
        $typo = $confidence->score($local, new VideoTitle(new VideoCandidate('2', 'Interstella', 2014)));
        $unrelated = $confidence->score($local, new VideoTitle(new VideoCandidate('3', 'Alien', 2014)));

        self::assertGreaterThan($typo, $exact);
        self::assertGreaterThan($unrelated, $typo);
        self::assertGreaterThanOrEqual(0, $unrelated);
    }

    public function testEveryEpisodeOfAParsedSpanMustFitTheSeries(): void
    {
        $local = new VideoRequest(VideoKind::Series, 'Show', 2011);
        $parses = [['seasonNumber' => 5, 'episodeNumbers' => [12, 13]]];
        $confidence = new VideoMatchConfidence();
        $complete = $this->title('complete', [new VideoSeason(5, episodes: [new VideoEpisode('12', 12, 'One'), new VideoEpisode('13', 13, 'Two')])]);
        $incomplete = $this->title('incomplete', [new VideoSeason(5, episodes: [new VideoEpisode('12', 12, 'One')])]);

        self::assertSame(100, $confidence->score($local, $complete, $parses));
        self::assertLessThan(90, $confidence->score($local, $incomplete, $parses));
    }

    public function testAbsoluteNumbersUseTheProvidersMapping(): void
    {
        $candidate = new VideoTitle(
            new VideoCandidate('1', 'Show', 2011),
            orderings: [new VideoOrdering('absolute', 'Absolute', 'absolute')],
            seasons: [new VideoSeason(5, episodes: [new VideoEpisode('12', 12, 'Later', absoluteNumber: 137)])],
        );
        $local = new VideoRequest(VideoKind::Series, 'Show', 2011);
        $confidence = new VideoMatchConfidence();

        self::assertSame(100, $confidence->score($local, $candidate, [['absoluteNumber' => 137]]));
        self::assertLessThan(90, $confidence->score($local, $candidate, [['absoluteNumber' => 12]]));
    }

    public function testAbsentAbsoluteOrderingCountsAiredEpisodesAndExcludesSpecials(): void
    {
        $candidate = $this->title('aired', [
            new VideoSeason(2, episodes: [new VideoEpisode('21', 1, 'Third')]),
            new VideoSeason(0, episodes: [new VideoEpisode('0', 1, 'Special')]),
            new VideoSeason(1, episodes: [new VideoEpisode('12', 2, 'Second'), new VideoEpisode('11', 1, 'First')]),
        ]);

        self::assertSame(100, new VideoMatchConfidence()->score(
            new VideoRequest(VideoKind::Series, 'Show', 2011), $candidate, [['absoluteNumber' => 3]],
        ));
        self::assertLessThan(90, new VideoMatchConfidence()->score(
            new VideoRequest(VideoKind::Series, 'Show', 2011), $candidate, [['absoluteNumber' => 4]],
        ));
    }

    public function testAnOfferedAbsoluteOrderingWithNoMappingDoesNotBorrowAiredNumbers(): void
    {
        $candidate = new VideoTitle(
            new VideoCandidate('1', 'Show', 2011),
            orderings: [new VideoOrdering('absolute', 'Absolute', 'absolute')],
            seasons: [new VideoSeason(1, episodes: [new VideoEpisode('11', 1, 'First')])],
        );

        self::assertLessThan(90, new VideoMatchConfidence()->score(
            new VideoRequest(VideoKind::Series, 'Show', 2011), $candidate, [['absoluteNumber' => 1]],
        ));
    }

    public function testADateFitsOnlyWhenItIdentifiesOneEpisode(): void
    {
        $local = new VideoRequest(VideoKind::Series, 'Show', 2011);
        $one = $this->title('one', [new VideoSeason(1, episodes: [new VideoEpisode('11', 1, 'First', airDate: '2024-03-14')])]);
        $two = $this->title('two', [new VideoSeason(1, episodes: [
            new VideoEpisode('11', 1, 'First', airDate: '2024-03-14'),
            new VideoEpisode('12', 2, 'Second', airDate: '2024-03-14'),
        ])]);
        $confidence = new VideoMatchConfidence();

        self::assertSame(100, $confidence->score($local, $one, [['airDate' => '2024-03-14']]));
        self::assertLessThan(90, $confidence->score($local, $two, [['airDate' => '2024-03-14']]));
        self::assertLessThan(90, $confidence->score($local, $one, [['airDate' => '2024-03-15']]));
    }

    public function testExtrasSamplesAndUnnumberedFilesDoNotCountAgainstNumberedEpisodes(): void
    {
        $candidate = $this->title('1', [new VideoSeason(1, episodes: [new VideoEpisode('11', 1, 'First')])]);
        $parses = [
            ['seasonNumber' => 1, 'episodeNumbers' => [1]],
            ['extraKind' => 'trailer', 'seasonNumber' => 9, 'episodeNumbers' => [9]],
            ['sample' => true, 'seasonNumber' => 9, 'episodeNumbers' => [9]],
            ['seasonNumber' => null, 'episodeNumbers' => [], 'absoluteNumber' => null, 'airDate' => null],
        ];

        self::assertSame(100, new VideoMatchConfidence()->score(new VideoRequest(VideoKind::Series, 'Show', 2011), $candidate, $parses));
    }

    public function testProviderIdsDoNotInflateTheScoreWhichTheIdentityCallerBypasses(): void
    {
        $candidate = new VideoTitle(new VideoCandidate('1', 'Different title', 2011, ['tvdb' => '1']));
        $local = new VideoRequest(VideoKind::Series, 'Show', 2011, ['tvdb' => '1']);

        self::assertLessThan(90, new VideoMatchConfidence()->score($local, $candidate));
    }

    /** @param list<VideoSeason> $seasons */
    private function title(string $id, array $seasons = []): VideoTitle
    {
        return new VideoTitle(new VideoCandidate($id, 'Show', 2011), seasons: $seasons);
    }
}
