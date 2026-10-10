<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Doctrine\Repository;

use App\Shared\Domain\ValueObject\FilesystemType;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Domain\ValueObject\LibraryClaimAttempt;
use App\Library\Domain\ValueObject\LibraryClaimKind;
use App\Library\Domain\ValueObject\LibraryClaimRelease;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Library\Domain\Model\LibraryState;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Clock\ClockInterface;

final class LibraryRepository implements LibraryRepositoryInterface
{
    /**
     * The seed that keys the advisory lock between imports and delete claims of one library:
     * hashtextextended(library ID, seed). Its own seed keeps it apart from the other advisory
     * locks of the application; a hash collision only makes two libraries wait for each other.
     */
    private const int IMPORT_LOCK_SEED = 20261010120000;

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

    public function claimScan(Uuid $libraryId, Uuid $claimId, int $leaseSeconds): LibraryClaimAttempt
    {
        return $this->claim($libraryId, $claimId, $leaseSeconds, LibraryClaimKind::Scan, "scan_status = 'scanning', updated_at = now()");
    }

    public function claimDelete(Uuid $libraryId, Uuid $claimId, int $leaseSeconds): LibraryClaimAttempt
    {
        $connection = $this->entityManager->getConnection();

        return $connection->transactional(function () use ($connection, $libraryId, $claimId, $leaseSeconds): LibraryClaimAttempt {
            // Waits for the imports that hold the shared lock, so the songs they write commit
            // before the claim does; an import that takes the lock later sees the claim.
            $connection->executeQuery(
                'SELECT pg_advisory_xact_lock(hashtextextended(:id, ' . self::IMPORT_LOCK_SEED . '))',
                ['id' => $libraryId->toString()],
            )->free();

            // A lapsed scan claim ends as failed, as releasing it would end it, so the library
            // never reads as scanning while a delete holds it.
            return $this->claim(
                $libraryId,
                $claimId,
                $leaseSeconds,
                LibraryClaimKind::Delete,
                "scan_status = CASE WHEN held.claim_kind = 'scan' THEN 'failed' ELSE libraries.scan_status END,
                 updated_at = CASE WHEN held.claim_kind = 'scan' THEN now() ELSE libraries.updated_at END",
            );
        });
    }

    public function renewClaim(Uuid $claimId, int $leaseSeconds): bool
    {
        // A renewal leaves updated_at alone: that records changes to the library, not progress.
        return $this->entityManager->getConnection()->executeStatement(
            'UPDATE libraries SET claim_expires_at = clock_timestamp() + make_interval(secs => :lease)
             WHERE claim_id = :claim',
            ['claim' => $claimId->toString(), 'lease' => $leaseSeconds],
            ['lease' => ParameterType::INTEGER],
        ) === 1;
    }

    public function endScanClaim(Uuid $claimId, bool $completed): bool
    {
        $libraryId = $this->entityManager->getConnection()->fetchOne(
            "UPDATE libraries
             SET scan_status = :status, claim_id = NULL, claim_kind = NULL, claim_expires_at = NULL, updated_at = now(),
                 last_scan = CASE WHEN :completed THEN now() ELSE last_scan END
             WHERE claim_id = :claim AND claim_kind = 'scan'
             RETURNING id",
            ['claim' => $claimId->toString(), 'status' => $completed ? 'completed' : 'failed', 'completed' => $completed],
            ['completed' => ParameterType::BOOLEAN],
        );

        return $this->refreshEnded($libraryId);
    }

    public function endDeleteClaim(Uuid $claimId): bool
    {
        $libraryId = $this->entityManager->getConnection()->fetchOne(
            "UPDATE libraries SET claim_id = NULL, claim_kind = NULL, claim_expires_at = NULL
             WHERE claim_id = :claim AND claim_kind = 'delete'
             RETURNING id",
            ['claim' => $claimId->toString()],
        );

        return $this->refreshEnded($libraryId);
    }

    public function releaseClaim(Uuid $libraryId, bool $evenIfLive): LibraryClaimRelease
    {
        $connection = $this->entityManager->getConnection();
        // RETURNING old (PostgreSQL 18) names the kind of the claim this statement ended.
        $released = $connection->fetchOne(
            "UPDATE libraries
             SET scan_status = CASE WHEN claim_kind = 'scan' THEN 'failed' ELSE scan_status END,
                 updated_at = CASE WHEN claim_kind = 'scan' THEN now() ELSE updated_at END,
                 claim_id = NULL, claim_kind = NULL, claim_expires_at = NULL
             WHERE id = :id AND claim_id IS NOT NULL AND (:even_if_live OR claim_expires_at <= clock_timestamp())
             RETURNING old.claim_kind",
            ['id' => $libraryId->toString(), 'even_if_live' => $evenIfLive],
            ['even_if_live' => ParameterType::BOOLEAN],
        );

        if (is_string($released)) {
            $this->refreshManaged($libraryId);

            return LibraryClaimRelease::released(LibraryClaimKind::from($released));
        }

        // Only a live claim can have stopped the release; this read just names it.
        $live = $connection->fetchOne(
            'SELECT claim_kind FROM libraries WHERE id = :id AND claim_id IS NOT NULL',
            ['id' => $libraryId->toString()],
        );

        return is_string($live) ? LibraryClaimRelease::live(LibraryClaimKind::from($live)) : LibraryClaimRelease::noClaim();
    }

    public function liveClaimKind(Uuid $libraryId): ?LibraryClaimKind
    {
        $kind = $this->entityManager->getConnection()->fetchOne(
            'SELECT claim_kind FROM libraries WHERE id = :id AND claim_expires_at > clock_timestamp()',
            ['id' => $libraryId->toString()],
        );

        return is_string($kind) ? LibraryClaimKind::from($kind) : null;
    }

    public function liveClaimKindForImport(Uuid $libraryId): ?LibraryClaimKind
    {
        $connection = $this->entityManager->getConnection();
        // Outside a transaction the lock would end with its own statement.
        if (!$connection->isTransactionActive()) {
            throw new LogicException('Read the claim for an import inside the transaction that writes the import.');
        }

        $connection->executeQuery(
            'SELECT pg_advisory_xact_lock_shared(hashtextextended(:id, ' . self::IMPORT_LOCK_SEED . '))',
            ['id' => $libraryId->toString()],
        )->free();

        // A statement of its own: under READ COMMITTED it reads a snapshot taken after the lock
        // was granted, so it sees a delete claim that committed while it waited.
        return $this->liveClaimKind($libraryId);
    }

    // --- Internal ---

    /**
     * Claims the library for $claimId with one statement. The CTE locks the library row, so a
     * concurrent claim waits for this one's transaction, and READ COMMITTED then hands the
     * waiting statement the committed row: of two claims of a free or lapsed library exactly one
     * wins, and the other reads the kind of the claim that beat it from the same locked row.
     *
     * @param string $statusAssignments the kind's SET assignments of scan_status and updated_at;
     *                                  `held` is the row as the lock found it
     */
    private function claim(Uuid $libraryId, Uuid $claimId, int $leaseSeconds, LibraryClaimKind $kind, string $statusAssignments): LibraryClaimAttempt
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            "WITH held AS (
                 SELECT claim_id, claim_kind, claim_expires_at <= clock_timestamp() AS lapsed
                 FROM libraries
                 WHERE id = :id
                 FOR UPDATE
             ), taken AS (
                 UPDATE libraries
                 SET {$statusAssignments}, claim_id = :claim, claim_kind = :kind,
                     claim_expires_at = clock_timestamp() + make_interval(secs => :lease)
                 FROM held
                 WHERE libraries.id = :id
                   AND (held.claim_id IS NULL OR held.claim_id = :claim OR held.lapsed)
                 RETURNING libraries.id
             )
             SELECT EXISTS (SELECT FROM taken) AS claimed, held.claim_kind FROM held",
            ['id' => $libraryId->toString(), 'claim' => $claimId->toString(), 'kind' => $kind->value, 'lease' => $leaseSeconds],
            ['lease' => ParameterType::INTEGER],
        );

        if ($row === false) {
            return LibraryClaimAttempt::noLibrary();
        }
        if ($row['claimed'] === true) {
            $this->refreshManaged($libraryId);

            return LibraryClaimAttempt::claimed();
        }

        return LibraryClaimAttempt::heldBy(LibraryClaimKind::from((string) $row['claim_kind']));
    }

    /** @param mixed $libraryId the ID an ending statement returned, or false when it ended no claim */
    private function refreshEnded(mixed $libraryId): bool
    {
        if (!is_string($libraryId)) {
            return false;
        }
        $this->refreshManaged(Uuid::fromString($libraryId));

        return true;
    }

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

    /**
     * No scan renews a lapsed scan claim any longer, so the library reads as failed until another
     * claim takes it over. A delete claim leaves the stored status in force, lapsed or not.
     */
    private function discoveryStatus(LibraryEntity $entity): ?string
    {
        $expiresAt = $entity->getClaimExpiresAt();
        if ($entity->getClaimKind() === LibraryClaimKind::Scan->value && $expiresAt !== null && $expiresAt <= $this->clock->now()) {
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
