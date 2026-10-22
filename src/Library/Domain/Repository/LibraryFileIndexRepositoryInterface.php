<?php

declare(strict_types=1);

namespace App\Library\Domain\Repository;

use App\Shared\Domain\Model\Uuid;

interface LibraryFileIndexRepositoryInterface
{
    /**
     * @param list<string> $paths
     * @return array<string, string> Available path-to-hash observations; missing rows are omitted.
     */
    public function findAvailableHashesByPaths(Uuid $libraryId, array $paths): array;

    /**
     * Requires an active transaction and holds available observations through its end.
     *
     * @param list<string> $paths
     * @return array<string, string>
     */
    public function lockAvailableHashesByPathsForImport(Uuid $libraryId, array $paths): array;

    /**
     * @return array<string, string> path => hash map for available indexed files in the library
     */
    public function findAvailableIndexPathMapByLibrary(Uuid $libraryId): array;

    /** @return list<string> All known paths, including unavailable sources. */
    public function findIndexedPathsByLibrary(Uuid $libraryId): array;

    /**
     * Last observed availability; a path without an index row is false.
     *
     * @param list<string> $paths
     * @return array<string, bool> One entry for every distinct requested path.
     */
    public function findAvailabilityByPaths(Uuid $libraryId, array $paths): array;

    /**
     * Retain the indexed paths and their metadata, marking their sources unavailable.
     * Uses the caller's connection and participates in its transaction when one is open.
     *
     * @param list<string> $paths
     */
    public function markUnavailableByPaths(Uuid $libraryId, array $paths): void;

    /**
     * Upsert a file index entry and mark its source available.
     */
    public function upsert(Uuid $libraryId, string $path, string $hash, int $size, string $extension, int $modifiedAt): void;

    /**
     * Remove a file index entry by path.
     */
    public function removeByPath(Uuid $libraryId, string $path): void;

    /**
     * Remove the file index entries of these paths, on the caller's connection and in its
     * transaction when one is open.
     *
     * @param list<string> $paths
     */
    public function removeByPaths(Uuid $libraryId, array $paths): void;
}
