<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain\Service\VideoName;

use App\Catalog\Domain\Service\VideoName\EpisodeNameParser;
use App\Catalog\Domain\ValueObject\VideoName\ExtraKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EpisodeNameParserTest extends TestCase
{
    public function testSeasonFolderIdentifiesSeriesAndYear(): void
    {
        $name = (new EpisodeNameParser())->parse('Show (2011)/Season 01/Show - S01E02 - Title.mkv');

        self::assertSame('Show (2011)', $name->seriesFolder);
        self::assertSame('Show', $name->title);
        self::assertSame(2011, $name->year);
        self::assertSame(1, $name->seasonNumber);
        self::assertSame([2], $name->episodeNumbers);
    }

    /** @param list<int> $episodes */
    #[DataProvider('numberedEpisodes')]
    public function testReadsEpisodeNumbers(string $path, ?int $season, array $episodes, ?int $absolute): void
    {
        $name = (new EpisodeNameParser())->parse($path);

        self::assertSame($season, $name->seasonNumber);
        self::assertSame($episodes, $name->episodeNumbers);
        self::assertSame($absolute, $name->absoluteNumber);
    }

    /** @return iterable<string, array{string, ?int, list<int>, ?int}> */
    public static function numberedEpisodes(): iterable
    {
        yield 'special' => ['Show (2011)/Specials/Show - S00E01.mkv', 0, [1], null];
        yield 'range' => ['Show - S02E01-E03.mkv', 2, [1, 2, 3], null];
        yield 'two-episode range' => ['Show - S02E01-E02.mkv', 2, [1, 2], null];
        yield 'joined' => ['Show - S02E01E02.mkv', 2, [1, 2], null];
        yield 'cross' => ['Show - 2x05.mkv', 2, [5], null];
        yield 'absolute' => ['Anime/Anime - 137.mkv', null, [], 137];
        yield 'number in series title' => ['86/86 - 137.mkv', null, [], 137];
        yield 'season relative' => ['Anime/Season 02/Anime - 13.mkv', 2, [13], null];
        yield 'both' => ['Anime - S01E05 - 005.mkv', 1, [5], 5];
        yield 'folder and both' => ['Anime/Season 01/Anime - S01E05 - 005.mkv', 1, [5], 5];
        yield 'technical tokens' => ['Show 1080p x265.mkv', null, [], null];
        yield 'year and title number' => ['Blade Runner 2049 (2017).mkv', null, [], null];
        yield 'date' => ['Daily Show 2024-03-14.mkv', null, [], null];
        yield 'ambiguous date' => ['Daily Show 03-04-2024.mkv', null, [], null];
    }

    #[DataProvider('airDates')]
    public function testReadsOnlyValidYearFirstDates(string $file, ?string $date): void
    {
        self::assertSame($date, (new EpisodeNameParser())->parse($file)->airDate);
    }

    /** @return iterable<array{string, ?string}> */
    public static function airDates(): iterable
    {
        yield ['Daily Show 2024-03-14.mkv', '2024-03-14'];
        yield ['Daily Show 2024.03.14.mkv', '2024-03-14'];
        yield ['Daily Show 03-04-2024.mkv', null];
        yield ['Daily Show 2024-02-30.mkv', null];
    }

    public function testRecognizesExtrasAndSampleWords(): void
    {
        $parser = new EpisodeNameParser();
        self::assertSame(ExtraKind::Other, $parser->parse('Show/Extras/Making Of.mkv')->extraKind);
        self::assertTrue($parser->parse('Show.S01E01.sample.mkv')->sample);
        self::assertFalse($parser->parse('Sampler - S01E01.mkv')->sample);
    }

    public function testReadsFolderProviderTagsWithoutUsingTheirIdsAsNumbers(): void
    {
        $name = (new EpisodeNameParser())->parse('Show {tvdb-121361}/Show - S01E02.mkv');

        self::assertSame('tvdb', $name->providerTags[0]->provider);
        self::assertSame('121361', $name->providerTags[0]->id);
        self::assertNull($name->absoluteNumber);
        self::assertSame([2], $name->episodeNumbers);
    }
}
