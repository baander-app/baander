<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Event;

use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Notification\Domain\Model\Notification;
use App\Notification\Domain\Repository\NotificationRepositoryInterface;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Shared\Application\Port\AdminAlertPortInterface;
use App\Shared\Domain\Model\Uuid;

final class AdminAlertService implements AdminAlertPortInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly NotificationRepositoryInterface $notificationRepository,
    ) {
    }

    public function alertAdmins(string $title, string $body, string $eventType, ?array $referenceData = null): void
    {
        $superAdmins = $this->userRepository->findAll(roleFilter: 'ROLE_SUPER_ADMIN', limit: 100);
        $admins = $this->userRepository->findAll(roleFilter: 'ROLE_ADMIN', limit: 100);

        // Deduplicate by UUID (SUPER_ADMINs also match ROLE_ADMIN filter via hierarchy)
        $seen = [];
        $adminUsers = [];
        foreach (array_merge($superAdmins, $admins) as $user) {
            $id = $user->getId()->toString();
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $adminUsers[] = $user;
            }
        }

        foreach ($adminUsers as $user) {
            $notification = Notification::create(
                userId: $user->getId(),
                category: NotificationCategory::AdminOperations,
                eventType: $eventType,
                title: $title,
                body: $body,
                referenceData: $referenceData,
            );

            $this->notificationRepository->save($notification);
        }
    }
}
