<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\Model\TvEpisode;
use App\Catalog\Domain\Model\TvSeries;
use App\Catalog\Domain\Model\Video;
use App\Catalog\Domain\Model\VideoPlacement;
use App\Catalog\Domain\ValueObject\VideoName\ExtraKind;
use App\Catalog\Infrastructure\Doctrine\Repository\MovieRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\TvCatalogRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\VideoRepository;
use App\Catalog\Infrastructure\VideoAvailability;
use App\Catalog\Infrastructure\VideoService;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Library\Infrastructure\Doctrine\Repository\LibraryFileIndexRepository;
use App\Library\Infrastructure\Doctrine\Repository\LibraryRepository;
use App\Library\Infrastructure\Filesystem\LibraryMediaFileResolver;
use App\Library\Infrastructure\Filesystem\LibraryMediaFiles;
use App\Library\Infrastructure\Filesystem\MediaFileGuard;
use App\Library\Infrastructure\Scanner\DirectoryScanner;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Filesystem\Filesystem;

final class VideoFileResolutionTest extends TestCase
{
    private Connection $connection;
    private EntityManager $manager;
    private string $schema;
    private string $root;
    private VideoRepository $videos;
    private TvCatalogRepository $catalog;
    private LibraryFileIndexRepository $index;
    private VideoAvailability $availability;
    private VideoService $sources;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a migrated disposable database.');
        }
        $this->schema = 'video_resolution_' . bin2hex(random_bytes(8));
        $this->root = sys_get_temp_dir() . '/baander-' . $this->schema;
        $this->connection = ArtistImportIdentityRace::connect('public');
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        foreach (['libraries', 'videos', 'movies', 'movie_video', 'library_file_index', 'tv_series', 'tv_seasons', 'tv_episodes', 'video_placements', 'video_placement_episode'] as $table) {
            ArtistImportIdentityRace::copyTableWithoutPgroonga($this->connection, $table);
        }
        $this->manager = ArtistImportIdentityRace::entityManager($this->connection);
        $this->videos = new VideoRepository($this->manager);
        $this->catalog = new TvCatalogRepository($this->manager);
        $this->index = new LibraryFileIndexRepository($this->manager);
        $clock = new NativeClock();
        $libraries = new LibraryRepository($this->manager, $clock);
        $files = new LibraryMediaFiles($libraries, $this->index, new MediaFileGuard(), $this->manager, $clock, new NullLogger());
        $this->availability = new VideoAvailability($this->videos, $files);
        $this->sources = new VideoService($this->videos, new LibraryMediaFileResolver($libraries), $this->availability);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->manager->close();
            $this->connection->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $this->connection->close();
        }
        if (isset($this->root)) {
            (new Filesystem())->remove($this->root);
        }
    }

    public function testTheSecondLibraryOpensItsOwnCopyAndAStaleFirstPathIsSkipped(): void
    {
        $first = $this->library('first');
        $second = $this->library('second');
        $video = Video::create('/obsolete/global/movie.mp4', 'same-hash');
        $this->videos->save($video);
        $this->place($first, $video, 'Feature/movie.mp4');
        $this->place($second, $video, 'Other/copy.mp4');

        $source = $this->sources->getSourceMetadata($video->getPublicId(), LibraryReadScope::restricted([$second->getId()]));
        self::assertNotNull($source);
        self::assertSame($second->getPath() . '/Other/copy.mp4', $source->filePath);
        self::assertSame('copy.mp4', $source->filename);
        self::assertTrue(unlink($first->getPath() . '/Feature/movie.mp4'));
        $fallback = $this->sources->getSourceMetadata($video->getPublicId(), LibraryReadScope::unrestricted());
        self::assertNotNull($fallback);
        self::assertSame($source->filePath, $fallback->filePath);
        self::assertNull($this->sources->getSourceMetadata($video->getPublicId(), LibraryReadScope::none()));
        $this->index->markUnavailableByPaths($first->getId(), [$first->getPath() . '/Feature/movie.mp4']);
        self::assertSame([$video->getId()->toString() => false], $this->availability->forVideos([$video->getId()], LibraryReadScope::restricted([$first->getId()])));
        self::assertSame([$video->getId()->toString() => true], $this->availability->forVideos([$video->getId()], LibraryReadScope::restricted([$second->getId()])));
    }

    public function testLinkedLibraryRootUsesTheScannerPathAndKeepsTheRelativeFileAlias(): void
    {
        $library = $this->library('linked-root');
        $canonicalRoot = $library->getPath();
        $video = Video::create('/obsolete/movie.mp4', 'linked-hash');
        $this->videos->save($video);
        $relativePath = 'Movie/feature.mp4';
        $this->place($library, $video, $relativePath);
        $actualFile = $canonicalRoot . '/Movie/source.mp4';
        self::assertTrue(rename($canonicalRoot . '/' . $relativePath, $actualFile));
        self::assertTrue(symlink('source.mp4', $canonicalRoot . '/' . $relativePath));
        $aliasRoot = $this->root . '/library-alias';
        self::assertTrue(symlink($canonicalRoot, $aliasRoot));
        $library->setPath($aliasRoot);
        $this->manager->flush();
        $observations = new DirectoryScanner(new NullLogger())->scan(new LibraryPath($aliasRoot));
        $observedPaths = array_map(static fn ($file): string => $file->getAbsolutePath(), $observations);
        self::assertContains($canonicalRoot . '/' . $relativePath, $observedPaths);
        $scope = LibraryReadScope::restricted([$library->getId()]);
        self::assertSame([$video->getId()->toString() => true], $this->availability->forVideos([$video->getId()], $scope));
        $paths = $this->availability->availablePaths([$video->getId()], $scope);
        self::assertSame($canonicalRoot . '/' . $relativePath, $paths[$video->getId()->toString()][0]->absolutePath);
        $source = $this->sources->getSourceMetadata($video->getPublicId(), $scope);
        self::assertNotNull($source);
        self::assertSame($actualFile, $source->filePath);
        self::assertSame('feature.mp4', $source->filename);
    }

    public function testRenamedMovieUsesItsPlacementAndReplacedHashHidesTheOldVersion(): void
    {
        $library = $this->library('renamed');
        $video = Video::create($library->getPath() . '/Old/movie.mp4', 'old-hash');
        $this->videos->save($video);
        $placement = $this->place($library, $video, 'Old/movie.mp4');
        (new Filesystem())->rename($library->getPath() . '/Old', $library->getPath() . '/New');
        $placement->observe('New/movie.mp4', []);
        $this->catalog->savePlacement($placement);
        $this->catalog->flush();
        $path = $library->getPath() . '/New/movie.mp4';
        $this->index->upsert($library->getId(), $path, $video->getHash(), 4, 'mp4', 1);
        $scope = LibraryReadScope::restricted([$library->getId()]);

        self::assertSame($path, $this->sources->getSourceMetadata($video->getPublicId(), $scope)?->filePath);
        $replacement = Video::create($path, 'new-hash');
        $this->videos->save($replacement);
        $movieId = $placement->getMovieId();
        self::assertNotNull($movieId);
        $newPlacement = VideoPlacement::create($library->getId(), $replacement->getId(), 'New/movie.mp4', [], movieId: $movieId);
        $this->catalog->savePlacement($newPlacement);
        $this->catalog->flush();
        $this->index->upsert($library->getId(), $path, $replacement->getHash(), 4, 'mp4', 2);
        self::assertSame([
            $video->getId()->toString() => false,
            $replacement->getId()->toString() => true,
        ], $this->availability->forVideos([$video->getId(), $replacement->getId()], $scope));
        self::assertNull($this->sources->getSourceMetadata($video->getPublicId(), $scope));
        self::assertSame($path, $this->sources->getSourceMetadata($replacement->getPublicId(), $scope)?->filePath);
    }

    public function testExtraOnlyVideoUsesItsPlacementAndMissingIndexObservationHidesIt(): void
    {
        $library = $this->library('extras');
        $video = Video::create('/obsolete/trailer.mp4', 'extra-hash');
        $this->videos->save($video);
        $this->place($library, $video, 'Movie/Trailers/trailer.mp4', true);
        $scope = LibraryReadScope::restricted([$library->getId()]);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM movie_video'));
        self::assertNotNull($this->sources->getSourceMetadata($video->getPublicId(), $scope));
        $this->index->markUnavailableByPaths($library->getId(), [$library->getPath() . '/Movie/Trailers/trailer.mp4']);
        self::assertSame([$video->getId()->toString() => false], $this->availability->forVideos([$video->getId()], $scope));
        self::assertNull($this->sources->getSourceMetadata($video->getPublicId(), $scope));
    }

    public function testEpisodePlacementBecomesVisibleOnlyAfterItHasAnEpisode(): void
    {
        $library = $this->library('episodes');
        $video = Video::create('/obsolete/episode.mp4', 'episode-hash');
        $this->videos->save($video);
        $series = TvSeries::create($library->getId(), 'Show', 'Show');
        $this->catalog->saveSeries($series);
        $this->catalog->flush();
        $placement = VideoPlacement::create($library->getId(), $video->getId(), 'Show/episode.mp4', [], seriesId: $series->getId());
        $this->catalog->savePlacement($placement);
        $this->catalog->flush();
        $path = $library->getPath() . '/Show/episode.mp4';
        (new Filesystem())->mkdir(dirname($path));
        self::assertSame(4, file_put_contents($path, 'data'));
        $this->index->upsert($library->getId(), $path, $video->getHash(), 4, 'mp4', 1);
        $scope = LibraryReadScope::restricted([$library->getId()]);
        self::assertFalse($this->videos->isVisibleByPublicId($video->getPublicId(), $scope));
        self::assertSame([$video->getId()->toString() => false], $this->availability->forVideos([$video->getId()], $scope));

        $episode = TvEpisode::create($series->getId(), null, null, 'Episode 1', absoluteNumber: 1);
        $this->catalog->saveEpisode($episode);
        $this->catalog->flush();
        $placement->assignEpisodes([$episode->getId()]);
        $this->catalog->savePlacement($placement);
        $this->catalog->flush();
        self::assertTrue($this->videos->isVisibleByPublicId($video->getPublicId(), $scope));
        self::assertSame([$video->getId()->toString() => true], $this->availability->forVideos([$video->getId()], $scope));
        self::assertSame($path, $this->sources->getSourceMetadata($video->getPublicId(), $scope)?->filePath);
    }

    private function library(string $name): LibraryEntity
    {
        $path = $this->root . '/' . $name;
        (new Filesystem())->mkdir($path);
        $library = new LibraryEntity($name, $name, $path, 'movie', 'local');
        $this->manager->persist($library);
        $this->manager->flush();

        return $library;
    }

    private function place(LibraryEntity $library, Video $video, string $relativePath, bool $extra = false): VideoPlacement
    {
        $movie = Movie::create($library->getId(), 'Movie');
        (new MovieRepository($this->manager))->save($movie);
        $placement = VideoPlacement::create($library->getId(), $video->getId(), $relativePath, [], movieId: $movie->getId(), extraKind: $extra ? ExtraKind::Trailer : null);
        $this->catalog->savePlacement($placement);
        $this->catalog->flush();
        $path = $library->getPath() . '/' . $relativePath;
        (new Filesystem())->mkdir(dirname($path));
        self::assertSame(4, file_put_contents($path, 'data'));
        $this->index->upsert($library->getId(), $path, $video->getHash(), 4, 'mp4', 1);

        return $placement;
    }
}
