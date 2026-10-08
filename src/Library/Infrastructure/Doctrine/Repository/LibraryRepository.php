<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Doctrine\Repository;

use App\Shared\Domain\ValueObject\FilesystemType;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Library\Domain\Model\LibraryState;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use Doctrine\ORM\EntityManagerInterface;

final class LibraryRepository implements LibraryRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function findVisible(LibraryReadScope $scope, ?LibraryType $type = null): array
    {
        $qb = $this->visibleQuery($scope);
        if ($type !== null) {
            $qb->andWhere('l.type = :type')
                ->setParameter('type', $type->value);
        }
        $entities = $qb->orderBy('l.sortOrder', 'ASC')
            ->addOrderBy('l.name', 'ASC')
            ->getQuery()
            ->getResult();
        return array_map($this->toDomain(...), $entities);
    }

    public function findVisibleByUuid(Uuid $uuid, LibraryReadScope $scope): ?Library
    {
        $entity = $this
            ->visibleQuery($scope)
            ->andWhere('l.id = :id')
            ->setParameter('id', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
        return $entity === null ? null : $this->toDomain($entity);
    }

    public function findVisibleBySlug(LibrarySlug $slug, LibraryReadScope $scope): ?Library
    {
        $entity = $this
            ->visibleQuery($scope)
            ->andWhere('l.slug = :slug')
            ->setParameter('slug', $slug->toString())
            ->getQuery()
            ->getOneOrNullResult();
        return $entity === null ? null : $this->toDomain($entity);
    }

    private function visibleQuery(LibraryReadScope $scope): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->entityManager
            ->getRepository(LibraryEntity::class)
            ->createQueryBuilder('l');
        if (!$scope->isUnrestricted()) {
            if ($scope->getLibraryIds() === []) {
                $qb->andWhere('1 = 0');
            } else {
                $qb
                    ->andWhere('l.id IN (:visible_libraries)')
                    ->setParameter('visible_libraries', $scope->getLibraryIds());
            }
        }
        return $qb;
    }

    public function save(Library $library): void
    {
        $entity = $this->findEntityOrCreate($library);
        $this->syncToEntity($library, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function findByUuid(Uuid $uuid): ?Library
    {
        $entity = $this->entityManager
            ->getRepository(LibraryEntity::class)
            ->find($uuid);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findBySlug(LibrarySlug $slug): ?Library
    {
        $entity = $this->entityManager
            ->getRepository(LibraryEntity::class)
            ->findOneBy(['slug' => $slug->toString()]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByType(LibraryType $type): array
    {
        $entities = $this->entityManager
            ->getRepository(LibraryEntity::class)
            ->findBy(['type' => $type->value], ['sortOrder' => 'ASC']);

        return array_map(fn (LibraryEntity $entity) => $this->toDomain($entity), $entities);
    }

    public function findAllOrdered(): array
    {
        $entities = $this->entityManager
            ->getRepository(LibraryEntity::class)
            ->findBy([], ['sortOrder' => 'ASC', 'name' => 'ASC']);

        return array_map(fn (LibraryEntity $entity) => $this->toDomain($entity), $entities);
    }

    public function findAccessibleByUser(Uuid $userId): array
    {
        $libraryIds = $this->entityManager->getConnection()->executeQuery(
            'SELECT library_id FROM user_library_access WHERE user_id = :userId',
            ['userId' => $userId->toString()],
        )->fetchFirstColumn();

        if ($libraryIds === []) {
            return [];
        }

        $entities = $this->entityManager
            ->getRepository(LibraryEntity::class)
            ->findBy(['id' => $libraryIds], ['sortOrder' => 'ASC']);

        return array_map(fn (LibraryEntity $entity) => $this->toDomain($entity), $entities);
    }

    public function delete(Library $library): void
    {
        $entity = $this->entityManager
            ->getRepository(LibraryEntity::class)
            ->find($library->getId());

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    public function claimScan(Uuid $libraryId): bool
    {
        // READ COMMITTED re-checks the condition after a concurrent claim commits, so a second
        // claimer that waited on the row lock updates nothing.
        $claimed = $this->entityManager->getConnection()->executeStatement(
            "UPDATE libraries SET scan_status = 'scanning', updated_at = now()
             WHERE id = :id AND scan_status IS DISTINCT FROM 'scanning'",
            ['id' => $libraryId->toString()],
        ) === 1;

        if ($claimed) {
            $this->refreshManaged($libraryId);
        }

        return $claimed;
    }

    public function releaseScanClaim(Uuid $libraryId): bool
    {
        $released = $this->entityManager->getConnection()->executeStatement(
            "UPDATE libraries SET scan_status = 'failed', updated_at = now()
             WHERE id = :id AND scan_status = 'scanning'",
            ['id' => $libraryId->toString()],
        ) === 1;

        if ($released) {
            $this->refreshManaged($libraryId);
        }

        return $released;
    }

    // --- Internal ---

    /** The claim bypasses the unit of work; a library it already loaded must not keep the old status. */
    private function refreshManaged(Uuid $libraryId): void
    {
        // A failed flush closes the manager; the claim must still be releasable then.
        if (!$this->entityManager->isOpen()) {
            return;
        }

        $entity = $this->entityManager->getUnitOfWork()->tryGetById(['id' => $libraryId], LibraryEntity::class);
        if ($entity instanceof LibraryEntity) {
            $this->entityManager->refresh($entity);
        }
    }

    private function findEntityOrCreate(Library $library): LibraryEntity
    {
        $state = $library->getState();
        $existing = $this->entityManager
            ->getRepository(LibraryEntity::class)
            ->find($state->id);

        if ($existing !== null) {
            return $existing;
        }

        return new LibraryEntity(
            $state->name,
            $state->slug->toString(),
            $state->path->toString(),
            $state->type->value,
            $state->filesystemType->value,
            $state->sortOrder,
            id: $state->id,
        );
    }

    private function toDomain(LibraryEntity $entity): Library
    {
        return Library::reconstitute(new LibraryState(
            id: $entity->getId(),
            name: $entity->getName(),
            slug: new LibrarySlug($entity->getSlug()),
            path: new LibraryPath($entity->getPath()),
            type: LibraryType::from($entity->getType()),
            filesystemType: FilesystemType::from($entity->getFilesystemType()),
            sortOrder: $entity->getSortOrder(),
            lastScan: $entity->getLastScan(),
            discoveryStatus: $entity->getScanStatus(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
        ));
    }

    private function syncToEntity(Library $library, LibraryEntity $entity): void
    {
        $state = $library->getState();
        $entity->setName($state->name);
        $entity->setSortOrder($state->sortOrder);
        $entity->setScanStatus($state->discoveryStatus);

        if ($state->lastScan !== null) {
            $entity->markScanned();
        }
    }
}
