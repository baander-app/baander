<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclaration;
use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclarationProviderInterface;

/** Owner constraints from Version001_InitialSchema for notification tables mapped with scalar user IDs. */
final class NotificationForeignKeys implements ForeignKeyDeclarationProviderInterface
{
    public function foreignKeys(): iterable
    {
        yield new ForeignKeyDeclaration('fk_notifications_user_id', 'notifications', 'user_id', 'users', 'id', 'CASCADE');
        yield new ForeignKeyDeclaration('fk_notification_preferences_user_id', 'notification_preferences', 'user_id', 'users', 'id', 'CASCADE');
        yield new ForeignKeyDeclaration('_fkpush_subscriptions_user_id', 'push_subscriptions', 'user_id', 'users', 'id', 'CASCADE');
    }
}
