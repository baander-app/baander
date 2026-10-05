<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Push;

use App\Notification\Application\Port\PushSubscriptionRemovalPortInterface;
use App\Notification\Infrastructure\Doctrine\Entity\PushSubscriptionEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final class PushSubscriptionRepository implements PushSubscriptionRepositoryInterface, PushSubscriptionRemovalPortInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(PushSubscriptionEntity $subscription): void
    {
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();
    }

    public function remove(PushSubscriptionEntity $subscription): void
    {
        $this->entityManager->remove($subscription);
        $this->entityManager->flush();
    }

    public function removeForUser(Uuid $ownerId, string $endpoint): void
    {
        $deletedIds = $this->entityManager->getConnection()->executeQuery(
            'DELETE FROM push_subscriptions WHERE user_id = :owner AND endpoint = :endpoint RETURNING id',
            ['owner' => $ownerId, 'endpoint' => $endpoint],
            ['owner' => 'uuid'],
        )->fetchFirstColumn();

        // Detach only identities actually deleted, without initializing lazy
        // references to missing rows or flushing unrelated pending work.
        $unitOfWork = $this->entityManager->getUnitOfWork();
        foreach ($deletedIds as $id) {
            $entity = $unitOfWork->tryGetById(['id' => $id], PushSubscriptionEntity::class);
            if ($entity !== false) {
                $this->entityManager->detach($entity);
            }
        }
    }

    public function removeAllForUser(Uuid $userId): void
    {
        $subscriptions = $this->findByUser($userId);

        foreach ($subscriptions as $subscription) {
            $this->entityManager->remove($subscription);
        }

        $this->entityManager->flush();
    }

    public function findByUser(Uuid $userId): array
    {
        return $this->entityManager
            ->getRepository(PushSubscriptionEntity::class)
            ->findBy(['user' => $userId]);
    }

    public function findByEndpoint(string $endpoint): ?PushSubscriptionEntity
    {
        return $this->entityManager
            ->getRepository(PushSubscriptionEntity::class)
            ->findOneBy(['endpoint' => $endpoint]);
    }
}
