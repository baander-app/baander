<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Application;

use App\Tests\Fixtures\Catalog\PassThroughTransaction;
use App\Tests\Fixtures\Library\InMemoryLibraryRepository;
use App\Library\Application\MovieScanner;
use App\Library\Application\Exception\LibraryScanClaimLostException;
use App\Library\Application\Service\LibraryScanLease;
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
    public function testMissingPathsCommitInBoundedTransactions(string $scannerClass, LibraryType $type, string $extension, bool $rescan): void
    {
        $library = $this->library($type);
        $clock = new MockClock();
        $libraries = new InMemoryLibraryRepository($clock);
        $libraries->add($library);
        $claim = new Uuid();
        self::assertTrue($libraries->claimScan($library->getId(), $claim, 900)->claimed);
        $lease = new LibraryScanLease($libraries, $clock, $library, $claim, 900, 60);
        $paths = array_map(static fn (int $number): string => '/media/missing-' . $number . '.' . $extension, range(1, 2001));
        $transaction = new PassThroughTransaction();
        $recorded = [];
        $index = $this->createMock(LibraryFileIndexRepositoryInterface::class);
        $index->method('findIndexedPathsByLibrary')->willReturn($paths);
        $index->expects(self::exactly(3))->method('markUnavailableByPaths')
            ->willReturnCallback(static function (Uuid $libraryId, array $batch) use ($library, $transaction, &$recorded): void {
                self::assertTrue($libraryId->equals($library->getId()));
                self::assertTrue($transaction->active);
                self::assertLessThanOrEqual(1000, count($batch));
                array_push($recorded, ...$batch);
            });
        $directory = $this->createStub(DirectoryScannerPortInterface::class);
        $directory->method('scan')->willReturn([]);

        (new $scannerClass($directory, $index, new NullLogger(), new CancelAtCheckpoint(), $transaction))->scan($library, $rescan, lease: $lease);

        self::assertSame($paths, $recorded);
        self::assertSame(3, $transaction->runs);
        self::assertSame($claim->toString(), $libraries->claimOf($library));
    }

    /** @return iterable<string, array{class-string<MusicScanner|MovieScanner>, LibraryType, string, bool, bool}> */
    public static function lostClaims(): iterable
    {
        foreach (self::scanners() as $name => $scanner) {
            yield $name . ' released' => [...$scanner, false];
            yield $name . ' replaced' => [...$scanner, true];
        }
    }

    /** @param class-string<MusicScanner|MovieScanner> $scannerClass */
    #[DataProvider('lostClaims')]
    public function testARecentlyLostClaimPreventsDirectoryIndexAndOutboxWrites(string $scannerClass, LibraryType $type, string $extension, bool $rescan, bool $replace): void
    {
        $path = tempnam(sys_get_temp_dir(), 'baander-old-scan-');
        self::assertIsString($path);
        file_put_contents($path, 'media');
        try {
            $library = $this->library($type);
            $clock = new MockClock('2026-10-21 12:00:00 UTC');
            $libraries = new InMemoryLibraryRepository($clock);
            $libraries->add($library);
            $claimId = new Uuid();
            self::assertTrue($libraries->claimScan($library->getId(), $claimId, 900)->claimed);
            $lease = new LibraryScanLease($libraries, $clock, $library, $claimId, 900, 60);
            $successor = $replace ? new Uuid() : null;
            $cancellation = $this->releaseAtCheckpoint($libraries, $library, $successor);
            $index = $this->createMock(LibraryFileIndexRepositoryInterface::class);
            $index->method('findIndexedPathsByLibrary')->willReturn([]);
            $index->method('findAvailableIndexPathMapByLibrary')->willReturn([]);
            $index->expects(self::never())->method('upsert');
            $index->expects(self::never())->method('markUnavailableByPaths');
            $directory = $this->createStub(DirectoryScannerPortInterface::class);
            $directory->method('scan')->willReturn([new MediaFile($path, basename($path), $extension, 5, 1792584000)]);
            $captured = [];
            try {
                (new $scannerClass($directory, $index, new NullLogger(), $cancellation, new PassThroughTransaction()))->scan(
                    $library, $rescan, static function () use (&$captured): void { $captured[] = 'old directory outbox'; }, $lease,
                );
                self::fail('The released scan must stop before directory writes.');
            } catch (LibraryScanClaimLostException) {
                self::assertSame([], $captured);
                self::assertSame($successor?->toString(), $libraries->claimOf($library));
                self::assertEquals(new \DateTimeImmutable('2026-10-21 12:00:00 UTC'), $clock->now());
            }
        } finally {
            unlink($path);
        }
    }

    /** @param class-string<MusicScanner|MovieScanner> $scannerClass */
    #[DataProvider('lostClaims')]
    public function testARecentlyLostEmptyScanLeavesSuccessorAvailabilityIntact(string $scannerClass, LibraryType $type, string $extension, bool $rescan, bool $replace): void
    {
        $library = $this->library($type);
        $clock = new MockClock('2026-10-21 12:00:00 UTC');
        $libraries = new InMemoryLibraryRepository($clock);
        $libraries->add($library);
        $claimId = new Uuid();
        self::assertTrue($libraries->claimScan($library->getId(), $claimId, 900)->claimed);
        $lease = new LibraryScanLease($libraries, $clock, $library, $claimId, 900, 60);
        $successor = $replace ? new Uuid() : null;
        $available = false;
        $cancellation = $this->releaseAtCheckpoint($libraries, $library, $successor, static function () use (&$available): void { $available = true; });
        $index = $this->createMock(LibraryFileIndexRepositoryInterface::class);
        $index->method('findIndexedPathsByLibrary')->willReturn(['/media/restored.' . $extension]);
        $index->method('markUnavailableByPaths')->willReturnCallback(static function () use (&$available): void { $available = false; });
        $index->expects(self::never())->method('upsert');
        $directory = $this->createStub(DirectoryScannerPortInterface::class);
        $directory->method('scan')->willReturn([]);
        $captured = [];
        try {
            (new $scannerClass($directory, $index, new NullLogger(), $cancellation, new PassThroughTransaction()))->scan(
                $library, $rescan, static function () use (&$captured): void { $captured[] = 'old directory outbox'; }, $lease,
            );
            self::fail('The released scan must stop before marking restored paths unavailable.');
        } catch (LibraryScanClaimLostException) {
            self::assertTrue($available);
            self::assertSame([], $captured);
            self::assertSame($successor?->toString(), $libraries->claimOf($library));
            self::assertEquals(new \DateTimeImmutable('2026-10-21 12:00:00 UTC'), $clock->now());
        }
    }

    private function releaseAtCheckpoint(InMemoryLibraryRepository $libraries, Library $library, ?Uuid $successor, ?\Closure $restore = null): JobCancellationCheckpointInterface
    {
        return new class($libraries, $library, $successor, $restore) implements JobCancellationCheckpointInterface {
            private bool $released = false;
            public function __construct(private InMemoryLibraryRepository $libraries, private Library $library, private ?Uuid $successor, private ?\Closure $restore) {}
            public function check(): void
            {
                if ($this->released) {
                    return;
                }
                $this->released = true;
                TestCase::assertNotNull($this->libraries->releaseClaim($this->library->getId(), evenIfLive: true)->released);
                if ($this->successor !== null) {
                    TestCase::assertTrue($this->libraries->claimScan($this->library->getId(), $this->successor, 900)->claimed);
                }
                if ($this->restore !== null) {
                    ($this->restore)();
                }
            }
        };
    }

    private function library(LibraryType $type): Library
    {
        return Library::create('Media', new LibrarySlug('media'), new LibraryPath('/media'), $type, FilesystemType::Local);
    }
}
