<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Application\CommandHandler\FilesDiscoveredHandler;
use App\Catalog\Application\Port\VideoAvailabilityPortInterface;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Application\MovieScanner;
use App\Library\Application\TvScanner;
use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Infrastructure\Doctrine\Repository\LibraryRepository;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\FilesystemType;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Tests\Fixtures\Catalog\TvIdentityRace;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[SkipDatabaseRollback]
final class VideoLibraryScanAvailabilityTest extends TestCase
{
    private TvIdentityRace $fixture;
    private Library $movieLibrary;
    private string $movieRoot;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }
        $this->fixture = new TvIdentityRace();
        $this->movieRoot = $this->fixture->root . '-movies';
        new Filesystem()->mkdir($this->movieRoot . '/Heat (1995)');
        $this->movieLibrary = Library::create(
            'Movie availability',
            new LibrarySlug('movie-scan-availability'),
            new LibraryPath($this->movieRoot),
            LibraryType::Movie,
            FilesystemType::Local,
        );
        new LibraryRepository($this->fixture->manager, new NativeClock())->save($this->movieLibrary);
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            new Filesystem()->remove([$this->fixture->root . '-absent', $this->movieRoot, $this->movieRoot . '-absent']);
            $this->fixture->close();
        }
        parent::tearDown();
    }

    public function testAbsentMovieAndTelevisionRootsHideTheirVideosUntilTheNextScanRestoresTheirPlacements(): void
    {
        $this->fixture->video('Show/Season 01/Show.S01E01.mp4');
        new Process([
            'ffmpeg', '-y', '-nostdin', '-loglevel', 'error', '-f', 'lavfi',
            '-i', 'color=c=red:s=64x64:r=5:d=0.4', '-an', '-c:v', 'libx264',
            '-pix_fmt', 'yuv420p', '-f', 'mp4', $this->movieRoot . '/Heat (1995)/Heat.mp4',
        ])->mustRun();
        $this->scan($this->fixture->library, rescan: true);
        $this->scan($this->movieLibrary);
        $connection = $this->fixture->connection;
        /** @var list<array{id: string, video_id: string, series_id: ?string, movie_id: ?string, relative_path: string}> $placements */
        $placements = $connection->fetchAllAssociative('SELECT id, video_id, series_id, movie_id, relative_path FROM video_placements ORDER BY id');
        self::assertCount(2, $placements);
        self::assertSame(1, (int) $connection->fetchOne('SELECT count(*) FROM movie_video'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT count(*) FROM video_placement_episode'));
        $videoIds = array_map(static fn(array $row): Uuid => Uuid::fromString($row['video_id']), $placements);
        $scope = LibraryReadScope::restricted([$this->fixture->library->getId(), $this->movieLibrary->getId()]);
        $availability = $this->fixture->kernel->getContainer()->get('test.service_container')->get(VideoAvailabilityPortInterface::class);
        $present = array_fill_keys(array_column($placements, 'video_id'), true);
        self::assertSame($present, $availability->forVideos($videoIds, $scope));

        self::assertTrue(rename($this->fixture->root, $this->fixture->root . '-absent'));
        self::assertTrue(rename($this->movieRoot, $this->movieRoot . '-absent'));
        $this->scan($this->fixture->library, expectedFiles: 0);
        $this->scan($this->movieLibrary, expectedFiles: 0);

        self::assertSame(array_fill_keys(array_column($placements, 'video_id'), false), $availability->forVideos($videoIds, $scope));
        self::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM library_file_index WHERE available'));
        self::assertSame($placements, $connection->fetchAllAssociative('SELECT id, video_id, series_id, movie_id, relative_path FROM video_placements ORDER BY id'));

        self::assertTrue(rename($this->fixture->root . '-absent', $this->fixture->root));
        self::assertTrue(rename($this->movieRoot . '-absent', $this->movieRoot));
        $this->scan($this->fixture->library);
        $this->scan($this->movieLibrary);

        self::assertSame($present, $availability->forVideos($videoIds, $scope));
        self::assertSame($placements, $connection->fetchAllAssociative('SELECT id, video_id, series_id, movie_id, relative_path FROM video_placements ORDER BY id'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT count(*) FROM movies'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT count(*) FROM tv_series'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT count(*) FROM tv_episodes'));
    }

    private function scan(Library $library, int $expectedFiles = 1, bool $rescan = false): void
    {
        $container = $this->fixture->kernel->getContainer()->get('test.service_container');
        $scanner = $container->get($library->getType() === LibraryType::TvShow ? TvScanner::class : MovieScanner::class);
        $result = $scanner->scan($library, rescan: $rescan);
        self::assertSame($expectedFiles, $result->filesDiscovered);
        $handler = $container->get(FilesDiscoveredHandler::class);
        foreach ($result->directories as $directory => $files) {
            $handler(new FilesDiscovered($library->getId(), $library->getType()->value, $directory, $files));
        }
    }
}
