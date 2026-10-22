<?php

declare(strict_types=1);

namespace App\Library\Application;

use App\Library\Application\Exception\LibraryScanClaimLostException;
use App\Library\Application\Port\DirectoryScannerPortInterface;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\Service\LibraryScanLease;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryFileIndexRepositoryInterface;
use App\Library\Infrastructure\Scanner\MediaFile;
use App\Shared\Application\JobCancelledException;
use App\Shared\Application\Port\JobCancellationCheckpointInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use Closure;
use Psr\Log\LoggerInterface;

final class MovieScanner
{
    public function __construct(
        private readonly DirectoryScannerPortInterface $directoryScanner,
        private readonly LibraryFileIndexRepositoryInterface $fileIndexRepository,
        private readonly LoggerInterface $logger,
        private readonly JobCancellationCheckpointInterface $cancellation,
        private readonly TransactionPortInterface $transaction,
    ) {
    }

    /**
     * Hashes the library's video files one directory (movie) at a time. A directory with new
     * or changed files commits its index updates and the callback's durable event capture
     * together. The callback must use the same transaction and must not send to an external
     * transport. Capture failure rolls back the directory so a later scan can retry it.
     * A cancelled scan stops before its next directory. Lease renewal retains its existing
     * cadence; each write transaction checks ownership before changing the index or outbox.
     *
     * @param (Closure(string, array<DiscoveredFile>): void)|null $publishDirectory
     *
     * @throws JobCancelledException         when the job running the scan was cancelled
     * @throws LibraryScanClaimLostException when another scan took the claim over
     */
    public function scan(Library $library, bool $rescan = false, ?Closure $publishDirectory = null, ?LibraryScanLease $lease = null): ScanResult
    {
        $filesProcessed = 0;
        $filesSkipped = 0;

        $mediaFiles = $this->directoryScanner->scan($library->getPath());
        $lease?->renew();
        $videoFiles = array_filter($mediaFiles, fn (MediaFile $f) => $f->isVideo());
        $filesDiscovered = count($videoFiles);

        $this->logger->info('Starting movie library scan', [
            'library' => $library->getSlug()->toString(),
            'discovered' => $filesDiscovered,
        ]);

        // Full rescans still reconcile every previously indexed path.
        $indexedPaths = $this->fileIndexRepository->findIndexedPathsByLibrary($library->getId());
        $indexMap = $rescan
            ? []
            : $this->fileIndexRepository->findAvailableIndexPathMapByLibrary($library->getId());

        // Group files by parent directory (movie grouping)
        $filesByDirectory = [];
        foreach ($videoFiles as $file) {
            $filesByDirectory[dirname($file->getAbsolutePath())][] = $file;
        }

        $directories = [];
        $seenPaths = [];
        foreach ($filesByDirectory as $dir => $files) {
            // Hashing reads every file, so a cancelled job stops before its next directory.
            $this->cancellation->check();
            $lease?->renew();

            $discoveredFiles = [];
            foreach ($files as $file) {
                $seenPaths[$file->getAbsolutePath()] = true;
                $hash = hash_file('xxh3', $file->getAbsolutePath());
                $lease?->renew();
                if ($hash === false) {
                    $this->logger->warning('Failed to hash file', ['path' => $file->getAbsolutePath()]);
                    $filesSkipped++;
                    continue;
                }
                $existingHash = $indexMap[$file->getAbsolutePath()] ?? null;
                if ($existingHash !== null && $existingHash === $hash && !$rescan) {
                    $filesSkipped++;
                    continue;
                }

                $discoveredFiles[] = new DiscoveredFile(
                    absolutePath: $file->getAbsolutePath(),
                    relativePath: $file->getRelativePath(),
                    extension: $file->getExtension(),
                    size: $file->getSize(),
                    modifiedAt: $file->getModifiedAt(),
                    hash: $hash,
                );
            }

            if ($discoveredFiles === []) {
                continue;
            }

            $this->transaction->run(function () use ($library, $dir, $discoveredFiles, $publishDirectory, $lease): void {
                $lease?->guardWrite();
                $orderedFiles = $discoveredFiles;
                usort($orderedFiles, static fn (DiscoveredFile $a, DiscoveredFile $b): int => strcmp($a->absolutePath, $b->absolutePath));
                foreach ($orderedFiles as $discoveredFile) {
                    $this->fileIndexRepository->upsert(
                        $library->getId(),
                        $discoveredFile->absolutePath,
                        $discoveredFile->hash,
                        $discoveredFile->size,
                        $discoveredFile->extension,
                        $discoveredFile->modifiedAt,
                    );
                }
                if ($publishDirectory !== null) {
                    $publishDirectory((string) $dir, $discoveredFiles);
                }
            });
            $directories[$dir] = $discoveredFiles;

            $filesProcessed += count($discoveredFiles);
        }

        // A complete scan records absence without discarding the previous observations.
        $this->cancellation->check();
        $missingPaths = array_values(array_filter($indexedPaths, static fn (string $path): bool => !isset($seenPaths[$path])));
        foreach (array_chunk($missingPaths, 1000) as $paths) {
            $this->cancellation->check();
            $this->transaction->run(function () use ($library, $paths, $lease): void {
                $lease?->guardWrite();
                $this->fileIndexRepository->markUnavailableByPaths($library->getId(), $paths);
            });
        }

        return new ScanResult(
            filesDiscovered: $filesDiscovered,
            filesProcessed: $filesProcessed,
            filesSkipped: $filesSkipped,
            directories: $directories,
        );
    }
}
