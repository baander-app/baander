<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Doctrine\Repository;

use App\Library\Domain\Repository\LibraryFileIndexRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

/**
 * Writes the file index outside the unit of work, without requiring a flush. Bulk mutations
 * lock existing target rows in path order and hold them through the mutation transaction.
 * Concurrent upserts of one path resolve on the (library_id, path) unique index.
 */
final class LibraryFileIndexRepository implements LibraryFileIndexRepositoryInterface
{
    /** Paths per statement, well below PostgreSQL's 65535 bind parameters. */
    private const int PATHS_PER_STATEMENT = 1000;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function findAvailableIndexPathMapByLibrary(Uuid $libraryId): array
    {
        return $this->entityManager->getConnection()->executeQuery(
            'SELECT path, hash FROM library_file_index WHERE library_id = :libraryId AND available = true',
            ['libraryId' => $libraryId->toString()],
        )->fetchAllKeyValue();
    }

    public function findIndexedPathsByLibrary(Uuid $libraryId): array
    {
        return $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT path FROM library_file_index WHERE library_id = :library_id',
            ['library_id' => $libraryId->toString()],
        );
    }

    public function findAvailabilityByPaths(Uuid $libraryId, array $paths): array
    {
        $availability = array_fill_keys($paths, false);
        $connection = $this->entityManager->getConnection();
        foreach (array_chunk(array_keys($availability), self::PATHS_PER_STATEMENT) as $chunk) {
            $rows = $connection->executeQuery(
                'SELECT path, available FROM library_file_index WHERE library_id = :library_id AND path IN (:paths)',
                ['library_id' => $libraryId->toString(), 'paths' => $chunk],
                ['paths' => ArrayParameterType::STRING],
            )->fetchAllKeyValue();
            foreach ($rows as $path => $available) {
                $availability[$path] = (bool) $connection->convertToPHPValue($available, 'boolean');
            }
        }

        return $availability;
    }

    public function findAvailableHashesByPaths(Uuid $libraryId, array $paths): array
    {
        return $this->availableHashes($libraryId, $paths, false);
    }

    public function lockAvailableHashesByPathsForImport(Uuid $libraryId, array $paths): array
    {
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            throw new LogicException('Import observation locks require an active transaction.');
        }

        return $this->availableHashes($libraryId, $paths, true);
    }

    /** @param list<string> $paths
     * @return array<string, string>
     */
    private function availableHashes(Uuid $libraryId, array $paths, bool $lock): array
    {
        $hashes = [];
        $connection = $this->entityManager->getConnection();
        foreach (array_chunk($this->orderedPaths($paths), self::PATHS_PER_STATEMENT) as $chunk) {
            $hashes += $connection->executeQuery(
                'SELECT path, hash FROM library_file_index WHERE library_id = :library_id AND path IN (:paths) AND available = true ORDER BY path COLLATE "C"' . ($lock ? ' FOR SHARE' : ''),
                ['library_id' => $libraryId->toString(), 'paths' => $chunk],
                ['paths' => ArrayParameterType::STRING],
            )->fetchAllKeyValue();
        }

        return $hashes;
    }

    public function markUnavailableByPaths(Uuid $libraryId, array $paths): void
    {
        $this->mutateExistingPaths($libraryId, $paths, 'UPDATE library_file_index SET available = false WHERE library_id = :library_id AND path IN (:paths) AND available = true');
    }

    public function upsert(Uuid $libraryId, string $path, string $hash, int $size, string $extension, int $modifiedAt): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO library_file_index (id, library_id, path, hash, size, extension, modified_at, discovered_at, available)
             VALUES (:id, :library_id, :path, :hash, :size, :extension, :modified_at, now(), true)
             ON CONFLICT (library_id, path) DO UPDATE
             SET hash = EXCLUDED.hash, size = EXCLUDED.size, modified_at = EXCLUDED.modified_at, discovered_at = EXCLUDED.discovered_at, available = true',
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

    public function removeByPaths(Uuid $libraryId, array $paths): void
    {
        $this->mutateExistingPaths($libraryId, $paths, 'DELETE FROM library_file_index WHERE library_id = :library_id AND path IN (:paths)');
    }

    /** @param list<string> $paths */
    private function mutateExistingPaths(Uuid $libraryId, array $paths, string $sql): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->transactional(function () use ($connection, $libraryId, $paths, $sql): void {
            $locked = [];
            foreach (array_chunk($this->orderedPaths($paths), self::PATHS_PER_STATEMENT) as $chunk) {
                array_push($locked, ...$connection->fetchFirstColumn(
                    'SELECT path FROM library_file_index WHERE library_id = :library_id AND path IN (:paths) ORDER BY path COLLATE "C" FOR NO KEY UPDATE',
                    ['library_id' => $libraryId->toString(), 'paths' => $chunk],
                    ['paths' => ArrayParameterType::STRING],
                ));
            }
            foreach (array_chunk($locked, self::PATHS_PER_STATEMENT) as $chunk) {
                $connection->executeStatement($sql,
                    ['library_id' => $libraryId->toString(), 'paths' => $chunk],
                    ['paths' => ArrayParameterType::STRING],
                );
            }
        });
    }

    /** @param list<string> $paths
     * @return list<string>
     */
    private function orderedPaths(array $paths): array
    {
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);

        return $paths;
    }
}
