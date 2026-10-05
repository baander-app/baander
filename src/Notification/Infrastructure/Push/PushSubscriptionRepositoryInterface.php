<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Push;

use App\Notification\Infrastructure\Doctrine\Entity\PushSubscriptionEntity;
use App\Shared\Domain\Model\Uuid;

interface PushSubscriptionRepositoryInterface
{
    public function remove(PushSubscriptionEntity $subscription): void;

    /**
     * @return list<PushSubscriptionEntity>
     */
    public function findByUser(Uuid $userId): array;
}
