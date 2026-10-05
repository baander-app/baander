<?php

declare(strict_types=1);

namespace App\Notification\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface PushSubscriptionRemovalPortInterface
{
    /**
     * Remove only the actor's subscription. Missing and unrelated endpoints are
     * indistinguishable. The atomic delete does not flush unrelated pending work
     * or commit an existing caller transaction.
     */
    public function removeForUser(Uuid $ownerId, string $endpoint): void;
}
