<?php

declare(strict_types=1);

namespace App\Filesystem\Application\Port;

use Swoole\Coroutine\System;

/**
 * Coroutine-aware file handle for streaming reads and writes.
 *
 * Wraps a fopen resource with Swoole\Coroutine\System::fread, fwrite, and fgets.
 * Must be closed when done — use close() or let GC handle it via __destruct.
 */
final class FileHandle
{
    private bool $closed = false;

    public function __construct(
        private readonly mixed $resource,
        private readonly string $path,
    )
    {
    }

    /**
     * Read from the file handle (coroutine-aware).
     *
     * @param int $length Bytes to read. 0 = read entire remaining content.
     */
    public function read(int $length = 0): string|false
    {
        if ($this->closed) {
            return false;
        }

        return System::fread($this->resource, $length);
    }

    /**
     * Write data to the file handle (coroutine-aware).
     *
     * @param int $length Max bytes to write. 0 = write all of $data.
     * @return int|false Bytes written, or false on failure.
     */
    public function write(string $data, int $length = 0): int|false
    {
        if ($this->closed) {
            return false;
        }

        return System::fwrite($this->resource, $data, $length);
    }

    /**
     * Read a single line from the file handle (coroutine-aware).
     *
     * Returns the line including EOL. Empty string at EOF — use eof() to distinguish.
     */
    public function readLine(): string|false
    {
        if ($this->closed) {
            return false;
        }

        return System::fgets($this->resource);
    }

    /**
     * Whether the end of file has been reached.
     */
    public function eof(): bool
    {
        return feof($this->resource);
    }

    /**
     * Get the underlying stream resource.
     */
    public function resource(): mixed
    {
        return $this->resource;
    }

    /**
     * Get the resolved file path.
     */
    public function path(): string
    {
        return $this->path;
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Close the file handle.
     */
    public function close(): void
    {
        if (!$this->closed) {
            fclose($this->resource);
            $this->closed = true;
        }
    }
}
