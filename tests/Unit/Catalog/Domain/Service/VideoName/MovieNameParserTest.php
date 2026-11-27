<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain\Service\VideoName;

use App\Catalog\Domain\Service\VideoName\MovieNameParser;
use App\Catalog\Domain\ValueObject\VideoName\ExtraKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MovieNameParserTest extends TestCase
{
    public function testFolderSuppliesTitleYearAndIdentity(): void
    {
        $name = (new MovieNameParser())->parse('The Matrix (1999) {tmdb-603}/feature.mkv');

        self::assertSame('The Matrix', $name->title);
        self::assertSame(1999, $name->year);
        self::assertSame('The Matrix (1999) {tmdb-603}', $name->folderPath);
        self::assertSame('tmdb', $name->providerTags[0]->provider);
        self::assertSame('603', $name->providerTags[0]->id);
    }

    public function testRootFileSuppliesTitleAndYear(): void
    {
        $name = (new MovieNameParser())->parse('Heat (1995).mkv');

        self::assertSame('Heat', $name->title);
        self::assertSame(1995, $name->year);
        self::assertSame('', $name->folderPath);
    }

    public function testFileSuppliesYearAndTagsMissingFromTheFolder(): void
    {
        $name = (new MovieNameParser())->parse('Heat/Heat (1995) [imdbid-tt0113277].mkv');

        self::assertSame('Heat', $name->title);
        self::assertSame(1995, $name->year);
        self::assertSame('imdb', $name->providerTags[0]->provider);
        self::assertSame('tt0113277', $name->providerTags[0]->id);
    }

    #[DataProvider('providerTags')]
    public function testReadsProviderTags(string $tag, string $provider, string $id): void
    {
        $name = (new MovieNameParser())->parse('Show ' . $tag . '/feature.mkv');

        self::assertSame('Show', $name->title);
        self::assertSame($provider, $name->providerTags[0]->provider);
        self::assertSame($id, $name->providerTags[0]->id);
    }

    /** @return iterable<array{string, string, string}> */
    public static function providerTags(): iterable
    {
        yield ['{tmdb-1399}', 'tmdb', '1399'];
        yield ['[tmdbid-1399]', 'tmdb', '1399'];
        yield ['{tvdb-121361}', 'tvdb', '121361'];
        yield ['[tvdbid-121361]', 'tvdb', '121361'];
        yield ['{imdb-tt0944947}', 'imdb', 'tt0944947'];
        yield ['[imdbid-tt0944947]', 'imdb', 'tt0944947'];
    }

    #[DataProvider('extraFiles')]
    public function testExtrasKeepTheMovieFolder(string $file, ExtraKind $kind): void
    {
        $name = (new MovieNameParser())->parse('Movie (1999)/' . $file);

        self::assertSame('Movie', $name->title);
        self::assertSame('Movie (1999)', $name->folderPath);
        self::assertSame($kind, $name->extraKind);
    }

    /** @return iterable<array{string, ExtraKind}> */
    public static function extraFiles(): iterable
    {
        yield ['Movie (1999)-trailer.mkv', ExtraKind::Trailer];
        yield ['Trailers/Trailer 1.mkv', ExtraKind::Trailer];
        yield ['Behind The Scenes/Making Of.mkv', ExtraKind::BehindTheScenes];
        yield ['Deleted Scenes/Ending.mkv', ExtraKind::DeletedScene];
        yield ['Featurettes/Design.mkv', ExtraKind::Featurette];
        yield ['Interviews/Director.mkv', ExtraKind::Interview];
        yield ['Scenes/Opening.mkv', ExtraKind::Scene];
        yield ['Shorts/Short.mkv', ExtraKind::Short];
        yield ['Clips/Clip.mkv', ExtraKind::Clip];
        yield ['Samples/Camera.mkv', ExtraKind::Sample];
        yield ['Other/Camera.mkv', ExtraKind::Other];
        yield ['theme-music/Opening.mkv', ExtraKind::ThemeMusic];
        yield ['backdrops/Opening.mkv', ExtraKind::Backdrop];
        yield ['Extras/Extra.mkv', ExtraKind::Other];
        yield ['Preview.trailer.mp4', ExtraKind::Trailer];
        yield ['Preview_trailer.mp4', ExtraKind::Trailer];
        yield ['Preview trailer.mp4', ExtraKind::Trailer];
        yield ['trailer.mp4', ExtraKind::Trailer];
        yield ['Design-behindthescenes.mkv', ExtraKind::BehindTheScenes];
        yield ['Ending-deleted.mkv', ExtraKind::DeletedScene];
        yield ['Ending-deletedscene.mkv', ExtraKind::DeletedScene];
        yield ['Design-featurette.mkv', ExtraKind::Featurette];
        yield ['Director-interview.mkv', ExtraKind::Interview];
        yield ['Opening-scene.mkv', ExtraKind::Scene];
        yield ['Short-short.mkv', ExtraKind::Short];
        yield ['Clip-clip.mkv', ExtraKind::Clip];
        yield ['Camera-other.mkv', ExtraKind::Other];
        yield ['Camera-extra.mkv', ExtraKind::Other];
    }
}
