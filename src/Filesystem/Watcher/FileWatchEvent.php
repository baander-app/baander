<?php

declare(strict_types=1);

namespace App\Filesystem\Watcher;

/**
 * Represents a file system change event from the watcher.
 */
final readonly class FileWatchEvent
{
    public function __construct(
        public int $watchDescriptor,
        public string $path,
        public string $fullPath,
        public int $type,
        public bool $isDirectory,
    ) {
    }

    public function isCreate(): bool
    {
        return ($this->type & IN_CREATE) !== 0;
    }

    public function isDelete(): bool
    {
        return ($this->type & IN_DELETE) !== 0;
    }

    public function isModify(): bool
    {
        return ($this->type & IN_MODIFY) !== 0;
    }

    public function isMove(): bool
    {
        return ($this->type & IN_MOVED_FROM) !== 0 || ($this->type & IN_MOVED_TO) !== 0;
    }

    public function isCloseWrite(): bool
    {
        return ($this->type & IN_CLOSE_WRITE) !== 0;
    }
}
