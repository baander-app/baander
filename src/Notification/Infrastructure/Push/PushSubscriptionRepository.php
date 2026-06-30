<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Push;

use App\Notification\Infrastructure\Doctrine\Entity\PushSubscriptionEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final class PushSubscriptionRepository implements PushSubscriptionRepositoryInterface
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

    public function removeByEndpoint(string $endpoint): void
    {
        $entity = $this->findByEndpoint($endpoint);
        if ($entity !== null) {
            $this->remove($entity);
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
            ->findBy(['userId' => $userId]);
    }

    public function findByEndpoint(string $endpoint): ?PushSubscriptionEntity
    {
        return $this->entityManager
            ->getRepository(PushSubscriptionEntity::class)
            ->findOneBy(['endpoint' => $endpoint]);
    }
}
