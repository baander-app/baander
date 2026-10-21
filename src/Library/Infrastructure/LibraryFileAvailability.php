<?php

declare(strict_types=1);

namespace App\Library\Infrastructure;

use App\Library\Application\Port\LibraryFileAvailabilityInterface;
use App\Library\Domain\Repository\LibraryFileIndexRepositoryInterface;
use App\Shared\Domain\Model\Uuid;

final readonly class LibraryFileAvailability implements LibraryFileAvailabilityInterface
{
    public function __construct(private LibraryFileIndexRepositoryInterface $fileIndex)
    {
    }

    public function findAvailabilityByPaths(Uuid $libraryId, array $paths): array
    {
        return $this->fileIndex->findAvailabilityByPaths($libraryId, $paths);
    }
}
