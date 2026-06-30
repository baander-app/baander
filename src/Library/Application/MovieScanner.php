<?php

declare(strict_types=1);

namespace App\Library\Application;

use App\Library\Application\Port\DirectoryScannerPortInterface;
use App\Library\Domain\Model\DiscoveredFile;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryFileIndexRepositoryInterface;
use App\Library\Infrastructure\Scanner\MediaFile;
use Psr\Log\LoggerInterface;

final class MovieScanner
{
    public function __construct(
        private readonly DirectoryScannerPortInterface $directoryScanner,
        private readonly LibraryFileIndexRepositoryInterface $fileIndexRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function scan(Library $library, bool $rescan = false): ScanResult
    {
        $filesDiscovered = 0;
        $filesProcessed = 0;
        $filesSkipped = 0;

        $mediaFiles = $this->directoryScanner->scan($library->getPath());
        $videoFiles = array_filter($mediaFiles, fn (MediaFile $f) => $f->isVideo());
        $filesDiscovered = count($videoFiles);

        $this->logger->info('Starting movie library scan', [
            'library' => $library->getSlug()->toString(),
            'discovered' => $filesDiscovered,
        ]);

        // Load existing index for diff
        $indexMap = $rescan
            ? []
            : $this->fileIndexRepository->findIndexPathMapByLibrary($library->getId());

        // Group files by parent directory (movie grouping)
        $directories = [];
        $seenPaths = [];
        foreach ($videoFiles as $file) {
            $dir = dirname($file->getAbsolutePath());
            $hash = hash_file('xxh3', $file->getAbsolutePath());
            if ($hash === false) {
                $this->logger->warning('Failed to hash file', ['path' => $file->getAbsolutePath()]);
                $filesSkipped++;
                continue;
            }
            $seenPaths[$file->getAbsolutePath()] = true;

            $existingHash = $indexMap[$file->getAbsolutePath()] ?? null;
            if ($existingHash !== null && $existingHash === $hash && !$rescan) {
                $filesSkipped++;
                continue;
            }

            $discoveredFile = new DiscoveredFile(
                absolutePath: $file->getAbsolutePath(),
                relativePath: $file->getRelativePath(),
                extension: $file->getExtension(),
                size: $file->getSize(),
                modifiedAt: $file->getModifiedAt(),
                hash: $hash,
            );

            if (!isset($directories[$dir])) {
                $directories[$dir] = [];
            }
            $directories[$dir][] = $discoveredFile;

            $this->fileIndexRepository->upsert(
                $library->getId(),
                $file->getAbsolutePath(),
                $hash,
                $file->getSize(),
                $file->getExtension(),
                $file->getModifiedAt(),
            );

            $filesProcessed++;
        }

        // Remove stale entries
        foreach (array_keys($indexMap) as $indexedPath) {
            if (!isset($seenPaths[$indexedPath])) {
                $this->fileIndexRepository->removeByPath($library->getId(), $indexedPath);
            }
        }

        return new ScanResult(
            filesDiscovered: $filesDiscovered,
            filesProcessed: $filesProcessed,
            filesSkipped: $filesSkipped,
            directories: $directories,
        );
    }
}
