<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Doctrine\Repository;

use App\Notification\Domain\Model\Notification;
use App\Notification\Domain\Repository\NotificationRepositoryInterface;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Notification\Infrastructure\Doctrine\Entity\NotificationEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final class NotificationRepository implements NotificationRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    private function getEntityRepository(): \Doctrine\ORM\EntityRepository
    {
        return $this->entityManager->getRepository(NotificationEntity::class);
    }

    public function save(Notification $notification): void
    {
        $entity = new NotificationEntity($notification->getId());
        $entity->setPublicId($notification->getPublicId());
        $entity->setUserId($notification->getUserId());
        $entity->setCategory($notification->getCategory()->value);
        $entity->setEventType($notification->getEventType());
        $entity->setTitle($notification->getTitle());
        $entity->setBody($notification->getBody());
        $entity->setIsRead($notification->isRead());
        $entity->setReferenceData($notification->getReferenceData());
        $entity->setParameters($notification->getParameters());

        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function findByPublicId(string $publicId): ?Notification
    {
        $entity = $this->getEntityRepository()->findOneBy(['publicId' => PublicId::fromString($publicId)]);
        if ($entity === null) {
            return null;
        }

        return $this->toDomain($entity);
    }

    public function findByUserId(
        Uuid $userId,
        ?NotificationCategory $category = null,
        ?bool $unreadOnly = null,
        ?int $limit = null,
        ?string $cursor = null,
        string $direction = 'desc',
    ): array {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('e')
            ->from(NotificationEntity::class, 'e')
            ->where('e.userId = :userId')
            ->setParameter('userId', $userId->toString())
            ->orderBy('e.id', $direction === 'asc' ? 'ASC' : 'DESC');

        if ($category !== null) {
            $qb->andWhere('e.category = :category')
                ->setParameter('category', $category->value);
        }

        if ($unreadOnly === true) {
            $qb->andWhere('e.isRead = false');
        }

        if ($cursor !== null) {
            if ($direction === 'asc') {
                $qb->andWhere('e.id > :cursor');
            } else {
                $qb->andWhere('e.id < :cursor');
            }
            $qb->setParameter('cursor', $cursor);
        }

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        $entities = $qb->getQuery()->getResult();

        return array_map(fn (NotificationEntity $e) => $this->toDomain($e), $entities);
    }

    public function countUnread(Uuid $userId): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(NotificationEntity::class, 'e')
            ->where('e.userId = :userId')
            ->andWhere('e.isRead = false')
            ->setParameter('userId', $userId->toString())
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function markAsRead(Uuid $id): void
    {
        $entity = $this->getEntityRepository()->find($id);
        if ($entity !== null) {
            $entity->setIsRead(true);
            $this->entityManager->flush();
        }
    }

    public function markAllAsRead(Uuid $userId): void
    {
        $this->entityManager->createQueryBuilder()
            ->update(NotificationEntity::class, 'e')
            ->set('e.isRead', 'true')
            ->where('e.userId = :userId')
            ->andWhere('e.isRead = false')
            ->setParameter('userId', $userId->toString())
            ->getQuery()
            ->execute();
    }

    public function delete(Notification $notification): void
    {
        $entity = $this->getEntityRepository()->find($notification->getId());
        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    public function findAfterId(Uuid $userId, Uuid $afterId): array
    {
        $entities = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(NotificationEntity::class, 'e')
            ->where('e.userId = :userId')
            ->andWhere('e.id > :afterId')
            ->setParameter('userId', $userId->toString())
            ->setParameter('afterId', $afterId->toString())
            ->orderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(fn (NotificationEntity $e) => $this->toDomain($e), $entities);
    }

    private function toDomain(NotificationEntity $entity): Notification
    {
        return Notification::reconstitute(
            $entity->getId(),
            $entity->getPublicId(),
            $entity->getUserId(),
            NotificationCategory::from($entity->getCategory()),
            $entity->getEventType(),
            $entity->getTitle(),
            $entity->getBody(),
            $entity->isRead(),
            $entity->getCreatedAt(),
            $entity->getReferenceData(),
            $entity->getParameters(),
        );
    }
}
