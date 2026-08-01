<?php

declare(strict_types=1);

namespace App\Library\Domain\Repository;

use App\Shared\Domain\Model\Uuid;

interface LibraryFileIndexRepositoryInterface
{
    /**
     * @return array<string, string> path => hash map for all indexed files in library
     */
    public function findIndexPathMapByLibrary(Uuid $libraryId): array;

    /**
     * Upsert a file index entry.
     */
    public function upsert(Uuid $libraryId, string $path, string $hash, int $size, string $extension, int $modifiedAt): void;

    /**
     * Remove a file index entry by path.
     */
    public function removeByPath(Uuid $libraryId, string $path): void;

    /**
     * Remove all entries for a library.
     */
    public function removeAllForLibrary(Uuid $libraryId): void;
}
