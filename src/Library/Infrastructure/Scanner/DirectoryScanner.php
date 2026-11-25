<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Scanner;

use App\Library\Application\Port\DirectoryScannerPortInterface;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Shared\Domain\ValueObject\MediaContainer;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class DirectoryScanner implements DirectoryScannerPortInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * Recursively scan a library path for media files. A file symlink is followed only when its
     * target stays inside the library; directory symlinks are not descended.
     *
     * @return MediaFile[]
     */
    public function scan(LibraryPath $path): array
    {
        // A long-running worker must not judge a retargeted link by PHP's cached real path.
        clearstatcache(true);
        $realPath = realpath($path->toString());

        if ($realPath === false || !is_dir($realPath)) {
            return [];
        }

        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($realPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $extension = strtolower($file->getExtension());

            if (MediaContainer::forExtension($extension) === null) {
                continue;
            }

            if (!self::contained($file, $realPath)) {
                // The index row of a file imported earlier through this link is then marked
                // unavailable by the scan, as for any indexed path the scan no longer finds.
                $this->logger->warning('Skipping a file whose real path leaves the library: {path}', [
                    'path' => $file->getPathname(),
                ]);
                continue;
            }

            $relativePath = substr($file->getPathname(), strlen($realPath) + 1);

            $files[] = new MediaFile(
                $file->getPathname(),
                $relativePath,
                $extension,
                $file->getSize(),
                $file->getMTime(),
            );
        }

        return $files;
    }

    private static function contained(SplFileInfo $file, string $root): bool
    {
        $target = $file->getRealPath();

        return $target !== false && str_starts_with($target, $root . '/');
    }
}
