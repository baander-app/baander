<?php

declare(strict_types=1);

namespace App\Filesystem\Application\Port;

interface ReadOnlyFilesystemPortInterface
{
    /**
     * Resolve a path to a full local filesystem path.
     *
     * Absolute paths are returned as-is.
     * Relative paths are resolved against the configured base path.
     *
     * @throws \InvalidArgumentException if the resolved path escapes the base path
     */
    public function resolve(string $path): string;

    /**
     * Check whether a file exists at the given path.
     */
    public function exists(string $path): bool;

    /**
     * Get the file size in bytes.
     */
    public function size(string $path): int|false;

    /**
     * Read an entire file's contents (coroutine-aware).
     *
     * Uses Swoole\Coroutine\System::readFile — non-blocking in coroutine context.
     */
    public function read(string $path): string|false;

    /**
     * Open a file handle for read-only streaming (coroutine-aware).
     *
     * Only read modes are accepted ('r', 'rb'). Any write/append mode throws.
     *
     * @throws \InvalidArgumentException if a write mode is provided
     */
    public function open(string $path, string $mode = 'r'): FileHandle;

    /**
     * List files and directories at the given path.
     *
     * @return string[]|false Array of filenames, or false on failure.
     */
    public function list(string $path): array|false;

    /**
     * Check whether the path is a directory.
     */
    public function isDirectory(string $path): bool;

    /**
     * Check whether the path is a regular file.
     */
    public function isFile(string $path): bool;

    /**
     * Get the last modification time as a Unix timestamp.
     */
    public function lastModified(string $path): int|false;
}
