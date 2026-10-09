<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Doctrine\Repository;

use App\Notification\Application\DTO\WebhookView;
use App\Notification\Application\Port\WebhookRepositoryInterface;
use App\Notification\Infrastructure\Doctrine\Entity\WebhookEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final readonly class WebhookRepository implements WebhookRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findAll(): array
    {
        $webhooks = $this->entityManager->getRepository(WebhookEntity::class)->findBy([], ['createdAt' => 'ASC', 'id' => 'ASC']);

        return array_map(self::view(...), $webhooks);
    }

    public function find(Uuid $id): ?WebhookView
    {
        $webhook = $this->entity($id);

        return $webhook === null ? null : self::view($webhook);
    }

    public function add(Uuid $id, string $url, ?array $categoryFilter, string $encryptedSecret): WebhookView
    {
        $webhook = new WebhookEntity($id, $encryptedSecret);
        $webhook->setUrl($url);
        $webhook->setCategoryFilter($categoryFilter);
        $this->entityManager->persist($webhook);
        $this->entityManager->flush();

        return self::view($webhook);
    }

    public function update(Uuid $id, string $url, ?array $categoryFilter): ?WebhookView
    {
        $webhook = $this->entity($id);
        if ($webhook === null) {
            return null;
        }

        $webhook->setUrl($url);
        $webhook->setCategoryFilter($categoryFilter);
        $webhook->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return self::view($webhook);
    }

    public function replaceSecret(Uuid $id, string $encryptedSecret): ?WebhookView
    {
        $webhook = $this->entity($id);
        if ($webhook === null) {
            return null;
        }

        $webhook->setEncryptedSigningSecret($encryptedSecret);
        $this->entityManager->flush();

        return self::view($webhook);
    }

    public function delete(Uuid $id): bool
    {
        $webhook = $this->entity($id);
        if ($webhook === null) {
            return false;
        }

        $this->entityManager->remove($webhook);
        $this->entityManager->flush();

        return true;
    }

    private function entity(Uuid $id): ?WebhookEntity
    {
        return $this->entityManager->find(WebhookEntity::class, $id);
    }

    private static function view(WebhookEntity $webhook): WebhookView
    {
        return new WebhookView(
            $webhook->getId(),
            $webhook->getUrl(),
            $webhook->getCategoryFilter(),
            $webhook->getSigningVersion(),
            $webhook->getCreatedAt(),
            $webhook->getUpdatedAt(),
        );
    }
}
