<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Event;

use App\Auth\Domain\Event\UserRegistered;
use App\Notification\Domain\Service\EventCategoryResolver;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** Deliberately excludes capture, session-local effects and transcode work. */
final class NotificationReplayDispatcher extends EventDispatcher
{
    public function __construct(
        EventCategoryResolver $categories,
        NotificationBridgeSubscriber $notifications,
        AdminAlertSubscriber $adminAlerts,
    ) {
        foreach ($categories->getMappedEventClasses() as $eventClass) {
            $this->addListener($eventClass, $notifications);
        }
        $this->addListener(UserRegistered::class, $adminAlerts->onUserRegistered(...));
    }
}
