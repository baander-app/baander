<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Doctrine\Repository;

use App\Shared\Domain\ValueObject\FilesystemType;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Domain\ValueObject\ScanClaimRelease;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Library\Domain\Model\LibraryState;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final class LibraryRepository implements LibraryRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
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

    public function claimScan(Uuid $libraryId, Uuid $claimId, int $leaseSeconds): bool
    {
        // READ COMMITTED re-checks the condition on the committed row after waiting on a concurrent
        // claim's row lock, so of two claims of a free or lapsed library one updates nothing.
        $claimed = $this->entityManager->getConnection()->executeStatement(
            "UPDATE libraries
             SET scan_status = 'scanning', scan_claim_id = :claim,
                 scan_claim_expires_at = clock_timestamp() + make_interval(secs => :lease), updated_at = now()
             WHERE id = :id
               AND (scan_claim_id IS NULL OR scan_claim_id = :claim OR scan_claim_expires_at <= clock_timestamp())",
            ['id' => $libraryId->toString(), 'claim' => $claimId->toString(), 'lease' => $leaseSeconds],
            ['lease' => ParameterType::INTEGER],
        ) === 1;

        if ($claimed) {
            $this->refreshManaged($libraryId);
        }

        return $claimed;
    }

    public function renewScanClaim(Uuid $claimId, int $leaseSeconds): bool
    {
        // A renewal leaves updated_at alone: that records changes to the library, not scan progress.
        return $this->entityManager->getConnection()->executeStatement(
            'UPDATE libraries SET scan_claim_expires_at = clock_timestamp() + make_interval(secs => :lease)
             WHERE scan_claim_id = :claim',
            ['claim' => $claimId->toString(), 'lease' => $leaseSeconds],
            ['lease' => ParameterType::INTEGER],
        ) === 1;
    }

    public function endScanClaim(Uuid $claimId, bool $completed): bool
    {
        $libraryId = $this->entityManager->getConnection()->fetchOne(
            'UPDATE libraries
             SET scan_status = :status, scan_claim_id = NULL, scan_claim_expires_at = NULL, updated_at = now(),
                 last_scan = CASE WHEN :completed THEN now() ELSE last_scan END
             WHERE scan_claim_id = :claim
             RETURNING id',
            ['claim' => $claimId->toString(), 'status' => $completed ? 'completed' : 'failed', 'completed' => $completed],
            ['completed' => ParameterType::BOOLEAN],
        );

        if (!is_string($libraryId)) {
            return false;
        }
        $this->refreshManaged(Uuid::fromString($libraryId));

        return true;
    }

    public function releaseScanClaim(Uuid $libraryId, bool $evenIfLive): ScanClaimRelease
    {
        $connection = $this->entityManager->getConnection();
        $released = $connection->executeStatement(
            "UPDATE libraries
             SET scan_status = 'failed', scan_claim_id = NULL, scan_claim_expires_at = NULL, updated_at = now()
             WHERE id = :id AND scan_claim_id IS NOT NULL AND (:even_if_live OR scan_claim_expires_at <= clock_timestamp())",
            ['id' => $libraryId->toString(), 'even_if_live' => $evenIfLive],
            ['even_if_live' => ParameterType::BOOLEAN],
        ) === 1;

        if ($released) {
            $this->refreshManaged($libraryId);

            return ScanClaimRelease::Released;
        }

        // Only a live claim can have stopped the release; this read just names the reason.
        $held = $connection->fetchOne(
            'SELECT 1 FROM libraries WHERE id = :id AND scan_claim_id IS NOT NULL',
            ['id' => $libraryId->toString()],
        );

        return $held === false ? ScanClaimRelease::NoClaim : ScanClaimRelease::Live;
    }

    public function hasLiveScanClaim(Uuid $libraryId): bool
    {
        return $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM libraries WHERE id = :id AND scan_claim_expires_at > clock_timestamp()',
            ['id' => $libraryId->toString()],
        ) !== false;
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
            discoveryStatus: $this->discoveryStatus($entity),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
        ));
    }

    /** No scan renews a lapsed claim any longer, so the library reads as failed until a scan claims it. */
    private function discoveryStatus(LibraryEntity $entity): ?string
    {
        $expiresAt = $entity->getScanClaimExpiresAt();
        if ($expiresAt !== null && $expiresAt <= $this->clock->now()) {
            return 'failed';
        }

        return $entity->getScanStatus();
    }

    private function syncToEntity(Library $library, LibraryEntity $entity): void
    {
        $state = $library->getState();
        $entity->setName($state->name);
        $entity->setSortOrder($state->sortOrder);
        // The scan status and time belong to the claim statements; a flush never writes them.
    }
}
