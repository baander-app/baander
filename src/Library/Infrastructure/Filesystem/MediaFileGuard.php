<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Filesystem;

use App\Library\Application\Port\LibraryMediaFileCheck;
use App\Library\Application\Port\LibraryMediaFileDeletionResult;
use App\Library\Application\Port\LibraryMediaFileInspection;
use App\Library\Application\Port\LibraryMediaFileLeft;
use App\Library\Application\Port\LibraryMediaFileLeftReason;
use App\Library\Application\Port\LibraryMediaFileVerdict;
use App\Shared\Domain\Model\Uuid;
use LogicException;

/**
 * The file system rules of media file deletion. A path is inside the library when the directory
 * entry it names lies under the root's real path once every symlink in its parent directories is
 * resolved, and, when that entry is a symlink, its target does too. Unlinking removes the entry,
 * so a symlink goes and its target stays.
 *
 * PHP has no unlinkat(), so a parent directory swapped for a symlink between the last check and
 * the unlink is not caught; the last check runs immediately before each unlink to keep that
 * window short.
 */
final class MediaFileGuard
{
    /** @param list<string> $paths */
    public function inspect(Uuid $libraryId, string $libraryPath, array $paths, bool $scanInProgress): LibraryMediaFileInspection
    {
        // PHP caches resolved paths; a symlink changed since then must count.
        clearstatcache(true);
        $root = $this->directory($libraryPath) ?? $libraryPath;

        $files = [];
        foreach (array_values(array_unique($paths)) as $path) {
            $files[] = $this->check($root, $path);
        }

        return new LibraryMediaFileInspection($libraryId, $root, $files, $scanInProgress);
    }

    /**
     * Unlinks every file the inspection found deletable, checking each again first. Run it after
     * the commit that deleted the files' catalog and index rows.
     *
     * @throws LogicException when the inspection refuses the deletion
     */
    public function delete(LibraryMediaFileInspection $deletion): LibraryMediaFileDeletionResult
    {
        if (!$deletion->allowsDeletion()) {
            throw new LogicException('Only an inspection that allows the deletion can delete files.');
        }

        $removed = [];
        $missing = [];
        $left = [];
        foreach ($deletion->files as $file) {
            if ($file->verdict === LibraryMediaFileVerdict::Missing) {
                $missing[] = $file->path;
                continue;
            }

            clearstatcache(true);
            $verdict = $this->check($deletion->root, $file->path)->verdict;
            if ($verdict === LibraryMediaFileVerdict::Missing) {
                $missing[] = $file->path;
                continue;
            }
            $entry = $this->entry($file->path);
            if ($verdict === LibraryMediaFileVerdict::OutsideRoot || $entry === null) {
                $left[] = new LibraryMediaFileLeft($file->path, LibraryMediaFileLeftReason::OutsideRoot, 'The file now resolves outside the library root.');
                continue;
            }

            error_clear_last();
            if (@unlink($entry)) {
                $removed[] = $file->path;
            } else {
                $left[] = new LibraryMediaFileLeft($file->path, LibraryMediaFileLeftReason::UnlinkFailed, error_get_last()['message'] ?? 'The file could not be unlinked.');
            }
        }

        return new LibraryMediaFileDeletionResult($removed, $missing, $left);
    }

    private function check(string $root, string $path): LibraryMediaFileCheck
    {
        $entry = $this->entry($path);
        if ($entry === null || !self::isInside($root, $entry)) {
            return new LibraryMediaFileCheck($path, LibraryMediaFileVerdict::OutsideRoot);
        }

        // A symlink pointing to nothing counts as missing: there is no audio file left to delete.
        if (!file_exists($entry)) {
            return new LibraryMediaFileCheck($path, LibraryMediaFileVerdict::Missing);
        }

        $target = realpath($entry);
        if ($target === false || !self::isInside($root, $target)) {
            return new LibraryMediaFileCheck($path, LibraryMediaFileVerdict::OutsideRoot);
        }

        $directory = dirname($entry);
        if (!is_writable($directory)) {
            return new LibraryMediaFileCheck($path, LibraryMediaFileVerdict::DirectoryNotWritable, $directory);
        }

        return new LibraryMediaFileCheck($path, LibraryMediaFileVerdict::Deletable);
    }

    /**
     * Where the directory entry $path names lies: its parent directories resolved, its own name
     * kept. Null for a path that names no file entry.
     */
    private function entry(string $path): ?string
    {
        if (!str_starts_with($path, '/') || str_ends_with($path, '/') || str_contains($path, "\0")) {
            return null;
        }

        $name = basename($path);
        if ($name === '.' || $name === '..') {
            return null;
        }

        $directory = $this->directory(dirname($path));

        return $directory === null ? null : self::join($directory, $name);
    }

    /**
     * The real path of a directory. A part that does not exist cannot be a symlink, so it is
     * resolved by name; such a path names no existing file anyway.
     */
    private function directory(string $path): ?string
    {
        $real = realpath($path);
        if ($real !== false) {
            return $real;
        }

        $parent = dirname($path);
        if ($parent === $path) {
            return null;
        }
        $resolvedParent = $this->directory($parent);
        if ($resolvedParent === null) {
            return null;
        }

        return match ($name = basename($path)) {
            '', '.' => $resolvedParent,
            '..' => dirname($resolvedParent),
            default => self::join($resolvedParent, $name),
        };
    }

    private static function isInside(string $root, string $path): bool
    {
        return str_starts_with($path, rtrim($root, '/') . '/');
    }

    private static function join(string $directory, string $name): string
    {
        return rtrim($directory, '/') . '/' . $name;
    }
}
