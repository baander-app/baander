<?php

declare(strict_types=1);

namespace App\Notification\Application\DTO;

enum PushSubscriptionRegistrationResult
{
    case Created;
    case Updated;
    case Conflict;
}
