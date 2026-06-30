<?php

declare(strict_types=1);

namespace App\UserPreference\Infrastructure\Doctrine\Repository;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Domain\Model\SidebarConfig;
use App\UserPreference\Domain\Model\SidebarConfigState;
use App\UserPreference\Domain\Model\SidebarItem;
use App\UserPreference\Domain\Repository\SidebarConfigRepositoryInterface;
use App\UserPreference\Infrastructure\Doctrine\Entity\SidebarConfigEntity;
use Doctrine\ORM\EntityManagerInterface;

final class SidebarConfigDoctrineRepository implements SidebarConfigRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(SidebarConfig $config): void
    {
        $existing = $this->findEntity($config->getUserId(), $config->getMediaType());

        if ($existing !== null) {
            $existing->setItems($this->itemsToArray($config->getItems()));
            $this->entityManager->flush();

            return;
        }

        $entity = new SidebarConfigEntity($config->getId());
        $entity->setUserId($config->getUserId());
        $entity->setMediaType($config->getMediaType());
        $entity->setItems($this->itemsToArray($config->getItems()));

        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function findByUserAndMediaType(Uuid $userId, string $mediaType): ?SidebarConfig
    {
        $entity = $this->findEntity($userId, $mediaType);

        if ($entity === null) {
            return null;
        }

        return $this->toDomain($entity);
    }

    public function delete(SidebarConfig $config): void
    {
        $entity = $this->findEntity($config->getUserId(), $config->getMediaType());

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    private function findEntity(Uuid $userId, string $mediaType): ?SidebarConfigEntity
    {
        return $this->entityManager
            ->getRepository(SidebarConfigEntity::class)
            ->findOneBy(['userId' => $userId, 'mediaType' => $mediaType]);
    }

    private function toDomain(SidebarConfigEntity $entity): SidebarConfig
    {
        $items = array_map(
            fn (array $data) => SidebarItem::fromArray($data),
            $entity->getItems(),
        );

        return SidebarConfig::reconstitute(new SidebarConfigState(
            id: $entity->getId(),
            userId: $entity->getUserId(),
            mediaType: $entity->getMediaType(),
            items: $items,
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
        ));
    }

    /**
     * @param SidebarItem[] $items
     * @return array<int, array<string, mixed>>
     */
    private function itemsToArray(array $items): array
    {
        return array_map(fn (SidebarItem $item) => $item->toArray(), $items);
    }
}
