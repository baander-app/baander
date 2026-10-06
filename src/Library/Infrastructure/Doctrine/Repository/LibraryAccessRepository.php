<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Doctrine\Repository;

use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
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
            library: $this->entityManager->getReference(LibraryEntity::class, $libraryId),
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

        // Hydrated composite association identifiers are flattened to strings by
        // Doctrine. Bind the original UUID values instead of deleting via the UoW.
        $this->entityManager->wrapInTransaction(
            static function (EntityManagerInterface $em) use ($existing, $userId, $libraryId): void {
                $em->createQueryBuilder()
                    ->delete(UserLibraryAccessEntity::class, 'access')
                    ->where('access.userId = :userId')
                    ->andWhere('access.library = :libraryId')
                    ->setParameter('userId', $userId, 'uuid')
                    ->setParameter('libraryId', $libraryId, 'uuid')
                    ->getQuery()
                    ->execute();

                // This immutable association has no removal callbacks or cascades.
                // Remove its stale identity so the same membership can be regranted.
                $em->detach($existing);
            },
        );
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
            ->findOneBy(['userId' => $userId, 'library' => $libraryId]);
    }
}
