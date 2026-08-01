<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Doctrine\Repository;

use App\Library\Domain\Repository\LibraryFileIndexRepositoryInterface;
use App\Library\Infrastructure\Doctrine\Entity\LibraryFileIndexEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

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
        $existing = $this->entityManager->getRepository(LibraryFileIndexEntity::class)
            ->findOneBy(['libraryId' => $libraryId, 'path' => $path]);

        if ($existing !== null) {
            $existing->setHash($hash);
            $existing->setSize($size);
            $existing->setModifiedAt($modifiedAt);
            $existing->setDiscoveredAt(new \DateTimeImmutable());
        } else {
            $existing = new LibraryFileIndexEntity(
                id: new Uuid(),
                libraryId: $libraryId,
                path: $path,
                hash: $hash,
                size: $size,
                extension: $extension,
                modifiedAt: $modifiedAt,
                discoveredAt: new \DateTimeImmutable(),
            );
        }

        $this->entityManager->persist($existing);
    }

    public function removeByPath(Uuid $libraryId, string $path): void
    {
        $existing = $this->entityManager->getRepository(LibraryFileIndexEntity::class)
            ->findOneBy(['libraryId' => $libraryId, 'path' => $path]);

        if ($existing !== null) {
            $this->entityManager->remove($existing);
        }
    }

    public function removeAllForLibrary(Uuid $libraryId): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM library_file_index WHERE library_id = :libraryId',
            ['libraryId' => $libraryId->toString()],
        );
    }
}
