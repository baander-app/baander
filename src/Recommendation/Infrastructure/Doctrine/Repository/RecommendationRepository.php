<?php

declare(strict_types=1);

namespace App\Recommendation\Infrastructure\Doctrine\Repository;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Recommendation\Domain\Model\Recommendation;
use App\Recommendation\Domain\Repository\RecommendationRepositoryInterface;
use App\Recommendation\Domain\ValueObject\RecommendationOrigin;
use App\Recommendation\Infrastructure\Doctrine\Entity\RecommendationEntity;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\Model\PublicId;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use RuntimeException;

final class RecommendationRepository implements RecommendationRepositoryInterface
{
    /**
     * Seed of hashtextextended(<user id>, seed) for the lock that serializes a user's member
     * writes. Its own seed keeps it apart from the application's other advisory locks; a hash
     * collision only makes unrelated work wait.
     */
    private const int MEMBER_RECOMMENDATIONS_LOCK_SEED = 20261125151723;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(Recommendation $recommendation): void
    {
        $entity = $this->findEntityOrCreate($recommendation);
        $this->syncToEntity($recommendation, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function saveMany(array $recommendations): void
    {
        foreach ($recommendations as $recommendation) {
            $entity = $this->findEntityOrCreate($recommendation);
            $this->syncToEntity($recommendation, $entity);
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();
    }

    public function findByPublicId(PublicId $publicId): ?Recommendation
    {
        $entity = $this->entityManager->getRepository(RecommendationEntity::class)->findOneBy(['publicId' => $publicId]);
        return $entity === null ? null : $this->toDomain($entity);
    }

    public function findByUuid(Uuid $uuid): ?Recommendation
    {
        $entity = $this->entityManager
            ->getRepository(RecommendationEntity::class)
            ->find($uuid);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function lockMemberRecommendations(Uuid $userId): void
    {
        $connection = $this->entityManager->getConnection();
        if (!$connection->isTransactionActive()) {
            // Outside a transaction the lock would end with its own statement and serialize nothing.
            throw new \LogicException('Lock a user\'s member recommendations inside the transaction that writes one.');
        }

        // SQL because DQL has no advisory lock. Not a row lock on the user: sign-in takes
        // FOR NO KEY UPDATE on the user row, and a write must not queue behind it.
        $connection->executeQuery(
            'SELECT pg_advisory_xact_lock(hashtextextended(:user, ' . self::MEMBER_RECOMMENDATIONS_LOCK_SEED . '))',
            ['user' => $userId->toString()],
        )->free();
    }

    public function countWrittenByMember(Uuid $userId): int
    {
        // A statement of its own after the lock: under READ COMMITTED it sees what the lock's
        // previous holder committed. The origin is a literal, not a parameter, so even a generic
        // plan can prove the predicate of idx_recommendations_user_id_member, which holds only
        // member rows; the count never reads the generator's rows for the user.
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(RecommendationEntity::class, 'r')
            ->where('IDENTITY(r.user) = :user')
            ->andWhere(sprintf("r.origin = '%s'", RecommendationOrigin::Member->value))
            ->setParameter('user', $userId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function hasDuplicateOf(Recommendation $recommendation): bool
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(RecommendationEntity::class, 'r')
            ->where('r.sourceType = :sourceType')
            ->andWhere('r.sourceId = :sourceId')
            ->andWhere('r.targetType = :targetType')
            ->andWhere('r.targetId = :targetId')
            ->andWhere('r.name = :name')
            ->setParameter('sourceType', (string) $recommendation->getSourceType())
            ->setParameter('sourceId', $recommendation->getSourceId())
            ->setParameter('targetType', (string) $recommendation->getTargetType())
            ->setParameter('targetId', $recommendation->getTargetId())
            ->setParameter('name', $recommendation->getName());
        $userId = $recommendation->getUserId();
        if ($userId === null) {
            $query->andWhere('r.user IS NULL');
        } else {
            $query->andWhere('IDENTITY(r.user) = :user')->setParameter('user', $userId, 'uuid');
        }

        // uniq_recommendations_source_target_name_user_id holds at most one such row.
        return (int) $query->getQuery()->getSingleScalarResult() > 0;
    }

    public function findBySource(string $sourceType, string $sourceId, ?Uuid $viewerId, string $name = 'default', int $limit = 100): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(RecommendationEntity::class, 'r')
            ->where('r.sourceType = :sourceType')
            ->andWhere('r.sourceId = :sourceId')
            ->andWhere('r.name = :name')
            ->setParameter('sourceType', $sourceType)
            ->setParameter('sourceId', $sourceId)
            ->setParameter('name', $name);

        return $this->bestFirst($query, $viewerId, $limit);
    }

    public function findTargeting(string $targetType, string $targetId, ?Uuid $viewerId, int $limit = 100): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(RecommendationEntity::class, 'r')
            ->where('r.targetType = :targetType')
            ->andWhere('r.targetId = :targetId')
            ->setParameter('targetType', $targetType)
            ->setParameter('targetId', $targetId);

        return $this->bestFirst($query, $viewerId, $limit);
    }

    /**
     * The viewer's rows and the global rows, filtered before the limit, so another user's rows
     * cannot fill the page; a null viewer reads every user's rows.
     *
     * @return list<Recommendation>
     */
    private function bestFirst(QueryBuilder $query, ?Uuid $viewerId, int $limit): array
    {
        if ($viewerId !== null) {
            $query->andWhere('(IDENTITY(r.user) = :viewer OR r.user IS NULL)')->setParameter('viewer', $viewerId, 'uuid');
        }

        /** @var list<RecommendationEntity> $entities */
        $entities = $query->orderBy('r.score', 'DESC')->setMaxResults($limit)->getQuery()->getResult();

        return array_map($this->toDomain(...), $entities);
    }

    public function findForUser(Uuid $userId, int $limit = 50): array
    {
        $limit = min($limit, 200);

        $entities = $this->entityManager
            ->getRepository(RecommendationEntity::class)
            ->createQueryBuilder('r')
            ->where('r.user = :userId OR r.user IS NULL')
            ->setParameter('userId', $userId)
            ->orderBy('r.score', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(fn (RecommendationEntity $e) => $this->toDomain($e), $entities);
    }

    public function deleteBySource(string $sourceType, string $sourceId, ?Uuid $actorId = null): void
    {
        $query = $this->entityManager->getRepository(RecommendationEntity::class)->createQueryBuilder('r')->delete()
            ->where('r.sourceType = :sourceType')->andWhere('r.sourceId = :sourceId')
            ->setParameter('sourceType', $sourceType)->setParameter('sourceId', $sourceId);
        if ($actorId !== null) { $query->andWhere('r.user = :actor')->setParameter('actor', $actorId); }
        $query->getQuery()->execute();
    }

    public function delete(Recommendation $recommendation): void
    {
        $entity = $this->entityManager
            ->getRepository(RecommendationEntity::class)
            ->find($recommendation->getId());

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    private function findEntityOrCreate(Recommendation $recommendation): RecommendationEntity
    {
        $existing = $this->entityManager
            ->getRepository(RecommendationEntity::class)
            ->find($recommendation->getId());

        if ($existing !== null) {
            return $existing;
        }

        return new RecommendationEntity(
            (string) $recommendation->getSourceType(),
            $recommendation->getSourceId(),
            (string) $recommendation->getTargetType(),
            $recommendation->getTargetId(),
            null,
            $recommendation->getName(),
            $recommendation->getScore(),
            $recommendation->getPosition(),
            $recommendation->getId(),
            $recommendation->getPublicId(),
            $recommendation->getOrigin(),
        );
    }

    private function syncToEntity(Recommendation $recommendation, RecommendationEntity $entity): void
    {
        $entity->setName($recommendation->getName());
        $entity->setScore($recommendation->getScore());
        $entity->setPosition($recommendation->getPosition());

        if ($recommendation->getUserId() !== null) {
            $user = $this->entityManager
                ->getRepository(UserEntity::class)
                ->find($recommendation->getUserId());

            if ($user === null) {
                throw new RuntimeException(sprintf(
                    'User with ID "%s" not found when syncing recommendation.',
                    $recommendation->getUserId()->toString(),
                ));
            }

            $entity->setUser($user);
        } else {
            $entity->setUser(null);
        }
    }

    private function toDomain(RecommendationEntity $entity): Recommendation
    {
        return Recommendation::reconstitute(
            $entity->getId(),
            $entity->getName(),
            $entity->getSourceType(),
            $entity->getSourceId(),
            $entity->getTargetType(),
            $entity->getTargetId(),
            $entity->getScore(),
            $entity->getPosition(),
            $entity->getUser()?->getId(),
            $entity->getCreatedAt(),
            $entity->getUpdatedAt(),
            $entity->getPublicId(),
            $entity->getOrigin(),
        );
    }
}
