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

    /**
     * One statement, so a concurrent grant of the same membership waits for this one and then
     * inserts nothing. A unique violation caught after a flush would close the EntityManager.
     */
    public function grant(Uuid $userId, Uuid $libraryId): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO user_library_access (user_id, library_id, granted_at) VALUES (:userId, :libraryId, now())
             ON CONFLICT (user_id, library_id) DO NOTHING',
            ['userId' => $userId->toString(), 'libraryId' => $libraryId->toString()],
        );
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
