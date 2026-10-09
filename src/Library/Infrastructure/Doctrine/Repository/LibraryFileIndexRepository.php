<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Doctrine\Repository;

use App\Library\Domain\Repository\LibraryFileIndexRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes the file index with single statements, outside the unit of work: each write is stored
 * when it returns, with no flush that a scan must remember. Concurrent upserts of one path
 * resolve on the (library_id, path) unique index instead of inserting it twice.
 */
final class LibraryFileIndexRepository implements LibraryFileIndexRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function findIndexPathMapByLibrary(Uuid $libraryId): array
    {
        return $this->entityManager->getConnection()->executeQuery(
            'SELECT path, hash FROM library_file_index WHERE library_id = :libraryId',
            ['libraryId' => $libraryId->toString()],
        )->fetchAllKeyValue();
    }

    public function upsert(Uuid $libraryId, string $path, string $hash, int $size, string $extension, int $modifiedAt): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO library_file_index (id, library_id, path, hash, size, extension, modified_at, discovered_at)
             VALUES (:id, :library_id, :path, :hash, :size, :extension, :modified_at, now())
             ON CONFLICT (library_id, path) DO UPDATE
             SET hash = EXCLUDED.hash, size = EXCLUDED.size, modified_at = EXCLUDED.modified_at, discovered_at = EXCLUDED.discovered_at',
            [
                'id' => (new Uuid())->toString(),
                'library_id' => $libraryId->toString(),
                'path' => $path,
                'hash' => $hash,
                'size' => $size,
                'extension' => $extension,
                'modified_at' => $modifiedAt,
            ],
        );
    }

    public function removeByPath(Uuid $libraryId, string $path): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM library_file_index WHERE library_id = :library_id AND path = :path',
            ['library_id' => $libraryId->toString(), 'path' => $path],
        );
    }
}
