<?php

declare(strict_types=1);

namespace App\Filesystem\Application\Port;

interface LocalFilesystemPortInterface
{
    /**
     * Resolve a path to a full local filesystem path.
     *
     * Absolute paths are returned as-is.
     * Relative paths are resolved against the configured base path.
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
     * No size limit, but the entire content is held in memory.
     */
    public function read(string $path): string|false;

    /**
     * Write contents to a file (coroutine-aware).
     *
     * Uses Swoole\Coroutine\System::writeFile — non-blocking in coroutine context.
     * Max 4MB per call. Clears existing content by default; use FILE_APPEND to append.
     * Parent directories are created automatically.
     */
    public function write(string $path, string $content, int $flags = 0): bool;

    /**
     * Open a file handle for coroutine-aware streaming operations.
     *
     * Returns a FileHandle that wraps fopen with Swoole\Coroutine\System::fread,
     * fwrite, and fgets for non-blocking I/O in coroutine context.
     */
    public function open(string $path, string $mode): FileHandle;
}
