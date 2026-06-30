<?php

declare(strict_types=1);

namespace App\Radio\Infrastructure\Doctrine\Repository;

use App\Radio\Domain\Model\CountrySubscription\CountrySubscription;
use App\Radio\Domain\Model\CountrySubscription\CountrySubscriptionState;
use App\Radio\Domain\Repository\CountrySubscription\CountrySubscriptionRepositoryInterface;
use App\Radio\Infrastructure\Doctrine\Entity\CountrySubscriptionEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final class CountrySubscriptionDoctrineRepository implements CountrySubscriptionRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function find(Uuid $id): ?CountrySubscription
    {
        $entity = $this->entityManager->find(CountrySubscriptionEntity::class, $id);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByUserId(Uuid $userId): array
    {
        $entities = $this->entityManager
            ->getRepository(CountrySubscriptionEntity::class)
            ->findBy(['userId' => $userId]);

        return array_map($this->toDomain(...), $entities);
    }

    public function findByUserAndSourceAndCountry(Uuid $userId, Uuid $sourceId, string $countryCode): ?CountrySubscription
    {
        $entity = $this->entityManager
            ->getRepository(CountrySubscriptionEntity::class)
            ->findOneBy(['userId' => $userId, 'sourceId' => $sourceId, 'countryCode' => $countryCode]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function save(CountrySubscription $subscription): void
    {
        $entity = $this->findEntityOrCreate($subscription);
        $this->syncToEntity($subscription, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function remove(CountrySubscription $subscription): void
    {
        $entity = $this->entityManager->find(CountrySubscriptionEntity::class, $subscription->getId());

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    private function findEntityOrCreate(CountrySubscription $subscription): CountrySubscriptionEntity
    {
        $existing = $this->entityManager->find(CountrySubscriptionEntity::class, $subscription->getId());

        if ($existing !== null) {
            return $existing;
        }

        return new CountrySubscriptionEntity(
            id: $subscription->getId(),
            userId: $subscription->getUserId(),
            sourceId: $subscription->getSourceId(),
            countryCode: $subscription->getCountryCode(),
        );
    }

    private function syncToEntity(CountrySubscription $subscription, CountrySubscriptionEntity $entity): void
    {
        $entity->setLastSyncedAt($subscription->getLastSyncedAt());
    }

    private function toDomain(CountrySubscriptionEntity $entity): CountrySubscription
    {
        return CountrySubscription::reconstitute(new CountrySubscriptionState(
            id: $entity->getId(),
            userId: $entity->getUserId(),
            sourceId: $entity->getSourceId(),
            countryCode: $entity->getCountryCode(),
            lastSyncedAt: $entity->getLastSyncedAt(),
            createdAt: $entity->getCreatedAt(),
        ));
    }
}
