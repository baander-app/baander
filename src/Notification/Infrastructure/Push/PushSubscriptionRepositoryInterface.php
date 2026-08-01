<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Push;

use App\Notification\Infrastructure\Doctrine\Entity\PushSubscriptionEntity;
use App\Shared\Domain\Model\Uuid;

interface PushSubscriptionRepositoryInterface
{
    public function save(PushSubscriptionEntity $subscription): void;

    public function remove(PushSubscriptionEntity $subscription): void;

    public function removeByEndpoint(string $endpoint): void;

    public function removeAllForUser(Uuid $userId): void;

    /**
     * @return list<PushSubscriptionEntity>
     */
    public function findByUser(Uuid $userId): array;

    public function findByEndpoint(string $endpoint): ?PushSubscriptionEntity;
}
