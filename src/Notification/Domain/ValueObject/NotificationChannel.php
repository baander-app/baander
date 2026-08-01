<?php

declare(strict_types=1);

namespace App\Notification\Domain\ValueObject;

enum NotificationChannel: string
{
    case InApp = 'in_app';
    case Email = 'email';
    case Push = 'push';
    case Webhook = 'webhook';
}
