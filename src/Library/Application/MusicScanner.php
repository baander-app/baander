<?php

declare(strict_types=1);

namespace App\Library\Application;

use App\Library\Application\Port\DirectoryScannerPortInterface;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryFileIndexRepositoryInterface;
use App\Library\Infrastructure\Scanner\MediaFile;
use App\Shared\Application\JobCancelledException;
use App\Shared\Application\Port\JobCancellationCheckpointInterface;
use Closure;
use Psr\Log\LoggerInterface;

final class MusicScanner
{
    public function __construct(
        private readonly DirectoryScannerPortInterface $directoryScanner,
        private readonly LibraryFileIndexRepositoryInterface $fileIndexRepository,
        private readonly LoggerInterface $logger,
        private readonly JobCancellationCheckpointInterface $cancellation,
    ) {
    }

    /**
     * Hashes the library's audio files one directory (album) at a time. A directory with new
     * or changed files goes to $publishDirectory first and into the file index after, so a
     * directory whose publication failed is published again by the next scan. A cancelled
     * scan stops before its next directory and leaves the index of the remaining ones as it was.
     *
     * @param (Closure(string, array<DiscoveredFile>): void)|null $publishDirectory
     *
     * @throws JobCancelledException when the job running the scan was cancelled
     */
    public function scan(Library $library, bool $rescan = false, ?Closure $publishDirectory = null): ScanResult
    {
        $filesProcessed = 0;
        $filesSkipped = 0;

        $mediaFiles = $this->directoryScanner->scan($library->getPath());
        $audioFiles = array_filter($mediaFiles, fn (MediaFile $f) => $f->isAudio());
        $filesDiscovered = count($audioFiles);

        $this->logger->info('Starting library scan', [
            'library_id' => $library->getId()->toString(),
            'library_name' => $library->getName(),
            'path' => $library->getPath()->toString(),
            'total_files' => $filesDiscovered,
            'rescan' => $rescan,
        ]);

        // Load existing index for diff (empty on rescan = process all files)
        $indexMap = $rescan
            ? []
            : $this->fileIndexRepository->findIndexPathMapByLibrary($library->getId());

        // Group files by parent directory (album grouping)
        $filesByDirectory = [];
        foreach ($audioFiles as $file) {
            $filesByDirectory[dirname($file->getAbsolutePath())][] = $file;
        }

        $directories = [];
        $seenPaths = [];
        foreach ($filesByDirectory as $dir => $files) {
            // Hashing reads every file, so a cancelled job stops before its next directory.
            $this->cancellation->check();

            $discoveredFiles = [];
            foreach ($files as $file) {
                $hash = hash_file('xxh3', $file->getAbsolutePath());
                if ($hash === false) {
                    $this->logger->warning('Failed to hash file', ['path' => $file->getAbsolutePath()]);
                    $filesSkipped++;
                    continue;
                }
                $seenPaths[$file->getAbsolutePath()] = true;

                // Check if file is new or changed
                $existingHash = $indexMap[$file->getAbsolutePath()] ?? null;
                if ($existingHash !== null && $existingHash === $hash && !$rescan) {
                    // Unchanged file — skip
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

            if ($publishDirectory !== null) {
                $publishDirectory((string) $dir, $discoveredFiles);
            }
            $directories[$dir] = $discoveredFiles;

            // Upsert file index
            foreach ($discoveredFiles as $discoveredFile) {
                $this->fileIndexRepository->upsert(
                    $library->getId(),
                    $discoveredFile->absolutePath,
                    $discoveredFile->hash,
                    $discoveredFile->size,
                    $discoveredFile->extension,
                    $discoveredFile->modifiedAt,
                );
            }

            $filesProcessed += count($discoveredFiles);
        }

        // Remove stale entries (files no longer on disk)
        foreach (array_keys($indexMap) as $indexedPath) {
            if (!isset($seenPaths[$indexedPath])) {
                $this->fileIndexRepository->removeByPath($library->getId(), $indexedPath);
            }
        }

        $this->logger->info('Library scan completed', [
            'library_id' => $library->getId()->toString(),
            'library_name' => $library->getName(),
            'total_files' => $filesDiscovered,
            'files_processed' => $filesProcessed,
            'files_skipped' => $filesSkipped,
        ]);

        return new ScanResult(
            filesDiscovered: $filesDiscovered,
            filesProcessed: $filesProcessed,
            filesSkipped: $filesSkipped,
            directories: $directories,
        );
    }
}
