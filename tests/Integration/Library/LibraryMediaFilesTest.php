<?php

declare(strict_types=1);

namespace App\Tests\Integration\Library;

use App\Library\Application\Exception\LibraryMediaDirectoryNotWritableException;
use App\Library\Application\Exception\LibraryMediaFileOutsideRootException;
use App\Library\Application\Exception\LibraryMediaFilesAllMissingException;
use App\Library\Application\Exception\LibraryRootUnavailableException;
use App\Library\Application\Exception\LibraryBusyException;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\MusicScanner;
use App\Library\Application\Port\LibraryMediaFileClaim;
use App\Library\Application\Port\LibraryMediaFileDeletionResult;
use App\Library\Application\Port\LibraryMediaFileInspection;
use App\Library\Application\Port\LibraryMediaFileLeft;
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
        $this->files = new LibraryMediaFiles($this->libraries, $this->fileIndex, new MediaFileGuard(), $this->manager, new NativeClock(), new NullLogger());
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

    public function testDeletesAFileInsideTheRootAndItsIndexRowAndLeavesTheScanStatusAlone(): void
    {
        [$library, $first, $second] = $this->scannedAlbum();
        $this->manager->getConnection()->executeStatement(
            "UPDATE libraries SET scan_status = 'completed', last_scan = now() - interval '1 day' WHERE id = ?",
            [$library->getId()->toString()],
        );
        $before = $this->claimRow($library);

        $result = $this->deleteWithFiles($library, [$first]);

        self::assertSame([$first], $result->removed);
        self::assertSame([], $result->left);
        self::assertFileDoesNotExist($first);
        self::assertFileExists($second);
        self::assertSame([$second], $this->indexedPaths($library));
        self::assertSame($before, $this->claimRow($library));
        self::assertNull($before['claim_id']);
    }

    public function testADeleteHoldsTheLibraryAgainstScansAndOtherDeletesUntilItIsReleased(): void
    {
        [$library, $first] = $this->scannedAlbum();
        $claim = $this->files->claim($library->getId());

        self::assertFalse($this->libraries->claimScan($library->getId(), new Uuid(), 900)->claimed);
        self::assertTrue($this->files->inspect($library->getId(), [$first])->libraryBusy);
        $this->expectBusy(fn () => $this->files->claim($library->getId()), 'delete');

        $this->files->release($claim);
        self::assertNull($this->claimRow($library)['claim_id']);
        self::assertFalse($this->files->inspect($library->getId(), [$first])->libraryBusy);
        $this->files->release($claim);
    }

    public function testAPathOutsideTheRootRefusesTheWholeRequest(): void
    {
        [$library, $first, $second] = $this->scannedAlbum();
        $outside = $this->write($this->base . '/elsewhere/03.flac', 'outside');

        try {
            $this->files->prepareDeletion($this->files->claim($library->getId()), [$first, $outside]);
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
            $this->files->prepareDeletion($this->files->claim($library->getId()), [$first, $second]);
            self::fail('An unavailable library root must refuse the deletion.');
        } catch (LibraryRootUnavailableException $refusal) {
            self::assertInstanceOf(ConflictException::class, $refusal);
            self::assertSame(['reason' => 'library_root_unavailable', 'root' => $this->base . '/library'], $refusal->details);
        }

        self::assertSame([$first, $second], $this->indexedPaths($library));
    }

    /** Part of the library's storage unmounted reads as every file missing; dropping their index rows would bring the songs back. */
    public function testEveryRequestedFileMissingRefusesTheRequestAsAConflict(): void
    {
        [$library, $first, $second] = $this->scannedAlbum();
        self::assertTrue(unlink($first));
        self::assertTrue(unlink($second));

        self::assertFalse($this->files->inspect($library->getId(), [$first, $second])->allowsDeletion());
        $claim = $this->files->claim($library->getId());
        try {
            $this->files->prepareDeletion($claim, [$first, $second]);
            self::fail('A request whose files are all missing must refuse the deletion.');
        } catch (LibraryMediaFilesAllMissingException $refusal) {
            self::assertInstanceOf(ConflictException::class, $refusal);
            self::assertSame(['reason' => 'all_files_missing', 'root' => $this->base . '/library'], $refusal->details);
        } finally {
            $this->files->release($claim);
        }

        self::assertSame([$first, $second], $this->indexedPaths($library));
    }

    public function testARequestWithoutFilesIsNotRefused(): void
    {
        [$library] = $this->scannedAlbum();

        self::assertTrue($this->files->inspect($library->getId(), [])->allowsDeletion());
        $result = $this->deleteWithFiles($library, []);

        self::assertSame([[], [], []], [$result->removed, $result->missing, $result->left]);
    }

    public function testOnlyALiveDeleteClaimHoldsTheLibraryAgainstImports(): void
    {
        [$library] = $this->scannedAlbum();
        self::assertFalse($this->files->isHeldByDelete($library->getId()));

        $scan = new Uuid();
        self::assertTrue($this->libraries->claimScan($library->getId(), $scan, 900)->claimed);
        self::assertFalse($this->files->isHeldByDelete($library->getId()), 'A scan does not hold imports back.');
        self::assertTrue($this->libraries->endScanClaim($scan, true));

        $claim = $this->files->claim($library->getId());
        self::assertTrue($this->files->isHeldByDelete($library->getId()));
        $this->manager->getConnection()->executeStatement(
            "UPDATE libraries SET claim_expires_at = clock_timestamp() - interval '1 second' WHERE id = ?",
            [$library->getId()->toString()],
        );
        self::assertFalse($this->files->isHeldByDelete($library->getId()), 'A lapsed delete claim holds nothing.');
        $this->files->release($claim);

        self::assertFalse($this->files->isHeldByDelete(new Uuid()));
    }

    public function testForgetMissingNamesTheFilesThatAreGoneInRequestOrderAndDeletesOnlyTheirIndexRows(): void
    {
        [$library, $first, $second] = $this->scannedAlbum();
        $dangling = $this->album . '/03.flac';
        self::assertTrue(symlink($this->base . '/elsewhere/deleted.flac', $dangling));
        self::assertTrue(unlink($first));

        self::assertSame([$dangling, $first], $this->files->forgetMissing($library->getId(), [$dangling, $second, $first]));
        self::assertSame([$second], $this->indexedPaths($library));
    }

    /**
     * The scan indexes a file as it queues its import. When the import finds the file gone, as on
     * storage unmounted in between, it has the index forget the file; once the file is back
     * unchanged, the next incremental scan reads it as new and queues it again instead of
     * skipping it as known.
     */
    public function testAFileAnImportFoundMissingIsQueuedAgainByTheNextScanWhenItComesBack(): void
    {
        [$library, $first, $second] = $this->scannedAlbum();
        self::assertTrue(rename($first, $this->base . '/elsewhere/01.flac'));

        self::assertSame([$first], $this->files->forgetMissing($library->getId(), [$first, $second]));
        self::assertTrue(rename($this->base . '/elsewhere/01.flac', $first));

        $published = [];
        /** @param array<DiscoveredFile> $files */
        $publish = static function (string $directory, array $files) use (&$published): void {
            foreach ($files as $file) {
                $published[] = $file->absolutePath;
            }
        };
        $scan = $this->scanner->scan($library, publishDirectory: $publish);

        self::assertSame([$first], $published, 'Only the returning file is new; its unchanged neighbour is still known.');
        self::assertSame(1, $scan->filesProcessed);
        self::assertSame([$first, $second], $this->indexedPaths($library));
    }

    public function testADirectoryTheServerCannotWriteRefusesTheRequestAsAConflict(): void
    {
        [$library, $first, $second] = $this->scannedAlbum();
        $this->makeReadOnly($this->album);

        try {
            $this->files->prepareDeletion($this->files->claim($library->getId()), [$first, $second]);
            self::fail('An unwritable directory must refuse the deletion.');
        } catch (LibraryMediaDirectoryNotWritableException $refusal) {
            self::assertInstanceOf(ConflictException::class, $refusal);
            self::assertSame(['reason' => 'directory_not_writable', 'directories' => [realpath($this->album)]], $refusal->details);
        }

        self::assertFileExists($first);
        self::assertSame([$first, $second], $this->indexedPaths($library));
    }

    public function testALiveScanClaimRefusesTheDeleteNamingTheScan(): void
    {
        [$library, $first] = $this->scannedAlbum();
        self::assertTrue($this->libraries->claimScan($library->getId(), new Uuid(), 900)->claimed);

        $inspection = $this->files->inspect($library->getId(), [$first]);
        self::assertTrue($inspection->libraryBusy);
        self::assertFalse($inspection->allowsDeletion());
        $this->expectBusy(fn () => $this->files->claim($library->getId()), 'scan');
        self::assertSame('scan', $this->claimRow($library)['claim_kind']);
        self::assertFileExists($first);
    }

    public function testADeleteOnALibraryWhoseScanDiedTakesTheLapsedClaimAndLeavesTheScanFailed(): void
    {
        [$library, $first, $second] = $this->scannedAlbum();
        self::assertTrue($this->libraries->claimScan($library->getId(), new Uuid(), -1)->claimed, 'A claim whose lease has lapsed.');
        self::assertFalse($this->files->inspect($library->getId(), [$first])->libraryBusy);

        $result = $this->deleteWithFiles($library, [$first]);

        self::assertSame([$first], $result->removed);
        self::assertSame([$second], $this->indexedPaths($library));
        $row = $this->claimRow($library);
        self::assertSame(['failed', null, null], [$row['scan_status'], $row['claim_id'], $row['claim_kind']]);
    }

    public function testADeleteWhoseClaimAnotherHolderTookOverStopsUnlinkingAndReportsTheFilesAsLeft(): void
    {
        [$library, $first, $second] = $this->scannedAlbum();
        $claim = $this->files->claim($library->getId());
        $deletion = $this->files->prepareDeletion($claim, [$first, $second]);
        $this->inCallerTransaction(fn () => $this->files->deleteIndexRows($deletion));
        // The delete stalled past its lease, and a scan took the library over.
        $this->manager->getConnection()->executeStatement(
            "UPDATE libraries SET claim_expires_at = clock_timestamp() - interval '1 second' WHERE id = ?",
            [$library->getId()->toString()],
        );
        $scan = new Uuid();
        self::assertTrue($this->libraries->claimScan($library->getId(), $scan, 900)->claimed);

        $result = $this->files->deleteFiles($claim, $deletion);
        $this->files->release($claim);

        self::assertSame([], $result->removed);
        self::assertSame([$first, $second], array_map(static fn (LibraryMediaFileLeft $left): string => $left->path, $result->left));
        self::assertSame(
            [LibraryMediaFileLeftReason::ClaimLost, LibraryMediaFileLeftReason::ClaimLost],
            array_map(static fn (LibraryMediaFileLeft $left): LibraryMediaFileLeftReason => $left->reason, $result->left),
        );
        self::assertFileExists($first);
        self::assertFileExists($second);
        $row = $this->claimRow($library);
        self::assertSame([$scan->toString(), 'scan'], [$row['claim_id'], $row['claim_kind']], 'Releasing the lost claim leaves the scan\'s alone.');
    }

    public function testAFileLeftAfterTheCommitHasNoIndexRowAndTheNextScanImportsIt(): void
    {
        [$library, $first, $second] = $this->scannedAlbum();

        $claim = $this->files->claim($library->getId());
        $deletion = $this->files->prepareDeletion($claim, [$first, $second]);
        $this->inCallerTransaction(fn () => $this->files->deleteIndexRows($deletion));
        $this->makeReadOnly($this->album);
        $result = $this->files->deleteFiles($claim, $deletion);
        $this->files->release($claim);

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
        $deletion = $this->files->prepareDeletion($this->files->claim($library->getId()), [$first]);

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

        $this->files->claim(new Uuid());
    }

    /**
     * Runs a delete with files the way the catalog delete handlers do.
     *
     * @param list<string> $paths
     */
    private function deleteWithFiles(Library $library, array $paths): LibraryMediaFileDeletionResult
    {
        $claim = $this->files->claim($library->getId());
        try {
            $deletion = $this->files->prepareDeletion($claim, $paths);
            $this->inCallerTransaction(fn () => $this->files->deleteIndexRows($deletion));

            return $this->files->deleteFiles($claim, $deletion);
        } finally {
            $this->files->release($claim);
        }
    }

    /** @param callable(): LibraryMediaFileClaim $claim */
    private function expectBusy(callable $claim, string $holder): void
    {
        try {
            $claim();
            self::fail('A library another holder claimed must refuse the delete.');
        } catch (LibraryBusyException $refusal) {
            self::assertInstanceOf(ConflictException::class, $refusal);
            self::assertSame(['reason' => 'library_busy', 'holder' => $holder], $refusal->details);
        }
    }

    /** @return array{scan_status: ?string, last_scan: ?string, claim_id: ?string, claim_kind: ?string} */
    private function claimRow(Library $library): array
    {
        $row = $this->manager->getConnection()->fetchAssociative(
            'SELECT scan_status, last_scan, claim_id, claim_kind FROM libraries WHERE id = ?',
            [$library->getId()->toString()],
        );
        self::assertIsArray($row);

        /** @var array{scan_status: ?string, last_scan: ?string, claim_id: ?string, claim_kind: ?string} $row */
        return $row;
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
