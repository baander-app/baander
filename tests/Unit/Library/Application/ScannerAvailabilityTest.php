<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Application;

use App\Tests\Fixtures\Catalog\PassThroughTransaction;
use App\Library\Application\MovieScanner;
use App\Library\Application\Exception\LibraryScanClaimLostException;
use App\Library\Application\Service\LibraryScanLease;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Shared\Application\Port\JobCancellationCheckpointInterface;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Clock\MockClock;
use App\Library\Application\MusicScanner;
use App\Library\Application\Port\DirectoryScannerPortInterface;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryFileIndexRepositoryInterface;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Infrastructure\Scanner\MediaFile;
use App\Shared\Application\JobCancelledException;
use App\Shared\Domain\ValueObject\FilesystemType;
use App\Tests\Fixtures\Messaging\CancelAtCheckpoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class ScannerAvailabilityTest extends TestCase
{
    /** @return iterable<string, array{class-string<MusicScanner|MovieScanner>, LibraryType, string, bool}> */
    public static function scanners(): iterable
    {
        foreach ([false, true] as $rescan) {
            yield 'music ' . (int) $rescan => [MusicScanner::class, LibraryType::Music, 'flac', $rescan];
            yield 'movies ' . (int) $rescan => [MovieScanner::class, LibraryType::Movie, 'mkv', $rescan];
        }
    }

    /** @param class-string<MusicScanner|MovieScanner> $scannerClass */
    #[DataProvider('scanners')]
    public function testAnEmptyScanMarksKnownPathsUnavailableWithoutDeletingThem(string $scannerClass, LibraryType $type, string $extension, bool $rescan): void
    {
        $library = $this->library($type);
        $path = '/media/missing.' . $extension;
        $index = $this->createMock(LibraryFileIndexRepositoryInterface::class);
        $index->method('findIndexedPathsByLibrary')->willReturn([$path]);
        $index->expects(self::never())->method('removeByPath');
        $index->expects(self::never())->method('removeByPaths');
        $index->expects(self::once())->method('markUnavailableByPaths')->with($library->getId(), [$path]);
        $directory = $this->createStub(DirectoryScannerPortInterface::class);
        $directory->method('scan')->willReturn([]);

        $result = (new $scannerClass($directory, $index, new NullLogger(), new CancelAtCheckpoint(), new PassThroughTransaction()))->scan($library, $rescan);

        self::assertSame(0, $result->filesProcessed);
    }

    /** @param class-string<MusicScanner|MovieScanner> $scannerClass */
    #[DataProvider('scanners')]
    public function testCancellationOfAnEmptyScanDoesNotChangeAvailability(string $scannerClass, LibraryType $type, string $extension, bool $rescan): void
    {
        $index = $this->createMock(LibraryFileIndexRepositoryInterface::class);
        $index->method('findIndexedPathsByLibrary')->willReturn(['/media/missing.' . $extension]);
        $index->expects(self::never())->method('markUnavailableByPaths');
        $directory = $this->createStub(DirectoryScannerPortInterface::class);
        $directory->method('scan')->willReturn([]);

        $this->expectException(JobCancelledException::class);
        (new $scannerClass($directory, $index, new NullLogger(), new CancelAtCheckpoint(0), new PassThroughTransaction()))->scan($this->library($type), $rescan);
    }

    /** @param class-string<MusicScanner|MovieScanner> $scannerClass */
    #[DataProvider('scanners')]
    public function testTraversalFailureDoesNotChangeAvailability(string $scannerClass, LibraryType $type, string $extension, bool $rescan): void
    {
        $index = $this->createMock(LibraryFileIndexRepositoryInterface::class);
        $index->expects(self::never())->method('markUnavailableByPaths');
        $directory = $this->createStub(DirectoryScannerPortInterface::class);
        $directory->method('scan')->willThrowException(new RuntimeException('Directory cannot be read'));

        $this->expectExceptionMessage('Directory cannot be read');
        (new $scannerClass($directory, $index, new NullLogger(), new CancelAtCheckpoint(), new PassThroughTransaction()))->scan($this->library($type), $rescan);
    }

    /** @param class-string<MusicScanner|MovieScanner> $scannerClass */
    #[DataProvider('scanners')]
    public function testReturningUnchangedFilesAreIndexedBeforeDurableCapture(string $scannerClass, LibraryType $type, string $extension, bool $rescan): void
    {
        $path = tempnam(sys_get_temp_dir(), 'baander-return-');
        self::assertIsString($path);
        file_put_contents($path, 'unchanged media');
        try {
            $index = $this->createMock(LibraryFileIndexRepositoryInterface::class);
            $index->method('findIndexedPathsByLibrary')->willReturn([$path]);
            $index->method('findAvailableIndexPathMapByLibrary')->willReturn([]);
            $events = new \ArrayObject();
            $index->expects(self::once())->method('upsert')->willReturnCallback(static function () use ($events): void {
                $events->append('indexed');
            });
            $index->expects(self::never())->method('markUnavailableByPaths');
            $directory = $this->createStub(DirectoryScannerPortInterface::class);
            $directory->method('scan')->willReturn([new MediaFile($path, basename($path), $extension, 15, time())]);

            $result = (new $scannerClass($directory, $index, new NullLogger(), new CancelAtCheckpoint(), new PassThroughTransaction()))->scan(
                $this->library($type), $rescan, static function () use ($events): void { self::assertSame(['indexed'], $events->getArrayCopy()); },
            );
            self::assertSame(1, $result->filesProcessed);
        } finally {
            unlink($path);
        }
    }

    /** @param class-string<MusicScanner|MovieScanner> $scannerClass */
    #[DataProvider('scanners')]
    public function testPublicationFailureLeavesUnseenPathsUntouched(string $scannerClass, LibraryType $type, string $extension, bool $rescan): void
    {
        $path = tempnam(sys_get_temp_dir(), 'baander-publication-');
        self::assertIsString($path);
        file_put_contents($path, 'media');
        try {
            $index = $this->createMock(LibraryFileIndexRepositoryInterface::class);
            $index->method('findIndexedPathsByLibrary')->willReturn(['/media/gone.' . $extension]);
            $index->expects(self::never())->method('markUnavailableByPaths');
            $index->expects(self::once())->method('upsert');
            $directory = $this->createStub(DirectoryScannerPortInterface::class);
            $directory->method('scan')->willReturn([new MediaFile($path, basename($path), $extension, 5, time())]);
            $this->expectExceptionMessage('Capture failed');
            (new $scannerClass($directory, $index, new NullLogger(), new CancelAtCheckpoint(), new PassThroughTransaction()))->scan(
                $this->library($type), $rescan, static function (): void { throw new RuntimeException('Capture failed'); },
            );
        } finally {
            unlink($path);
        }
    }

    /** @param class-string<MusicScanner|MovieScanner> $scannerClass */
    #[DataProvider('scanners')]
    public function testAFailedHashDoesNotCountAnObservedPathAsMissing(string $scannerClass, LibraryType $type, string $extension, bool $rescan): void
    {
        $path = sys_get_temp_dir() . '/baander-hash-failure-' . bin2hex(random_bytes(8));
        $missing = '/media/unseen.' . $extension;
        $library = $this->library($type);
        $index = $this->createMock(LibraryFileIndexRepositoryInterface::class);
        $index->method('findIndexedPathsByLibrary')->willReturn([$path, $missing]);
        $index->expects(self::once())->method('markUnavailableByPaths')->with($library->getId(), [$missing]);
        $index->expects(self::never())->method('upsert');
        $directory = $this->createStub(DirectoryScannerPortInterface::class);
        $directory->method('scan')->willReturn([new MediaFile($path, basename($path), $extension, 5, time())]);
        set_error_handler(static fn (): bool => true);
        try {
            $result = (new $scannerClass($directory, $index, new NullLogger(), new CancelAtCheckpoint(), new PassThroughTransaction()))->scan($library, $rescan);
            self::assertSame(1, $result->filesSkipped);
        } finally {
            restore_error_handler();
        }
    }

    /** @param class-string<MusicScanner|MovieScanner> $scannerClass */
    #[DataProvider('scanners')]
    public function testALostClaimPreventsMissingStateWrites(string $scannerClass, LibraryType $type, string $extension, bool $rescan): void
    {
        $library = $this->library($type);
        $index = $this->createMock(LibraryFileIndexRepositoryInterface::class);
        $index->method('findIndexedPathsByLibrary')->willReturn(['/media/missing.' . $extension]);
        $index->expects(self::never())->method('markUnavailableByPaths');
        $directory = $this->createStub(DirectoryScannerPortInterface::class);
        $directory->method('scan')->willReturn([]);
        $libraries = $this->createMock(LibraryRepositoryInterface::class);
        $libraries->expects(self::once())->method('renewClaim')->willReturn(false);
        $clock = new MockClock();
        $cancellation = new class($clock) implements JobCancellationCheckpointInterface {
            public function __construct(private MockClock $clock) {}
            public function check(): void { $this->clock->sleep(60); }
        };
        $lease = new LibraryScanLease($libraries, $clock, $library, new Uuid(), 900, 60);

        $this->expectException(LibraryScanClaimLostException::class);
        (new $scannerClass($directory, $index, new NullLogger(), $cancellation, new PassThroughTransaction()))->scan($library, $rescan, lease: $lease);
    }

    private function library(LibraryType $type): Library
    {
        return Library::create('Media', new LibrarySlug('media'), new LibraryPath('/media'), $type, FilesystemType::Local);
    }
}
