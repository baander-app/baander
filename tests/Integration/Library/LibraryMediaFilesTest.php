<?php

declare(strict_types=1);

namespace App\Tests\Integration\Library;

use App\Library\Application\Exception\LibraryMediaDirectoryNotWritableException;
use App\Library\Application\Exception\LibraryMediaFileOutsideRootException;
use App\Library\Application\Exception\LibraryRootUnavailableException;
use App\Library\Application\Exception\LibraryScanAlreadyRunningException;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\MusicScanner;
use App\Library\Application\Port\LibraryMediaFileInspection;
use App\Library\Application\Port\LibraryMediaFileLeftReason;
use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Infrastructure\Doctrine\Repository\LibraryFileIndexRepository;
use App\Library\Infrastructure\Doctrine\Repository\LibraryRepository;
use App\Library\Infrastructure\Filesystem\LibraryMediaFiles;
use App\Library\Infrastructure\Filesystem\MediaFileGuard;
use App\Library\Infrastructure\Scanner\DirectoryScanner;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Port\JobCancellationCheckpointInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\FilesystemType;
use App\Tests\Integration\OwnershipPersistenceHarness;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Clock\NativeClock;

/**
 * Media file deletion against a real directory tree and the migrated PostgreSQL file index: the
 * scanner indexes the files, the deletion checks every path before changing anything, deletes the
 * index rows in the caller's transaction and unlinks after it, and a file it leaves is imported
 * again by the next incremental scan.
 */
final class LibraryMediaFilesTest extends TestCase
{
    use OwnershipPersistenceHarness {
        setUp as private harnessSetUp;
        tearDown as private harnessTearDown;
    }

    private string $base;
    private string $album;
    private LibraryRepository $libraries;
    private LibraryFileIndexRepository $fileIndex;
    private LibraryMediaFiles $files;
    private MusicScanner $scanner;

    protected function setUp(): void
    {
        $this->harnessSetUp();

        $this->base = sys_get_temp_dir() . '/baander-media-files-' . bin2hex(random_bytes(6));
        $this->album = $this->base . '/library/Artist/Album';
        self::assertTrue(mkdir($this->album, 0777, true));
        self::assertTrue(mkdir($this->base . '/elsewhere'));

        $this->libraries = new LibraryRepository($this->manager, new NativeClock());
        $this->fileIndex = new LibraryFileIndexRepository($this->manager);
        $this->files = new LibraryMediaFiles($this->libraries, $this->fileIndex, new MediaFileGuard(), $this->manager);
        $this->scanner = new MusicScanner(
            new DirectoryScanner(),
            $this->fileIndex,
            new NullLogger(),
            new class implements JobCancellationCheckpointInterface {
                public function check(): void
                {
                }
            },
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->base)) {
            $this->remove($this->base);
        }
        $this->harnessTearDown();
    }

    public function testDeletesAFileInsideTheRootAndItsIndexRow(): void
    {
        [$library, $first, $second] = $this->scannedAlbum();

        $deletion = $this->files->prepareDeletion($library->getId(), [$first]);
        $this->inCallerTransaction(fn () => $this->files->deleteIndexRows($deletion));
        $result = $this->files->deleteFiles($deletion);

        self::assertSame([$first], $result->removed);
        self::assertSame([], $result->left);
        self::assertFileDoesNotExist($first);
        self::assertFileExists($second);
        self::assertSame([$second], $this->indexedPaths($library));
    }

    public function testAPathOutsideTheRootRefusesTheWholeRequest(): void
    {
        [$library, $first, $second] = $this->scannedAlbum();
        $outside = $this->write($this->base . '/elsewhere/03.flac', 'outside');

        try {
            $this->files->prepareDeletion($library->getId(), [$first, $outside]);
            self::fail('A path outside the root must refuse the deletion.');
        } catch (LibraryMediaFileOutsideRootException $refusal) {
            self::assertInstanceOf(InvalidInputException::class, $refusal);
            self::assertSame(['reason' => 'path_outside_library', 'paths' => [$outside]], $refusal->details);
        }

        self::assertFileExists($first);
        self::assertFileExists($outside);
        self::assertSame([$first, $second], $this->indexedPaths($library));
    }

    public function testAnUnavailableLibraryRootRefusesTheRequestAsAConflict(): void
    {
        [$library, $first, $second] = $this->scannedAlbum();
        self::assertTrue(rename($this->base . '/library', $this->base . '/unmounted'));

        try {
            $this->files->prepareDeletion($library->getId(), [$first, $second]);
            self::fail('An unavailable library root must refuse the deletion.');
        } catch (LibraryRootUnavailableException $refusal) {
            self::assertInstanceOf(ConflictException::class, $refusal);
            self::assertSame(['reason' => 'library_root_unavailable', 'root' => $this->base . '/library'], $refusal->details);
        }

        self::assertSame([$first, $second], $this->indexedPaths($library));
    }

    public function testADirectoryTheServerCannotWriteRefusesTheRequestAsAConflict(): void
    {
        [$library, $first, $second] = $this->scannedAlbum();
        $this->makeReadOnly($this->album);

        try {
            $this->files->prepareDeletion($library->getId(), [$first, $second]);
            self::fail('An unwritable directory must refuse the deletion.');
        } catch (LibraryMediaDirectoryNotWritableException $refusal) {
            self::assertInstanceOf(ConflictException::class, $refusal);
            self::assertSame(['reason' => 'directory_not_writable', 'directories' => [realpath($this->album)]], $refusal->details);
        }

        self::assertFileExists($first);
        self::assertSame([$first, $second], $this->indexedPaths($library));
    }

    public function testALiveScanClaimRefusesTheRequestAndALapsedOneDoesNot(): void
    {
        [$library, $first] = $this->scannedAlbum();
        self::assertTrue($this->libraries->claimScan($library->getId(), new Uuid(), -1), 'A claim whose lease has lapsed.');
        self::assertFalse($this->files->prepareDeletion($library->getId(), [$first])->scanInProgress);

        self::assertTrue($this->libraries->claimScan($library->getId(), new Uuid(), 900));
        $inspection = $this->files->inspect($library->getId(), [$first]);
        self::assertTrue($inspection->scanInProgress);
        self::assertFalse($inspection->allowsDeletion());
        try {
            $this->files->prepareDeletion($library->getId(), [$first]);
            self::fail('A live scan claim must refuse the deletion.');
        } catch (LibraryScanAlreadyRunningException $refusal) {
            self::assertInstanceOf(ConflictException::class, $refusal);
        }
        self::assertFileExists($first);
    }

    public function testAFileLeftAfterTheCommitHasNoIndexRowAndTheNextScanImportsIt(): void
    {
        [$library, $first, $second] = $this->scannedAlbum();

        $deletion = $this->files->prepareDeletion($library->getId(), [$first, $second]);
        $this->inCallerTransaction(fn () => $this->files->deleteIndexRows($deletion));
        $this->makeReadOnly($this->album);
        $result = $this->files->deleteFiles($deletion);

        self::assertSame([], $result->removed);
        self::assertSame([$first, $second], array_map(static fn ($left): string => $left->path, $result->left));
        self::assertSame(LibraryMediaFileLeftReason::UnlinkFailed, $result->left[0]->reason);
        self::assertFileExists($first);
        self::assertSame([], $this->indexedPaths($library));

        $published = [];
        /** @param array<DiscoveredFile> $files */
        $publish = static function (string $directory, array $files) use (&$published): void {
            foreach ($files as $file) {
                $published[] = $file->absolutePath;
            }
        };
        $scan = $this->scanner->scan($library, publishDirectory: $publish);

        self::assertSame(2, $scan->filesProcessed);
        sort($published);
        self::assertSame([$first, $second], $published);
        self::assertSame([$first, $second], $this->indexedPaths($library));
    }

    public function testIndexRowsComeBackWhenTheCallersTransactionRollsBack(): void
    {
        [$library, $first, $second] = $this->scannedAlbum();
        $deletion = $this->files->prepareDeletion($library->getId(), [$first]);

        try {
            $this->inCallerTransaction(function () use ($deletion, $library, $second): void {
                $this->files->deleteIndexRows($deletion);
                self::assertSame([$second], $this->indexedPaths($library));
                throw new RuntimeException('The catalog delete failed.');
            });
            self::fail('The caller transaction must fail.');
        } catch (RuntimeException) {
        }

        self::assertSame([$first, $second], $this->indexedPaths($library));
    }

    public function testDeletingIndexRowsOutsideATransactionIsRefused(): void
    {
        $this->manager->getConnection()->rollBack();
        try {
            $this->files->deleteIndexRows(new LibraryMediaFileInspection(new Uuid(), $this->base, [], false, true));
            self::fail('Index rows must be deleted inside the caller transaction.');
        } catch (LogicException) {
        } finally {
            $this->manager->getConnection()->beginTransaction();
        }
    }

    public function testAnUnknownLibraryIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);

        $this->files->prepareDeletion(new Uuid(), []);
    }

    /** @return array{Library, string, string} the library and its two indexed files, as the scanner stored their paths */
    private function scannedAlbum(): array
    {
        $this->write($this->album . '/01.flac', 'first');
        $this->write($this->album . '/02.flac', 'second');
        $suffix = bin2hex(random_bytes(6));
        $library = Library::create(
            name: 'Media files ' . $suffix,
            slug: new LibrarySlug('media-files-' . $suffix),
            path: new LibraryPath($this->base . '/library'),
            type: LibraryType::Music,
            filesystemType: FilesystemType::Local,
        );
        $this->libraries->save($library);

        self::assertSame(2, $this->scanner->scan($library)->filesProcessed);
        $first = $this->realAlbum() . '/01.flac';
        $second = $this->realAlbum() . '/02.flac';
        self::assertSame([$first, $second], $this->indexedPaths($library));

        return [$library, $first, $second];
    }

    private function realAlbum(): string
    {
        return (string) realpath($this->album);
    }

    /** Runs $operation in a transaction nested in the harness's, as a delete runs it in its own. */
    private function inCallerTransaction(callable $operation): void
    {
        $this->manager->getConnection()->transactional(static function () use ($operation): void {
            $operation();
        });
    }

    /** @return list<string> */
    private function indexedPaths(Library $library): array
    {
        $paths = array_keys($this->fileIndex->findIndexPathMapByLibrary($library->getId()));
        sort($paths);

        return $paths;
    }

    private function write(string $path, string $contents): string
    {
        self::assertNotFalse(file_put_contents($path, $contents));

        return $path;
    }

    /** Root ignores directory modes, so the read-only cases need an unprivileged test user. */
    private function makeReadOnly(string $directory): void
    {
        self::assertTrue(chmod($directory, 0555));
        clearstatcache(true);
        self::assertFalse(is_writable($directory), 'The read-only cases need a test user other than root.');
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        chmod($path, 0777);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
