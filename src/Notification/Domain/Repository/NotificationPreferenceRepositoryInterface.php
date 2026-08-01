<?php

declare(strict_types=1);

namespace App\Notification\Domain\Repository;

use App\Notification\Domain\Model\NotificationPreference;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Notification\Domain\ValueObject\NotificationChannel;
use App\Shared\Domain\Model\Uuid;

interface NotificationPreferenceRepositoryInterface
{
    public function save(NotificationPreference $preference): void;

    public function findByUserAndCategoryAndChannel(
        Uuid $userId,
        NotificationCategory $category,
        NotificationChannel $channel,
    ): ?NotificationPreference;

    /**
     * @return list<NotificationPreference>
     */
    public function findByUserId(Uuid $userId): array;

    public function isEnabled(
        Uuid $userId,
        NotificationCategory $category,
        NotificationChannel $channel,
    ): bool;
}
