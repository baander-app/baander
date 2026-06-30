<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Application;

use App\Library\Application\MusicScanner;
use App\Library\Application\Port\DirectoryScannerPortInterface;
use App\Library\Domain\Repository\LibraryFileIndexRepositoryInterface;
use App\Filesystem\Domain\ValueObject\FilesystemType;
use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Infrastructure\Scanner\MediaFile;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class MusicScannerTest extends TestCase
{
    private DirectoryScannerPortInterface&MockObject $directoryScanner;
    private LibraryFileIndexRepositoryInterface&MockObject $fileIndexRepository;
    private LoggerInterface&MockObject $logger;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->directoryScanner = $this->createMock(DirectoryScannerPortInterface::class);
        $this->fileIndexRepository = $this->createMock(LibraryFileIndexRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->tmpDir = sys_get_temp_dir() . '/baander_test_' . uniqid();
        mkdir($this->tmpDir . '/Album1', 0777, true);
        mkdir($this->tmpDir . '/Album2', 0777, true);
    }

    protected function tearDown(): void
    {
        @exec("rm -rf {$this->tmpDir}");
    }

    private function createScanner(): MusicScanner
    {
        return new MusicScanner(
            directoryScanner: $this->directoryScanner,
            fileIndexRepository: $this->fileIndexRepository,
            logger: $this->logger,
        );
    }

    private function createLibrary(): Library
    {
        return Library::create(
            name: 'Test Library',
            slug: new LibrarySlug('test-library'),
            path: new LibraryPath($this->tmpDir),
            type: LibraryType::Music,
            filesystemType: FilesystemType::Local,
        );
    }

    private function createAudioFile(string $dir, string $name, string $content = 'audio content'): MediaFile
    {
        $path = "{$this->tmpDir}/{$dir}/{$name}";
        file_put_contents($path, $content);

        return new MediaFile(
            absolutePath: $path,
            relativePath: "{$dir}/{$name}",
            extension: pathinfo($name, PATHINFO_EXTENSION),
            size: strlen($content),
            modifiedAt: filemtime($path),
        );
    }

    public function testEmptyLibraryReturnsEmptyDirectories(): void
    {
        $library = $this->createLibrary();

        $this->directoryScanner->method('scan')
            ->willReturn([]);

        $this->fileIndexRepository->method('findIndexPathMapByLibrary')
            ->willReturn([]);

        $scanner = $this->createScanner();
        $result = $scanner->scan($library);

        $this->assertSame([], $result->directories);
        $this->assertSame(0, $result->filesDiscovered);
        $this->assertSame(0, $result->filesProcessed);
        $this->assertSame(0, $result->filesSkipped);
    }

    public function testNewFilesAreGroupedByDirectory(): void
    {
        $library = $this->createLibrary();

        $files = [
            $this->createAudioFile('Album1', 'track01.mp3'),
            $this->createAudioFile('Album1', 'track02.mp3'),
            $this->createAudioFile('Album2', 'track01.mp3'),
        ];

        $this->directoryScanner->method('scan')
            ->willReturn($files);

        $this->fileIndexRepository->method('findIndexPathMapByLibrary')
            ->willReturn([]);

        $scanner = $this->createScanner();
        $result = $scanner->scan($library);

        $this->assertSame(3, $result->filesDiscovered);
        $this->assertSame(3, $result->filesProcessed);
        $this->assertCount(2, $result->directories);
    }

    public function testUnchangedFilesAreSkipped(): void
    {
        $library = $this->createLibrary();

        $file = $this->createAudioFile('Album1', 'track01.mp3');
        $hash = hash_file('xxh3', $file->getAbsolutePath());

        $this->directoryScanner->method('scan')
            ->willReturn([$file]);

        // File already indexed with same hash
        $this->fileIndexRepository->method('findIndexPathMapByLibrary')
            ->willReturn([$file->getAbsolutePath() => $hash]);

        $scanner = $this->createScanner();
        $result = $scanner->scan($library);

        $this->assertSame(1, $result->filesDiscovered);
        $this->assertSame(0, $result->filesProcessed);
        $this->assertSame(1, $result->filesSkipped);
        $this->assertSame([], $result->directories);
    }

    public function testChangedFilesAreProcessed(): void
    {
        $library = $this->createLibrary();

        $file = $this->createAudioFile('Album1', 'track01.mp3');

        $this->directoryScanner->method('scan')
            ->willReturn([$file]);

        // File indexed with different hash
        $this->fileIndexRepository->method('findIndexPathMapByLibrary')
            ->willReturn([$file->getAbsolutePath() => 'old_hash']);

        $scanner = $this->createScanner();
        $result = $scanner->scan($library);

        $this->assertSame(1, $result->filesDiscovered);
        $this->assertSame(1, $result->filesProcessed);
        $this->assertCount(1, $result->directories);
    }

    public function testRescanForcesReprocessing(): void
    {
        $library = $this->createLibrary();

        $file = $this->createAudioFile('Album1', 'track01.mp3');

        $this->directoryScanner->method('scan')
            ->willReturn([$file]);

        // Rescan: index map should not be loaded
        $this->fileIndexRepository->expects($this->never())
            ->method('findIndexPathMapByLibrary');

        $scanner = $this->createScanner();
        $result = $scanner->scan($library, rescan: true);

        $this->assertSame(1, $result->filesProcessed);
    }

    public function testNonAudioFilesAreFilteredOut(): void
    {
        $library = $this->createLibrary();

        $coverPath = "{$this->tmpDir}/Album1/cover.jpg";
        file_put_contents($coverPath, 'image data');

        $audioFile = $this->createAudioFile('Album1', 'track01.mp3');

        $coverFile = new MediaFile(
            absolutePath: $coverPath,
            relativePath: 'Album1/cover.jpg',
            extension: 'jpg',
            size: filesize($coverPath),
            modifiedAt: filemtime($coverPath),
        );

        $this->directoryScanner->method('scan')
            ->willReturn([$coverFile, $audioFile]);

        $this->fileIndexRepository->method('findIndexPathMapByLibrary')
            ->willReturn([]);

        $scanner = $this->createScanner();
        $result = $scanner->scan($library);

        // Only 1 audio file discovered
        $this->assertSame(1, $result->filesDiscovered);
        $this->assertSame(1, $result->filesProcessed);
    }
}
