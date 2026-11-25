<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Filesystem;

use App\Library\Application\Port\LibraryMediaFileResolverInterface;
use App\Library\Domain\Model\Library;
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
        self::assertAbsolute($absolutePath);

        $library = $this->libraries->findByUuid($libraryId);
        if ($library === null) {
            throw new \InvalidArgumentException('Media library not found.');
        }

        $path = self::resolveIn($library, $absolutePath);
        if (!is_file($path)) {
            throw new \InvalidArgumentException('Media source file not found.');
        }

        return $path;
    }

    public function resolveInAnyLibrary(string $absolutePath): string
    {
        self::assertAbsolute($absolutePath);

        foreach ($this->libraries->findAllOrdered() as $library) {
            try {
                $path = self::resolveIn($library, $absolutePath);
            } catch (\InvalidArgumentException) {
                continue;
            }
            if (is_file($path)) {
                return $path;
            }
        }

        throw new \InvalidArgumentException('Media file not found in any library.');
    }

    /**
     * The boundary would resolve a relative path against the working directory, and a URL is
     * relative to it, so neither reaches a filesystem call.
     */
    private static function assertAbsolute(string $path): void
    {
        if (!str_starts_with($path, '/')) {
            throw new \InvalidArgumentException('Catalog media paths must be absolute.');
        }
    }

    private static function resolveIn(Library $library, string $absolutePath): string
    {
        return StoragePathBoundary::resolve($library->getPath()->toString(), $absolutePath);
    }
}
