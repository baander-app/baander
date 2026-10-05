<?php

declare(strict_types=1);

namespace App\Notification\Application\Port;

use App\Notification\Application\DTO\PushSubscriptionRegistration;
use App\Notification\Application\DTO\PushSubscriptionRegistrationResult;
use App\Shared\Domain\Model\Uuid;

interface PushSubscriptionRegistrationPortInterface
{
    /**
     * Create an unclaimed endpoint or rotate its owner's delivery credentials.
     * Other owners cannot claim it. Does not flush unrelated pending work or
     * commit an existing caller transaction.
     */
    public function registerForUser(Uuid $ownerId, PushSubscriptionRegistration $subscription): PushSubscriptionRegistrationResult;
}
