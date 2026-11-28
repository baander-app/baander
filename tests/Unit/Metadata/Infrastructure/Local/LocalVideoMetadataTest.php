<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Infrastructure\Local;

use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Metadata\Application\DTO\Video\VideoKind;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoProviderProblem;
use App\Metadata\Application\DTO\Video\VideoRequest;
use App\Metadata\Application\DTO\Video\VideoSidecar;
use App\Metadata\Application\DTO\Video\VideoSidecarKind;
use App\Metadata\Application\DTO\Video\VideoTitle;
use App\Metadata\Application\Settings\MetadataProvider;
use App\Metadata\Infrastructure\Local\LocalVideoMetadata;
use App\Metadata\Infrastructure\Local\NfoReader;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Metadata\ProviderSwitches;
use PHPUnit\Framework\TestCase;

final class LocalVideoMetadataTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../../../Fixtures/Metadata/Local';

    public function testSeriesUsesClassifiedSidecarsForDetailsIdentifiedCastAndArtwork(): void
    {
        $library = Uuid::v7();
        $request = new VideoRequest(
            VideoKind::Series,
            'Folder name',
            libraryId: $library,
            sidecars: [
                self::sidecar(VideoSidecarKind::SeriesNfo, 'Show/tvshow.nfo'),
                self::sidecar(VideoSidecarKind::SeasonNfo, 'Show/Season 01/season.nfo'),
                self::sidecar(VideoSidecarKind::VideoNfo, 'Show/Season 01/Show S01E01-E02.nfo', stem: 'Show S01E01-E02'),
                self::sidecar(VideoSidecarKind::VideoNfo, 'Show/Specials/Show S00E07.nfo', stem: 'Show S00E07'),
                self::sidecar(VideoSidecarKind::Poster, 'Show/Season 01/poster.jpg'),
                self::sidecar(VideoSidecarKind::Poster, 'Show/poster.jpg'),
                self::sidecar(VideoSidecarKind::Backdrop, 'Show/fanart.webp'),
                self::sidecar(VideoSidecarKind::SeasonPoster, 'Show/season01-poster.png', season: 1),
                self::sidecar(VideoSidecarKind::VideoThumbnail, 'Show/Specials/Show S01E01-E02-thumb.jpg', stem: 'Show S01E01-E02'),
                self::sidecar(VideoSidecarKind::VideoThumbnail, 'Show/Season 01/Show S01E01-E02-thumb.jpg', stem: 'Show S01E01-E02'),
            ],
            titleId: Uuid::v7(),
        );

        $provider = $this->provider();
        $title = $provider->identify($request);
        self::assertInstanceOf(VideoTitle::class, $title);
        self::assertSame('Fixture Show', $title->entry->title);
        self::assertSame(2022, $title->entry->year);
        self::assertSame(['tvdb' => '1234', 'tmdb' => '4321', 'imdb' => 'tt1234567'], $title->entry->externalIds);
        self::assertSame('A series from sidecars.', $title->overview);
        self::assertSame(self::FIXTURES . '/Show/poster.jpg', $title->poster?->path);
        self::assertSame(self::FIXTURES . '/Show/fanart.webp', $title->backdrop?->path);
        self::assertSame($library, $title->poster->libraryId);
        self::assertSame(['Credited Actor'], array_column($title->cast ?? [], 'name'));
        self::assertEquals(['imdb_name' => 'nm1234567', 'tvdb_person' => '81'], $title->cast[0]->identifiers);
        self::assertSame('Lead', $title->cast[0]->characterName);
        self::assertSame(3, $title->cast[0]->billingOrder);
        self::assertTrue($title->hasOrdering('aired'));
        self::assertSame([0, 1], array_column($title->seasons, 'number'));
        self::assertSame('s0e7', $title->seasons[0]->episodes[0]->id);
        $season = $title->seasons[1];
        self::assertSame('First Season', $season->name);
        self::assertSame('The beginning.', $season->overview);
        self::assertSame(self::FIXTURES . '/Show/season01-poster.png', $season->poster?->path);
        self::assertSame(['tvdb:610', 'tvdb:611'], array_column($season->episodes, 'id'));
        self::assertSame(['First & foremost', 'Second'], array_column($season->episodes, 'title'));
        self::assertSame(['Part one.', 'Part two.'], array_column($season->episodes, 'overview'));
        self::assertSame(['2022-02-20', '2022-02-27'], array_column($season->episodes, 'airDate'));
        self::assertSame(['tvdb' => '610'], $season->episodes[0]->externalIds);
        self::assertSame(['tmdb_person' => '50'], $season->episodes[0]->cast[0]->identifiers);
        self::assertSame(self::FIXTURES . '/Show/Season 01/Show S01E01-E02-thumb.jpg', $season->episodes[1]->still?->path);
    }

    public function testMovieNfoProvidesCanonicalIdAndXmlArtworkDoesNotBecomeAPath(): void
    {
        $provider = $this->provider();
        $request = new VideoRequest(
            VideoKind::Movie,
            'Folder name',
            libraryId: Uuid::v7(),
            sidecars: [self::sidecar(VideoSidecarKind::MovieNfo, 'Movie/movie.nfo')],
            titleId: Uuid::v7(),
        );

        self::assertSame(VideoProviderId::Local, $provider->provider());
        self::assertTrue($provider->isConfigured());
        self::assertSame([], $provider->search($request));
        $title = $provider->identify($request);
        self::assertInstanceOf(VideoTitle::class, $title);
        self::assertSame('Fixture Movie', $title->entry->title);
        self::assertSame(1984, $title->entry->year);
        self::assertSame(['tmdb' => '42'], $title->entry->externalIds);
        self::assertSame('A movie from a sidecar.', $title->overview);
        self::assertNull($title->poster);
    }

    public function testProviderIdInTitleNfoIdentifiesATitleWithoutItsOwnDisplayName(): void
    {
        $files = $this->createStub(LibraryMediaFilesInterface::class);
        $files->method('readSidecar')->willReturnCallback(static fn (Uuid $library, string $path): ?string => match ($path) {
            '/library/tvshow.nfo' => '<tvshow><uniqueid type="tvdb">1234</uniqueid></tvshow>',
            '/library/movie.nfo' => '<movie><uniqueid type="tmdb">42</uniqueid></movie>',
            '/library/unidentified.nfo' => '<movie><uniqueid type="unknown">42</uniqueid></movie>',
            default => null,
        });
        $provider = new LocalVideoMetadata(new NfoReader($files), ProviderSwitches::allEnabled());
        $library = Uuid::v7();
        $series = $provider->identify(new VideoRequest(
            VideoKind::Series,
            'Parsed Series',
            libraryId: $library,
            sidecars: [new VideoSidecar(VideoSidecarKind::SeriesNfo, '/library/tvshow.nfo')],
            titleId: Uuid::v7(),
        ));
        $movie = $provider->identify(new VideoRequest(
            VideoKind::Movie,
            'Parsed Movie',
            libraryId: $library,
            sidecars: [new VideoSidecar(VideoSidecarKind::MovieNfo, '/library/movie.nfo')],
            titleId: Uuid::v7(),
        ));

        self::assertInstanceOf(VideoTitle::class, $series);
        self::assertSame('Parsed Series', $series->entry->title);
        self::assertSame(['tvdb' => '1234'], $series->entry->externalIds);
        self::assertInstanceOf(VideoTitle::class, $movie);
        self::assertSame('Parsed Movie', $movie->entry->title);
        self::assertSame(['tmdb' => '42'], $movie->entry->externalIds);
        self::assertNull($provider->identify(new VideoRequest(
            VideoKind::Movie,
            'Parsed Movie',
            externalIds: ['tmdb' => '42'],
            libraryId: $library,
            sidecars: [new VideoSidecar(VideoSidecarKind::MovieNfo, '/library/unidentified.nfo')],
            titleId: Uuid::v7(),
        )));
    }

    public function testMalformedOrMissingTitleNfoYieldsNoMatchEvenWithEpisodeDetailsAndProviderId(): void
    {
        $provider = $this->provider();
        $episode = self::sidecar(VideoSidecarKind::VideoNfo, 'Show/Season 01/Show S01E01-E02.nfo');
        $library = Uuid::v7();
        self::assertNull($provider->identify(new VideoRequest(
            VideoKind::Series,
            'Show',
            externalIds: ['tvdb' => '1234'],
            libraryId: $library,
            sidecars: [$episode],
            titleId: Uuid::v7(),
        )));
        self::assertNull($provider->identify(new VideoRequest(
            VideoKind::Series,
            'Show',
            libraryId: $library,
            sidecars: [self::sidecar(VideoSidecarKind::SeriesNfo, 'invalid.nfo'), $episode],
            titleId: Uuid::v7(),
        )));
    }

    public function testDisabledProviderDeclinesDirectSearchAndIdentifyWithoutReadingAnySidecars(): void
    {
        $files = $this->createMock(LibraryMediaFilesInterface::class);
        $files->expects($this->never())->method('readSidecar');
        $provider = new LocalVideoMetadata(new NfoReader($files), ProviderSwitches::withDisabled(MetadataProvider::Local));
        $request = new VideoRequest(
            VideoKind::Series,
            'Show',
            libraryId: Uuid::v7(),
            sidecars: [self::sidecar(VideoSidecarKind::SeriesNfo, 'Show/tvshow.nfo')],
            titleId: Uuid::v7(),
        );

        self::assertSame(VideoProviderProblem::Disabled, $provider->search($request));
        self::assertSame(VideoProviderProblem::Disabled, $provider->identify($request));
    }

    public function testUnavailableOrderingKeepsTheOfferOfAiredWithoutReusingItsSeasonStructure(): void
    {
        $title = $this->provider()->identify(new VideoRequest(
            VideoKind::Series,
            'Show',
            externalIds: ['local' => 'already-matched'],
            ordering: 'dvd',
            libraryId: Uuid::v7(),
            sidecars: [
                self::sidecar(VideoSidecarKind::SeriesNfo, 'Show/tvshow.nfo'),
                self::sidecar(VideoSidecarKind::VideoNfo, 'Show/Specials/Show S00E07.nfo'),
            ],
            titleId: Uuid::v7(),
        ));

        self::assertInstanceOf(VideoTitle::class, $title);
        self::assertSame('already-matched', $title->entry->id);
        self::assertTrue($title->hasOrdering('aired'));
        self::assertFalse($title->hasOrdering('dvd'));
        self::assertSame([], $title->seasons);
    }

    public function testRenamesAndRenumberingKeepIdentitiesWhenAStableIdentifierExists(): void
    {
        $library = Uuid::v7();
        $titleId = Uuid::v7();
        $videoId = Uuid::v7();
        $files = $this->createStub(LibraryMediaFilesInterface::class);
        $files->method('readSidecar')->willReturnCallback(static fn (Uuid $id, string $path): ?string => match ($path) {
            '/library/Original/tvshow.nfo', '/library/Renamed/tvshow.nfo' => '<tvshow><title>Show</title></tvshow>',
            '/library/Original/episodes.nfo' => '<episodedetails><title>Identified</title><season>0</season><episode>2</episode><uniqueid type="tvdb">99</uniqueid></episodedetails>'
                . '<episodedetails><title>File identified</title><season>1</season><episode>1</episode></episodedetails>',
            '/library/Renamed/episodes.nfo' => '<episodedetails><title>Identified</title><season>0</season><episode>7</episode><uniqueid type="tvdb">99</uniqueid></episodedetails>'
                . '<episodedetails><title>File identified</title><season>2</season><episode>1</episode></episodedetails>',
            default => null,
        });
        $provider = new LocalVideoMetadata(new NfoReader($files), ProviderSwitches::allEnabled());
        $previous = null;
        foreach (['Original', 'Renamed'] as $folder) {
            $title = $provider->identify(new VideoRequest(
                VideoKind::Series,
                'Show',
                libraryId: $library,
                sidecars: [
                    new VideoSidecar(VideoSidecarKind::SeriesNfo, "/library/$folder/tvshow.nfo"),
                    new VideoSidecar(VideoSidecarKind::VideoNfo, "/library/$folder/episodes.nfo", videoId: $videoId),
                ],
                titleId: $titleId,
            ));
            self::assertInstanceOf(VideoTitle::class, $title);
            self::assertSame($titleId->toString(), $title->entry->id);
            self::assertSame('tvdb:99', $title->seasons[0]->episodes[0]->id);
            $fileEpisode = $title->seasons[1]->episodes[0];
            self::assertSame('video:' . $videoId->toString() . ':1', $fileEpisode->id);
            if ($previous !== null) {
                self::assertSame($previous, $fileEpisode->id);
                self::assertSame([0, 2], array_column($title->seasons, 'number'));
            }
            $previous = $fileEpisode->id;
        }
    }

    private function provider(): LocalVideoMetadata
    {
        $files = $this->createStub(LibraryMediaFilesInterface::class);
        $files->method('readSidecar')->willReturnCallback(static function (Uuid $library, string $path, int $maxBytes): ?string {
            if (!is_file($path)) {
                return null;
            }
            $xml = file_get_contents($path, length: $maxBytes + 1);
            return $xml !== false && strlen($xml) <= $maxBytes ? $xml : null;
        });

        return new LocalVideoMetadata(new NfoReader($files), ProviderSwitches::allEnabled());
    }

    private static function sidecar(VideoSidecarKind $kind, string $path, ?int $season = null, ?string $stem = null): VideoSidecar
    {
        return new VideoSidecar($kind, self::FIXTURES . '/' . $path, $season, $stem);
    }
}
