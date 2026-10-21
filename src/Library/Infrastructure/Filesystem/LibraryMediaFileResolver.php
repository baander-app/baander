<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Filesystem;

use App\Library\Application\Port\LibraryMediaFileResolverInterface;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Filesystem\StoragePathBoundary;

final readonly class LibraryMediaFileResolver implements LibraryMediaFileResolverInterface
{
    public function __construct(private LibraryRepositoryInterface $libraries)
    {
    }

    public function resolve(Uuid $libraryId, string $absolutePath): string
    {
        if (!str_starts_with($absolutePath, '/')) {
            throw new \InvalidArgumentException('Catalog media paths must be absolute.');
        }

        $library = $this->libraries->findByUuid($libraryId);
        if ($library === null) {
            throw new \InvalidArgumentException('Media library not found.');
        }

        $path = StoragePathBoundary::resolve($library->getPath()->toString(), $absolutePath);
        if (!is_file($path)) {
            throw new \InvalidArgumentException('Media source file not found.');
        }

        return $path;
    }
}
