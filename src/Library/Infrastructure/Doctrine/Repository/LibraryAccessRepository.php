<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Doctrine\Repository;

use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Infrastructure\Doctrine\Entity\UserLibraryAccessEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final class LibraryAccessRepository implements LibraryAccessPortInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function grant(Uuid $userId, Uuid $libraryId): void
    {
        $existing = $this->findEntity($userId, $libraryId);
        if ($existing !== null) {
            return; // Idempotent
        }

        $entity = new UserLibraryAccessEntity(
            userId: $userId,
            libraryId: $libraryId,
            grantedAt: new \DateTimeImmutable(),
        );

        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function revoke(Uuid $userId, Uuid $libraryId): void
    {
        $existing = $this->findEntity($userId, $libraryId);
        if ($existing === null) {
            return; // Idempotent
        }

        $this->entityManager->remove($existing);
        $this->entityManager->flush();
    }

    public function getUserLibraryIds(Uuid $userId): array
    {
        $results = $this->entityManager
            ->getRepository(UserLibraryAccessEntity::class)
            ->findBy(['userId' => $userId]);

        return array_map(
            static fn(UserLibraryAccessEntity $e) => $e->getLibraryId()->toString(),
            $results,
        );
    }

    public function hasAccess(Uuid $userId, Uuid $libraryId): bool
    {
        return $this->findEntity($userId, $libraryId) !== null;
    }

    private function findEntity(Uuid $userId, Uuid $libraryId): ?UserLibraryAccessEntity
    {
        return $this->entityManager
            ->getRepository(UserLibraryAccessEntity::class)
            ->findOneBy(['userId' => $userId, 'libraryId' => $libraryId]);
    }
}
