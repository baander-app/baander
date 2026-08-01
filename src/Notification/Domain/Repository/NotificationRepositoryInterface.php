<?php

declare(strict_types=1);

namespace App\Notification\Domain\Repository;

use App\Notification\Domain\Model\Notification;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Shared\Domain\Model\Uuid;

interface NotificationRepositoryInterface
{
    public function save(Notification $notification): void;

    public function findByPublicId(string $publicId): ?Notification;

    /**
     * @return list<Notification>
     */
    public function findByUserId(
        Uuid $userId,
        ?NotificationCategory $category = null,
        ?bool $unreadOnly = null,
        ?int $limit = null,
        ?string $cursor = null,
        string $direction = 'desc',
    ): array;

    public function countUnread(Uuid $userId): int;

    public function markAsRead(Uuid $id): void;

    public function markAllAsRead(Uuid $userId): void;

    public function delete(Notification $notification): void;

    /**
     * @return list<Notification> Notifications created after the given UUID for SSE replay
     */
    public function findAfterId(Uuid $userId, Uuid $afterId): array;
}
