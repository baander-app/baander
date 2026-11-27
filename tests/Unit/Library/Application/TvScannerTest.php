<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Application;

use App\Library\Application\Exception\LibraryScanClaimLostException;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\MovieScanner;
use App\Library\Application\MusicScanner;
use App\Library\Application\Service\LibraryScanLease;
use App\Library\Application\TvScanner;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryFileIndexRepositoryInterface;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Infrastructure\Scanner\DirectoryScanner;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\FilesystemType;
use App\Tests\Fixtures\Catalog\PassThroughTransaction;
use App\Tests\Fixtures\Library\InMemoryLibraryRepository;
use App\Tests\Fixtures\Messaging\CancelAtCheckpoint;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

final class TvScannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-tv-scan-' . bin2hex(random_bytes(8));
        (new Filesystem())->mkdir([$this->root . '/Show/Season 01', $this->root . '/Show/Specials']);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testSeasonAndSpecialsPublishSeparateDirectoriesWithLibraryRelativePaths(): void
    {
        $this->file('Show/Season 01/Show - S01E01.mkv', 'episode');
        $this->file('Show/Specials/Show - S00E01.mp4', 'special');
        $index = $this->createMock(LibraryFileIndexRepositoryInterface::class);
        $index->expects(self::exactly(2))->method('upsert');
        $transaction = new PassThroughTransaction();
        $published = [];
        $result = $this->scanner($index, $transaction)->scan(
            $this->library(),
            publishDirectory: static function (string $directory, array $files) use (&$published, $transaction): void {
                self::assertTrue($transaction->active);
                $published[$directory] = array_map(static fn (DiscoveredFile $file): string => $file->relativePath, $files);
            },
        );

        self::assertSame(2, $result->filesProcessed);
        self::assertSame(['Show/Season 01/Show - S01E01.mkv'], $published[$this->root . '/Show/Season 01']);
        self::assertSame(['Show/Specials/Show - S00E01.mp4'], $published[$this->root . '/Show/Specials']);
    }

    public function testChangedSidecarPublishesItsDirectoryWhileUnchangedVideosAreSkipped(): void
    {
        $episode = $this->file('Show/Season 01/Show - S01E01.mkv', 'episode');
        $nfo = $this->file('Show/Season 01/Show - S01E01.nfo', '<episodedetails/>');
        $index = $this->createMock(LibraryFileIndexRepositoryInterface::class);
        $index->method('findAvailableIndexPathMapByLibrary')->willReturn([
            $episode => hash_file('xxh3', $episode),
            $nfo => 'old-sidecar-hash',
        ]);
        $index->expects(self::once())->method('upsert')->with(self::anything(), $nfo);

        $result = $this->scanner($index)->scan($this->library());

        self::assertSame(1, $result->filesProcessed);
        self::assertSame(1, $result->filesSkipped);
        self::assertSame('Show/Season 01/Show - S01E01.nfo', $result->directories[dirname($nfo)][0]->relativePath);
    }

    public function testMovieScansIndexSidecarsAndMusicScansExcludeThem(): void
    {
        foreach (['nfo', 'jpg', 'jpeg', 'png', 'webp'] as $extension) {
            $this->file('Show/tvshow.' . $extension, 'sidecar');
        }
        $this->file('Show/track.flac', 'audio');
        $this->file('Show/unrelated.txt', 'text');
        $logger = new NullLogger();
        $directory = new DirectoryScanner($logger);
        $movieIndex = $this->createMock(LibraryFileIndexRepositoryInterface::class);
        $movieIndex->expects(self::exactly(5))->method('upsert');
        $musicIndex = $this->createMock(LibraryFileIndexRepositoryInterface::class);
        $musicIndex->expects(self::once())->method('upsert')->with(self::anything(), $this->root . '/Show/track.flac');

        $movies = new MovieScanner($directory, $movieIndex, $logger, new CancelAtCheckpoint(), new PassThroughTransaction());
        $music = new MusicScanner($directory, $musicIndex, $logger, new CancelAtCheckpoint(), new PassThroughTransaction());

        self::assertSame(5, $movies->scan($this->library(LibraryType::Movie))->filesProcessed);
        self::assertSame(1, $music->scan($this->library(LibraryType::Music))->filesProcessed);
    }

    public function testRemovedFilesAreMarkedUnavailableWithoutReindexingUnchangedFiles(): void
    {
        $episode = $this->file('Show/Season 01/Show - S01E01.mkv', 'episode');
        $missing = $this->root . '/Show/Season 01/Show - S01E02.mkv';
        $library = $this->library();
        $index = $this->createMock(LibraryFileIndexRepositoryInterface::class);
        $index->method('findIndexedPathsByLibrary')->willReturn([$episode, $missing]);
        $index->method('findAvailableIndexPathMapByLibrary')->willReturn([$episode => hash_file('xxh3', $episode)]);
        $index->expects(self::never())->method('upsert');
        $index->expects(self::once())->method('markUnavailableByPaths')->with($library->getId(), [$missing]);

        self::assertSame(0, $this->scanner($index)->scan($library)->filesProcessed);
    }

    public function testTraversalRenewsTheLeaseAndStopsBeforeIndexWritesWhenTheClaimWasReplaced(): void
    {
        $this->file('Show/Season 01/Show - S01E01.mkv', 'episode');
        $clock = new MockClock('2026-11-27 12:00:00 UTC');
        $library = $this->library();
        $libraries = new InMemoryLibraryRepository($clock);
        $libraries->add($library);
        $claim = new Uuid();
        self::assertTrue($libraries->claimScan($library->getId(), $claim, 60)->claimed);
        $lease = new LibraryScanLease($libraries, $clock, $library, $claim, 60, 1);
        self::assertNotNull($libraries->releaseClaim($library->getId(), evenIfLive: true)->released);
        self::assertTrue($libraries->claimScan($library->getId(), new Uuid(), 60)->claimed);
        $clock->sleep(2);
        $index = $this->createMock(LibraryFileIndexRepositoryInterface::class);
        $index->expects(self::never())->method('upsert');
        $index->expects(self::never())->method('markUnavailableByPaths');

        $this->expectException(LibraryScanClaimLostException::class);
        $this->scanner($index)->scan($library, lease: $lease);
    }

    private function scanner(LibraryFileIndexRepositoryInterface $index, ?PassThroughTransaction $transaction = null): TvScanner
    {
        $logger = new NullLogger();

        return new TvScanner(new MovieScanner(
            new DirectoryScanner($logger),
            $index,
            $logger,
            new CancelAtCheckpoint(),
            $transaction ?? new PassThroughTransaction(),
        ));
    }

    private function library(LibraryType $type = LibraryType::TvShow): Library
    {
        return Library::create('Television', new LibrarySlug('television'), new LibraryPath($this->root), $type, FilesystemType::Local);
    }

    private function file(string $path, string $contents): string
    {
        $absolute = $this->root . '/' . $path;
        self::assertNotFalse(file_put_contents($absolute, $contents));

        return $absolute;
    }
}
