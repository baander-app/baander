<?php

declare(strict_types=1);

namespace App\Filesystem\Infrastructure\Service;

use App\Filesystem\Application\Port\ReadOnlyFilesystemPortInterface;
use App\Shared\Domain\ValueObject\FilesystemType;
use InvalidArgumentException;

final class ReadOnlyFilesystemFactory
{
    /**
     * Create a read-only filesystem scoped to the given base path.
     *
     * @throws InvalidArgumentException if the filesystem type is not supported
     */
    public function create(FilesystemType $type, string $basePath): ReadOnlyFilesystemPortInterface
    {
        return match ($type) {
            FilesystemType::Local => new ReadOnlyFilesystem(new LocalFilesystem($basePath), $basePath),
        };
    }
}
