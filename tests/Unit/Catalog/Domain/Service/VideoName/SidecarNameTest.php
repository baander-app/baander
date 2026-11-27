<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain\Service\VideoName;

use App\Catalog\Domain\Service\VideoName\SidecarName;
use App\Catalog\Domain\ValueObject\VideoName\SidecarKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SidecarNameTest extends TestCase
{
    #[DataProvider('sidecars')]
    public function testClassifiesSidecars(string $file, SidecarKind $kind, ?int $season, ?string $video): void
    {
        $sidecar = (new SidecarName())->parse($file);

        self::assertNotNull($sidecar);
        self::assertSame($kind, $sidecar->kind);
        self::assertSame($season, $sidecar->seasonNumber);
        self::assertSame($video, $sidecar->videoStem);
    }

    /** @return iterable<array{string, SidecarKind, ?int, ?string}> */
    public static function sidecars(): iterable
    {
        yield ['Show/tvshow.nfo', SidecarKind::SeriesNfo, null, null];
        yield ['Show/Season 1/season.nfo', SidecarKind::SeasonNfo, null, null];
        yield ['Movie/movie.nfo', SidecarKind::MovieNfo, null, null];
        yield ['Show - S01E01.nfo', SidecarKind::VideoNfo, null, 'Show - S01E01'];
        yield ['poster.jpg', SidecarKind::Poster, null, null];
        yield ['folder.png', SidecarKind::Poster, null, null];
        yield ['fanart.webp', SidecarKind::Backdrop, null, null];
        yield ['backdrop.jpeg', SidecarKind::Backdrop, null, null];
        yield ['season01-poster.jpg', SidecarKind::SeasonPoster, 1, null];
        yield ['season-specials-poster.jpg', SidecarKind::SeasonPoster, 0, null];
        yield ['Show - S01E01-thumb.jpg', SidecarKind::VideoThumbnail, null, 'Show - S01E01'];
    }

    public function testLeavesUnrecognizedFilesUnclassified(): void
    {
        self::assertNull((new SidecarName())->parse('feature.mkv'));
        self::assertNull((new SidecarName())->parse('notes.txt'));
    }
}
