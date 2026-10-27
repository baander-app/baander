<?php

declare(strict_types=1);

namespace Baander\Tooling\Filesystem;

final class FileLock
{
    /** @param resource|null $stream */
    private function __construct(private $stream) {}

    #[\NoDiscard]
    public static function acquire(string $path, bool $nonBlocking = true): self
    {
        PrivateDirectory::assertNoSymlinks($path);
        PrivateDirectory::ensure(dirname($path));
        $previousMask = umask(0077);
        try {
            $stream = @fopen($path, 'x+b');
        } finally {
            umask($previousMask);
        }
        if ($stream === false) {
            PrivateDirectory::assertNoSymlinks($path);
            $stream = @fopen($path, 'r+b');
        }
        if ($stream === false) {
            throw new \RuntimeException("Cannot open operation lock: {$path}");
        }
        try {
            clearstatcache(true, $path);
            $info = @lstat($path);
            $opened = fstat($stream);
            if ($info === false || $opened === false || ($info['mode'] & 0170000) !== 0100000
                || $info['dev'] !== $opened['dev'] || $info['ino'] !== $opened['ino']) {
                throw new \RuntimeException("Operation lock changed while opening: {$path}");
            }
            if (!flock($stream, LOCK_EX | ($nonBlocking ? LOCK_NB : 0))) {
                throw new \RuntimeException('Wait for another operation on this instance to finish.');
            }

            return new self($stream);
        } catch (\Throwable $error) {
            fclose($stream);
            throw $error;
        }
    }

    public function release(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
