<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Scanner;

use App\Library\Application\Port\DirectoryScannerPortInterface;
use App\Library\Domain\ValueObject\LibraryPath;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class DirectoryScanner implements DirectoryScannerPortInterface
{
    /** Audio file extensions to discover. */
    private const array AUDIO_EXTENSIONS = ['mp3', 'flac', 'ogg', 'wav', 'aac', 'm4a', 'wma', 'opus', 'wma'];

    /** Video file extensions to discover. */
    private const array VIDEO_EXTENSIONS = ['mp4', 'mkv', 'avi', 'mov', 'wmv', 'webm', 'm4v'];

    /**
     * Recursively scan a library path for media files.
     *
     * @return MediaFile[]
     */
    public function scan(LibraryPath $path): array
    {
        $realPath = realpath($path->toString());

        if ($realPath === false || !is_dir($realPath)) {
            return [];
        }

        $files = [];
        $extensions = array_merge(self::AUDIO_EXTENSIONS, self::VIDEO_EXTENSIONS);

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

            if (!in_array($extension, $extensions, true)) {
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
}
