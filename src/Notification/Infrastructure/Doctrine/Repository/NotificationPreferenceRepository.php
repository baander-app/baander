<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Doctrine\Repository;

use App\Notification\Domain\Model\NotificationPreference;
use App\Notification\Domain\Repository\NotificationPreferenceRepositoryInterface;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Notification\Domain\ValueObject\NotificationChannel;
use App\Notification\Infrastructure\Doctrine\Entity\NotificationPreferenceEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final class NotificationPreferenceRepository implements NotificationPreferenceRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    private function getEntityRepository(): \Doctrine\ORM\EntityRepository
    {
        return $this->entityManager->getRepository(NotificationPreferenceEntity::class);
    }

    public function save(NotificationPreference $preference): void
    {
        $existing = $this->getEntityRepository()->findOneBy([
            'userId' => $preference->getUserId(),
            'category' => $preference->getCategory()->value,
            'channel' => $preference->getChannel()->value,
        ]);

        if ($existing !== null) {
            $existing->setEnabled($preference->isEnabled());
            $this->entityManager->flush();

            return;
        }

        $entity = new NotificationPreferenceEntity($preference->getId());
        $entity->setUserId($preference->getUserId());
        $entity->setCategory($preference->getCategory()->value);
        $entity->setChannel($preference->getChannel()->value);
        $entity->setEnabled($preference->isEnabled());

        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function findByUserAndCategoryAndChannel(
        Uuid $userId,
        NotificationCategory $category,
        NotificationChannel $channel,
    ): ?NotificationPreference {
        $entity = $this->getEntityRepository()->findOneBy([
            'userId' => $userId,
            'category' => $category->value,
            'channel' => $channel->value,
        ]);

        if ($entity === null) {
            return null;
        }

        return $this->toDomain($entity);
    }

    public function findByUserId(Uuid $userId): array
    {
        $entities = $this->getEntityRepository()->findBy([
            'userId' => $userId,
        ]);

        return array_map(fn (NotificationPreferenceEntity $e) => $this->toDomain($e), $entities);
    }

    public function isEnabled(
        Uuid $userId,
        NotificationCategory $category,
        NotificationChannel $channel,
    ): bool {
        $preference = $this->findByUserAndCategoryAndChannel($userId, $category, $channel);

        if ($preference !== null) {
            return $preference->isEnabled();
        }

        // Default: all channels enabled when no explicit preference exists
        return true;
    }

    private function toDomain(NotificationPreferenceEntity $entity): NotificationPreference
    {
        return NotificationPreference::reconstitute(
            $entity->getId(),
            $entity->getUserId(),
            NotificationCategory::from($entity->getCategory()),
            NotificationChannel::from($entity->getChannel()),
            $entity->isEnabled(),
            $entity->getCreatedAt(),
            $entity->getUpdatedAt(),
        );
    }
}
